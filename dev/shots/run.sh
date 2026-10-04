#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# App Store screenshots for folder_retention, reproducible from the working tree.
#
#   dev/shots/run.sh              # -> screenshots/*.png
#
# Starts its own throwaway Nextcloud (container fret-shots-<pid>, SQLite, no published
# port), installs groupfolders from the GitHub source tarball (never from apps.nextcloud.com)
# and this app from the working tree, builds English demo content (Team folders, aged files,
# rules), runs one simulated retention pass and photographs the admin page with the
# host's headless Firefox through geckodriver (WebDriver over HTTP, no npm package).
#
# Environment:
#   IMAGE=nextcloud:34.0.4-apache   Nextcloud image
#   GF_TAG=v22.0.6                  pin the groupfolders release (default: newest matching one)
#   GECKODRIVER=/path/geckodriver   default: dev/shots/.tools/geckodriver (downloaded if missing)
#   OUT=<dir>                       output directory (default: screenshots/ in the repo)
#   KEEP=1                          leave the container running afterwards (prints its address)
#   NO_OPTIMIZE=1                   skip the pngquant/oxipng pass (runs in a throwaway alpine container)
#
# Only ever touches the container it created itself (exact name fret-shots-<pid>).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
IMAGE="${IMAGE:-nextcloud:34.0.4-apache}"
C="fret-shots-$$"
KEEP="${KEEP:-0}"
OUT="${OUT:-$REPO/screenshots}"
PW='Shots-Demo-Pw-2026!'
WORK="$(mktemp -d "${TMPDIR:-/tmp}/fret-shots.XXXXXX")"
GECKODRIVER="${GECKODRIVER:-$HERE/.tools/geckodriver}"

cleanup() {
	local rc=$?
	if [[ "$KEEP" == 1 ]]; then
		echo "KEEP=1: container $C stays (docker rm -fv $C), work dir $WORK" >&2
	else
		docker rm -fv "$C" >/dev/null 2>&1 || true
		rm -rf "$WORK"
	fi
	exit $rc
}
trap cleanup EXIT
trap 'exit 130' INT TERM

info() { echo "  · $*" >&2; }

occ() { docker exec -u www-data "$C" php occ "$@"; }
sql() { docker exec -u www-data "$C" php /tmp/fret-sql.php "$@"; }
now() { date +%s; }
ago() { echo $(( $(now) - $1 * 86400 )); }

# ------------------------------------------------------------------ prerequisites

command -v firefox >/dev/null || { echo "firefox (headless) is needed on the host" >&2; exit 1; }
command -v node >/dev/null || { echo "node >= 18 is needed on the host" >&2; exit 1; }
if [[ ! -x "$GECKODRIVER" ]]; then
	info "fetching geckodriver 0.35.0 into $(dirname "$GECKODRIVER")"
	mkdir -p "$(dirname "$GECKODRIVER")"
	curl -fsSL https://github.com/mozilla/geckodriver/releases/download/v0.35.0/geckodriver-v0.35.0-linux64.tar.gz \
		| tar -xz -C "$(dirname "$GECKODRIVER")"
fi
compgen -G "$REPO/js/*.mjs" >/dev/null || {
	info "no js/ build – building with node:24-alpine"
	docker run --rm -v "$REPO":/app -w /app node:24-alpine \
		sh -c 'npm ci --no-audit --no-fund --loglevel=error && npm run build' >"$WORK/js-build.txt" 2>&1 \
		|| { echo "js build failed, see $WORK/js-build.txt" >&2; exit 2; }
}

# ------------------------------------------------------------------ throwaway Nextcloud

info "starting $C from $IMAGE"
docker create --name "$C" \
	-e SQLITE_DATABASE=nextcloud \
	-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$PW" \
	-e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
	"$IMAGE" >/dev/null
docker start "$C" >/dev/null
for i in $(seq 1 120); do
	if occ status --output=json 2>/dev/null | grep -q '"installed":true' \
		&& docker exec "$C" curl -sf -o /dev/null http://localhost/status.php; then
		break
	fi
	[[ $i -lt 120 ]] || { echo "Nextcloud in $C did not come up" >&2; exit 2; }
	sleep 2
done
IP="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$C")"
[[ -n "$IP" ]] || { echo "no IP for $C" >&2; exit 2; }
docker cp "$REPO/dev/it/sql.php" "$C:/tmp/fret-sql.php" >/dev/null

occ config:system:set trusted_domains 1 --value="$IP" >/dev/null
occ config:system:set overwrite.cli.url --value="http://$IP" >/dev/null
occ config:system:set default_language --value=en >/dev/null
occ config:system:set default_locale --value=en_US >/dev/null
occ config:system:set default_timezone --value=UTC >/dev/null
# no AJAX cron: otherwise the background job would run alongside the page loads
occ background:cron >/dev/null
# quiet first login: no welcome wizard, no dashboard/recommendation noise
occ app:disable firstrunwizard >/dev/null 2>&1 || true
occ app:disable recommendations >/dev/null 2>&1 || true
occ app:disable support >/dev/null 2>&1 || true

# groupfolders from GitHub (source tarball; the occ commands are enough, the Files app shows
# Team folders as normal mounts). Never apps.nextcloud.com.
major=$(occ status --output=json | sed -n 's/.*"versionstring":"\([0-9]*\)\..*/\1/p')
tag="${GF_TAG:-}"
if [[ -z "$tag" ]]; then
	tag=$(curl -fsSL "https://api.github.com/repos/nextcloud/groupfolders/releases?per_page=100" \
		| sed -n 's/.*"tag_name": *"\(v[0-9.]*\)".*/\1/p' | grep -E "^v$((major - 12))\.[0-9]+\.[0-9]+$" \
		| sort -V | tail -n1 || true)
fi
[[ -n "$tag" ]] || { echo "no groupfolders release for NC $major on GitHub" >&2; exit 2; }
info "groupfolders $tag"
curl -fsSL -o "$WORK/gf.tgz" "https://github.com/nextcloud/groupfolders/archive/refs/tags/$tag.tar.gz"
docker cp "$WORK/gf.tgz" "$C:/tmp/gf.tgz" >/dev/null
docker exec "$C" sh -c 'mkdir -p /var/www/html/custom_apps/groupfolders \
	&& tar xzf /tmp/gf.tgz --strip-components=1 -C /var/www/html/custom_apps/groupfolders \
	&& chown -R www-data:www-data /var/www/html/custom_apps/groupfolders'
occ app:enable groupfolders >/dev/null

info "installing folder_retention from the working tree"
tar -C "$REPO" --exclude=./vendor --exclude=./tests --exclude=./.git --exclude=./node_modules \
	--exclude=./src --exclude=./scripts --exclude=./dev --exclude=./screenshots --exclude="*.map" \
	--exclude=./package-lock.json -cf - . \
	| docker exec -i "$C" sh -c 'mkdir -p /var/www/html/custom_apps/folder_retention \
		&& tar -xf - -C /var/www/html/custom_apps/folder_retention \
		&& chown -R www-data:www-data /var/www/html/custom_apps/folder_retention'
occ app:enable folder_retention >/dev/null

# ------------------------------------------------------------------ demo content

info "users and Team folders"
adduser() {
	docker exec -e OC_PASS="$PW" -u www-data "$C" php occ user:add --password-from-env --display-name="$2" "$1" >/dev/null
}
adduser alex "Alex Morgan"
adduser sam "Sam Rivera"
adduser jordan "Jordan Lee"
adduser taylor "Taylor Brooks"
occ user:setting admin core lang en >/dev/null
occ user:setting admin core locale en_US >/dev/null
occ user:edit admin display-name "Admin" >/dev/null 2>&1 || occ user:modify admin displayname "Admin" >/dev/null 2>&1 || true

touch_fs() {
	docker exec "$C" curl -sf -o /dev/null -u "$1:$PW" -X PROPFIND -H 'Depth: 1' \
		"http://localhost/remote.php/dav/files/$1/"
}

# gf <name> <group> <member…> → Team folder id
gf() {
	local name=$1 group=$2 gid u
	shift 2
	gid=$(occ groupfolders:create "$name" | tail -n1 | tr -dc "0-9")
	occ group:add "$group" >/dev/null
	for u in "$@"; do occ group:adduser "$group" "$u" >/dev/null; done
	occ groupfolders:group "$gid" "$group" write share delete >/dev/null
	echo "$gid"
}
gf Finance finance alex sam >/dev/null
gf HR hr jordan >/dev/null
gf Projects projects alex jordan taylor >/dev/null
gf Marketing marketing taylor sam >/dev/null
for u in admin alex sam jordan taylor; do touch_fs "$u"; done
# admin sees the Team folders in Files as well (shot 05)
for g in finance hr projects marketing; do occ group:adduser "$g" admin >/dev/null; done

# percent-encode a path (keeps "/"), byte by byte so umlauts and dashes survive
urlenc() {
	local LC_ALL=C s=$1 out='' i c
	for (( i = 0; i < ${#s}; i++ )); do
		c=${s:i:1}
		case "$c" in
			[a-zA-Z0-9.~_/-]) out+=$c ;;
			*) out+=$(printf '%%%02X' "'$c") ;;
		esac
	done
	printf '%s' "$out"
}
dav() { echo "http://localhost/remote.php/dav/files/$1/$(urlenc "$2")"; }
mkcol() {
	local user=$1 path=$2 acc='' part
	IFS=/ read -ra parts <<<"$path"
	for part in "${parts[@]}"; do
		acc="${acc:+$acc/}$part"
		docker exec "$C" curl -s -o /dev/null -u "$user:$PW" -X MKCOL "$(dav "$user" "$acc")"
	done
}
# david <user> <path> → fileid via WebDAV (works for Team folders and home alike)
david() {
	docker exec "$C" curl -sf -u "$1:$PW" -X PROPFIND -H "Depth: 0" -H "Content-Type: application/xml" \
		--data "<?xml version=\"1.0\"?><d:propfind xmlns:d=\"DAV:\" xmlns:oc=\"http://owncloud.org/ns\"><d:prop><oc:fileid/></d:prop></d:propfind>" \
		"$(dav "$1" "$2")" | sed -n "s/.*<oc:fileid>\([0-9]*\)<\/oc:fileid>.*/\1/p"
}
# put <user> <path> <age in days> <size in KB>
put() {
	local user=$1 path=$2 days=$3 kb=$4 id ts
	mkcol "$user" "$(dirname "$path")"
	docker exec "$C" sh -c "head -c $((kb * 1024)) /dev/urandom | curl -sf -o /dev/null -u '$user:$PW' -T - '$(dav "$user" "$path")'"
	# age the file like dev/it does: mtime, upload/creation time and "first seen"
	id=$(david "$user" "$path")
	[[ -n "$id" ]] || { echo "upload of $path failed" >&2; exit 2; }
	ts=$(ago "$days")
	ts=$(( ts - (RANDOM % 36000) ))
	sql "UPDATE oc_filecache SET mtime = ? WHERE fileid = ?" "$ts" "$id"
	sql "INSERT OR IGNORE INTO oc_filecache_extended (fileid) VALUES (?)" "$id"
	sql "UPDATE oc_filecache_extended SET upload_time = ?, creation_time = ? WHERE fileid = ?" "$ts" "$ts" "$id"
	sql "INSERT OR REPLACE INTO oc_folder_retention_seen (file_id, first_seen) VALUES (?, ?)" "$id" "$ts"
}

info "files"
put alex "Finance/Invoices/2014/INV-2014-0412.pdf" 4190 184
put alex "Finance/Invoices/2014/INV-2014-0587.pdf" 4120 212
put alex "Finance/Invoices/2015/INV-2015-0133.pdf" 3700 176
put sam  "Finance/Invoices/2015/INV-2015-0921.pdf" 3640 201
put sam  "Finance/Invoices/2024/INV-2024-0218.pdf" 590 158
put sam  "Finance/Invoices/2024/INV-2024-0911.pdf" 390 163
put alex "Finance/Invoices/2026/INV-2026-0304.pdf" 210 149
put alex "Finance/Annual Reports/Annual Report 2019.pdf" 2100 2380
put alex "Finance/Annual Reports/Annual Report 2025.pdf" 180 2915
put sam  "Finance/Bank Exports/statement-2026-06.csv" 112 64
put sam  "Finance/Bank Exports/statement-2026-07.csv" 81 71
put sam  "Finance/Bank Exports/statement-2026-08.csv" 52 69
put sam  "Finance/Bank Exports/statement-2026-09.csv" 21 66

put jordan "HR/Applications/Applicant 0142 – CV.pdf" 251 412
put jordan "HR/Applications/Applicant 0142 – Cover letter.pdf" 251 96
put jordan "HR/Applications/Applicant 0157 – CV.pdf" 214 388
put jordan "HR/Applications/Applicant 0163 – Portfolio.pdf" 189 3120
put jordan "HR/Applications/Applicant 0171 – CV.pdf" 176 351
put jordan "HR/Applications/Applicant 0188 – CV.pdf" 97 377
put jordan "HR/Applications/Applicant 0194 – CV.pdf" 33 402
put jordan "HR/Contracts/Employment contract template.docx" 900 58
put jordan "HR/Policies/Travel policy.pdf" 720 240
put jordan "HR/Policies/Remote work policy.pdf" 300 188

put taylor "Projects/Website Relaunch/Sitemap.pdf" 31 140
put taylor "Projects/Website Relaunch/Wireframes v4.pdf" 12 1880
put alex   "Projects/Mobile App/Requirements.md" 88 22
put alex   "Projects/Mobile App/Release plan.xlsx" 40 48
put jordan "Projects/Archive/Intranet 2021/Final report.pdf" 1510 940
put jordan "Projects/Archive/CRM Migration 2022/Handover.docx" 1120 310
put taylor "Projects/Archive/Trade Fair 2023/Budget.xlsx" 760 52
put taylor "Projects/Archive/Office Move 2024/Floor plan.pdf" 700 1240

put taylor "Marketing/Drafts/Newsletter October – draft.docx" 46 84
put taylor "Marketing/Drafts/Social posts week 38.txt" 35 6
put sam    "Marketing/Drafts/Landing page copy v3.docx" 27 41
put taylor "Marketing/Drafts/Press release – draft.docx" 12 37
put sam    "Marketing/Drafts/Banner concepts.pdf" 3 1530
put taylor "Marketing/Campaigns/Spring 2026/Campaign brief.pdf" 180 320
put taylor "Marketing/Brand Assets/Logo guidelines.pdf" 820 4210

put alex   "Notes/Meeting notes.md" 20 4
put jordan "Documents/Onboarding checklist.docx" 60 31

info "rules"
# rule <folder id> <unit> <value|''> [scope] [basis] [notify]
rule() {
	local t
	t=$(now)
	[[ -n "$1" ]] || { echo "rule: folder not found" >&2; exit 2; }
	sql "INSERT INTO oc_folder_retention_rules (folder_id, period_value, period_unit, scope, basis, notify, created_by, created_at, updated_at)
		VALUES (?, NULLIF(?, ''), ?, ?, ?, ?, 'admin', ?, ?)" "$1" "$2" "$3" "${4:-inherit}" "${5:-created}" "${6:-0}" "$t" "$t"
}
occ folder_retention:run --dry-run >/dev/null 2>&1 || true # creates missing default rules
rule "$(david alex Finance)" 120 month
rule "$(david sam 'Finance/Bank Exports')" 3 month inherit modified
rule "$(david jordan HR)" '' never
rule "$(david jordan HR/Applications)" 6 month inherit created 1
rule "$(david jordan Projects/Archive)" 24 month
rule "$(david taylor Marketing/Drafts)" 30 day

info "simulated run + tags"
occ config:app:set folder_retention simulation_mode --value=true --type=boolean >/dev/null
occ config:app:set folder_retention tags_enabled --value=true --type=boolean >/dev/null
occ folder_retention:run >"$WORK/run.txt" 2>&1 || { cat "$WORK/run.txt" >&2; exit 2; }
occ folder_retention:tags >"$WORK/tags.txt" 2>&1 || { cat "$WORK/tags.txt" >&2; exit 2; }
# The pass above is one run; spread its entries over the last nights so the log reads like
# a few nightly runs (simulated hits are recorded once per file and rule anyway).
n=0
total=$(sql "SELECT COUNT(*) FROM oc_folder_retention_log")
for id in $(sql "SELECT id FROM oc_folder_retention_log ORDER BY id"); do
	night=$(( 3 - n * 4 / (total > 0 ? total : 1) ))
	ts=$(( ( $(now) / 86400 - night ) * 86400 + 2 * 3600 + 5 * 60 + n * 7 ))
	[[ $ts -lt $(now) ]] || ts=$(( ts - 86400 ))
	sql "UPDATE oc_folder_retention_log SET deleted_at = ? WHERE id = ?" "$ts" "$id"
	n=$((n + 1))
done
# the nightly job, not occ, records a completed cycle; show last night 02:05 like a real instance
occ config:app:set folder_retention last_cycle_completed --value=$(( $(now) / 86400 * 86400 + 2 * 3600 + 5 * 60 - ( $(now) % 86400 < 7500 ? 86400 : 0 ) )) --type=integer >/dev/null
info "log entries: $(sql "SELECT COUNT(*) FROM oc_folder_retention_log")"

# ------------------------------------------------------------------ shoot

mkdir -p "$OUT"
rm -f "$OUT"/0[0-9]-*.png
info "shooting against http://$IP"
FRET_HOST="http://$IP" FRET_USER=admin FRET_PASS="$PW" GECKODRIVER="$GECKODRIVER" \
	node "$HERE/shoot.mjs" "$OUT"

if [[ "${NO_OPTIMIZE:-0}" != 1 ]]; then
	info "optimising PNGs (pngquant + oxipng in alpine)"
	docker run --rm -v "$OUT":/o alpine sh -c 'apk add -q pngquant oxipng >/dev/null \
		&& for f in /o/0*.png; do pngquant --force --skip-if-larger --quality=80-95 --strip --output "$f" "$f" || true; done \
		&& oxipng -q -o 3 --strip safe /o/0*.png' \
		|| info "optimising failed – keeping the unoptimised PNGs"
fi
[[ "$KEEP" != 1 ]] || echo "Instance: http://$IP (admin / $PW)" >&2
ls -la "$OUT"/0*.png >&2
