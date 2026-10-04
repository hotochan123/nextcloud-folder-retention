#!/usr/bin/env bash
# Integrations-Harness für folder_retention: startet eine Wegwerf-Nextcloud (SQLite, ohne
# Port-Freigabe), spielt die Datenverlust-Szenarien aus dem Audit zu 0.7.4 nach und gibt je
# Szenario eine maschinenlesbare Zeile aus:
#   PASS|FAIL|SKIP <id> <text>
# Letzte Zeile: "Harness <pass>/<fail>/<skip>", Exitcode 1 bei mindestens einem FAIL.
# Diagnose (was gerade passiert) geht nach stderr.
#
# Umgebung:
#   IMAGE=nextcloud:34.0.4-apache   Nextcloud-Image (35 geht auch, groupfolders wird passend gewählt)
#   APP_SRC=<Repo-Arbeitsbaum>      Quelle der App; ohne js/ wird mit node:24-alpine gebaut
#   ONLY=S1,S3                      nur diese Szenarien (Vorbereitung läuft immer)
#   KEEP=1                          Container + Arbeitsverzeichnis am Ende stehen lassen
#   GF_TAG=v22.0.6                  groupfolders-Release fest vorgeben (sonst: neueste passende von GitHub)
#   OLD_REV=fb4395c                 Ausgangsstand für das Update-Szenario S24
#   NO_GF=1                         groupfolders gar nicht erst versuchen (S8 = SKIP)
#   FRET_IT_PREFIX=fret-a-          Container-Präfix (Standard fret-it-), für parallele Läufe
#
# Niemals gegen die Produktivinstanz: Der Harness legt ausschließlich eigene Container fret-it-* an.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
IMAGE="${IMAGE:-nextcloud:34.0.4-apache}"
APP_SRC="$(cd "${APP_SRC:-$REPO}" && pwd)"
# FRET_IT_PREFIX: eigener Präfix, wenn mehrere Harness-Läufe parallel laufen (z. B. fret-a-)
C="${FRET_IT_PREFIX:-fret-it-}$$"
ONLY="${ONLY:-}"
KEEP="${KEEP:-0}"
NC_TZ=Europe/Berlin
OLD_REV="${OLD_REV:-fb4395c}"
PW='It-Harness-Pw-2026!'
WORK="$(mktemp -d "${TMPDIR:-/tmp}/fret-it.XXXXXX")"
RES="$WORK/results"
: > "$RES"
STARTED=$(date +%s)

cleanup() {
	local rc=$?
	if [[ "$KEEP" == 1 ]]; then
		echo "KEEP=1: Container $C (ggf. auch $C-os, $C-up, $C-rm, $C-rs, $C-enc) und $WORK bleiben stehen (docker rm -fv $C $C-os $C-up $C-rm $C-rs $C-enc)" >&2
	else
		docker rm -fv "$C" "$C-os" "$C-up" "$C-rm" "$C-rs" "$C-enc" >/dev/null 2>&1 || true
		if [[ $rc -eq 0 ]]; then
			rm -rf "$WORK"
		else
			echo "Protokolle der Läufe: $WORK" >&2
		fi
	fi
}
trap cleanup EXIT

info() { echo "  · $*" >&2; }

# ---------------------------------------------------------------- Helfer im Container

occ() { docker exec -u www-data "$C" php occ "$@"; }

# SQL gegen die SQLite-DB der Wegwerf-Instanz (Tabellenpräfix oc_)
sql() { docker exec -u www-data "$C" php /tmp/fret-sql.php "$@"; }

now() { date +%s; }
ago() { echo $(( $(now) - $1 * 86400 )); }

# Datei per WebDAV hochladen; optional mit X-OC-CTime (landet in filecache_extended.creation_time)
put() {
	local user=$1 path=$2 ctime=${3:-}
	local hdr=()
	[[ -n "$ctime" ]] && hdr=(-H "X-OC-CTime: $ctime")
	echo "Inhalt $path" | docker exec -i "$C" curl -sf -o /dev/null -u "$user:$PW" -T - "${hdr[@]}" \
		"http://localhost/remote.php/dav/files/$user/$path"
}

# PROPFIND richtet das Dateisystem des Kontos ein → Home- und Team-Ordner-Mounts landen in
# oc_mounts, lastLogin wird gesetzt (RootProvider sieht nur „gesehene“ Konten).
touch_fs() {
	docker exec "$C" curl -sf -o /dev/null -u "$1:$PW" -X PROPFIND -H 'Depth: 1' \
		"http://localhost/remote.php/dav/files/$1/"
}

# fileid der lebenden Datei (nicht Papierkorb/Versionen) über den eindeutigen Dateinamen
fid() {
	sql "SELECT fileid FROM oc_filecache WHERE name = ? AND path NOT LIKE 'files_trashbin/%'
		AND path NOT LIKE 'files_versions/%' AND path NOT LIKE 'trash/%' AND path NOT LIKE 'versions/%'
		ORDER BY fileid DESC LIMIT 1" "$1"
}

# Datei „altern“ lassen: oc_filecache.mtime sowie oc_filecache_extended.upload_time und
# .creation_time auf <tage> (bzw. <ctime-tage>) vor jetzt setzen. storage_mtime bleibt, damit
# kein Scanner den Eintrag „repariert“. Dazu „zuerst gesehen“ (0.8.0): Ohne den Eintrag sähe die
# frisch angelegte Datei mit alter Upload-Zeit aus wie eine Kopie und zählte ab dem ersten Lauf.
age() {
	local name=$1 days=$2 cdays=${3:-$2} id
	id=$(fid "$name")
	[[ -n "$id" ]] || { info "age: $name nicht im Filecache"; return 1; }
	age_id "$id" "$days" "$cdays"
}

# wie age, aber über die fileid (für gleichnamige Dateien)
age_id() {
	local id=$1 days=$2 cdays=${3:-$2} ts cts
	ts=$(ago "$days")
	cts=$(ago "$cdays")
	sql "UPDATE oc_filecache SET mtime = ? WHERE fileid = ?" "$ts" "$id"
	sql "INSERT OR IGNORE INTO oc_filecache_extended (fileid) VALUES (?)" "$id"
	sql "UPDATE oc_filecache_extended SET upload_time = ?, creation_time = ? WHERE fileid = ?" "$ts" "$cts" "$id"
	seen "$id" "$ts"
}

# „zuerst gesehen“ setzen (nur, wenn die Tabelle existiert – ältere Stände ohne 0.8-Migration)
seen() {
	sql "INSERT OR REPLACE INTO oc_folder_retention_seen (file_id, first_seen) SELECT ?, ?
		WHERE EXISTS (SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'oc_folder_retention_seen')" "$1" "$2"
}

# fileid einer Datei im Home von <user> über den Pfad unter files/
fid_path() {
	sql "SELECT f.fileid FROM oc_filecache f JOIN oc_storages s ON s.numeric_id = f.storage
		WHERE s.id = ? AND f.path = ?" "home::$1" "files/$2"
}

in_files() { docker exec "$C" test -f "/var/www/html/data/$1/files/$2"; }
# Papierkorb-Kopie liegt als <name>.d<timestamp> in files_trashbin/files
in_trash() {
	docker exec "$C" sh -c "ls /var/www/html/data/$1/files_trashbin/files/ 2>/dev/null | grep -q '^$2\.d[0-9]'"
}
# irgendwo auf der Platte (Dateien, Papierkorb, Team-Ordner samt Papierkorb)?
anywhere() { docker exec "$C" sh -c "find /var/www/html/data -name '$1*' | grep -q ."; }

# Log der App zu einer Datei: "mode:status" je Zeile, älteste zuerst
logrows() {
	sql "SELECT mode || ':' || status || CASE WHEN message IS NULL THEN '' ELSE ' (' || message || ')' END
		FROM oc_folder_retention_log WHERE path LIKE ? ORDER BY id" "%$1"
}
last_status() { logrows "$1" | tail -n1; }

# Aufbewahrungslauf per occ; Ausgabe nach $WORK/<tag>.txt, Exitcode egal (Fehler → FAILURE)
retention_run() {
	occ folder_retention:run > "$WORK/$1.txt" 2>&1 || true
}

# Sicherheitssperren (oc_folder_retention_block) als „schlüssel@zeitpunkt“, sortiert
blocks() {
	sql "SELECT block_key || '@' || blocked_at FROM oc_folder_retention_block WHERE block_key LIKE ? ORDER BY block_key" "${1:-%}" | paste -sd' '
}

set_sim() { occ config:app:set folder_retention simulation_mode --value="$1" --type=boolean >/dev/null; }

# Beide Standardregeln (allgemein + persönliche Ordner, folder_id NULL) direkt in der DB setzen
set_default_rules() {
	local unit=$1 value=${2:-}
	if [[ "$unit" == never ]]; then
		sql "UPDATE oc_folder_retention_rules SET period_unit = 'never', period_value = NULL, basis = 'created' WHERE folder_id IS NULL"
	else
		sql "UPDATE oc_folder_retention_rules SET period_unit = ?, period_value = ?, basis = 'created' WHERE folder_id IS NULL" "$unit" "$value"
	fi
}

result() {
	local st=$1 id=$2
	shift 2
	echo "$st $id $*" | tee -a "$RES"
}

scenario() {
	local id=$1 fn=$2 rc
	if [[ -n "$ONLY" && ",$ONLY," != *",$id,"* ]]; then
		return 0
	fi
	echo "== $id" >&2
	set +e
	( set -e; "$fn" )
	rc=$?
	set -e
	if ! grep -q "^[A-Z]* $id " "$RES"; then
		result FAIL "$id" "Szenario abgebrochen (Exit $rc), Ausgaben in $WORK"
	fi
}

# ---------------------------------------------------------------- Aufbau

build_js_if_needed() {
	if compgen -G "$APP_SRC/js/*.mjs" >/dev/null || compgen -G "$APP_SRC/js/*.js" >/dev/null; then
		return 0
	fi
	info "APP_SRC hat kein js/ – baue mit node:24-alpine (npm ci + build)"
	docker run --rm -v "$APP_SRC":/app -w /app node:24-alpine \
		sh -c 'npm ci --no-audit --no-fund --loglevel=error && npm run build' >"$WORK/js-build.txt" 2>&1 \
		|| { echo "js-Build fehlgeschlagen, siehe $WORK/js-build.txt" >&2; exit 2; }
}

# $1 (optional): Funktion, die vor dem ersten Start Dateien in den angelegten Container legt
start_nc() {
	local prep=${1:-}
	info "starte $C aus $IMAGE"
	docker create --name "$C" \
		-e SQLITE_DATABASE=nextcloud \
		-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$PW" \
		-e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
		"$IMAGE" >/dev/null
	[[ -z "$prep" ]] || "$prep"
	docker start "$C" >/dev/null
	local i
	for i in $(seq 1 120); do
		if occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
			# Installation fertig heißt noch nicht, dass Apache schon antwortet
			if docker exec "$C" curl -sf -o /dev/null http://localhost/status.php; then
				return 0
			fi
		fi
		sleep 2
	done
	echo "Nextcloud in $C wurde nicht fertig" >&2
	exit 2
}

install_groupfolders() {
	GF_OK=0
	GF_WHY=''
	if [[ "${NO_GF:-0}" == 1 ]]; then
		GF_WHY='NO_GF=1 gesetzt'
		return 0
	fi
	local major gfmajor tag
	major=$(occ status --output=json | sed -n 's/.*"versionstring":"\([0-9]*\)\..*/\1/p')
	# groupfolders zählt seit NC 30 = v18 im Gleichschritt: v(NC-12)
	gfmajor=$((major - 12))
	tag="${GF_TAG:-}"
	if [[ -z "$tag" ]]; then
		tag=$(curl -fsSL "https://api.github.com/repos/nextcloud/groupfolders/releases?per_page=100" 2>/dev/null \
			| sed -n 's/.*"tag_name": *"\(v[0-9.]*\)".*/\1/p' | grep -E "^v$gfmajor\.[0-9]+\.[0-9]+$" \
			| sort -V | tail -n1 || true)
	fi
	if [[ -z "$tag" ]]; then
		GF_WHY="keine groupfolders-Release v$gfmajor.x für NC $major auf GitHub gefunden"
		return 0
	fi
	info "groupfolders $tag (Quell-Tarball von GitHub, ohne gebautes JS – reicht für occ)"
	if ! curl -fsSL -o "$WORK/gf.tgz" "https://github.com/nextcloud/groupfolders/archive/refs/tags/$tag.tar.gz"; then
		GF_WHY="Download groupfolders $tag von GitHub fehlgeschlagen"
		return 0
	fi
	docker cp "$WORK/gf.tgz" "$C:/tmp/gf.tgz"
	docker exec "$C" sh -c 'mkdir -p /var/www/html/custom_apps/groupfolders \
		&& tar xzf /tmp/gf.tgz --strip-components=1 -C /var/www/html/custom_apps/groupfolders \
		&& chown -R www-data:www-data /var/www/html/custom_apps/groupfolders'
	if occ app:enable groupfolders >"$WORK/gf-enable.txt" 2>&1; then
		GF_OK=1
	else
		GF_WHY="groupfolders $tag ließ sich nicht aktivieren: $(tail -n1 "$WORK/gf-enable.txt")"
	fi
}

# $1 (optional): Quelle statt APP_SRC; $2 (optional): nur kopieren, nicht aktivieren
install_app() {
	local src=${1:-$APP_SRC}
	info "kopiere App aus $src"
	# Wie scripts/deploy-test.sh: nur, was zur Laufzeit gebraucht wird
	tar -C "$src" --exclude=./vendor --exclude=./tests --exclude=./.git --exclude=./node_modules \
		--exclude=./src --exclude=./scripts --exclude=./dev --exclude="*.map" --exclude=./package-lock.json -cf - . \
		| docker exec -i "$C" sh -c 'rm -rf /var/www/html/custom_apps/folder_retention \
			&& mkdir /var/www/html/custom_apps/folder_retention \
			&& tar -xf - -C /var/www/html/custom_apps/folder_retention \
			&& chown -R www-data:www-data /var/www/html/custom_apps/folder_retention'
	[[ -z "${2:-}" ]] || return 0
	occ app:enable folder_retention >"$WORK/app-enable.txt" 2>&1 \
		|| { cat "$WORK/app-enable.txt" >&2; exit 2; }
	info "$(occ app:list --output=json | grep -o '"folder_retention":"[^"]*"')"
}

setup() {
	build_js_if_needed
	start_nc
	docker exec -i "$C" sh -c 'cat > /tmp/fret-sql.php' < "$HERE/sql.php"
	docker exec -i "$C" sh -c 'cat > /tmp/fret-proc.php' < "$HERE/proc.php"
	# Kein AJAX-Cron: sonst liefe RetentionJob unkontrolliert bei WebDAV-Anfragen mit
	occ background:cron >/dev/null
	occ config:system:set default_timezone --value="$NC_TZ" >/dev/null
	install_groupfolders
	install_app
	local u
	for u in alice bob carol dave erin frank grace heidi ivan judy mallory oscar peggy trent victor walter rupert; do
		docker exec -e OC_PASS="$PW" -u www-data "$C" php occ user:add --password-from-env "$u" >/dev/null
		touch_fs "$u"
	done
}

# ---------------------------------------------------------------- Szenarien

# S5 zuerst: braucht den Zustand direkt nach der Installation
s5() {
	put alice s5-fresh.txt
	age s5-fresh.txt 60
	set_sim false
	retention_run s5
	local rules
	rules=$(sql "SELECT COALESCE(target, 'allgemein') || '=' || period_unit || COALESCE(period_value, '')
		FROM oc_folder_retention_rules WHERE folder_id IS NULL ORDER BY id" | paste -sd' ')
	local deleted
	deleted=$(sql "SELECT COUNT(*) FROM oc_folder_retention_log WHERE mode <> 'simulation' AND status = 'deleted'")
	if [[ "$rules" =~ ^allgemein=never\ personal=never$ ]] && in_files alice s5-fresh.txt && [[ "$deleted" == 0 ]]; then
		result PASS S5 "Standardregeln nach Installation: $rules; 60 Tage alte Datei bleibt, 0 Löschungen"
	else
		local where=weg
		in_files alice s5-fresh.txt && where=da
		in_trash alice s5-fresh.txt && where=Papierkorb
		result FAIL S5 "Standardregeln nach Installation: $rules; Datei: $where; gelöscht laut Log: $deleted"
	fi
}

# Regeln für alle weiteren Szenarien: beide Standardregeln 1 Tag ab Erstellung/Ablage
prepare_rules() {
	occ folder_retention:run --dry-run >/dev/null 2>&1 || true # legt fehlende Standardregeln an
	set_default_rules day 1
	info "Standardregeln jetzt: $(sql "SELECT COALESCE(target, 'allgemein') || '=' || COALESCE(period_value, '') || period_unit FROM oc_folder_retention_rules WHERE folder_id IS NULL" | paste -sd' ')"
}

s7() {
	set_sim true
	put alice s7-sim.txt
	age s7-sim.txt 10
	retention_run s7
	local rows
	rows=$(logrows s7-sim.txt | paste -sd' ')
	if in_files alice s7-sim.txt && [[ "$rows" == *simulation:* ]] && [[ "$rows" != *real:* && "$rows" != *live:* ]]; then
		result PASS S7 "Simulation: Datei bleibt, Log: $rows"
	else
		result FAIL S7 "Simulation: Datei $(in_files alice s7-sim.txt && echo da || echo weg), Log: ${rows:-leer}"
	fi
	set_sim false
}

s1() {
	set_sim false
	local u
	for u in alice bob carol; do
		put "$u" "s1-$u.txt"
		age "s1-$u.txt" 10
	done
	retention_run s1
	local ok=1 note=''
	for u in alice bob carol; do
		local name="s1-$u.txt" st state
		st=$(last_status "$name")
		if in_files "$u" "$name"; then
			state=noch-da
			ok=0
		elif in_trash "$u" "$name"; then
			state=Papierkorb
			[[ "$st" == *:deleted* ]] || ok=0
		else
			state=ENDGÜLTIG-WEG
			ok=0
		fi
		note+="$u=$state[${st:-kein Log}] "
	done
	if [[ $ok == 1 ]]; then
		result PASS S1 "drei Konten in einem Lauf: $note"
	else
		result FAIL S1 "drei Konten in einem Lauf: $note"
	fi
}

s2() {
	set_sim false
	put dave s2-restore.txt
	age s2-restore.txt 10
	retention_run s2-a
	if ! in_trash dave s2-restore.txt; then
		result FAIL S2 "Vorbedingung: erster Lauf hat nicht in den Papierkorb verschoben ($(in_files dave s2-restore.txt && echo noch da || echo endgültig weg); Log: $(logrows s2-restore.txt | paste -sd' '))"
		return 0
	fi
	occ trashbin:restore dave > "$WORK/s2-restore.txt" 2>&1
	if ! in_files dave s2-restore.txt; then
		result FAIL S2 "Wiederherstellen per occ trashbin:restore hat nicht geklappt"
		return 0
	fi
	retention_run s2-b
	local rows
	rows=$(logrows s2-restore.txt | paste -sd' ')
	if in_files dave s2-restore.txt; then
		result PASS S2 "wiederhergestellte Datei bleibt im nächsten Lauf; Log: $rows"
	else
		result FAIL S2 "wiederhergestellte Datei im nächsten Lauf erneut entfernt ($(in_trash dave s2-restore.txt && echo Papierkorb || echo endgültig)); Log: $rows"
	fi
}

s3() {
	set_sim false
	put erin s3-ctime.txt 1420070400 # X-OC-CTime: 01.01.2015
	local ext
	ext=$(sql "SELECT 'creation_time=' || e.creation_time || ' upload_time=' || e.upload_time FROM oc_filecache_extended e WHERE e.fileid = ?" "$(fid s3-ctime.txt)")
	retention_run s3
	if in_files erin s3-ctime.txt; then
		result PASS S3 "heute hochgeladen mit Client-CTime 2015 ($ext): nicht fällig"
	else
		result FAIL S3 "heute hochgeladen mit Client-CTime 2015 ($ext): entfernt, Log: $(logrows s3-ctime.txt | paste -sd' ')"
	fi
}

s4() {
	set_sim false
	docker exec "$C" sh -c "printf 'alt\n' > /var/www/html/data/frank/files/s4-scan.txt \
		&& touch -d '2015-01-01 12:00:00' /var/www/html/data/frank/files/s4-scan.txt \
		&& chown www-data:www-data /var/www/html/data/frank/files/s4-scan.txt"
	occ files:scan --path=/frank/files >/dev/null
	local id ext
	id=$(fid s4-scan.txt)
	ext=$(sql "SELECT 'mtime=' || f.mtime || ' upload_time=' || COALESCE(e.upload_time, 'NULL') || ' creation_time=' || COALESCE(e.creation_time, 'NULL')
		FROM oc_filecache f LEFT JOIN oc_filecache_extended e ON e.fileid = f.fileid WHERE f.fileid = ?" "$id")
	retention_run s4
	if in_files frank s4-scan.txt; then
		result PASS S4 "per files:scan aufgenommen ($ext): im ersten Lauf nicht fällig"
	else
		result FAIL S4 "per files:scan aufgenommen ($ext): im ersten Lauf entfernt, Log: $(logrows s4-scan.txt | paste -sd' ')"
	fi
}

s9() {
	set_sim false
	put alice s9-share.txt
	docker exec "$C" curl -sf -o /dev/null -u "alice:$PW" -H 'OCS-APIRequest: true' -X POST \
		-d path=/s9-share.txt -d shareType=0 -d shareWith=bob \
		http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares
	touch_fs bob
	docker exec "$C" curl -sf -o /dev/null -u "bob:$PW" -X PROPFIND -H 'Depth: 0' \
		http://localhost/remote.php/dav/files/bob/s9-share.txt \
		|| { result FAIL S9 "Vorbedingung: Freigabe bei bob nicht sichtbar"; return 0; }
	age s9-share.txt 10
	retention_run s9
	local rows
	rows=$(logrows s9-share.txt | paste -sd' ')
	if ! in_files alice s9-share.txt && in_trash alice s9-share.txt; then
		result PASS S9 "geteilte Datei liegt in alices Papierkorb; Log: $rows"
	elif in_files alice s9-share.txt; then
		result FAIL S9 "geteilte Datei noch bei alice (nur Freigabe entfernt?); Log: $rows"
	else
		result FAIL S9 "geteilte Datei endgültig weg; Log: $rows"
	fi
}

# Team-Ordner anlegen, Gruppe mit Mitgliedern berechtigen, Mounts einrichten; gibt die ID aus
gf_create() {
	local name=$1 group=$2 gid u
	shift 2
	gid=$(occ groupfolders:create "$name" | tail -n1 | tr -dc "0-9")
	occ group:add "$group" >/dev/null
	for u in "$@"; do
		occ group:adduser "$group" "$u" >/dev/null
	done
	occ groupfolders:group "$gid" "$group" write share delete >/dev/null
	for u in "$@"; do
		touch_fs "$u" # Team-Ordner-Mount in oc_mounts
	done
	echo "$gid"
}

# Liegt <name> im groupfolders-Papierkorb (DB-Eintrag UND Datei auf der Platte)?
gf_trashed() {
	[[ "$(sql "SELECT COUNT(*) FROM oc_group_folders_trash WHERE name = ?" "$1")" -ge 1 ]] \
		&& docker exec "$C" sh -c "find /var/www/html/data/__groupfolders -path '*trash*' -name '$1.d*' | grep -q ."
}

s8() {
	if [[ "$GF_OK" != 1 ]]; then
		result SKIP S8 "groupfolders nicht verfügbar: $GF_WHY"
		return 0
	fi
	set_sim false
	local gid
	gid=$(gf_create Teamordner team alice)
	put alice Teamordner/s8-team.txt
	age s8-team.txt 10
	# Im selben Lauf vorher eine persönliche Datei von bob (home:* sortiert vor team:*):
	# Danach muss der Team-Ordner trotzdem im passenden Kontext gelöscht werden.
	put bob s8-bob.txt
	age s8-bob.txt 10
	retention_run s8
	local rows bob=weg
	rows=$(logrows s8-team.txt | paste -sd" ")
	in_trash bob s8-bob.txt && bob=Papierkorb
	in_files bob s8-bob.txt && bob=da
	if docker exec "$C" test -f "/var/www/html/data/__groupfolders/$gid/files/s8-team.txt"; then
		result FAIL S8 "Datei im Team-Ordner noch da; Log: $rows; bobs Datei: $bob"
	elif gf_trashed s8-team.txt; then
		result PASS S8 "Datei im Team-Ordner-Papierkorb; Log: $rows; bobs Datei im selben Lauf: $bob"
	else
		result FAIL S8 "Datei im Team-Ordner ENDGÜLTIG weg (nicht im groupfolders-Papierkorb); Log: $rows; bobs Datei im selben Lauf: $bob"
	fi
}

# Team-Ordner-Datei, die ein Mitglied zusätzlich direkt an ein anderes Mitglied teilt:
# Die Sicht des Empfängers darf nicht nur die Freigabe entfernen (B7).
s11() {
	if [[ "$GF_OK" != 1 ]]; then
		result SKIP S11 "groupfolders nicht verfügbar: $GF_WHY"
		return 0
	fi
	set_sim false
	local gid rows
	gid=$(gf_create Team2 team2 bob carol)
	put carol Team2/s11-teamshare.txt
	docker exec "$C" curl -sf -o /dev/null -u "carol:$PW" -H "OCS-APIRequest: true" -X POST \
		-d path=/Team2/s11-teamshare.txt -d shareType=0 -d shareWith=bob \
		http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares
	touch_fs bob
	age s11-teamshare.txt 10
	retention_run s11
	rows=$(logrows s11-teamshare.txt | paste -sd" ")
	if docker exec "$C" test -f "/var/www/html/data/__groupfolders/$gid/files/s11-teamshare.txt"; then
		result FAIL S11 "Team-Ordner-Datei noch da (nur Freigabe entfernt?); Log: $rows"
	elif gf_trashed s11-teamshare.txt; then
		result PASS S11 "geteilte Team-Ordner-Datei im groupfolders-Papierkorb; Log: $rows"
	else
		result FAIL S11 "geteilte Team-Ordner-Datei ENDGÜLTIG weg; Log: $rows"
	fi
}

# Datei direkt ins Datenverzeichnis legen (<MB> Nullbytes) und per files:scan aufnehmen
mkfile() {
	local user=$1 name=$2 mb=$3
	docker exec "$C" sh -c "head -c $((mb * 1024 * 1024)) /dev/zero > /var/www/html/data/$user/files/$name \
		&& chown www-data:www-data /var/www/html/data/$user/files/$name"
	occ files:scan --path="/$user/files" >/dev/null
}

# Ausstehende Expire-Befehle von files_trashbin ausführen (CommandJob aus Trashbin::scheduleExpire)
run_expire_jobs() {
	local id
	for id in $(sql "SELECT id FROM oc_jobs WHERE class = ?" 'OC\Command\CommandJob'); do
		occ background-job:execute "$id" --force-execute >/dev/null 2>&1 || true
	done
}

# Quota fast voll: files_trashbin räumt nach dem Verschieben (Expire) den Papierkorb, bis
# 50 % des freien Quota-Platzes reichen – ins Papierkorb verschobene Dateien wären dann endgültig weg.
# ivan: Quota 20 MB, 12 MB nicht fällig + 1 MB fällig + 4 MB fällig. Die kleine passt
# (frei danach 4 MB → 2 MB Papierkorb), die große nicht (frei 8 MB → 4 MB < 1 + 4 MB).
s12() {
	set_sim false
	# Skeleton-Dateien (~63 MB) weg, sonst ist die Quota schon voll
	docker exec "$C" sh -c 'rm -rf /var/www/html/data/ivan/files/*'
	occ files:scan --path=/ivan/files >/dev/null
	occ user:setting ivan files quota "20 MB" >/dev/null
	mkfile ivan s12-keep.bin 12
	mkfile ivan s12-small.bin 1
	mkfile ivan s12-big.bin 4
	age s12-small.bin 10
	age s12-big.bin 10
	retention_run s12
	run_expire_jobs
	local f state note='' ok=1 rows
	for f in s12-small.bin s12-big.bin s12-keep.bin; do
		if in_files ivan "$f"; then
			state=da
		elif in_trash ivan "$f"; then
			state=Papierkorb
		else
			state=ENDGÜLTIG-WEG
			ok=0
		fi
		note+="$f=$state "
	done
	rows=$(logrows s12-big.bin | paste -sd' ')
	in_trash ivan s12-small.bin || { ok=0; note+='(kleine Datei nicht im Papierkorb) '; }
	in_files ivan s12-big.bin || ok=0
	[[ "$rows" == *[Qq]uota* ]] || ok=0 # Meldung in der Sprache der Instanz (tag_language; frisch: en)
	if [[ $ok == 1 ]]; then
		result PASS S12 "Quota fast voll, nach Expire: $note; Log groß: $rows"
	else
		result FAIL S12 "Quota fast voll, nach Expire: $note; Log groß: ${rows:-leer}; Log klein: $(logrows s12-small.bin | paste -sd' ')"
	fi
}

# occ trashbin:size speichert eine Zahl, files_trashbin liest Text → jedes Verschieben in den
# Papierkorb wirft. Danach darf keine Datei endgültig fehlen (früher: je Konto eine verloren).
s13() {
	set_sim false
	occ trashbin:size 1GB >/dev/null
	put judy s13-a.txt
	put judy s13-b.txt
	put mallory s13-c.txt
	age s13-a.txt 10
	age s13-b.txt 10
	age s13-c.txt 10
	retention_run s13
	occ config:app:delete files_trashbin trashbin_size >/dev/null 2>&1 || true
	local u f state note='' ok=1
	for u in judy:s13-a.txt judy:s13-b.txt mallory:s13-c.txt; do
		f=${u#*:}
		u=${u%%:*}
		if in_files "$u" "$f"; then
			state=da
		elif in_trash "$u" "$f"; then
			state=Papierkorb
		else
			state=ENDGÜLTIG-WEG
			ok=0
		fi
		note+="$u/$f=$state[$(last_status "$f")] "
	done
	if [[ $ok == 1 ]]; then
		result PASS S13 "trashbin_size mit falschem Typ: $note"
	else
		result FAIL S13 "trashbin_size mit falschem Typ: $note"
	fi
}

# Versionen wandern beim Löschen mit nach files_trashbin/versions und zählen für Expire mit.
# oscar: Quota 20 MB, 13 MB nicht fällig + 1 MB fällig mit 3 × 1 MB Versionen. Nur die Datei
# gerechnet: frei danach 7 MB → 3,5 MB > 1 MB, also gelöscht – danach misst Expire 4 MB im
# Papierkorb und räumt die Datei endgültig ab. Mit Versionen: 3,5 MB < 4 MB → bleibt liegen.
s15() {
	set_sim false
	docker exec "$C" sh -c 'rm -rf /var/www/html/data/oscar/files/*'
	occ files:scan --path=/oscar/files >/dev/null
	occ user:setting oscar files quota "20 MB" >/dev/null
	mkfile oscar s15-keep.bin 13
	local i vers rows state
	# Jedes Überschreiben legt eine Version an; verschiedene mtimes, damit keine Version die andere ersetzt
	for i in 1 2 3 4; do
		docker exec "$C" sh -c "head -c 1048576 /dev/urandom | curl -sf -o /dev/null -u 'oscar:$PW' -T - \
			-H 'X-OC-MTime: $((1700000000 + i * 100))' http://localhost/remote.php/dav/files/oscar/s15-doc.bin"
	done
	vers=$(sql "SELECT COUNT(*) FROM oc_filecache WHERE path LIKE 'files_versions/s15-doc.bin.v%'")
	if [[ "${vers:-0}" -lt 3 ]]; then
		result FAIL S15 "Vorbedingung: nur ${vers:-0} Versionen angelegt"
		return 0
	fi
	age s15-doc.bin 10
	retention_run s15
	run_expire_jobs
	rows=$(logrows s15-doc.bin | paste -sd' ')
	if in_files oscar s15-doc.bin; then
		state=da
	elif in_trash oscar s15-doc.bin; then
		state=Papierkorb
	else
		state=ENDGÜLTIG-WEG
	fi
	if [[ $state != ENDGÜLTIG-WEG ]]; then
		result PASS S15 "Datei mit $vers Versionen bei knapper Quota, nach Expire: $state; Log: ${rows:-leer}"
	else
		result FAIL S15 "Datei mit $vers Versionen bei knapper Quota: $state nach Expire; Log: ${rows:-leer}"
	fi
}

# Kopie erbt upload_time/creation_time des Originals (Cache::copyFromCache). Datei-IDs über der
# Grenze (seen_max_fileid, gesetzt bei Installation/Update) zählen ab dem ersten Sehen.
s14() {
	set_sim false
	put erin s14-orig.txt
	age s14-orig.txt 700
	docker exec "$C" curl -sf -o /dev/null -u "erin:$PW" -X COPY \
		-H "Destination: http://localhost/remote.php/dav/files/erin/s14-copy.txt" \
		http://localhost/remote.php/dav/files/erin/s14-orig.txt \
		|| { result FAIL S14 "Vorbedingung: WebDAV-COPY fehlgeschlagen"; return 0; }
	local ext since
	ext=$(sql "SELECT 'upload_time=' || COALESCE(e.upload_time, 'NULL') FROM oc_filecache_extended e WHERE e.fileid = ?" "$(fid s14-copy.txt)")
	since=$(occ config:app:get folder_retention seen_max_fileid 2>/dev/null || echo fehlt)
	retention_run s14
	if in_files erin s14-copy.txt && in_trash erin s14-orig.txt; then
		result PASS S14 "Kopie einer 700 Tage alten Datei ($ext, seen_max_fileid=$since) bleibt, Original im Papierkorb"
	else
		result FAIL S14 "Kopie: $(in_files erin s14-copy.txt && echo da || echo weg) [$(last_status s14-copy.txt)], Original: $(in_trash erin s14-orig.txt && echo Papierkorb || echo nicht im Papierkorb) ($ext, seen_max_fileid=$since)"
	fi
}

s10() {
	set_sim false
	local n=600
	info "S10: lege $n fällige Dateien für heidi an"
	docker exec "$C" sh -c "mkdir -p /var/www/html/data/heidi/files/s10 && cd /var/www/html/data/heidi/files/s10 \
		&& i=0; while [ \$i -lt $n ]; do echo \$i > s10-\$i.txt; i=\$((i+1)); done; chown -R www-data:www-data /var/www/html/data/heidi/files/s10"
	occ files:scan --path=/heidi/files/s10 >/dev/null
	local storage old
	storage=$(sql "SELECT numeric_id FROM oc_storages WHERE id = 'home::heidi'")
	old=$(ago 10)
	sql "UPDATE oc_filecache SET mtime = ? WHERE storage = ? AND path LIKE 'files/s10/s10-%'" "$old" "$storage"
	sql "INSERT OR REPLACE INTO oc_filecache_extended (fileid, creation_time, upload_time)
		SELECT fileid, ?, ? FROM oc_filecache WHERE storage = ? AND path LIKE 'files/s10/s10-%'" "$old" "$old" "$storage"
	if [[ -n "$(sql "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'oc_folder_retention_seen'")" ]]; then
		sql "INSERT OR REPLACE INTO oc_folder_retention_seen (file_id, first_seen)
			SELECT fileid, ? FROM oc_filecache WHERE storage = ? AND path LIKE 'files/s10/s10-%'" "$old" "$storage"
	fi

	local job
	job=$(sql "SELECT id FROM oc_jobs WHERE class = ?" 'OCA\FolderRetention\BackgroundJob\RetentionJob')
	[[ -n "$job" ]] || { result FAIL S10 "RetentionJob nicht in oc_jobs registriert"; return 0; }
	# Neuen Zyklus erzwingen
	occ config:app:delete folder_retention last_cycle_completed >/dev/null 2>&1 || true
	occ config:app:delete folder_retention job_cursor >/dev/null 2>&1 || true

	info "S10: Job $job im Hintergrund, dann occ-Lauf parallel"
	docker exec -d -u www-data "$C" sh -c "php occ background-job:execute $job --force-execute > /tmp/s10-job.txt 2>&1; echo \$? > /tmp/s10-job.rc"
	local i started=0
	for i in $(seq 1 150); do
		if [[ "$(sql "SELECT COUNT(*) FROM oc_folder_retention_log WHERE path LIKE '%/s10/%'")" != 0 ]]; then
			started=1
			break
		fi
		docker exec "$C" test -f /tmp/s10-job.rc && break
		sleep 0.2
	done
	local occ_rc=0
	occ folder_retention:run > "$WORK/s10-occ.txt" 2>&1 || occ_rc=$?
	for i in $(seq 1 300); do
		docker exec "$C" test -f /tmp/s10-job.rc && break
		sleep 1
	done
	docker exec "$C" cat /tmp/s10-job.txt > "$WORK/s10-job.txt" 2>/dev/null || true
	local job_done=0
	docker exec "$C" test -f /tmp/s10-job.rc && job_done=1

	local dup rows trashed remain lost lockmsg=0
	dup=$(sql "SELECT COUNT(*) FROM (SELECT file_id FROM oc_folder_retention_log
		WHERE mode <> 'simulation' AND path LIKE '%/s10/%' GROUP BY file_id HAVING COUNT(*) > 1)")
	rows=$(sql "SELECT status || '=' || COUNT(*) FROM oc_folder_retention_log WHERE path LIKE '%/s10/%' GROUP BY status" | paste -sd' ')
	trashed=$(docker exec "$C" sh -c "ls /var/www/html/data/heidi/files_trashbin/files/ 2>/dev/null | grep -c '^s10-' || true")
	remain=$(docker exec "$C" sh -c "ls /var/www/html/data/heidi/files/s10/ | grep -c '^s10-' || true")
	lost=$((n - trashed - remain))
	# Sperrmeldung des occ-Befehls – nur Zeilen außerhalb der Ergebnistabelle, sonst zählte
	# „skipped_locked (Datei ist gesperrt …)“ einer einzelnen Datei mit
	grep -v '^[|+]' "$WORK/s10-occ.txt" \
		| grep -Eiq 'gesperrt|läuft bereits|laeuft bereits|bereits .*(lauf|läuft)|anderer .*lauf|sperre|locked|already running' \
		&& lockmsg=1
	local note="Job gestartet=$started fertig=$job_done, occ-Exit=$occ_rc, Log: ${rows:-leer}, Papierkorb=$trashed übrig=$remain verloren=$lost, Doppel-Log-Dateien=$dup, Sperrmeldung=$lockmsg"
	if [[ $started == 0 ]]; then
		result FAIL S10 "Job hat nicht vor dem occ-Lauf angefangen – keine Überlappung prüfbar ($note)"
	elif [[ $job_done == 0 ]]; then
		result FAIL S10 "Job nach 300 s nicht fertig ($note)"
	elif [[ "$dup" == 0 && $lost == 0 && $lockmsg == 1 ]]; then
		result PASS S10 "$note"
	else
		result FAIL S10 "$note"
	fi
}

# Gleicher Dateiname in mehreren Ordnern, ein Lauf: files_trashbin nennt den Eintrag
# „<name>.d<time()>“ und überschreibt ein vorhandenes Ziel derselben Sekunde endgültig. Alle drei
# müssen im Papierkorb liegen, mit ihrem eigenen Inhalt. Mit groupfolders zusätzlich zwei
# gleichnamige Dateien in einem Team-Ordner (dessen Papierkorb benennt genauso).
s16() {
	set_sim false
	local d id ids=() gids=() gid=''
	for d in dA dB dC; do
		docker exec "$C" curl -sf -o /dev/null -u "peggy:$PW" -X MKCOL "http://localhost/remote.php/dav/files/peggy/$d"
		put peggy "$d/Bericht.txt"
		id=$(fid_path peggy "$d/Bericht.txt")
		[[ -n "$id" ]] || { result FAIL S16 "Vorbedingung: $d/Bericht.txt nicht im Filecache"; return 0; }
		age_id "$id" 10
		ids+=("$id")
	done
	if [[ "$GF_OK" == 1 ]]; then
		gid=$(gf_create Team16 team16 peggy)
		for d in x y; do
			docker exec "$C" curl -sf -o /dev/null -u "peggy:$PW" -X MKCOL "http://localhost/remote.php/dav/files/peggy/Team16/$d"
			put peggy "Team16/$d/s16-scan.pdf"
			id=$(sql "SELECT fileid FROM oc_filecache WHERE name = 's16-scan.pdf' AND path LIKE ? AND path NOT LIKE '%trash%'" "%/$d/s16-scan.pdf")
			[[ -n "$id" ]] || { result FAIL S16 "Vorbedingung: Team16/$d/s16-scan.pdf nicht im Filecache"; return 0; }
			age_id "$id" 10
			gids+=("$id")
		done
	fi
	retention_run s16
	local trashed contents remain rows want ok=1 note gf=''
	trashed=$(docker exec "$C" sh -c "ls /var/www/html/data/peggy/files_trashbin/files/ 2>/dev/null | grep -c '^Bericht\.txt\.d' || true")
	contents=$(docker exec "$C" sh -c 'cat /var/www/html/data/peggy/files_trashbin/files/Bericht.txt.d* 2>/dev/null' | sort | paste -sd',')
	want='Inhalt dA/Bericht.txt,Inhalt dB/Bericht.txt,Inhalt dC/Bericht.txt'
	remain=$(docker exec "$C" sh -c 'ls /var/www/html/data/peggy/files/dA /var/www/html/data/peggy/files/dB /var/www/html/data/peggy/files/dC 2>/dev/null | grep -c Bericht || true')
	rows=$(sql "SELECT mode || ':' || status || '=' || COUNT(*) FROM oc_folder_retention_log WHERE file_id IN ($(IFS=,; echo "${ids[*]}")) GROUP BY mode, status" | paste -sd' ')
	[[ "$trashed" == 3 && "$contents" == "$want" && "$remain" == 0 && "$rows" == 'real:deleted=3' ]] || ok=0
	if [[ -n "$gid" ]]; then
		local gtrash gdisk grows
		gtrash=$(sql "SELECT COUNT(*) FROM oc_group_folders_trash WHERE name = 's16-scan.pdf'")
		gdisk=$(docker exec "$C" sh -c "find /var/www/html/data/__groupfolders -path '*trash*' -name 's16-scan.pdf.d*' | wc -l")
		grows=$(sql "SELECT mode || ':' || status || '=' || COUNT(*) FROM oc_folder_retention_log WHERE file_id IN ($(IFS=,; echo "${gids[*]}")) GROUP BY mode, status" | paste -sd' ')
		[[ "$gtrash" == 2 && "$gdisk" == 2 && "$grows" == 'real:deleted=2' ]] || ok=0
		gf="; Team-Ordner: Papierkorb-DB=$gtrash Platte=$gdisk Log=${grows:-leer}"
	else
		gf="; Team-Ordner nicht geprüft ($GF_WHY)"
	fi
	note="Papierkorb=$trashed Bericht.txt.d* [${contents:-leer}], übrig=$remain, Log=${rows:-leer}$gf"
	if [[ $ok == 1 ]]; then
		result PASS S16 "drei gleichnamige Dateien in einem Lauf: $note"
	else
		result FAIL S16 "drei gleichnamige Dateien in einem Lauf: $note"
	fi
}

# Grenze Bestand/neu im Übergangsfenster nach dem Update: Kopie entsteht nach dem Update, aber
# bevor ein vollständiger Zyklus unter 0.8 gelaufen ist (fb4395c: seen_since fehlte → Kopie
# sofort fällig; im zweiten Lauf als Bestand). Die Grenze ist die Datei-ID beim Update.
s18() {
	set_sim false
	local mark max
	mark=$(occ config:app:get folder_retention seen_max_fileid 2>/dev/null || true)
	put victor s18-orig.txt
	age s18-orig.txt 700
	# „Update jetzt“ nachstellen: Grenze = höchste Datei-ID, kein Zyklus seither abgeschlossen
	max=$(sql "SELECT MAX(fileid) FROM oc_filecache")
	occ config:app:set folder_retention seen_max_fileid --value="$max" --type=integer >/dev/null
	occ config:app:delete folder_retention seen_since >/dev/null 2>&1 || true
	occ config:app:delete folder_retention last_cycle_completed >/dev/null 2>&1 || true
	occ config:app:delete folder_retention job_cursor >/dev/null 2>&1 || true
	docker exec "$C" curl -sf -o /dev/null -u "victor:$PW" -X COPY \
		-H "Destination: http://localhost/remote.php/dav/files/victor/s18-copy.txt" \
		http://localhost/remote.php/dav/files/victor/s18-orig.txt \
		|| { result FAIL S18 "Vorbedingung: WebDAV-COPY fehlgeschlagen"; return 0; }
	local ext a b
	ext=$(sql "SELECT 'fileid=' || f.fileid || ' upload_time=' || COALESCE(e.upload_time, 'NULL') FROM oc_filecache f LEFT JOIN oc_filecache_extended e ON e.fileid = f.fileid WHERE f.fileid = ?" "$(fid s18-copy.txt)")
	retention_run s18-a
	a=$(in_files victor s18-copy.txt && echo da || echo weg)
	retention_run s18-b
	b=$(in_files victor s18-copy.txt && echo da || echo weg)
	local note="Grenze nach Installation=${mark:-fehlt}, Update-Grenze=$max, Kopie ($ext): Lauf 1 $a, Lauf 2 $b; Original: $(in_trash victor s18-orig.txt && echo Papierkorb || echo nicht im Papierkorb); Log Kopie: $(logrows s18-copy.txt | paste -sd' ')"
	if [[ -n "$mark" && $a == da && $b == da ]] && in_trash victor s18-orig.txt; then
		result PASS S18 "$note"
	else
		result FAIL S18 "$note"
	fi
}

# AJAX-Cron (Nextcloud-Standard): anonymer Aufruf von cron.php mit „X-NC-Skip-Trashbin: true“
# darf nichts endgültig löschen und keinen Bereich sperren; die Verwaltung meldet den Modus.
s17() {
	set_sim false
	put trent s17-web.txt
	age s17-web.txt 10
	local job before after ran api i rows
	job=$(sql "SELECT id FROM oc_jobs WHERE class = ?" 'OCA\FolderRetention\BackgroundJob\RetentionJob')
	[[ -n "$job" ]] || { result FAIL S17 "RetentionJob nicht in oc_jobs registriert"; return 0; }
	occ config:app:delete folder_retention last_cycle_completed >/dev/null 2>&1 || true
	occ config:app:delete folder_retention job_cursor >/dev/null 2>&1 || true
	before=$(blocks)
	occ background:ajax >/dev/null
	# Nur der RetentionJob ist dran: alle anderen gelten als eben geprüft
	sql "UPDATE oc_jobs SET last_checked = ?, reserved_at = 0 WHERE id <> ?" "$(now)" "$job"
	sql "UPDATE oc_jobs SET last_run = 0, last_checked = 0, reserved_at = 0 WHERE id = ?" "$job"
	for i in 1 2 3; do
		docker exec "$C" curl -s -o /dev/null -H 'X-NC-Skip-Trashbin: true' http://localhost/cron.php || true
	done
	ran=$(sql "SELECT last_run FROM oc_jobs WHERE id = ?" "$job")
	api=$(docker exec "$C" curl -s -u "admin:$PW" -H 'OCS-APIRequest: true' http://localhost/index.php/apps/folder_retention/api/settings || true)
	occ background:cron >/dev/null
	after=$(blocks)
	rows=$(logrows s17-web.txt | paste -sd' ')
	local mode
	mode=$(grep -o '"cronMode":"[a-z]*"' <<<"$api" || echo "cronMode fehlt")
	local note="Job lief=$([[ "${ran:-0}" -gt 0 ]] && echo ja || echo nein), Datei: $(in_files trent s17-web.txt && echo da || (in_trash trent s17-web.txt && echo Papierkorb || echo ENDGÜLTIG-WEG)), Log: ${rows:-leer}, Sperren unverändert: $([[ "$before" == "$after" ]] && echo ja || echo "nein ($after)"), API: $mode"
	if [[ "${ran:-0}" -gt 0 ]] && in_files trent s17-web.txt && [[ -z "$rows" && "$before" == "$after" && "$mode" == '"cronMode":"ajax"' ]]; then
		result PASS S17 "$note"
	else
		result FAIL S17 "$note"
	fi
}

# „Sperre aufheben“ hebt nur die angezeigte Sperre auf – eine inzwischen dazugekommene (oder
# erneuerte) bleibt. Über die Verwaltungs-API und per occ.
s19() {
	local state1 state2 state3 code1 code2 out3
	sql "DELETE FROM oc_folder_retention_block WHERE block_key IN ('it:a', 'it:b')"
	sql "INSERT INTO oc_folder_retention_block (block_key, label, reason, blocked_at) VALUES ('it:a', 'A', 'x', 100), ('it:b', 'B', 'y', 200)"
	api_put() {
		docker exec "$C" curl -s -o /dev/null -w '%{http_code}' -u "admin:$PW" -H 'OCS-APIRequest: true' \
			-H 'Content-Type: application/json' -X PUT -d "$1" http://localhost/index.php/apps/folder_retention/api/settings
	}
	keys() { sql "SELECT '\"' || block_key || '\"' FROM oc_folder_retention_block WHERE block_key IN ('it:a', 'it:b') ORDER BY block_key" | paste -sd' ' || true; }
	# Admin hat nur A gesehen; B kam später dazu
	code1=$(api_put '{"unblock":[{"key":"it:a","at":100}]}')
	state1=$(keys)
	# veraltete Anzeige von B (anderer Zeitpunkt) – B wurde inzwischen erneut gesperrt
	code2=$(api_put '{"unblock":[{"key":"it:b","at":150}]}')
	state2=$(keys)
	out3=$(occ folder_retention:run --unblock=it:b 2>&1 | head -n 3 | paste -sd' ' || true)
	state3=$(keys)
	sql "DELETE FROM oc_folder_retention_block WHERE block_key IN ('it:a', 'it:b')"
	local note="API A aufheben: HTTP $code1 → übrig [${state1:-keine}]; API B mit altem Zeitpunkt: HTTP $code2 → übrig [${state2:-keine}]; occ --unblock=it:b → übrig [${state3:-keine}] ($out3)"
	if [[ "$code1" == 200 && "$state1" == '"it:b"' && "$code2" == 200 && "$state2" == '"it:b"' && -z "$state3" ]]; then
		result PASS S19 "$note"
	else
		result FAIL S19 "$note"
	fi
}

# Sperre aus einem anderen Prozess gilt sofort: Prozess P (wie cron.php, der vor dem Job andere
# Jobs abarbeitet) hat die Sperrliste schon gelesen, dann setzt Q (occ-Lauf mit endgültiger
# Löschung) eine Sperre. P muss sie sehen und darf mit eigener Sperre keine fremde verlieren.
# Dazu: Sperren aus der App-Config (Vorstufe 0.8.0) wandern in die Tabelle.
s20() {
	sql "DELETE FROM oc_folder_retention_block WHERE block_key LIKE 'it:s20%'"
	docker exec "$C" rm -f /tmp/s20-ready /tmp/s20-go
	docker exec -u www-data "$C" php /tmp/fret-proc.php wait it:s20c it:s20d > "$WORK/s20-p.txt" 2>&1 &
	local pid=$! i
	for i in $(seq 1 100); do
		docker exec "$C" test -f /tmp/s20-ready && break
		sleep 0.2
	done
	docker exec "$C" test -f /tmp/s20-ready || { wait "$pid" || true; result FAIL S20 "Prozess P nicht bereit: $(tail -n3 "$WORK/s20-p.txt" | paste -sd' ')"; return 0; }
	docker exec -u www-data "$C" php /tmp/fret-proc.php block it:s20c 200 > "$WORK/s20-q.txt" 2>&1
	docker exec "$C" touch /tmp/s20-go
	wait "$pid" || true
	local p rows legacy mig cfg
	p=$(grep -o 'before=[01] after=[01]' "$WORK/s20-p.txt" || echo "P: $(tail -n2 "$WORK/s20-p.txt" | paste -sd' ')")
	rows=$(blocks 'it:s20%')
	# Altbestand in der App-Config: beim nächsten Zugriff übernommen und dort entfernt
	occ config:app:set folder_retention blocked_roots --value='{"it:s20legacy":{"label":"Alt","reason":"vor 0.8","at":50}}' >/dev/null
	occ folder_retention:run --unblock=it:s20-gibt-es-nicht > "$WORK/s20-legacy.txt" 2>&1 || true
	legacy=$(blocks 'it:s20legacy')
	cfg=$(occ config:app:get folder_retention blocked_roots 2>/dev/null || echo entfernt)
	mig=$(grep -c 'it:s20legacy' "$WORK/s20-legacy.txt" || true)
	sql "DELETE FROM oc_folder_retention_block WHERE block_key LIKE 'it:s20%'"
	docker exec "$C" rm -f /tmp/s20-ready /tmp/s20-go
	local note="P vor/nach fremder Sperre: $p; Sperren danach: [${rows:-keine}]; Altbestand: Tabelle [${legacy:-fehlt}], App-Config $cfg, occ zeigt ihn $mig×"
	if [[ "$p" == 'before=0 after=1' && "$rows" == 'it:s20c@200 it:s20d@300' && "$legacy" == 'it:s20legacy@50' && "$cfg" == entfernt && "$mig" -ge 1 ]]; then
		result PASS S20 "$note"
	else
		result FAIL S20 "$note"
	fi
}

# Lange Namen: files_trashbin kürzt „<name>.d<Zeit>“ über 250 Byte in der Mitte. Zwei Dateien, die
# sich nur im herausgeschnittenen Stück unterscheiden, bekämen in derselben Sekunde denselben
# Papierkorb-Namen – die erste wäre endgültig weg. Beide müssen mit eigenem Inhalt im Papierkorb liegen.
s21() {
	set_sim false
	local a b ida idb
	a="$(printf 'a%.0s' $(seq 1 122))1$(printf 'b%.0s' $(seq 1 118)).txt"
	b="$(printf 'a%.0s' $(seq 1 122))2$(printf 'b%.0s' $(seq 1 118)).txt"
	put walter "$a"
	put walter "$b"
	ida=$(fid_path walter "$a")
	idb=$(fid_path walter "$b")
	[[ -n "$ida" && -n "$idb" ]] || { result FAIL S21 "Vorbedingung: lange Dateien nicht im Filecache"; return 0; }
	age_id "$ida" 10
	age_id "$idb" 10
	retention_run s21
	local names contents fc rows want ok=1
	names=$(docker exec "$C" sh -c "ls /var/www/html/data/walter/files_trashbin/files/ 2>/dev/null | grep -c '^aaaa' || true")
	contents=$(docker exec "$C" sh -c 'cat /var/www/html/data/walter/files_trashbin/files/aaaa* 2>/dev/null' | sed 's/^Inhalt a*\([12]\)b*\.txt$/Inhalt \1/' | sort | paste -sd',')
	fc=$(sql "SELECT COUNT(*) FROM oc_filecache WHERE fileid IN (?, ?) AND path LIKE 'files_trashbin/files/%'" "$ida" "$idb")
	rows=$(sql "SELECT mode || ':' || status || '=' || COUNT(*) FROM oc_folder_retention_log WHERE file_id IN (?, ?) GROUP BY mode, status" "$ida" "$idb" | paste -sd' ')
	want='Inhalt 1,Inhalt 2'
	[[ "$names" == 2 && "$contents" == "$want" && "$fc" == 2 && "$rows" == 'real:deleted=2' ]] || ok=0
	local note="Papierkorb-Einträge=$names [${contents:-leer}], im Filecache als Papierkorb=$fc, Log=${rows:-leer}"
	if [[ $ok == 1 ]]; then
		result PASS S21 "zwei lange, gleich gekürzte Namen in einem Lauf: $note"
	else
		result FAIL S21 "zwei lange, gleich gekürzte Namen in einem Lauf: $note"
	fi
}

# Zwei Läufe direkt hintereinander (z. B. „occ …run --rule=3; occ …run --rule=4“, oder der Job
# übernimmt die eben freigegebene Sperre): Lauf 2 verschiebt eine gleichnamige Datei womöglich noch
# in derselben Sekunde wie Lauf 1 seine letzte. files_trashbin überschriebe dann den Eintrag aus
# Lauf 1 endgültig – der Speicher von Lauf 2 kennt ihn nicht, nur der Filecache.
s22() {
	set_sim false
	local d id ids=() out
	for d in s22A s22B; do
		docker exec "$C" curl -sf -o /dev/null -u "rupert:$PW" -X MKCOL "http://localhost/remote.php/dav/files/rupert/$d"
		put rupert "$d/Protokoll.pdf"
		id=$(fid_path rupert "$d/Protokoll.pdf")
		[[ -n "$id" ]] || { result FAIL S22 "Vorbedingung: $d/Protokoll.pdf nicht im Filecache"; return 0; }
		ids+=("$id")
	done
	out=$(docker exec -u www-data "$C" php /tmp/fret-proc.php samesecond "${ids[0]}" "${ids[1]}" 2>&1 | tail -n1)
	local trashed contents remain want same ok=1
	trashed=$(docker exec "$C" sh -c "ls /var/www/html/data/rupert/files_trashbin/files/ 2>/dev/null | grep -c '^Protokoll\.pdf\.d' || true")
	contents=$(docker exec "$C" sh -c 'cat /var/www/html/data/rupert/files_trashbin/files/Protokoll.pdf.d* 2>/dev/null' | sort | paste -sd',')
	want='Inhalt s22A/Protokoll.pdf,Inhalt s22B/Protokoll.pdf'
	remain=$(docker exec "$C" sh -c 'ls /var/www/html/data/rupert/files/s22A /var/www/html/data/rupert/files/s22B 2>/dev/null | grep -c Protokoll || true')
	# Hat der Test die Lage wirklich hergestellt (Lauf 2 beginnt in der Sekunde, in der Lauf 1 endete)?
	same=nein
	[[ "$out" =~ aEnd=([0-9]+)\ bStart=([0-9]+) && "${BASH_REMATCH[1]}" == "${BASH_REMATCH[2]}" ]] && same=ja
	[[ "$out" == 'a=deleted b=deleted '* && "$trashed" == 2 && "$contents" == "$want" && "$remain" == 0 ]] || ok=0
	local note="$out; Lauf 2 in derselben Sekunde begonnen: $same; Papierkorb=$trashed Protokoll.pdf.d* [${contents:-leer}], übrig=$remain"
	if [[ $ok == 1 ]]; then
		result PASS S22 "gleichnamige Datei über die Laufgrenze: $note"
	else
		result FAIL S22 "gleichnamige Datei über die Laufgrenze: $note"
	fi
}

# Wiederherstellung lange nach der Löschung: Frist zählt ab dem ersten Sehen danach
s28() {
	set_sim false
	put dave s28-late.txt
	age s28-late.txt 30
	retention_run s28-a
	local id
	id=$(sql "SELECT file_id FROM oc_folder_retention_log WHERE path LIKE ? AND mode = 'real' AND status = 'deleted'" "%s28-late.txt" | tail -n1)
	if [[ -z "$id" ]] || ! in_trash dave s28-late.txt; then
		result FAIL S28 "Vorbedingung: erster Lauf hat nicht in den Papierkorb verschoben (Log: $(logrows s28-late.txt | paste -sd' '))"
		return 0
	fi
	# Löschung vor 10 Tagen (Regel 1 Tag), heute zurückgeholt
	sql "UPDATE oc_folder_retention_log SET deleted_at = ? WHERE file_id = ?" "$(ago 10)" "$id"
	occ trashbin:restore dave > "$WORK/s28-restore.txt" 2>&1
	if ! in_files dave s28-late.txt; then
		result FAIL S28 "Wiederherstellen per occ trashbin:restore hat nicht geklappt"
		return 0
	fi
	local before seen1 stays=nein again=nein
	before=$(now)
	retention_run s28-b
	seen1=$(sql "SELECT first_seen FROM oc_folder_retention_seen WHERE file_id = ?" "$id")
	in_files dave s28-late.txt && stays=ja
	# zwei Tage nach der Wiederherstellung wieder fällig: Frist ab Wiederherstellung, nicht „nie“
	seen "$id" "$(ago 2)"
	retention_run s28-c
	in_trash dave s28-late.txt && ! in_files dave s28-late.txt && again=ja
	local note="bleibt nach Wiederherstellung: $stays (first_seen ${seen1:-fehlt}, Laufbeginn $before); 2 Tage danach im Papierkorb: $again; Log: $(logrows s28-late.txt | paste -sd' ')"
	if [[ $stays == ja && -n "$seen1" && "$seen1" -ge "$before" && $again == ja ]]; then
		result PASS S28 "Wiederherstellung 10 Tage nach Löschung: $note"
	else
		result FAIL S28 "Wiederherstellung 10 Tage nach Löschung: $note"
	fi
}

# Ausnahme im Papierkorb, danach zweiter Lauf im selben Prozess (background-job:worker)
s29() {
	set_sim false
	put erin s29-worker.txt
	age s29-worker.txt 10
	local id out where
	id=$(fid s29-worker.txt)
	[[ -n "$id" ]] || { result FAIL S29 "Vorbedingung: s29-worker.txt nicht im Filecache"; return 0; }
	out=$(docker exec -u www-data "$C" php /tmp/fret-proc.php trashthrow "$id" 2>&1 | tail -n1)
	if in_files erin s29-worker.txt; then where=bleibt; elif in_trash erin s29-worker.txt; then where=Papierkorb; else where=ENDGÜLTIG-WEG; fi
	if [[ "$out" == 'a=error b=error '* && ( "$out" == *Prozessneustart* || "$out" == *'process restart'* ) && $where == bleibt ]]; then
		result PASS S29 "Papierkorb-Ausnahme, zweiter Lauf im selben Prozess: $out; Datei $where"
	else
		result FAIL S29 "Papierkorb-Ausnahme, zweiter Lauf im selben Prozess: $out; Datei $where"
	fi
}

# Simulation protokolliert neu, wenn sich die Bewertung ändert (Alteintrag von 0.7.x, Frist)
s30() {
	set_sim true
	put alice s30-sim.txt
	age s30-sim.txt 10
	retention_run s30-a
	local n1 n2 n3 n4 n5 rows
	n1=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	# Eintrag so, wie ihn 0.7.x schrieb: Bezug mtime, anderes Datum
	sql "UPDATE oc_folder_retention_log SET reference_source = 'mtime', reference_date = ? WHERE path LIKE ? AND mode = 'simulation'" "$(ago 400)" "%s30-sim.txt"
	retention_run s30-b
	n2=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	retention_run s30-c
	n3=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	# Friständerung an derselben Regel (gleiche Regel-ID, neue Frist)
	set_default_rules day 2
	retention_run s30-d
	n4=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	retention_run s30-e
	n5=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	set_default_rules day 1
	set_sim false
	rows=$(sql "SELECT rule_id || '/' || COALESCE(rule_label, '') || '/' || reference_source FROM oc_folder_retention_log
		WHERE path LIKE ? ORDER BY id" "%s30-sim.txt" | paste -sd' ')
	local note="Einträge nach Lauf 1..5: $n1 $n2 $n3 $n4 $n5 (erwartet 1 2 2 3 3); $rows"
	if [[ "$n1 $n2 $n3 $n4 $n5" == "1 2 2 3 3" ]] && in_files alice s30-sim.txt; then
		result PASS S30 "$note"
	else
		result FAIL S30 "$note"
	fi
}

# Zuletzt: verändert die Papierkorb-Freigabe instanzweit
s6() {
	set_sim false
	occ group:add trash-users >/dev/null
	occ group:adduser trash-users alice >/dev/null
	put grace s6-notrash.txt
	age s6-notrash.txt 10
	# occ app:enable --groups verweigert das für Filesystem-Apps – Admin-Oberfläche/alte
	# Konfigurationen können den Wert trotzdem tragen, daher direkt setzen
	occ config:app:set files_trashbin enabled --value='["trash-users"]' >/dev/null
	retention_run s6
	occ config:app:set files_trashbin enabled --value=yes >/dev/null
	local st
	st=$(logrows s6-notrash.txt | paste -sd' ')
	if in_files grace s6-notrash.txt && [[ "$st" != *:deleted* ]]; then
		result PASS S6 "Papierkorb nur für Gruppe, Besitzer nicht drin: Datei bleibt; Log: ${st:-leer}"
	elif in_trash grace s6-notrash.txt; then
		result PASS S6 "Papierkorb nur für Gruppe: Datei trotzdem im Papierkorb des Besitzers; Log: ${st:-leer}"
	elif in_files grace s6-notrash.txt; then
		result FAIL S6 "Datei noch da, aber Log meldet gelöscht: $st"
	else
		result FAIL S6 "Papierkorb nur für Gruppe: Datei ENDGÜLTIG gelöscht; Log: ${st:-leer}"
	fi
}

# ---------------------------------------------------------------- eigene Instanzen (S23, S24)

# Vor dem ersten Start: kein App-Store (auch nicht bei occ upgrade), keine Skeleton-Dateien
prep_side() {
	printf '%s\n' '<?php' '// Harness: eigene Instanz ohne App-Store und ohne Skeleton-Dateien' \
		"\$CONFIG = ['appstoreenabled' => false, 'has_internet_connection' => false, 'skeletondirectory' => ''];" \
		> "$WORK/side.config.php"
	docker cp "$WORK/side.config.php" "$C:/usr/src/nextcloud/config/fret-side.config.php"
}

# Primärspeicher = Objektspeicher (FretDirObjectStore, Objekte als Dateien – ohne S3)
prep_objectstore() {
	prep_side
	docker cp "$HERE/objectstore/FretDirObjectStore.php" "$C:/usr/src/nextcloud/lib/private/Files/ObjectStore/FretDirObjectStore.php"
	docker cp "$HERE/objectstore/objectstore.config.php" "$C:/usr/src/nextcloud/config/objectstore.config.php"
}

# Eigene Wegwerf-Instanz für ein Szenario; der Aufrufer setzt vorher „local C=…“ (Helfer lesen $C)
side_nc() {
	start_nc "${1:-prep_side}"
	docker exec -i "$C" sh -c 'cat > /tmp/fret-sql.php' < "$HERE/sql.php"
	occ background:cron >/dev/null
	occ config:system:set default_timezone --value="$NC_TZ" >/dev/null
}

side_user() {
	docker exec -e OC_PASS="$PW" -u www-data "$C" php occ user:add --password-from-env "$1" >/dev/null
	touch_fs "$1"
}

# <bytes> Nullbytes per WebDAV hochladen (Objektspeicher: nicht über die Platte möglich)
put_bytes() {
	docker exec "$C" sh -c "head -c $3 /dev/zero | curl -sf -o /dev/null -u '$1:$PW' -T - \
		http://localhost/remote.php/dav/files/$1/$2"
}

# Zustand einer Datei laut Filecache (Objektspeicher hat keine Dateien unter data/<konto>/)
obj_state() {
	local storage
	storage=$(sql "SELECT numeric_id FROM oc_storages WHERE id = ?" "object::user:$1")
	if [[ -n "$(sql "SELECT fileid FROM oc_filecache WHERE storage = ? AND path = ?" "$storage" "files/$2")" ]]; then
		echo da
	elif [[ -n "$(sql "SELECT fileid FROM oc_filecache WHERE storage = ? AND path LIKE ?" "$storage" "files_trashbin/files/$2.d%")" ]]; then
		echo Papierkorb
	else
		echo ENDGÜLTIG-WEG
	fi
}

# Objektspeicher als Primärspeicher: Nextcloud rechnet für die Papierkorb-Räumung die Größe der
# Konto-Wurzel – dort samt Papierkorb und Versionen (normaler Cache statt HomeCache). olga:
# Quota 10 MB, 3 MB nicht fällig, 1,4 MB schon im Papierkorb, 2 MB fällig. Nur files/ gerechnet
# passt die Datei (7 MB frei → 3,5 MB > 3,4 MB); mit der Wurzel (6,7 MB) nicht – Expire räumte sie.
s23() {
	local C="$C-os"
	side_nc prep_objectstore
	install_app
	local kind
	kind=$(sql "SELECT COUNT(*) FROM oc_storages WHERE id LIKE 'object::%'")
	if [[ "${kind:-0}" == 0 ]]; then
		result FAIL S23 "Vorbedingung: kein Objektspeicher als Primärspeicher (oc_storages ohne object::)"
		return 0
	fi
	side_user olga
	occ user:setting olga files quota "10 MB" >/dev/null
	put_bytes olga s23-keep.bin 3145728
	put_bytes olga s23-old.bin 1468006
	docker exec "$C" curl -sf -o /dev/null -u "olga:$PW" -X DELETE http://localhost/remote.php/dav/files/olga/s23-old.bin \
		|| { result FAIL S23 "Vorbedingung: Löschen von s23-old.bin fehlgeschlagen"; return 0; }
	put_bytes olga s23-target.bin 2097152
	prepare_rules
	set_sim false
	age s23-target.bin 10
	local storage sizes
	storage=$(sql "SELECT numeric_id FROM oc_storages WHERE id = ?" 'object::user:olga')
	sizes=$(sql "SELECT CASE path WHEN '' THEN 'Wurzel' ELSE path END || '=' || size FROM oc_filecache WHERE storage = ? AND path IN ('', 'files', 'files_trashbin') ORDER BY path" "$storage" | paste -sd' ')
	retention_run s23
	occ trashbin:expire olga >/dev/null 2>&1 || true
	run_expire_jobs
	local target old rows
	target=$(obj_state olga s23-target.bin)
	old=$(obj_state olga s23-old.bin)
	rows=$(logrows s23-target.bin | paste -sd' ')
	local note="Objektspeicher, Quota 10 MB ($sizes), nach Lauf + Expire: Ziel=$target, alter Papierkorb-Eintrag=$old; Log: ${rows:-leer}"
	if [[ $target == ENDGÜLTIG-WEG ]]; then
		result FAIL S23 "$note"
	elif [[ $target == Papierkorb || "$rows" == *[Qq]uota* ]]; then
		result PASS S23 "$note"
	else
		result FAIL S23 "$note (Datei noch da, aber Log nennt keine Quota)"
	fi
}

# Update vom frühen 0.8.0-Stand (OLD_REV, Grenze „seen_since“ als Zeitpunkt) auf den Arbeitsbaum:
# Eine Kopie aus der 0.8.0-Zeit (erbt die Upload-Zeit des 400 Tage alten Originals, zuerst
# gesehen nach seen_since) war dort geschützt und muss es nach dem Update bleiben.
s24() {
	local C="$C-up" old="$WORK/app-$OLD_REV"
	mkdir -p "$old"
	git -C "$REPO" archive "$OLD_REV" | tar -x -C "$old" \
		|| { result FAIL S24 "Vorbedingung: git archive $OLD_REV fehlgeschlagen"; return 0; }
	# Ausgangsstand nur in dieser Kopie auf die max-version von APP_SRC heben – sonst lässt sich
	# OLD_REV (max-version 34) z. B. auf NC 35 gar nicht erst installieren
	local maxv
	maxv=$(sed -n 's/.*<nextcloud [^>]*max-version="\([0-9]*\)".*/\1/p' "$APP_SRC/appinfo/info.xml")
	[[ -z "$maxv" ]] || sed -i "s/\(<nextcloud [^>]*max-version=\"\)[0-9]*\"/\1$maxv\"/" "$old/appinfo/info.xml"
	side_nc
	install_app "$old"
	side_user uwe
	prepare_rules
	set_sim true
	put uwe s24-orig.txt
	age s24-orig.txt 400
	retention_run s24-a # Simulation: Zyklus fertig → seen_since
	local since
	since=$(occ config:app:get folder_retention seen_since 2>/dev/null || true)
	[[ -n "$since" ]] || { result FAIL S24 "Vorbedingung: $OLD_REV hat seen_since nicht gesetzt"; return 0; }
	sleep 2
	docker exec "$C" curl -sf -o /dev/null -u "uwe:$PW" -X COPY \
		-H "Destination: http://localhost/remote.php/dav/files/uwe/s24-kopie.txt" \
		http://localhost/remote.php/dav/files/uwe/s24-orig.txt \
		|| { result FAIL S24 "Vorbedingung: WebDAV-COPY fehlgeschlagen"; return 0; }
	retention_run s24-b # vermerkt „zuerst gesehen“ der Kopie
	local id seen pre
	id=$(fid s24-kopie.txt)
	seen=$(sql "SELECT first_seen FROM oc_folder_retention_seen WHERE file_id = ?" "$id")
	pre=$(occ folder_retention:run --dry-run 2>&1 | grep -c 's24-kopie' || true)
	if [[ -z "$seen" || "$seen" -le "$since" || "$pre" != 0 ]]; then
		result FAIL S24 "Vorbedingung unter $OLD_REV: seen_since=$since, Kopie first_seen=${seen:-fehlt}, im Dry-Run fällig: $pre"
		return 0
	fi
	install_app "$APP_SRC" copy-only
	occ upgrade >"$WORK/s24-upgrade.txt" 2>&1 || { result FAIL S24 "occ upgrade fehlgeschlagen: $(tail -n2 "$WORK/s24-upgrade.txt" | paste -sd' ')"; return 0; }
	local mark left version
	version=$(occ app:list --output=json | grep -o '"folder_retention":"[^"]*"')
	mark=$(occ config:app:get folder_retention seen_max_fileid 2>/dev/null || echo fehlt)
	left=$(occ config:app:get folder_retention seen_since 2>/dev/null || echo gelöscht)
	set_sim false
	retention_run s24-c
	local kopie orig
	kopie=$(in_files uwe s24-kopie.txt && echo da || (in_trash uwe s24-kopie.txt && echo Papierkorb || echo weg))
	orig=$(in_trash uwe s24-orig.txt && echo Papierkorb || (in_files uwe s24-orig.txt && echo da || echo weg))
	local note="$version, seen_since=$since → $left, seen_max_fileid=$mark, Kopie fileid=$id first_seen=$seen: $kopie [$(last_status s24-kopie.txt)], Original: $orig"
	if [[ $kopie == da && $orig == Papierkorb && $left == gelöscht && "$mark" =~ ^[0-9]+$ && "$mark" -lt "$id" ]]; then
		result PASS S24 "$note"
	else
		result FAIL S24 "$note"
	fi
}

# Team-Ordner mit carol und bob: Nextcloud nennt das Konto, über das gelöscht wird, als Löschenden
# (Papierkorb „deleted_by“, Aktivität). Es muss stabil das erste Mitglied in sortierter Reihenfolge
# sein (bob) – auch wenn im selben Lauf vorher carols eigene Datei gelöscht wurde (Kontext carol) –,
# und das Protokoll der App muss es nennen.
s25() {
	if [[ "$GF_OK" != 1 ]]; then
		result SKIP S25 "groupfolders nicht verfügbar: $GF_WHY"
		return 0
	fi
	set_sim false
	gf_create Team25 team25 carol bob >/dev/null
	put carol Team25/s25-team.txt
	age s25-team.txt 10
	put carol s25-carol.txt
	age s25-carol.txt 10
	retention_run s25
	local team own by ok=1
	team=$(last_status s25-team.txt)
	own=$(last_status s25-carol.txt)
	by=$(sql "SELECT deleted_by FROM oc_group_folders_trash WHERE name = 's25-team.txt'" 2>/dev/null || echo 'Spalte fehlt')
	gf_trashed s25-team.txt || ok=0
	in_trash carol s25-carol.txt || ok=0
	# Meldung in der Sprache der Instanz (tag_language; frische Installation: en)
	[[ "$team" == 'real:deleted ('*'über Konto bob '*'gelöscht hat folder_retention'* || "$team" == 'real:deleted ('*'via account bob '*'folder_retention deleted it'* ]] || ok=0
	[[ "$own" == 'real:deleted ('*'über Konto carol '* || "$own" == 'real:deleted ('*'via account carol '* ]] || ok=0
	[[ "$by" == bob || "$by" == 'Spalte fehlt' ]] || ok=0
	local note="Team-Ordner-Papierkorb „gelöscht von“: ${by:-leer}; Log Team: ${team:-leer}; Log carol: ${own:-leer}"
	if [[ $ok == 1 ]]; then
		result PASS S25 "$note"
	else
		result FAIL S25 "$note"
	fi
}

# occ app:remove (ohne --keep-data) bei ausgeschalteter Simulation, dann neu installieren:
# Nextcloud lässt App-Config und Regeln stehen (INSTALL.md §9) – der Uninstall-Schritt muss die
# Simulation einschalten, sonst löschte die Neuinstallation sofort nach den alten Fristen.
s26() {
	local C="$C-rm"
	side_nc
	install_app
	side_user uma
	prepare_rules
	set_sim false
	local rules_before rules_after
	rules_before=$(sql "SELECT COALESCE(target, 'allgemein') || '=' || COALESCE(period_value, '') || period_unit FROM oc_folder_retention_rules WHERE folder_id IS NULL ORDER BY id" | paste -sd' ')
	occ app:remove folder_retention >"$WORK/s26-remove.txt" 2>&1 \
		|| { result FAIL S26 "occ app:remove fehlgeschlagen: $(tail -n2 "$WORK/s26-remove.txt" | paste -sd' ')"; return 0; }
	if docker exec "$C" test -d /var/www/html/custom_apps/folder_retention; then
		result FAIL S26 "Vorbedingung: App-Ordner nach app:remove noch da"
		return 0
	fi
	install_app
	local sim
	sim=$(occ config:app:get folder_retention simulation_mode 2>/dev/null || echo fehlt)
	rules_after=$(sql "SELECT COALESCE(target, 'allgemein') || '=' || COALESCE(period_value, '') || period_unit FROM oc_folder_retention_rules WHERE folder_id IS NULL ORDER BY id" | paste -sd' ')
	put uma s26-due.txt
	age s26-due.txt 10
	retention_run s26
	local st where=weg
	st=$(last_status s26-due.txt)
	in_files uma s26-due.txt && where=da
	in_trash uma s26-due.txt && where=Papierkorb
	local note="app:remove: $(grep -o 'uninstall steps executed' "$WORK/s26-remove.txt" || echo 'ohne Uninstall-Schritte'); nach Neuinstallation simulation_mode=$sim, Regeln $rules_before → $rules_after (bleiben laut INSTALL.md), fällige Datei: $where [${st:-kein Log}]"
	if [[ ( "$sim" == 1 || "$sim" == true ) && $where == da && "$st" == simulation:would_delete* && "$rules_after" == "$rules_before" ]]; then
		result PASS S26 "$note"
	else
		result FAIL S26 "$note"
	fi
}

# „Vollständig aufräumen“ genau mit dem SQL-Block aus INSTALL.md §9, danach neu installieren:
# Tabellen wieder da, Simulation an, beide Standardregeln „nie“, occ-Lauf ohne SQL-Fehler.
s27() {
	local C="$C-rs" doc="$APP_SRC/INSTALL.md"
	[[ -f "$doc" ]] || doc="$REPO/INSTALL.md"
	local stmts
	stmts=$(awk '/^## 9\. /{s=1} s && /^```sql/{b=1; next} b && /^```/{exit} b && NF' "$doc")
	[[ -n "$stmts" ]] || { result FAIL S27 "Vorbedingung: kein SQL-Block in INSTALL.md §9"; return 0; }
	side_nc
	install_app
	side_user ulla
	prepare_rules
	set_sim false
	occ app:remove folder_retention >"$WORK/s27-remove.txt" 2>&1 \
		|| { result FAIL S27 "occ app:remove fehlgeschlagen: $(tail -n2 "$WORK/s27-remove.txt" | paste -sd' ')"; return 0; }
	local line n=0
	while IFS= read -r line; do
		sql "${line%;}" || { result FAIL S27 "SQL aus INSTALL.md scheitert: $line"; return 0; }
		n=$((n + 1))
	done <<< "$stmts"
	install_app
	local tables sim rules dry rc=0
	tables=$(sql "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'oc_folder_retention_%'")
	sim=$(occ config:app:get folder_retention simulation_mode 2>/dev/null || echo fehlt)
	put ulla s27-old.txt
	age s27-old.txt 400
	dry=$(occ folder_retention:run --dry-run 2>&1) || rc=$?
	rules=$(sql "SELECT COALESCE(target, 'allgemein') || '=' || period_unit || COALESCE(period_value, '')
		FROM oc_folder_retention_rules WHERE folder_id IS NULL ORDER BY id" | paste -sd' ')
	local note="$n Anweisungen aus INSTALL.md; danach Tabellen=$tables, simulation_mode=$sim, Regeln: ${rules:-keine}, Dry-Run rc=$rc$( [[ "$dry" == *s27-old* ]] && echo ', 400 Tage alte Datei fällig' )"
	if [[ "$tables" == 5 && ( "$sim" == 1 || "$sim" == true ) && "$rules" =~ ^allgemein=never\ personal=never$ && $rc == 0 && "$dry" != *s27-old* ]]; then
		result PASS S27 "$note"
	else
		result FAIL S27 "$note; Dry-Run: $(echo "$dry" | tail -n2 | paste -sd' ')"
	fi
}

# Team-Ordner mit groupfolders-Verschlüsselung (Master-Key): groupfolders KOPIERT die Datei in
# den Papierkorb – dort steht sie unter neuer Datei-ID, oc_group_folders_trash nennt die alte.
# Die App muss das als „deleted“ verbuchen, nicht als „deleted_final“ mit Sperre. Im selben
# Lauf eine persönliche Datei (gleicher Speicher → Umbenennen, ID bleibt).
s31() {
	if [[ "$GF_OK" != 1 ]]; then
		result SKIP S31 "groupfolders nicht verfügbar: $GF_WHY"
		return 0
	fi
	local C="$C-enc"
	side_nc
	docker cp "$WORK/gf.tgz" "$C:/tmp/gf.tgz"
	docker exec "$C" sh -c 'mkdir -p /var/www/html/custom_apps/groupfolders \
		&& tar xzf /tmp/gf.tgz --strip-components=1 -C /var/www/html/custom_apps/groupfolders \
		&& chown -R www-data:www-data /var/www/html/custom_apps/groupfolders'
	occ app:enable groupfolders >/dev/null
	install_app
	occ app:enable encryption >/dev/null
	occ encryption:enable >/dev/null
	echo y | docker exec -i -u www-data "$C" php occ encryption:enable-master-key >/dev/null
	occ config:app:set groupfolders enable_encryption --value=true >/dev/null
	side_user ella
	gf_create Team31 team31 ella >/dev/null
	put ella Team31/s31-team.txt
	put ella s31-home.txt
	prepare_rules
	set_sim false
	age s31-team.txt 10
	age s31-home.txt 10
	local old
	old=$(fid s31-team.txt)
	retention_run s31
	local team home trashid gfrow blk ok=1
	team=$(last_status s31-team.txt)
	home=$(last_status s31-home.txt)
	trashid=$(sql "SELECT fileid FROM oc_filecache WHERE name LIKE 's31-team.txt.d%'")
	gfrow=$(sql "SELECT file_id FROM oc_group_folders_trash WHERE name = 's31-team.txt'")
	blk=$(blocks)
	gf_trashed s31-team.txt || ok=0
	in_trash ella s31-home.txt || ok=0
	[[ "$team" == real:deleted\ * && "$home" == real:deleted\ * && -z "$blk" ]] || ok=0
	local note="Verschlüsselung (Master-Key) im Team-Ordner: fileid $old → Papierkorb-ID ${trashid:-fehlt} (oc_group_folders_trash.file_id=${gfrow:-fehlt}); Log Team: ${team:-leer}; Log Home: ${home:-leer}; Sperren: ${blk:-keine}"
	if [[ $ok == 1 ]]; then
		result PASS S31 "$note"
	else
		result FAIL S31 "$note"
	fi
}

# S32 Protokoll-Übersicht: Tage, Ordner und Ordnerfilter (direkter Elternordner, LIKE-Zeichen im
# Namen) gegen die echte Datenbank. notLike() hängt auf SQLite kein ESCAPE an – der Filter darf
# sich darauf nicht verlassen.
s32() {
	local at path n=0
	at=$(( $(now) - 60 ))
	for path in "S32 100%_[x]/a.txt" "S32 100%_[x]/sub/b.txt" "S32 1000x[x]/c.txt" "s32-loose.txt"; do
		n=$((n + 1))
		sql "INSERT INTO oc_folder_retention_log (file_id, storage_id, path, rule_label, reference_date, reference_source, deleted_at, mode, status) VALUES (?, 1, ?, 's32', ?, 'upload', ?, 'simulation', 'would_delete')" \
			"$((990000 + n))" "$path" "$((at - 86400))" "$at"
	done
	api() { docker exec "$C" curl -sf -u "admin:$PW" -H 'OCS-APIRequest: true' "http://localhost/index.php/apps/folder_retention/api/$1"; }
	paths() { api "log?search=s32&folder=$1" | grep -o '"path":"[^"]*"' | sed 's/"path":"//; s/"$//; s#\\/#/#g' | sort | tr '\n' '|'; }
	local p1 p2 p3 root folders days ok=1
	p1=$(paths 'S32%20100%25_%5Bx%5D')
	p2=$(paths 'S32%20100%25_%5Bx%5D%2Fsub')
	p3=$(paths 'S32%201000x%5Bx%5D')
	root=$(api 'log?folder=' | grep -o '"path":"s32-loose.txt"' || true)
	folders=$(api 'log/folders?search=S32' | grep -o '"folder":"[^"]*","total":[0-9]*' | tr '\n' ' ')
	days=$(api 'log/days?search=s32' | grep -o '"total":[0-9]*,"counts"' | head -1)
	[[ "$p1" == "S32 100%_[x]/a.txt|" ]] || ok=0
	[[ "$p2" == "S32 100%_[x]/sub/b.txt|" ]] || ok=0
	[[ "$p3" == "S32 1000x[x]/c.txt|" ]] || ok=0
	[[ -n "$root" ]] || ok=0
	[[ "$folders" == *'"folder":"S32 100%_[x]","total":1'* && "$folders" == *'"folder":"S32 100%_[x]\/sub","total":1'* ]] || ok=0
	[[ "$days" == '"total":4,"counts"' ]] || ok=0
	sql "DELETE FROM oc_folder_retention_log WHERE rule_label = 's32'"
	local note="Ordnerfilter: [${p1}] [${p2}] [${p3}], ohne Ordner: ${root:-fehlt}; Ordner: ${folders}; Tag: ${days:-leer}"
	if [[ $ok == 1 ]]; then
		result PASS S32 "$note"
	else
		result FAIL S32 "$note"
	fi
}

# ---------------------------------------------------------------- Ablauf

setup
scenario S5 s5
prepare_rules
scenario S7 s7
scenario S30 s30
scenario S1 s1
scenario S2 s2
scenario S28 s28
scenario S29 s29
scenario S3 s3
scenario S4 s4
scenario S9 s9
scenario S8 s8
scenario S11 s11
scenario S12 s12
scenario S13 s13
scenario S14 s14
scenario S18 s18
scenario S16 s16
scenario S25 s25
scenario S19 s19
scenario S20 s20
scenario S21 s21
scenario S22 s22
scenario S15 s15
scenario S10 s10
scenario S17 s17
scenario S23 s23
scenario S24 s24
scenario S6 s6
scenario S26 s26
scenario S27 s27
scenario S31 s31
scenario S32 s32

pass=$(grep -c '^PASS ' "$RES" || true)
fail=$(grep -c '^FAIL ' "$RES" || true)
skip=$(grep -c '^SKIP ' "$RES" || true)
info "Laufzeit $(( $(now) - STARTED )) s, Image $IMAGE, App aus $APP_SRC"
echo "Harness $pass/$fail/$skip"
[[ "$fail" == 0 ]]
