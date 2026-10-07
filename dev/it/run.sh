#!/usr/bin/env bash
# Integration harness for folder_retention: starts a throwaway Nextcloud (SQLite, without a
# published port), replays the data-loss scenarios from the 0.7.4 audit and prints one
# machine-readable line per scenario:
#   PASS|FAIL|SKIP <id> <text>
# Last line: "Harness <pass>/<fail>/<skip>", exit code 1 if at least one FAIL.
# Diagnostics (what is happening right now) go to stderr.
#
# Environment:
#   IMAGE=nextcloud:34.0.4-apache   Nextcloud image (35 works too, groupfolders is chosen to match)
#   APP_SRC=<repo working tree>     source of the app; without js/ it is built with node:24-alpine
#   ONLY=S1,S3                      only these scenarios (setup always runs)
#   KEEP=1                          leave the container + work directory in place at the end
#   GF_TAG=v22.0.6                  pin the groupfolders release (otherwise: newest matching one from GitHub)
#   OLD_REV=fb4395c                 starting state for the update scenario S24
#   NO_GF=1                         do not even try groupfolders (S8 = SKIP)
#   FRET_IT_PREFIX=fret-a-          container prefix (default fret-it-), for parallel runs
#
# Never against the production instance: the harness only ever creates its own fret-it-* containers.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
IMAGE="${IMAGE:-nextcloud:34.0.4-apache}"
APP_SRC="$(cd "${APP_SRC:-$REPO}" && pwd)"
# FRET_IT_PREFIX: own prefix when several harness runs happen in parallel (e.g. fret-a-)
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
		echo "KEEP=1: container $C (possibly also $C-os, $C-up, $C-rm, $C-rs, $C-enc) and $WORK are kept (docker rm -fv $C $C-os $C-up $C-rm $C-rs $C-enc)" >&2
	else
		docker rm -fv "$C" "$C-os" "$C-up" "$C-rm" "$C-rs" "$C-enc" >/dev/null 2>&1 || true
		if [[ $rc -eq 0 ]]; then
			rm -rf "$WORK"
		else
			echo "Run outputs: $WORK" >&2
		fi
	fi
}
trap cleanup EXIT

info() { echo "  · $*" >&2; }

# ---------------------------------------------------------------- helpers in the container

occ() { docker exec -u www-data "$C" php occ "$@"; }

# SQL against the SQLite DB of the throwaway instance (table prefix oc_)
sql() { docker exec -u www-data "$C" php /tmp/fret-sql.php "$@"; }

now() { date +%s; }
ago() { echo $(( $(now) - $1 * 86400 )); }

# Upload a file via WebDAV; optionally with X-OC-CTime (ends up in filecache_extended.creation_time)
put() {
	local user=$1 path=$2 ctime=${3:-}
	local hdr=()
	[[ -n "$ctime" ]] && hdr=(-H "X-OC-CTime: $ctime")
	echo "Content $path" | docker exec -i "$C" curl -sf -o /dev/null -u "$user:$PW" -T - "${hdr[@]}" \
		"http://localhost/remote.php/dav/files/$user/$path"
}

# PROPFIND sets up the account's file system → home and team folder mounts end up in
# oc_mounts, lastLogin gets set (RootProvider only sees "seen" accounts).
touch_fs() {
	docker exec "$C" curl -sf -o /dev/null -u "$1:$PW" -X PROPFIND -H 'Depth: 1' \
		"http://localhost/remote.php/dav/files/$1/"
}

# fileid of the live file (not trash bin/versions) via the unique file name
fid() {
	sql "SELECT fileid FROM oc_filecache WHERE name = ? AND path NOT LIKE 'files_trashbin/%'
		AND path NOT LIKE 'files_versions/%' AND path NOT LIKE 'trash/%' AND path NOT LIKE 'versions/%'
		ORDER BY fileid DESC LIMIT 1" "$1"
}

# Let a file "age": set oc_filecache.mtime and oc_filecache_extended.upload_time and
# .creation_time to <days> (or <ctime-days>) before now. storage_mtime stays so that
# no scanner "repairs" the entry. Plus "first seen" (0.8.0): without that entry the
# freshly created file with an old upload time would look like a copy and count from the first run.
age() {
	local name=$1 days=$2 cdays=${3:-$2} id
	id=$(fid "$name")
	[[ -n "$id" ]] || { info "age: $name not in the filecache"; return 1; }
	age_id "$id" "$days" "$cdays"
}

# like age, but via the fileid (for files with the same name)
age_id() {
	local id=$1 days=$2 cdays=${3:-$2} ts cts
	ts=$(ago "$days")
	cts=$(ago "$cdays")
	sql "UPDATE oc_filecache SET mtime = ? WHERE fileid = ?" "$ts" "$id"
	sql "INSERT OR IGNORE INTO oc_filecache_extended (fileid) VALUES (?)" "$id"
	sql "UPDATE oc_filecache_extended SET upload_time = ?, creation_time = ? WHERE fileid = ?" "$ts" "$cts" "$id"
	seen "$id" "$ts"
}

# set "first seen" (only if the table exists – older states without the 0.8 migration)
seen() {
	sql "INSERT OR REPLACE INTO oc_folder_retention_seen (file_id, first_seen) SELECT ?, ?
		WHERE EXISTS (SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'oc_folder_retention_seen')" "$1" "$2"
}

# fileid of a file in <user>'s home via the path below files/
fid_path() {
	sql "SELECT f.fileid FROM oc_filecache f JOIN oc_storages s ON s.numeric_id = f.storage
		WHERE s.id = ? AND f.path = ?" "home::$1" "files/$2"
}

in_files() { docker exec "$C" test -f "/var/www/html/data/$1/files/$2"; }
# the trash bin copy lives as <name>.d<timestamp> in files_trashbin/files
in_trash() {
	docker exec "$C" sh -c "ls /var/www/html/data/$1/files_trashbin/files/ 2>/dev/null | grep -q '^$2\.d[0-9]'"
}
# anywhere on disk (files, trash bin, team folders including their trash bin)?
anywhere() { docker exec "$C" sh -c "find /var/www/html/data -name '$1*' | grep -q ."; }

# The app's log for a file: "mode:status" per line, oldest first
logrows() {
	sql "SELECT mode || ':' || status || CASE WHEN message IS NULL THEN '' ELSE ' (' || message || ')' END
		FROM oc_folder_retention_log WHERE path LIKE ? ORDER BY id" "%$1"
}
last_status() { logrows "$1" | tail -n1; }

# Retention run via occ; output to $WORK/<tag>.txt, exit code ignored (error → FAILURE)
retention_run() {
	occ folder_retention:run > "$WORK/$1.txt" 2>&1 || true
}

# Safety blocks (oc_folder_retention_block) as "key@timestamp", sorted
blocks() {
	sql "SELECT block_key || '@' || blocked_at FROM oc_folder_retention_block WHERE block_key LIKE ? ORDER BY block_key" "${1:-%}" | paste -sd' '
}

set_sim() { occ config:app:set folder_retention simulation_mode --value="$1" --type=boolean >/dev/null; }

# Set both default rules (general + personal folders, folder_id NULL) directly in the DB
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
		result FAIL "$id" "scenario aborted (exit $rc), outputs in $WORK"
	fi
}

# ---------------------------------------------------------------- setup

build_js_if_needed() {
	if compgen -G "$APP_SRC/js/*.mjs" >/dev/null || compgen -G "$APP_SRC/js/*.js" >/dev/null; then
		return 0
	fi
	info "APP_SRC has no js/ – building with node:24-alpine (npm ci + build)"
	docker run --rm -v "$APP_SRC":/app -w /app node:24-alpine \
		sh -c 'npm ci --no-audit --no-fund --loglevel=error && npm run build' >"$WORK/js-build.txt" 2>&1 \
		|| { echo "js build failed, see $WORK/js-build.txt" >&2; exit 2; }
}

# $1 (optional): function that puts files into the created container before the first start
start_nc() {
	local prep=${1:-}
	info "starting $C from $IMAGE"
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
			# installation finished does not yet mean Apache is already answering
			if docker exec "$C" curl -sf -o /dev/null http://localhost/status.php; then
				return 0
			fi
		fi
		sleep 2
	done
	echo "Nextcloud in $C did not come up" >&2
	exit 2
}

install_groupfolders() {
	GF_OK=0
	GF_WHY=''
	if [[ "${NO_GF:-0}" == 1 ]]; then
		GF_WHY='NO_GF=1 set'
		return 0
	fi
	local major gfmajor tag
	major=$(occ status --output=json | sed -n 's/.*"versionstring":"\([0-9]*\)\..*/\1/p')
	# groupfolders has counted in lockstep since NC 30 = v18: v(NC-12)
	gfmajor=$((major - 12))
	tag="${GF_TAG:-}"
	if [[ -z "$tag" ]]; then
		tag=$(curl -fsSL "https://api.github.com/repos/nextcloud/groupfolders/releases?per_page=100" 2>/dev/null \
			| sed -n 's/.*"tag_name": *"\(v[0-9.]*\)".*/\1/p' | grep -E "^v$gfmajor\.[0-9]+\.[0-9]+$" \
			| sort -V | tail -n1 || true)
	fi
	if [[ -z "$tag" ]]; then
		GF_WHY="no groupfolders release v$gfmajor.x for NC $major found on GitHub"
		return 0
	fi
	info "groupfolders $tag (source tarball from GitHub, without built JS – enough for occ)"
	if ! curl -fsSL -o "$WORK/gf.tgz" "https://github.com/nextcloud/groupfolders/archive/refs/tags/$tag.tar.gz"; then
		GF_WHY="download of groupfolders $tag from GitHub failed"
		return 0
	fi
	docker cp "$WORK/gf.tgz" "$C:/tmp/gf.tgz"
	docker exec "$C" sh -c 'mkdir -p /var/www/html/custom_apps/groupfolders \
		&& tar xzf /tmp/gf.tgz --strip-components=1 -C /var/www/html/custom_apps/groupfolders \
		&& chown -R www-data:www-data /var/www/html/custom_apps/groupfolders'
	if occ app:enable groupfolders >"$WORK/gf-enable.txt" 2>&1; then
		GF_OK=1
	else
		GF_WHY="groupfolders $tag could not be enabled: $(tail -n1 "$WORK/gf-enable.txt")"
	fi
}

# $1 (optional): source instead of APP_SRC; $2 (optional): only copy, do not enable
install_app() {
	local src=${1:-$APP_SRC}
	info "copying app from $src"
	# Like scripts/deploy-test.sh: only what is needed at runtime
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
	# No AJAX cron: otherwise RetentionJob would run uncontrolled along with WebDAV requests
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

# ---------------------------------------------------------------- scenarios

# S5 first: needs the state right after installation
s5() {
	put alice s5-fresh.txt
	age s5-fresh.txt 60
	set_sim false
	retention_run s5
	local rules
	rules=$(sql "SELECT COALESCE(target, 'general') || '=' || period_unit || COALESCE(period_value, '')
		FROM oc_folder_retention_rules WHERE folder_id IS NULL ORDER BY id" | paste -sd' ')
	local deleted
	deleted=$(sql "SELECT COUNT(*) FROM oc_folder_retention_log WHERE mode <> 'simulation' AND status = 'deleted'")
	if [[ "$rules" =~ ^general=never\ personal=never$ ]] && in_files alice s5-fresh.txt && [[ "$deleted" == 0 ]]; then
		result PASS S5 "default rules after installation: $rules; 60-day-old file stays, 0 deletions"
	else
		local where=gone
		in_files alice s5-fresh.txt && where=present
		in_trash alice s5-fresh.txt && where=trash
		result FAIL S5 "default rules after installation: $rules; file: $where; deleted according to the log: $deleted"
	fi
}

# Rules for all further scenarios: both default rules 1 day from creation/upload
prepare_rules() {
	occ folder_retention:run --dry-run >/dev/null 2>&1 || true # creates missing default rules
	set_default_rules day 1
	info "default rules now: $(sql "SELECT COALESCE(target, 'general') || '=' || COALESCE(period_value, '') || period_unit FROM oc_folder_retention_rules WHERE folder_id IS NULL" | paste -sd' ')"
}

s7() {
	set_sim true
	put alice s7-sim.txt
	age s7-sim.txt 10
	retention_run s7
	local rows
	rows=$(logrows s7-sim.txt | paste -sd' ')
	if in_files alice s7-sim.txt && [[ "$rows" == *simulation:* ]] && [[ "$rows" != *real:* && "$rows" != *live:* ]]; then
		result PASS S7 "simulation: file stays, log: $rows"
	else
		result FAIL S7 "simulation: file $(in_files alice s7-sim.txt && echo present || echo gone), log: ${rows:-empty}"
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
			state=still-there
			ok=0
		elif in_trash "$u" "$name"; then
			state=trash
			[[ "$st" == *:deleted* ]] || ok=0
		else
			state=PERMANENTLY-GONE
			ok=0
		fi
		note+="$u=$state[${st:-no log}] "
	done
	if [[ $ok == 1 ]]; then
		result PASS S1 "three accounts in one run: $note"
	else
		result FAIL S1 "three accounts in one run: $note"
	fi
}

s2() {
	set_sim false
	put dave s2-restore.txt
	age s2-restore.txt 10
	retention_run s2-a
	if ! in_trash dave s2-restore.txt; then
		result FAIL S2 "precondition: first run did not move to the trash bin ($(in_files dave s2-restore.txt && echo still there || echo permanently gone); log: $(logrows s2-restore.txt | paste -sd' '))"
		return 0
	fi
	occ trashbin:restore dave > "$WORK/s2-restore.txt" 2>&1
	if ! in_files dave s2-restore.txt; then
		result FAIL S2 "restoring via occ trashbin:restore failed"
		return 0
	fi
	retention_run s2-b
	local rows
	rows=$(logrows s2-restore.txt | paste -sd' ')
	if in_files dave s2-restore.txt; then
		result PASS S2 "restored file stays in the next run; log: $rows"
	else
		result FAIL S2 "restored file removed again in the next run ($(in_trash dave s2-restore.txt && echo trash || echo permanently)); log: $rows"
	fi
}

s3() {
	set_sim false
	put erin s3-ctime.txt 1420070400 # X-OC-CTime: 2015-01-01
	local ext
	ext=$(sql "SELECT 'creation_time=' || e.creation_time || ' upload_time=' || e.upload_time FROM oc_filecache_extended e WHERE e.fileid = ?" "$(fid s3-ctime.txt)")
	retention_run s3
	if in_files erin s3-ctime.txt; then
		result PASS S3 "uploaded today with client ctime 2015 ($ext): not due"
	else
		result FAIL S3 "uploaded today with client ctime 2015 ($ext): removed, log: $(logrows s3-ctime.txt | paste -sd' ')"
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
		result PASS S4 "picked up by files:scan ($ext): not due in the first run"
	else
		result FAIL S4 "picked up by files:scan ($ext): removed in the first run, log: $(logrows s4-scan.txt | paste -sd' ')"
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
		|| { result FAIL S9 "precondition: share not visible for bob"; return 0; }
	age s9-share.txt 10
	retention_run s9
	local rows
	rows=$(logrows s9-share.txt | paste -sd' ')
	if ! in_files alice s9-share.txt && in_trash alice s9-share.txt; then
		result PASS S9 "shared file is in alice's trash bin; log: $rows"
	elif in_files alice s9-share.txt; then
		result FAIL S9 "shared file still with alice (only the share removed?); log: $rows"
	else
		result FAIL S9 "shared file permanently gone; log: $rows"
	fi
}

# Create a team folder, grant a group with members access, set up mounts; prints the ID
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
		touch_fs "$u" # team folder mount in oc_mounts
	done
	echo "$gid"
}

# Is <name> in the groupfolders trash bin (DB entry AND file on disk)?
gf_trashed() {
	[[ "$(sql "SELECT COUNT(*) FROM oc_group_folders_trash WHERE name = ?" "$1")" -ge 1 ]] \
		&& docker exec "$C" sh -c "find /var/www/html/data/__groupfolders -path '*trash*' -name '$1.d*' | grep -q ."
}

s8() {
	if [[ "$GF_OK" != 1 ]]; then
		result SKIP S8 "groupfolders not available: $GF_WHY"
		return 0
	fi
	set_sim false
	local gid
	gid=$(gf_create Teamordner team alice)
	put alice Teamordner/s8-team.txt
	age s8-team.txt 10
	# Earlier in the same run a personal file of bob (home:* sorts before team:*):
	# the team folder must still be deleted in the right context afterwards.
	put bob s8-bob.txt
	age s8-bob.txt 10
	retention_run s8
	local rows bob=gone
	rows=$(logrows s8-team.txt | paste -sd" ")
	in_trash bob s8-bob.txt && bob=trash
	in_files bob s8-bob.txt && bob=present
	if docker exec "$C" test -f "/var/www/html/data/__groupfolders/$gid/files/s8-team.txt"; then
		result FAIL S8 "file in the team folder still there; log: $rows; bob's file: $bob"
	elif gf_trashed s8-team.txt; then
		result PASS S8 "file in the team folder trash bin; log: $rows; bob's file in the same run: $bob"
	else
		result FAIL S8 "file in the team folder PERMANENTLY gone (not in the groupfolders trash bin); log: $rows; bob's file in the same run: $bob"
	fi
}

# Team folder file that a member additionally shares directly with another member:
# the recipient's view must not merely remove the share (B7).
s11() {
	if [[ "$GF_OK" != 1 ]]; then
		result SKIP S11 "groupfolders not available: $GF_WHY"
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
		result FAIL S11 "team folder file still there (only the share removed?); log: $rows"
	elif gf_trashed s11-teamshare.txt; then
		result PASS S11 "shared team folder file in the groupfolders trash bin; log: $rows"
	else
		result FAIL S11 "shared team folder file PERMANENTLY gone; log: $rows"
	fi
}

# Put a file directly into the data directory (<MB> zero bytes) and pick it up via files:scan
mkfile() {
	local user=$1 name=$2 mb=$3
	docker exec "$C" sh -c "head -c $((mb * 1024 * 1024)) /dev/zero > /var/www/html/data/$user/files/$name \
		&& chown www-data:www-data /var/www/html/data/$user/files/$name"
	occ files:scan --path="/$user/files" >/dev/null
}

# Run pending expire commands of files_trashbin (CommandJob from Trashbin::scheduleExpire)
run_expire_jobs() {
	local id
	for id in $(sql "SELECT id FROM oc_jobs WHERE class = ?" 'OC\Command\CommandJob'); do
		occ background-job:execute "$id" --force-execute >/dev/null 2>&1 || true
	done
}

# Quota almost full: after the move, files_trashbin clears the trash bin (expire) until it
# fits in 50 % of the free quota space – files moved to the trash bin would then be gone for good.
# ivan: quota 20 MB, 12 MB not due + 1 MB due + 4 MB due. The small one fits
# (free afterwards 4 MB → 2 MB trash bin), the big one does not (free 8 MB → 4 MB < 1 + 4 MB).
s12() {
	set_sim false
	# skeleton files (~63 MB) removed, otherwise the quota is already full
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
			state=present
		elif in_trash ivan "$f"; then
			state=trash
		else
			state=PERMANENTLY-GONE
			ok=0
		fi
		note+="$f=$state "
	done
	rows=$(logrows s12-big.bin | paste -sd' ')
	in_trash ivan s12-small.bin || { ok=0; note+='(small file not in the trash bin) '; }
	in_files ivan s12-big.bin || ok=0
	[[ "$rows" == *[Qq]uota* ]] || ok=0 # message in the instance's language (tag_language; fresh: en)
	if [[ $ok == 1 ]]; then
		result PASS S12 "quota almost full, after expire: $note; log big: $rows"
	else
		result FAIL S12 "quota almost full, after expire: $note; log big: ${rows:-empty}; log small: $(logrows s12-small.bin | paste -sd' ')"
	fi
}

# occ trashbin:size stores a number, files_trashbin reads text → every move to the
# trash bin throws. Afterwards no file may be permanently missing (previously: one lost per account).
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
			state=present
		elif in_trash "$u" "$f"; then
			state=trash
		else
			state=PERMANENTLY-GONE
			ok=0
		fi
		note+="$u/$f=$state[$(last_status "$f")] "
	done
	if [[ $ok == 1 ]]; then
		result PASS S13 "trashbin_size with the wrong type: $note"
	else
		result FAIL S13 "trashbin_size with the wrong type: $note"
	fi
}

# Versions move along to files_trashbin/versions on deletion and count towards expire.
# oscar: quota 20 MB, 13 MB not due + 1 MB due with 3 × 1 MB versions. Counting only the file:
# free afterwards 7 MB → 3.5 MB > 1 MB, so deleted – then expire measures 4 MB in the
# trash bin and clears the file for good. With versions: 3.5 MB < 4 MB → stays.
s15() {
	set_sim false
	docker exec "$C" sh -c 'rm -rf /var/www/html/data/oscar/files/*'
	occ files:scan --path=/oscar/files >/dev/null
	occ user:setting oscar files quota "20 MB" >/dev/null
	mkfile oscar s15-keep.bin 13
	local i vers rows state
	# Every overwrite creates a version; different mtimes so that no version replaces another
	for i in 1 2 3 4; do
		docker exec "$C" sh -c "head -c 1048576 /dev/urandom | curl -sf -o /dev/null -u 'oscar:$PW' -T - \
			-H 'X-OC-MTime: $((1700000000 + i * 100))' http://localhost/remote.php/dav/files/oscar/s15-doc.bin"
	done
	vers=$(sql "SELECT COUNT(*) FROM oc_filecache WHERE path LIKE 'files_versions/s15-doc.bin.v%'")
	if [[ "${vers:-0}" -lt 3 ]]; then
		result FAIL S15 "precondition: only ${vers:-0} versions created"
		return 0
	fi
	age s15-doc.bin 10
	retention_run s15
	run_expire_jobs
	rows=$(logrows s15-doc.bin | paste -sd' ')
	if in_files oscar s15-doc.bin; then
		state=present
	elif in_trash oscar s15-doc.bin; then
		state=trash
	else
		state=PERMANENTLY-GONE
	fi
	if [[ $state != PERMANENTLY-GONE ]]; then
		result PASS S15 "file with $vers versions at a tight quota, after expire: $state; log: ${rows:-empty}"
	else
		result FAIL S15 "file with $vers versions at a tight quota: $state after expire; log: ${rows:-empty}"
	fi
}

# A copy inherits upload_time/creation_time of the original (Cache::copyFromCache). File IDs above the
# boundary (seen_max_fileid, set on installation/update) count from when they are first seen.
s14() {
	set_sim false
	put erin s14-orig.txt
	age s14-orig.txt 700
	docker exec "$C" curl -sf -o /dev/null -u "erin:$PW" -X COPY \
		-H "Destination: http://localhost/remote.php/dav/files/erin/s14-copy.txt" \
		http://localhost/remote.php/dav/files/erin/s14-orig.txt \
		|| { result FAIL S14 "precondition: WebDAV COPY failed"; return 0; }
	local ext since
	ext=$(sql "SELECT 'upload_time=' || COALESCE(e.upload_time, 'NULL') FROM oc_filecache_extended e WHERE e.fileid = ?" "$(fid s14-copy.txt)")
	since=$(occ config:app:get folder_retention seen_max_fileid 2>/dev/null || echo missing)
	retention_run s14
	if in_files erin s14-copy.txt && in_trash erin s14-orig.txt; then
		result PASS S14 "copy of a 700-day-old file ($ext, seen_max_fileid=$since) stays, original in the trash bin"
	else
		result FAIL S14 "copy: $(in_files erin s14-copy.txt && echo present || echo gone) [$(last_status s14-copy.txt)], original: $(in_trash erin s14-orig.txt && echo trash || echo not in the trash bin) ($ext, seen_max_fileid=$since)"
	fi
}

s10() {
	set_sim false
	local n=600
	info "S10: creating $n due files for heidi"
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
	[[ -n "$job" ]] || { result FAIL S10 "RetentionJob not registered in oc_jobs"; return 0; }
	# force a new cycle
	occ config:app:delete folder_retention last_cycle_completed >/dev/null 2>&1 || true
	occ config:app:delete folder_retention job_cursor >/dev/null 2>&1 || true

	info "S10: job $job in the background, then an occ run in parallel"
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
	# block message of the occ command – only lines outside the result table, otherwise
	# "skipped_locked (Datei ist gesperrt …)" of a single file would count too
	grep -v '^[|+]' "$WORK/s10-occ.txt" \
		| grep -Eiq 'gesperrt|läuft bereits|laeuft bereits|bereits .*(lauf|läuft)|anderer .*lauf|sperre|locked|already running' \
		&& lockmsg=1
	local note="job started=$started done=$job_done, occ exit=$occ_rc, log: ${rows:-empty}, trash=$trashed left=$remain lost=$lost, files logged twice=$dup, lock message=$lockmsg"
	if [[ $started == 0 ]]; then
		result FAIL S10 "job did not start before the occ run – no overlap to check ($note)"
	elif [[ $job_done == 0 ]]; then
		result FAIL S10 "job not done after 300 s ($note)"
	elif [[ "$dup" == 0 && $lost == 0 && $lockmsg == 1 ]]; then
		result PASS S10 "$note"
	else
		result FAIL S10 "$note"
	fi
}

# Same file name in several folders, one run: files_trashbin names the entry
# "<name>.d<time()>" and permanently overwrites an existing target of the same second. All three
# must be in the trash bin, with their own content. With groupfolders also two
# files of the same name in a team folder (whose trash bin names them the same way).
s16() {
	set_sim false
	local d id ids=() gids=() gid=''
	for d in dA dB dC; do
		docker exec "$C" curl -sf -o /dev/null -u "peggy:$PW" -X MKCOL "http://localhost/remote.php/dav/files/peggy/$d"
		put peggy "$d/Bericht.txt"
		id=$(fid_path peggy "$d/Bericht.txt")
		[[ -n "$id" ]] || { result FAIL S16 "precondition: $d/Bericht.txt not in the filecache"; return 0; }
		age_id "$id" 10
		ids+=("$id")
	done
	if [[ "$GF_OK" == 1 ]]; then
		gid=$(gf_create Team16 team16 peggy)
		for d in x y; do
			docker exec "$C" curl -sf -o /dev/null -u "peggy:$PW" -X MKCOL "http://localhost/remote.php/dav/files/peggy/Team16/$d"
			put peggy "Team16/$d/s16-scan.pdf"
			id=$(sql "SELECT fileid FROM oc_filecache WHERE name = 's16-scan.pdf' AND path LIKE ? AND path NOT LIKE '%trash%'" "%/$d/s16-scan.pdf")
			[[ -n "$id" ]] || { result FAIL S16 "precondition: Team16/$d/s16-scan.pdf not in the filecache"; return 0; }
			age_id "$id" 10
			gids+=("$id")
		done
	fi
	retention_run s16
	local trashed contents remain rows want ok=1 note gf=''
	trashed=$(docker exec "$C" sh -c "ls /var/www/html/data/peggy/files_trashbin/files/ 2>/dev/null | grep -c '^Bericht\.txt\.d' || true")
	contents=$(docker exec "$C" sh -c 'cat /var/www/html/data/peggy/files_trashbin/files/Bericht.txt.d* 2>/dev/null' | sort | paste -sd',')
	want='Content dA/Bericht.txt,Content dB/Bericht.txt,Content dC/Bericht.txt'
	remain=$(docker exec "$C" sh -c 'ls /var/www/html/data/peggy/files/dA /var/www/html/data/peggy/files/dB /var/www/html/data/peggy/files/dC 2>/dev/null | grep -c Bericht || true')
	rows=$(sql "SELECT mode || ':' || status || '=' || COUNT(*) FROM oc_folder_retention_log WHERE file_id IN ($(IFS=,; echo "${ids[*]}")) GROUP BY mode, status" | paste -sd' ')
	[[ "$trashed" == 3 && "$contents" == "$want" && "$remain" == 0 && "$rows" == 'real:deleted=3' ]] || ok=0
	if [[ -n "$gid" ]]; then
		local gtrash gdisk grows
		gtrash=$(sql "SELECT COUNT(*) FROM oc_group_folders_trash WHERE name = 's16-scan.pdf'")
		gdisk=$(docker exec "$C" sh -c "find /var/www/html/data/__groupfolders -path '*trash*' -name 's16-scan.pdf.d*' | wc -l")
		grows=$(sql "SELECT mode || ':' || status || '=' || COUNT(*) FROM oc_folder_retention_log WHERE file_id IN ($(IFS=,; echo "${gids[*]}")) GROUP BY mode, status" | paste -sd' ')
		[[ "$gtrash" == 2 && "$gdisk" == 2 && "$grows" == 'real:deleted=2' ]] || ok=0
		gf="; team folder: trash DB=$gtrash disk=$gdisk log=${grows:-empty}"
	else
		gf="; team folder not checked ($GF_WHY)"
	fi
	note="trash=$trashed Bericht.txt.d* [${contents:-empty}], left=$remain, log=${rows:-empty}$gf"
	if [[ $ok == 1 ]]; then
		result PASS S16 "three files with the same name in one run: $note"
	else
		result FAIL S16 "three files with the same name in one run: $note"
	fi
}

# Existing/new boundary in the transition window after the update: the copy is made after the update, but
# before a complete cycle has run under 0.8 (fb4395c: seen_since was missing → copy
# due immediately; in the second run treated as existing). The boundary is the file ID at the update.
s18() {
	set_sim false
	local mark max
	mark=$(occ config:app:get folder_retention seen_max_fileid 2>/dev/null || true)
	put victor s18-orig.txt
	age s18-orig.txt 700
	# simulate "update now": boundary = highest file ID, no cycle completed since
	max=$(sql "SELECT MAX(fileid) FROM oc_filecache")
	occ config:app:set folder_retention seen_max_fileid --value="$max" --type=integer >/dev/null
	occ config:app:delete folder_retention seen_since >/dev/null 2>&1 || true
	occ config:app:delete folder_retention last_cycle_completed >/dev/null 2>&1 || true
	occ config:app:delete folder_retention job_cursor >/dev/null 2>&1 || true
	docker exec "$C" curl -sf -o /dev/null -u "victor:$PW" -X COPY \
		-H "Destination: http://localhost/remote.php/dav/files/victor/s18-copy.txt" \
		http://localhost/remote.php/dav/files/victor/s18-orig.txt \
		|| { result FAIL S18 "precondition: WebDAV COPY failed"; return 0; }
	local ext a b
	ext=$(sql "SELECT 'fileid=' || f.fileid || ' upload_time=' || COALESCE(e.upload_time, 'NULL') FROM oc_filecache f LEFT JOIN oc_filecache_extended e ON e.fileid = f.fileid WHERE f.fileid = ?" "$(fid s18-copy.txt)")
	retention_run s18-a
	a=$(in_files victor s18-copy.txt && echo present || echo gone)
	retention_run s18-b
	b=$(in_files victor s18-copy.txt && echo present || echo gone)
	local note="mark after installation=${mark:-missing}, update mark=$max, copy ($ext): run 1 $a, run 2 $b; original: $(in_trash victor s18-orig.txt && echo trash || echo not in the trash bin); log copy: $(logrows s18-copy.txt | paste -sd' ')"
	if [[ -n "$mark" && $a == present && $b == present ]] && in_trash victor s18-orig.txt; then
		result PASS S18 "$note"
	else
		result FAIL S18 "$note"
	fi
}

# AJAX cron (Nextcloud default): an anonymous call of cron.php with "X-NC-Skip-Trashbin: true"
# must not delete anything permanently and must not block any area; the admin page reports the mode.
s17() {
	set_sim false
	put trent s17-web.txt
	age s17-web.txt 10
	local job before after ran api i rows
	job=$(sql "SELECT id FROM oc_jobs WHERE class = ?" 'OCA\FolderRetention\BackgroundJob\RetentionJob')
	[[ -n "$job" ]] || { result FAIL S17 "RetentionJob not registered in oc_jobs"; return 0; }
	occ config:app:delete folder_retention last_cycle_completed >/dev/null 2>&1 || true
	occ config:app:delete folder_retention job_cursor >/dev/null 2>&1 || true
	before=$(blocks)
	occ background:ajax >/dev/null
	# Only the RetentionJob is due: all others count as just checked
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
	mode=$(grep -o '"cronMode":"[a-z]*"' <<<"$api" || echo "cronMode missing")
	local note="job ran=$([[ "${ran:-0}" -gt 0 ]] && echo yes || echo no), file: $(in_files trent s17-web.txt && echo present || (in_trash trent s17-web.txt && echo trash || echo PERMANENTLY-GONE)), log: ${rows:-empty}, locks unchanged: $([[ "$before" == "$after" ]] && echo yes || echo "no ($after)"), API: $mode"
	if [[ "${ran:-0}" -gt 0 ]] && in_files trent s17-web.txt && [[ -z "$rows" && "$before" == "$after" && "$mode" == '"cronMode":"ajax"' ]]; then
		result PASS S17 "$note"
	else
		result FAIL S17 "$note"
	fi
}

# "Lift block" lifts only the displayed block – one added (or renewed) in the meantime
# stays. Via the admin API and via occ.
s19() {
	local state1 state2 state3 code1 code2 out3
	sql "DELETE FROM oc_folder_retention_block WHERE block_key IN ('it:a', 'it:b')"
	sql "INSERT INTO oc_folder_retention_block (block_key, label, reason, blocked_at) VALUES ('it:a', 'A', 'x', 100), ('it:b', 'B', 'y', 200)"
	api_put() {
		docker exec "$C" curl -s -o /dev/null -w '%{http_code}' -u "admin:$PW" -H 'OCS-APIRequest: true' \
			-H 'Content-Type: application/json' -X PUT -d "$1" http://localhost/index.php/apps/folder_retention/api/settings
	}
	keys() { sql "SELECT '\"' || block_key || '\"' FROM oc_folder_retention_block WHERE block_key IN ('it:a', 'it:b') ORDER BY block_key" | paste -sd' ' || true; }
	# admin has only seen A; B was added later
	code1=$(api_put '{"unblock":[{"key":"it:a","at":100}]}')
	state1=$(keys)
	# stale display of B (different timestamp) – B has been blocked again in the meantime
	code2=$(api_put '{"unblock":[{"key":"it:b","at":150}]}')
	state2=$(keys)
	out3=$(occ folder_retention:run --unblock=it:b 2>&1 | head -n 3 | paste -sd' ' || true)
	state3=$(keys)
	sql "DELETE FROM oc_folder_retention_block WHERE block_key IN ('it:a', 'it:b')"
	local note="API lift A: HTTP $code1 → left [${state1:-none}]; API B with an old timestamp: HTTP $code2 → left [${state2:-none}]; occ --unblock=it:b → left [${state3:-none}] ($out3)"
	if [[ "$code1" == 200 && "$state1" == '"it:b"' && "$code2" == 200 && "$state2" == '"it:b"' && -z "$state3" ]]; then
		result PASS S19 "$note"
	else
		result FAIL S19 "$note"
	fi
}

# A block from another process applies immediately: process P (like cron.php, which works through other
# jobs before this job) has already read the block list, then Q (occ run with permanent
# deletion) sets a block. P must see it and must not lose anyone else's block with its own.
# Also: blocks from the app config (pre-0.8.0 stage) move into the table.
s20() {
	sql "DELETE FROM oc_folder_retention_block WHERE block_key LIKE 'it:s20%'"
	docker exec "$C" rm -f /tmp/s20-ready /tmp/s20-go
	docker exec -u www-data "$C" php /tmp/fret-proc.php wait it:s20c it:s20d > "$WORK/s20-p.txt" 2>&1 &
	local pid=$! i
	for i in $(seq 1 100); do
		docker exec "$C" test -f /tmp/s20-ready && break
		sleep 0.2
	done
	docker exec "$C" test -f /tmp/s20-ready || { wait "$pid" || true; result FAIL S20 "process P not ready: $(tail -n3 "$WORK/s20-p.txt" | paste -sd' ')"; return 0; }
	docker exec -u www-data "$C" php /tmp/fret-proc.php block it:s20c 200 > "$WORK/s20-q.txt" 2>&1
	docker exec "$C" touch /tmp/s20-go
	wait "$pid" || true
	local p rows legacy mig cfg
	p=$(grep -o 'before=[01] after=[01]' "$WORK/s20-p.txt" || echo "P: $(tail -n2 "$WORK/s20-p.txt" | paste -sd' ')")
	rows=$(blocks 'it:s20%')
	# legacy entry in the app config: taken over on the next access and removed there
	occ config:app:set folder_retention blocked_roots --value='{"it:s20legacy":{"label":"Alt","reason":"vor 0.8","at":50}}' >/dev/null
	occ folder_retention:run --unblock=it:s20-does-not-exist > "$WORK/s20-legacy.txt" 2>&1 || true
	legacy=$(blocks 'it:s20legacy')
	cfg=$(occ config:app:get folder_retention blocked_roots 2>/dev/null || echo removed)
	mig=$(grep -c 'it:s20legacy' "$WORK/s20-legacy.txt" || true)
	sql "DELETE FROM oc_folder_retention_block WHERE block_key LIKE 'it:s20%'"
	docker exec "$C" rm -f /tmp/s20-ready /tmp/s20-go
	local note="P before/after another lock: $p; locks afterwards: [${rows:-none}]; legacy: table [${legacy:-missing}], app config $cfg, occ shows it $mig×"
	if [[ "$p" == 'before=0 after=1' && "$rows" == 'it:s20c@200 it:s20d@300' && "$legacy" == 'it:s20legacy@50' && "$cfg" == removed && "$mig" -ge 1 ]]; then
		result PASS S20 "$note"
	else
		result FAIL S20 "$note"
	fi
}

# Long names: files_trashbin truncates "<name>.d<time>" over 250 bytes in the middle. Two files that
# differ only in the cut-out part would get the same trash bin name within the same second
# – the first would be gone for good. Both must be in the trash bin with their own content.
s21() {
	set_sim false
	local a b ida idb
	a="$(printf 'a%.0s' $(seq 1 122))1$(printf 'b%.0s' $(seq 1 118)).txt"
	b="$(printf 'a%.0s' $(seq 1 122))2$(printf 'b%.0s' $(seq 1 118)).txt"
	put walter "$a"
	put walter "$b"
	ida=$(fid_path walter "$a")
	idb=$(fid_path walter "$b")
	[[ -n "$ida" && -n "$idb" ]] || { result FAIL S21 "precondition: long files not in the filecache"; return 0; }
	age_id "$ida" 10
	age_id "$idb" 10
	retention_run s21
	local names contents fc rows want ok=1
	names=$(docker exec "$C" sh -c "ls /var/www/html/data/walter/files_trashbin/files/ 2>/dev/null | grep -c '^aaaa' || true")
	contents=$(docker exec "$C" sh -c 'cat /var/www/html/data/walter/files_trashbin/files/aaaa* 2>/dev/null' | sed 's/^Content a*\([12]\)b*\.txt$/Content \1/' | sort | paste -sd',')
	fc=$(sql "SELECT COUNT(*) FROM oc_filecache WHERE fileid IN (?, ?) AND path LIKE 'files_trashbin/files/%'" "$ida" "$idb")
	rows=$(sql "SELECT mode || ':' || status || '=' || COUNT(*) FROM oc_folder_retention_log WHERE file_id IN (?, ?) GROUP BY mode, status" "$ida" "$idb" | paste -sd' ')
	want='Content 1,Content 2'
	[[ "$names" == 2 && "$contents" == "$want" && "$fc" == 2 && "$rows" == 'real:deleted=2' ]] || ok=0
	local note="trash entries=$names [${contents:-empty}], in the filecache as trash=$fc, log=${rows:-empty}"
	if [[ $ok == 1 ]]; then
		result PASS S21 "two long names shortened alike in one run: $note"
	else
		result FAIL S21 "two long names shortened alike in one run: $note"
	fi
}

# Two runs back to back (e.g. "occ …run --rule=3; occ …run --rule=4", or the job
# takes over the block just released): run 2 may move a file of the same name still
# within the same second as run 1 moved its last one. files_trashbin would then permanently overwrite the entry from
# run 1 – run 2's memory does not know it, only the filecache does.
s22() {
	set_sim false
	local d id ids=() out
	for d in s22A s22B; do
		docker exec "$C" curl -sf -o /dev/null -u "rupert:$PW" -X MKCOL "http://localhost/remote.php/dav/files/rupert/$d"
		put rupert "$d/Protokoll.pdf"
		id=$(fid_path rupert "$d/Protokoll.pdf")
		[[ -n "$id" ]] || { result FAIL S22 "precondition: $d/Protokoll.pdf not in the filecache"; return 0; }
		ids+=("$id")
	done
	out=$(docker exec -u www-data "$C" php /tmp/fret-proc.php samesecond "${ids[0]}" "${ids[1]}" 2>&1 | tail -n1)
	local trashed contents remain want same ok=1
	trashed=$(docker exec "$C" sh -c "ls /var/www/html/data/rupert/files_trashbin/files/ 2>/dev/null | grep -c '^Protokoll\.pdf\.d' || true")
	contents=$(docker exec "$C" sh -c 'cat /var/www/html/data/rupert/files_trashbin/files/Protokoll.pdf.d* 2>/dev/null' | sort | paste -sd',')
	want='Content s22A/Protokoll.pdf,Content s22B/Protokoll.pdf'
	remain=$(docker exec "$C" sh -c 'ls /var/www/html/data/rupert/files/s22A /var/www/html/data/rupert/files/s22B 2>/dev/null | grep -c Protokoll || true')
	# Did the test really create the situation (run 2 starts in the second in which run 1 ended)?
	same=no
	[[ "$out" =~ aEnd=([0-9]+)\ bStart=([0-9]+) && "${BASH_REMATCH[1]}" == "${BASH_REMATCH[2]}" ]] && same=yes
	[[ "$out" == 'a=deleted b=deleted '* && "$trashed" == 2 && "$contents" == "$want" && "$remain" == 0 ]] || ok=0
	local note="$out; run 2 started in the same second: $same; trash=$trashed Protokoll.pdf.d* [${contents:-empty}], left=$remain"
	if [[ $ok == 1 ]]; then
		result PASS S22 "same-named file across the run boundary: $note"
	else
		result FAIL S22 "same-named file across the run boundary: $note"
	fi
}

# Restore long after the deletion: the retention period counts from the first time it is seen afterwards
s28() {
	set_sim false
	put dave s28-late.txt
	age s28-late.txt 30
	retention_run s28-a
	local id
	id=$(sql "SELECT file_id FROM oc_folder_retention_log WHERE path LIKE ? AND mode = 'real' AND status = 'deleted'" "%s28-late.txt" | tail -n1)
	if [[ -z "$id" ]] || ! in_trash dave s28-late.txt; then
		result FAIL S28 "precondition: first run did not move to the trash bin (log: $(logrows s28-late.txt | paste -sd' '))"
		return 0
	fi
	# deletion 10 days ago (rule 1 day), restored today
	sql "UPDATE oc_folder_retention_log SET deleted_at = ? WHERE file_id = ?" "$(ago 10)" "$id"
	occ trashbin:restore dave > "$WORK/s28-restore.txt" 2>&1
	if ! in_files dave s28-late.txt; then
		result FAIL S28 "restoring via occ trashbin:restore failed"
		return 0
	fi
	local before seen1 stays=no again=no
	before=$(now)
	retention_run s28-b
	seen1=$(sql "SELECT first_seen FROM oc_folder_retention_seen WHERE file_id = ?" "$id")
	in_files dave s28-late.txt && stays=yes
	# due again two days after the restore: retention period from the restore, not "never"
	seen "$id" "$(ago 2)"
	retention_run s28-c
	in_trash dave s28-late.txt && ! in_files dave s28-late.txt && again=yes
	local note="stays after restore: $stays (first_seen ${seen1:-missing}, run start $before); in the trash bin 2 days later: $again; log: $(logrows s28-late.txt | paste -sd' ')"
	if [[ $stays == yes && -n "$seen1" && "$seen1" -ge "$before" && $again == yes ]]; then
		result PASS S28 "restore 10 days after deletion: $note"
	else
		result FAIL S28 "restore 10 days after deletion: $note"
	fi
}

# Exception in the trash bin, then a second run in the same process (background-job:worker)
s29() {
	set_sim false
	put erin s29-worker.txt
	age s29-worker.txt 10
	local id out where
	id=$(fid s29-worker.txt)
	[[ -n "$id" ]] || { result FAIL S29 "precondition: s29-worker.txt not in the filecache"; return 0; }
	out=$(docker exec -u www-data "$C" php /tmp/fret-proc.php trashthrow "$id" 2>&1 | tail -n1)
	if in_files erin s29-worker.txt; then where=stays; elif in_trash erin s29-worker.txt; then where=trash; else where=PERMANENTLY-GONE; fi
	if [[ "$out" == 'a=error b=error '* && ( "$out" == *Prozessneustart* || "$out" == *'process restart'* ) && $where == stays ]]; then
		result PASS S29 "trash bin exception, second run in the same process: $out; file $where"
	else
		result FAIL S29 "trash bin exception, second run in the same process: $out; file $where"
	fi
}

# Simulation mode logs again when the evaluation changes (legacy entry from 0.7.x, retention period)
s30() {
	set_sim true
	put alice s30-sim.txt
	age s30-sim.txt 10
	retention_run s30-a
	local n1 n2 n3 n4 n5 rows
	n1=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	# entry as 0.7.x wrote it: reference mtime, different date
	sql "UPDATE oc_folder_retention_log SET reference_source = 'mtime', reference_date = ? WHERE path LIKE ? AND mode = 'simulation'" "$(ago 400)" "%s30-sim.txt"
	retention_run s30-b
	n2=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	retention_run s30-c
	n3=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	# retention period change on the same rule (same rule ID, new retention period)
	set_default_rules day 2
	retention_run s30-d
	n4=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	retention_run s30-e
	n5=$(logrows s30-sim.txt | grep -c '^simulation:would_delete' || true)
	set_default_rules day 1
	set_sim false
	rows=$(sql "SELECT rule_id || '/' || COALESCE(rule_label, '') || '/' || reference_source FROM oc_folder_retention_log
		WHERE path LIKE ? ORDER BY id" "%s30-sim.txt" | paste -sd' ')
	local note="entries after run 1..5: $n1 $n2 $n3 $n4 $n5 (expected 1 2 2 3 3); $rows"
	if [[ "$n1 $n2 $n3 $n4 $n5" == "1 2 2 3 3" ]] && in_files alice s30-sim.txt; then
		result PASS S30 "$note"
	else
		result FAIL S30 "$note"
	fi
}

# Last: changes the trash bin availability instance-wide
s6() {
	set_sim false
	occ group:add trash-users >/dev/null
	occ group:adduser trash-users alice >/dev/null
	put grace s6-notrash.txt
	age s6-notrash.txt 10
	# occ app:enable --groups refuses this for filesystem apps – the admin UI/old
	# configurations can still carry the value, so set it directly
	occ config:app:set files_trashbin enabled --value='["trash-users"]' >/dev/null
	retention_run s6
	occ config:app:set files_trashbin enabled --value=yes >/dev/null
	local st
	st=$(logrows s6-notrash.txt | paste -sd' ')
	if in_files grace s6-notrash.txt && [[ "$st" != *:deleted* ]]; then
		result PASS S6 "trash bin only for a group, owner not in it: file stays; log: ${st:-empty}"
	elif in_trash grace s6-notrash.txt; then
		result PASS S6 "trash bin only for a group: file in the owner's trash bin anyway; log: ${st:-empty}"
	elif in_files grace s6-notrash.txt; then
		result FAIL S6 "file still there, but the log reports it deleted: $st"
	else
		result FAIL S6 "trash bin only for a group: file PERMANENTLY deleted; log: ${st:-empty}"
	fi
}

# ---------------------------------------------------------------- separate instances (S23, S24)

# Before the first start: no App Store (not even on occ upgrade), no skeleton files
prep_side() {
	printf '%s\n' '<?php' '// Harness: own instance without app store and without skeleton files' \
		"\$CONFIG = ['appstoreenabled' => false, 'has_internet_connection' => false, 'skeletondirectory' => ''];" \
		> "$WORK/side.config.php"
	docker cp "$WORK/side.config.php" "$C:/usr/src/nextcloud/config/fret-side.config.php"
}

# primary storage = object store (FretDirObjectStore, objects as files – without S3)
prep_objectstore() {
	prep_side
	docker cp "$HERE/objectstore/FretDirObjectStore.php" "$C:/usr/src/nextcloud/lib/private/Files/ObjectStore/FretDirObjectStore.php"
	docker cp "$HERE/objectstore/objectstore.config.php" "$C:/usr/src/nextcloud/config/objectstore.config.php"
}

# Separate throwaway instance for a scenario; the caller sets "local C=…" beforehand (helpers read $C)
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

# Upload <bytes> zero bytes via WebDAV (object store: not possible via the disk)
put_bytes() {
	docker exec "$C" sh -c "head -c $3 /dev/zero | curl -sf -o /dev/null -u '$1:$PW' -T - \
		http://localhost/remote.php/dav/files/$1/$2"
}

# State of a file according to the filecache (object store has no files under data/<account>/)
obj_state() {
	local storage
	storage=$(sql "SELECT numeric_id FROM oc_storages WHERE id = ?" "object::user:$1")
	if [[ -n "$(sql "SELECT fileid FROM oc_filecache WHERE storage = ? AND path = ?" "$storage" "files/$2")" ]]; then
		echo present
	elif [[ -n "$(sql "SELECT fileid FROM oc_filecache WHERE storage = ? AND path LIKE ?" "$storage" "files_trashbin/files/$2.d%")" ]]; then
		echo trash
	else
		echo PERMANENTLY-GONE
	fi
}

# Object store as primary storage: for clearing the trash bin Nextcloud computes the size of the
# account root – there including trash bin and versions (normal cache instead of HomeCache). olga:
# quota 10 MB, 3 MB not due, 1.4 MB already in the trash bin, 2 MB due. Counting only files/
# the file fits (7 MB free → 3.5 MB > 3.4 MB); with the root (6.7 MB) it does not – expire would clear it.
s23() {
	local C="$C-os"
	side_nc prep_objectstore
	install_app
	local kind
	kind=$(sql "SELECT COUNT(*) FROM oc_storages WHERE id LIKE 'object::%'")
	if [[ "${kind:-0}" == 0 ]]; then
		result FAIL S23 "precondition: no object store as primary storage (oc_storages without object::)"
		return 0
	fi
	side_user olga
	occ user:setting olga files quota "10 MB" >/dev/null
	put_bytes olga s23-keep.bin 3145728
	put_bytes olga s23-old.bin 1468006
	docker exec "$C" curl -sf -o /dev/null -u "olga:$PW" -X DELETE http://localhost/remote.php/dav/files/olga/s23-old.bin \
		|| { result FAIL S23 "precondition: deleting s23-old.bin failed"; return 0; }
	put_bytes olga s23-target.bin 2097152
	prepare_rules
	set_sim false
	age s23-target.bin 10
	local storage sizes
	storage=$(sql "SELECT numeric_id FROM oc_storages WHERE id = ?" 'object::user:olga')
	sizes=$(sql "SELECT CASE path WHEN '' THEN 'root' ELSE path END || '=' || size FROM oc_filecache WHERE storage = ? AND path IN ('', 'files', 'files_trashbin') ORDER BY path" "$storage" | paste -sd' ')
	retention_run s23
	occ trashbin:expire olga >/dev/null 2>&1 || true
	run_expire_jobs
	local target old rows
	target=$(obj_state olga s23-target.bin)
	old=$(obj_state olga s23-old.bin)
	rows=$(logrows s23-target.bin | paste -sd' ')
	local note="object store, quota 10 MB ($sizes), after run + expire: target=$target, old trash entry=$old; log: ${rows:-empty}"
	if [[ $target == PERMANENTLY-GONE ]]; then
		result FAIL S23 "$note"
	elif [[ $target == trash || "$rows" == *[Qq]uota* ]]; then
		result PASS S23 "$note"
	else
		result FAIL S23 "$note (file still there, but the log names no quota)"
	fi
}

# Update from the early 0.8.0 state (OLD_REV, boundary "seen_since" as a timestamp) to the working tree:
# a copy from the 0.8.0 era (inherits the upload time of the 400-day-old original, first
# seen after seen_since) was protected there and must stay protected after the update.
s24() {
	local C="$C-up" old="$WORK/app-$OLD_REV"
	mkdir -p "$old"
	git -C "$REPO" archive "$OLD_REV" | tar -x -C "$old" \
		|| { result FAIL S24 "precondition: git archive $OLD_REV failed"; return 0; }
	# raise the starting state to APP_SRC's max-version only in this copy – otherwise
	# OLD_REV (max-version 34) cannot even be installed on e.g. NC 35
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
	retention_run s24-a # simulation mode: cycle complete → seen_since
	local since
	since=$(occ config:app:get folder_retention seen_since 2>/dev/null || true)
	[[ -n "$since" ]] || { result FAIL S24 "precondition: $OLD_REV did not set seen_since"; return 0; }
	sleep 2
	docker exec "$C" curl -sf -o /dev/null -u "uwe:$PW" -X COPY \
		-H "Destination: http://localhost/remote.php/dav/files/uwe/s24-kopie.txt" \
		http://localhost/remote.php/dav/files/uwe/s24-orig.txt \
		|| { result FAIL S24 "precondition: WebDAV COPY failed"; return 0; }
	retention_run s24-b # records "first seen" for the copy
	local id seen pre
	id=$(fid s24-kopie.txt)
	seen=$(sql "SELECT first_seen FROM oc_folder_retention_seen WHERE file_id = ?" "$id")
	pre=$(occ folder_retention:run --dry-run 2>&1 | grep -c 's24-kopie' || true)
	if [[ -z "$seen" || "$seen" -le "$since" || "$pre" != 0 ]]; then
		result FAIL S24 "precondition under $OLD_REV: seen_since=$since, copy first_seen=${seen:-missing}, due in the dry run: $pre"
		return 0
	fi
	install_app "$APP_SRC" copy-only
	occ upgrade >"$WORK/s24-upgrade.txt" 2>&1 || { result FAIL S24 "occ upgrade failed: $(tail -n2 "$WORK/s24-upgrade.txt" | paste -sd' ')"; return 0; }
	local mark left version
	version=$(occ app:list --output=json | grep -o '"folder_retention":"[^"]*"')
	mark=$(occ config:app:get folder_retention seen_max_fileid 2>/dev/null || echo missing)
	left=$(occ config:app:get folder_retention seen_since 2>/dev/null || echo deleted)
	set_sim false
	retention_run s24-c
	local kopie orig
	kopie=$(in_files uwe s24-kopie.txt && echo present || (in_trash uwe s24-kopie.txt && echo trash || echo gone))
	orig=$(in_trash uwe s24-orig.txt && echo trash || (in_files uwe s24-orig.txt && echo present || echo gone))
	local note="$version, seen_since=$since → $left, seen_max_fileid=$mark, copy fileid=$id first_seen=$seen: $kopie [$(last_status s24-kopie.txt)], original: $orig"
	if [[ $kopie == present && $orig == trash && $left == deleted && "$mark" =~ ^[0-9]+$ && "$mark" -lt "$id" ]]; then
		result PASS S24 "$note"
	else
		result FAIL S24 "$note"
	fi
}

# Team folder with carol and bob: Nextcloud names the account through which the deletion happens as the deleter
# (trash bin "deleted_by", activity). It must consistently be the first member in sorted order
# (bob) – even if carol's own file was deleted earlier in the same run (context carol) –
# and the app's log must name it.
s25() {
	if [[ "$GF_OK" != 1 ]]; then
		result SKIP S25 "groupfolders not available: $GF_WHY"
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
	by=$(sql "SELECT deleted_by FROM oc_group_folders_trash WHERE name = 's25-team.txt'" 2>/dev/null || echo 'column missing')
	gf_trashed s25-team.txt || ok=0
	in_trash carol s25-carol.txt || ok=0
	# message in the instance's language (tag_language; fresh installation: en)
	[[ "$team" == 'real:deleted ('*'über Konto bob '*'gelöscht hat folder_retention'* || "$team" == 'real:deleted ('*'via account bob '*'folder_retention deleted it'* ]] || ok=0
	[[ "$own" == 'real:deleted ('*'über Konto carol '* || "$own" == 'real:deleted ('*'via account carol '* ]] || ok=0
	[[ "$by" == bob || "$by" == 'column missing' ]] || ok=0
	local note="team folder trash bin \"deleted by\": ${by:-empty}; log team: ${team:-empty}; log carol: ${own:-empty}"
	if [[ $ok == 1 ]]; then
		result PASS S25 "$note"
	else
		result FAIL S25 "$note"
	fi
}

# occ app:remove (without --keep-data) with simulation mode off, then reinstall:
# Nextcloud leaves the app config and rules in place (INSTALL.md §9) – the uninstall step must
# turn simulation mode on, otherwise the reinstall would delete right away according to the old retention periods.
s26() {
	local C="$C-rm"
	side_nc
	install_app
	side_user uma
	prepare_rules
	set_sim false
	local rules_before rules_after
	rules_before=$(sql "SELECT COALESCE(target, 'general') || '=' || COALESCE(period_value, '') || period_unit FROM oc_folder_retention_rules WHERE folder_id IS NULL ORDER BY id" | paste -sd' ')
	occ app:remove folder_retention >"$WORK/s26-remove.txt" 2>&1 \
		|| { result FAIL S26 "occ app:remove failed: $(tail -n2 "$WORK/s26-remove.txt" | paste -sd' ')"; return 0; }
	if docker exec "$C" test -d /var/www/html/custom_apps/folder_retention; then
		result FAIL S26 "precondition: app folder still there after app:remove"
		return 0
	fi
	install_app
	local sim
	sim=$(occ config:app:get folder_retention simulation_mode 2>/dev/null || echo missing)
	rules_after=$(sql "SELECT COALESCE(target, 'general') || '=' || COALESCE(period_value, '') || period_unit FROM oc_folder_retention_rules WHERE folder_id IS NULL ORDER BY id" | paste -sd' ')
	put uma s26-due.txt
	age s26-due.txt 10
	retention_run s26
	local st where=gone
	st=$(last_status s26-due.txt)
	in_files uma s26-due.txt && where=present
	in_trash uma s26-due.txt && where=trash
	local note="app:remove: $(grep -o 'uninstall steps executed' "$WORK/s26-remove.txt" || echo 'without uninstall steps'); after reinstalling simulation_mode=$sim, rules $rules_before → $rules_after (kept as INSTALL.md says), due file: $where [${st:-no log}]"
	if [[ ( "$sim" == 1 || "$sim" == true ) && $where == present && "$st" == simulation:would_delete* && "$rules_after" == "$rules_before" ]]; then
		result PASS S26 "$note"
	else
		result FAIL S26 "$note"
	fi
}

# "Clean up completely" with exactly the SQL block from INSTALL.md §9, then reinstall:
# tables back, simulation mode on, both default rules "never", occ run without SQL errors.
s27() {
	local C="$C-rs" doc="$APP_SRC/INSTALL.md"
	[[ -f "$doc" ]] || doc="$REPO/INSTALL.md"
	local stmts
	stmts=$(awk '/^## 9\. /{s=1} s && /^```sql/{b=1; next} b && /^```/{exit} b && NF' "$doc")
	[[ -n "$stmts" ]] || { result FAIL S27 "precondition: no SQL block in INSTALL.md §9"; return 0; }
	side_nc
	install_app
	side_user ulla
	prepare_rules
	set_sim false
	occ app:remove folder_retention >"$WORK/s27-remove.txt" 2>&1 \
		|| { result FAIL S27 "occ app:remove failed: $(tail -n2 "$WORK/s27-remove.txt" | paste -sd' ')"; return 0; }
	local line n=0
	while IFS= read -r line; do
		sql "${line%;}" || { result FAIL S27 "SQL from INSTALL.md fails: $line"; return 0; }
		n=$((n + 1))
	done <<< "$stmts"
	install_app
	local tables sim rules dry rc=0
	tables=$(sql "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'oc_folder_retention_%'")
	sim=$(occ config:app:get folder_retention simulation_mode 2>/dev/null || echo missing)
	put ulla s27-old.txt
	age s27-old.txt 400
	dry=$(occ folder_retention:run --dry-run 2>&1) || rc=$?
	rules=$(sql "SELECT COALESCE(target, 'general') || '=' || period_unit || COALESCE(period_value, '')
		FROM oc_folder_retention_rules WHERE folder_id IS NULL ORDER BY id" | paste -sd' ')
	local note="$n statements from INSTALL.md; afterwards tables=$tables, simulation_mode=$sim, rules: ${rules:-none}, dry run rc=$rc$( [[ "$dry" == *s27-old* ]] && echo ', 400-day-old file due' )"
	if [[ "$tables" == 5 && ( "$sim" == 1 || "$sim" == true ) && "$rules" =~ ^general=never\ personal=never$ && $rc == 0 && "$dry" != *s27-old* ]]; then
		result PASS S27 "$note"
	else
		result FAIL S27 "$note; dry run: $(echo "$dry" | tail -n2 | paste -sd' ')"
	fi
}

# Team folder with groupfolders encryption (master key): groupfolders COPIES the file into
# the trash bin – there it has a new file ID, oc_group_folders_trash names the old one.
# The app must record this as "deleted", not as "deleted_final" with a block. In the same
# run a personal file (same storage → rename, ID stays).
s31() {
	if [[ "$GF_OK" != 1 ]]; then
		result SKIP S31 "groupfolders not available: $GF_WHY"
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
	local note="encryption (master key) in the team folder: fileid $old → trash ID ${trashid:-missing} (oc_group_folders_trash.file_id=${gfrow:-missing}); log team: ${team:-empty}; log home: ${home:-empty}; locks: ${blk:-none}"
	if [[ $ok == 1 ]]; then
		result PASS S31 "$note"
	else
		result FAIL S31 "$note"
	fi
}

# S32 log overview: days, folders and folder filter (direct parent folder, LIKE characters in the
# name) against the real database. notLike() does not append ESCAPE on SQLite – the filter must
# not rely on it.
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
	local p1 p2 p3 root folders unbounded days ok=1
	p1=$(paths 'S32%20100%25_%5Bx%5D')
	p2=$(paths 'S32%20100%25_%5Bx%5D%2Fsub')
	p3=$(paths 'S32%201000x%5Bx%5D')
	root=$(api 'log?folder=' | grep -o '"path":"s32-loose.txt"' || true)
	folders=$(api "log/folders?search=S32&from=$((at - 3600))&to=$((at + 3600))" | sed -E 's/,"root":"[^"]*"|,"superseded":(true|false)//g' | grep -o '"folder":"[^"]*","total":[0-9]*' | tr '\n' ' ' || true)
	# without a time range the count would read the whole log – rejected
	unbounded=$(docker exec "$C" curl -s -o /dev/null -w '%{http_code}' -u "admin:$PW" -H 'OCS-APIRequest: true' "http://localhost/index.php/apps/folder_retention/api/log/folders?search=S32")
	days=$(api 'log/days?search=s32' | grep -o '"total":[0-9]*,"counts"' | head -1)
	[[ "$p1" == "S32 100%_[x]/a.txt|" ]] || ok=0
	[[ "$p2" == "S32 100%_[x]/sub/b.txt|" ]] || ok=0
	[[ "$p3" == "S32 1000x[x]/c.txt|" ]] || ok=0
	[[ -n "$root" ]] || ok=0
	[[ "$folders" == *'"folder":"S32 100%_[x]","total":1'* && "$folders" == *'"folder":"S32 100%_[x]\/sub","total":1'* ]] || ok=0
	[[ "$days" == '"total":4,"counts"' ]] || ok=0
	[[ "$unbounded" == 400 ]] || ok=0
	sql "DELETE FROM oc_folder_retention_log WHERE rule_label = 's32'"
	local note="folder filter: [${p1}] [${p2}] [${p3}], without folder: ${root:-missing}; folders: ${folders}; day: ${days:-empty}; without time range: HTTP ${unbounded}"
	if [[ $ok == 1 ]]; then
		result PASS S32 "$note"
	else
		result FAIL S32 "$note"
	fi
}

# S33 log retention: after a completed cycle the job removes log entries older than the retention
# period (default 365 days) – except real deletions of files that still exist (trash bin or
# restored; the restore logic needs them) – and "first seen" entries of files that are gone.
s33() {
	set_sim true
	put walter s33-kept.txt
	local live old recent
	live=$(fid s33-kept.txt)
	old=$(ago 400)
	recent=$(ago 1)
	log33() {
		sql "INSERT INTO oc_folder_retention_log (file_id, storage_id, path, rule_label, reference_date, reference_source, deleted_at, mode, status) VALUES (?, 1, ?, 's33', 1, 'upload', ?, ?, ?)" "$1" "$2" "$3" "$4" "$5"
	}
	log33 990101 s33-old-sim.txt "$old" simulation would_delete
	log33 "$live" s33-old-deleted-exists.txt "$old" real deleted
	log33 990102 s33-old-deleted-gone.txt "$old" real deleted
	log33 990103 s33-recent-error.txt "$recent" real error
	seen 990104 "$old"
	seen "$live" "$old"

	local job
	job=$(sql "SELECT id FROM oc_jobs WHERE class = ?" 'OCA\FolderRetention\BackgroundJob\RetentionJob')
	occ config:app:delete folder_retention last_cycle_completed >/dev/null 2>&1 || true
	occ config:app:delete folder_retention job_cursor >/dev/null 2>&1 || true
	occ background-job:execute "$job" --force-execute > "$WORK/s33.txt" 2>&1 || true

	local left orphan liveSeen ok=1
	left=$(sql "SELECT path FROM oc_folder_retention_log WHERE rule_label = 's33' ORDER BY path" | paste -sd' ')
	orphan=$(sql "SELECT COUNT(*) FROM oc_folder_retention_seen WHERE file_id = 990104")
	liveSeen=$(sql "SELECT COUNT(*) FROM oc_folder_retention_seen WHERE file_id = ?" "$live")
	[[ "$left" == "s33-old-deleted-exists.txt s33-recent-error.txt" ]] || ok=0
	[[ "$orphan" == 0 && "$liveSeen" == 1 ]] || ok=0
	sql "DELETE FROM oc_folder_retention_log WHERE rule_label = 's33'"
	local note="left in the log: [$left]; first seen: orphan $orphan, existing file $liveSeen"
	if [[ $ok == 1 ]]; then
		result PASS S33 "$note"
	else
		result FAIL S33 "$note; job output: $(tail -n3 "$WORK/s33.txt" | paste -sd' ')"
	fi
}

# S34 deleting an account removes its log entries (home storage) and its workspace marking;
# entries of other storages stay.
s34() {
	side_user zoe
	put zoe s34-file.txt
	local storage
	storage=$(sql "SELECT numeric_id FROM oc_storages WHERE id = ?" "home::zoe")
	[[ -n "$storage" ]] || { result FAIL S34 "precondition: home storage of zoe missing"; return 0; }
	sql "INSERT INTO oc_folder_retention_log (file_id, storage_id, path, rule_label, reference_date, reference_source, deleted_at, mode, status) VALUES (990201, ?, 'Persönlich · zoe/s34-file.txt', 's34', 1, 'upload', ?, 'simulation', 'would_delete')" "$storage" "$(now)"
	sql "INSERT INTO oc_folder_retention_log (file_id, storage_id, path, rule_label, reference_date, reference_source, deleted_at, mode, status) VALUES (990202, ?, 'other/s34-other.txt', 's34', 1, 'upload', ?, 'simulation', 'would_delete')" "$((storage + 100000))" "$(now)"
	occ config:app:set folder_retention workspace_accounts --value='["zoe"]' >/dev/null
	occ user:delete zoe > "$WORK/s34.txt" 2>&1 || true

	local left ws
	left=$(sql "SELECT path FROM oc_folder_retention_log WHERE rule_label = 's34' ORDER BY path" | paste -sd' ')
	ws=$(occ config:app:get folder_retention workspace_accounts 2>/dev/null || true)
	sql "DELETE FROM oc_folder_retention_log WHERE rule_label = 's34'"
	local note="left in the log: [$left], workspace accounts: $ws"
	if [[ "$left" == "other/s34-other.txt" && "$ws" != *zoe* ]]; then
		result PASS S34 "$note"
	else
		result FAIL S34 "$note; user:delete: $(tail -n2 "$WORK/s34.txt" | paste -sd' ')"
	fi
}

# S35 repeated reports update the existing entry (findRepeat/touch on the real database, message
# NULL), and the optional deletion limit halts deletion until it is resumed.
s35() {
	local rep
	rep=$(docker exec -u www-data "$C" php /tmp/fret-proc.php repeat 990301 2>&1 | tail -n1)
	sql "DELETE FROM oc_folder_retention_log WHERE rule_label = 's35'"

	set_sim false
	retention_run s35-pre # whatever is still due from earlier scenarios, without a limit
	side_user yuri
	local n
	for n in 1 2 3; do
		put yuri "s35-$n.txt"
		age "s35-$n.txt" 10
	done
	occ config:app:set folder_retention deletion_limit --value=2 --type=integer >/dev/null
	retention_run s35-a
	local first halt
	first=$(for n in 1 2 3; do in_trash yuri "s35-$n.txt" && echo -n T || echo -n F; done)
	halt=$(occ config:app:get folder_retention deletion_halt 2>/dev/null || true)
	retention_run s35-b
	local still
	still=$(for n in 1 2 3; do in_trash yuri "s35-$n.txt" && echo -n T || echo -n F; done)
	occ config:app:delete folder_retention deletion_halt >/dev/null
	occ config:app:delete folder_retention cycle_deleted >/dev/null 2>&1 || true
	retention_run s35-c
	local resumed
	resumed=$(for n in 1 2 3; do in_trash yuri "s35-$n.txt" && echo -n T || echo -n F; done)
	occ config:app:delete folder_retention deletion_limit >/dev/null
	occ config:app:delete folder_retention deletion_halt >/dev/null 2>&1 || true

	local note="repeat: $rep; limit 2: run 1 $first, halt ${halt:-missing}, run 2 $still, after resuming $resumed"
	if [[ "$rep" == "same=id other=- moved=1" && "$first" == TTF && -n "$halt" && "$still" == TTF && "$resumed" == TTT ]]; then
		result PASS S35 "$note"
	else
		result FAIL S35 "$note; output run 1: $(tail -n2 "$WORK/s35-a.txt" | paste -sd' ')"
	fi
}

# S36 area key in the log: two areas with the same name stay separate groups, a real run writes the
# key, the CSV export streams with download headers, and the migration step for an existing
# installation (occ migrations:execute, used on prod without occ upgrade) runs without error.
s36() {
	local at
	at=$(( $(now) - 60 ))
	sql "INSERT INTO oc_folder_retention_log (file_id, storage_id, path, rule_label, reference_date, reference_source, deleted_at, mode, status, root_key) VALUES (990401, 1, 'S36 Archive/a.txt', 's36', 1, 'upload', ?, 'simulation', 'would_delete', '0000000001:000000000100')" "$at"
	sql "INSERT INTO oc_folder_retention_log (file_id, storage_id, path, rule_label, reference_date, reference_source, deleted_at, mode, status, root_key) VALUES (990402, 1, 'S36 Archive/b.txt', 's36', 1, 'upload', ?, 'simulation', 'would_delete', '0000000001:000000000200')" "$at"
	sql "INSERT INTO oc_folder_retention_log (file_id, storage_id, path, rule_label, reference_date, reference_source, deleted_at, mode, status, root_key) VALUES (990403, 1, 'S36 Archive/c.txt', 's36', 1, 'upload', ?, 'simulation', 'would_delete', '0000000001:000000000200')" "$at"
	api() { docker exec "$C" curl -sf -u "admin:$PW" -H 'OCS-APIRequest: true' "http://localhost/index.php/apps/folder_retention/api/$1"; }
	local groups files keyed headers body rows ok=1
	groups=$(api "log/folders?search=s36&from=$((at - 3600))&to=$((at + 3600))" | sed -E 's/,"superseded":(true|false)//g' | grep -o '"folder":"S36 Archive","root":"[0-9:]*","total":[0-9]*' | sed 's/"folder":"S36 Archive",//' | tr '\n' ' ' || true)
	files=$(api "log?search=s36&folder=S36%20Archive&root=0000000001:000000000200" | grep -o '"path":"[^"]*"' | tr '\n' ' ' || true)
	keyed=$(sql "SELECT COUNT(*) FROM oc_folder_retention_log WHERE rule_label <> 's36' AND root_key IS NOT NULL")
	headers=$(docker exec "$C" curl -sf -D - -o /tmp/s36.csv -u "admin:$PW" -H 'OCS-APIRequest: true' "http://localhost/index.php/apps/folder_retention/api/log/export?search=s36" | tr -d '\r' | grep -iE '^content-(type|disposition):' | paste -sd' ' || true)
	body=$(docker exec "$C" head -c 3 /tmp/s36.csv | od -An -tx1 | tr -d ' \n')
	rows=$(docker exec "$C" sh -c 'wc -l < /tmp/s36.csv' | tr -d ' ')
	sql "DELETE FROM oc_folder_retention_log WHERE rule_label = 's36'"
	# as on an existing installation: column and migration record gone, then the single step
	local mig col
	sql "DELETE FROM oc_migrations WHERE app = 'folder_retention' AND version = '1004Date20261005000000'"
	sql "ALTER TABLE oc_folder_retention_log DROP COLUMN root_key"
	mig=$(docker exec -u www-data "$C" php /tmp/fret-proc.php migrate 1004Date20261005000000 2>&1 | tail -n1 || true)
	mig+="/$(docker exec -u www-data "$C" php /tmp/fret-proc.php migrate 1004Date20261005000000 2>&1 | tail -n1 || true)"
	col=$(sql "SELECT COUNT(*) FROM pragma_table_info('oc_folder_retention_log') WHERE name = 'root_key'")
	[[ "$groups" == *'"root":"0000000001:000000000100","total":1'* && "$groups" == *'"root":"0000000001:000000000200","total":2'* ]] || ok=0
	[[ "$files" == *'b.txt'* && "$files" == *'c.txt'* && "$files" != *'a.txt'* ]] || ok=0
	[[ "$keyed" -gt 0 ]] || ok=0
	[[ "$headers" == *'text/csv'* && "$headers" == *'attachment; filename="'* ]] || ok=0
	[[ "$body" == efbbbf && "$rows" == 4 ]] || ok=0
	[[ "$mig" == done/already && "$col" == 1 ]] || ok=0
	local note="groups: $groups| files of the second: $files| entries with key from real runs: $keyed; export: $headers, BOM $body, lines $rows; migration step: $mig, column back: $col"
	if [[ $ok == 1 ]]; then
		result PASS S36 "$note"
	else
		result FAIL S36 "$note"
	fi
}

# S37 superseded simulated hits: a rule change marks them at once, a later run (file deleted for
# real) and a file gone to the trash bin (purge after a cycle) as well; the overview keeps them
# apart and the status filter finds them; a new simulated run logs fresh hits instead of hiding
# behind the superseded ones. Last: migration 1005 on existing entries (rule changed since / gone).
s37() {
	api() { docker exec "$C" curl -sf -u "admin:$PW" -H 'OCS-APIRequest: true' "http://localhost/index.php/apps/folder_retention/api/$1"; }
	putrule() {
		docker exec "$C" curl -s -o /dev/null -w '%{http_code}' -X PUT -u "admin:$PW" -H 'OCS-APIRequest: true' \
			-H 'Content-Type: application/json' -d "$2" "http://localhost/index.php/apps/folder_retention/api/rules/$1"
	}
	counts() {
		echo "$(sql "SELECT COUNT(*) FROM oc_folder_retention_log WHERE path LIKE '%s37-%' AND status = 'would_delete' AND superseded_at IS NULL")/$(sql "SELECT COUNT(*) FROM oc_folder_retention_log WHERE path LIKE '%s37-%' AND status = 'would_delete' AND superseded_at IS NOT NULL")"
	}
	local t0 f
	t0=$(( $(now) - 60 ))
	set_sim true
	for f in a b c; do
		put alice "s37-$f.txt"
		age "s37-$f.txt" 10
	done
	retention_run s37-1
	local c1 c2 c3 c4 c5 http1 http2 groups days filt current purge
	c1=$(counts)
	# 1) personal folders to "never": the three hits were evaluated with the old period
	http1=$(putrule personal '{"periodUnit":"never"}')
	c2=$(counts)
	groups=$(api "log/folders?search=s37-&from=$t0&to=$(( $(now) + 3600 ))" | grep -o '"superseded":[a-z]*,"total":[0-9]*' | paste -sd' ' || true)
	days=$(api "log/days?search=s37-" | grep -o '"superseded":[0-9]*' | head -1 || true)
	filt=$(api "log?search=s37-&status=superseded" | grep -o '"total":[0-9]*' || true)
	current=$(api "log?search=s37-&status=would_delete" | grep -o '"total":[0-9]*' || true)
	# 2) back to one day: the next simulated run logs fresh hits
	http2=$(putrule personal '{"periodUnit":"day","periodValue":1}')
	retention_run s37-2
	c3=$(counts)
	# 3) the user moves c to the trash bin; after a cycle its hit no longer applies
	docker exec "$C" curl -sf -o /dev/null -u "alice:$PW" -X DELETE "http://localhost/remote.php/dav/files/alice/s37-c.txt" || true
	purge=$(docker exec -u www-data "$C" php /tmp/fret-proc.php purge 2>&1 | tail -n1 || true)
	c4=$(counts)
	# 4) real run deletes a and b: their hits are done
	set_sim false
	retention_run s37-3
	c5=$(counts)
	# 5) migration on an existing installation: column and record gone, one entry of a removed rule
	local mig c6
	sql "INSERT INTO oc_folder_retention_log (file_id, storage_id, path, rule_id, rule_label, reference_date, reference_source, deleted_at, mode, status) VALUES (990501, 1, 's37-gone-rule.txt', 999999, 's37', 1, 'upload', ?, 'simulation', 'would_delete')" "$t0"
	sql "DELETE FROM oc_migrations WHERE app = 'folder_retention' AND version = '1005Date20261005120000'"
	sql "ALTER TABLE oc_folder_retention_log DROP COLUMN superseded_at"
	mig=$(docker exec -u www-data "$C" php /tmp/fret-proc.php migrate 1005Date20261005120000 2>&1 | tail -n1 || true)
	# run 1 (before the second rule change) and the removed rule: superseded; run 2: current
	c6=$(counts)
	sql "DELETE FROM oc_folder_retention_log WHERE rule_label = 's37'"
	local ok=1
	[[ "$c1" == 3/0 && "$http1" == 200 && "$c2" == 0/3 ]] || ok=0
	[[ "$groups" == *'"superseded":true,"total":3'* && "$groups" != *'"superseded":false'* ]] || ok=0
	[[ "$days" == '"superseded":3' && "$filt" == '"total":3' && "$current" == '"total":0' ]] || ok=0
	[[ "$http2" == 200 && "$c3" == 3/3 && "$c4" == 2/4 && "$c5" == 0/6 ]] || ok=0
	[[ "$mig" == done && "$c6" == 3/4 ]] || ok=0
	local note="sim run: $c1, rule to never (HTTP $http1): $c2, groups: $groups, day: $days, filter superseded $filt / current $current; rule back (HTTP $http2) + run: $c3; c to trash + purge ($purge): $c4; real run: $c5; migration $mig: $c6 (current/superseded)"
	if [[ $ok == 1 ]]; then
		result PASS S37 "$note"
	else
		result FAIL S37 "$note"
	fi
}

# S38 language of the tags, chosen in the settings: German, neutral ("⌛ 1 d"), English. Each switch
# queues a full tag sync; after it every file carries the tag with the new name and the app's
# old tags are deleted – a foreign tag stays. An unknown language is rejected.
s38() {
	api_put() {
		docker exec "$C" curl -s -o /tmp/s38-put.json -w '%{http_code}' -u "admin:$PW" -H 'OCS-APIRequest: true' \
			-H 'Content-Type: application/json' -X PUT -d "$1" http://localhost/index.php/apps/folder_retention/api/settings
	}
	tag_of() { sql "SELECT t.name FROM oc_systemtag t JOIN oc_systemtag_object_mapping m ON m.systemtagid = t.id WHERE m.objecttype = 'files' AND m.objectid = ?" "$1" | paste -sd'|' || true; }
	names() { sql "SELECT name FROM oc_systemtag ORDER BY name" | paste -sd'|' || true; }
	queued() { sql "SELECT COUNT(*) FROM oc_jobs WHERE class LIKE '%TagSyncJob' AND argument = '{\"folderId\":null}'"; }
	switch() {
		local code
		sql "DELETE FROM oc_jobs WHERE class LIKE '%TagSyncJob'"
		code=$(api_put "{\"tagLanguage\":\"$1\"}")
		echo "$code/$(grep -o '"tagLanguage":"[a-z]*"' <(docker exec "$C" cat /tmp/s38-put.json) | cut -d'"' -f4)/q$(queued)"
		occ folder_retention:tags >/dev/null 2>&1 || true
	}
	local id h0 hde hneu hen hbad tde tneu ten nde nneu nen
	set_default_rules day 1
	put alice s38.txt
	id=$(fid s38.txt)
	# via the API like an admin: occ would leave the web process with a cached "tags off"
	api_put '{"tags":true}' >/dev/null
	occ tag:add "S38 foreign" public >/dev/null
	h0=$(switch en)
	hde=$(switch de)
	tde=$(tag_of "$id"); nde=$(names)
	hneu=$(switch neutral)
	tneu=$(tag_of "$id"); nneu=$(names)
	hen=$(switch en)
	ten=$(tag_of "$id"); nen=$(names)
	hbad=$(api_put '{"tagLanguage":"xx"}')
	occ config:app:set folder_retention tags_enabled --value=0 --type=boolean >/dev/null
	local ok=1
	[[ "$hde" == 200/de/q1 && "$hneu" == 200/neutral/q1 && "$hen" == 200/en/q1 && "$hbad" == 400 ]] || ok=0
	[[ "$tde" == "Aufbewahrung: "* && "$tde" != *'|'* && "$nde" != *'Retention: '* ]] || ok=0
	[[ "$tneu" == "⌛ "* && "$tneu" != *'|'* && "$nneu" != *'Aufbewahrung: '* && "$nneu" != *'Retention: '* ]] || ok=0
	[[ "$ten" == "Retention: "* && "$ten" != *'|'* && "$nen" != *'⌛'* && "$nen" != *'Aufbewahrung: '* ]] || ok=0
	[[ "$nde" == *'S38 foreign'* && "$nneu" == *'S38 foreign'* && "$nen" == *'S38 foreign'* ]] || ok=0
	local note="start: $h0; de: $hde → file [$tde], tags [$nde]; neutral: $hneu → [$tneu], tags [$nneu]; en: $hen → [$ten], tags [$nen]; unknown language: HTTP $hbad"
	if [[ $ok == 1 ]]; then
		result PASS S38 "$note"
	else
		result FAIL S38 "$note"
	fi
}

# ---------------------------------------------------------------- sequence

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
scenario S33 s33
scenario S34 s34
scenario S35 s35
scenario S36 s36
scenario S37 s37
scenario S38 s38

pass=$(grep -c '^PASS ' "$RES" || true)
fail=$(grep -c '^FAIL ' "$RES" || true)
skip=$(grep -c '^SKIP ' "$RES" || true)
info "runtime $(( $(now) - STARTED )) s, image $IMAGE, app from $APP_SRC"
echo "Harness $pass/$fail/$skip"
[[ "$fail" == 0 ]]
