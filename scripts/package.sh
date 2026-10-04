#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Builds the release archive for the Nextcloud App Store.
#
#   scripts/package.sh                      # build + check + pack (+ sign if a key is there)
#   SKIP_BUILD=1 scripts/package.sh         # leave js/ as it is (must be newer than src/)
#   ALLOW_UNRELEASED=1 scripts/package.sh   # test archive of an unreleased state
#   SIGN=0 scripts/package.sh               # never sign, even if the key exists
#   FR_KEY=/path/folder_retention.key FR_CRT=/path/folder_retention.crt scripts/package.sh
#
# Result: dist/folder_retention-<version>.tar.gz (+ .sha256) with exactly one
# folder `folder_retention/` inside — that is what the store expects. Sources
# (src/, node_modules/, vendor/, tests/, dev/, docs/, build/, scripts/) stay
# out; what ships is the finished bundle in js/ (without source maps) and the
# generated translations l10n/<lang>.{js,json} (not the .php-/.js- fragments).
#
# Reproducible: files are sorted, owned by 0:0, directories and executables
# 755, everything else 644, and every mtime is the commit time of HEAD, so the
# same commit packs to the same bytes (gzip without name and timestamp).
#
# Release state: appinfo/info.xml and package.json must carry the same version,
# and CHANGELOG.md needs the section the store reads the release notes from:
# "## [<version>]" for a release, "## [Unreleased]" for a pre-release (a
# version with a "-" suffix). ALLOW_UNRELEASED=1 turns these checks and a
# failing translation check into warnings; CI uses it to pack whatever state
# it builds. Never upload such an archive.
#
# Signing (both parts need the key AND the certificate issued by Nextcloud):
#  1. appinfo/signature.json via `occ integrity:sign-app`, run from the
#     Nextcloud sources of SIGN_IMAGE (default nextcloud:35.0.1-apache) in a
#     throw-away `docker run --rm --network none` container. The private key
#     goes in through stdin into a tmpfs (RAM) inside that container and is
#     never written to a container layer, a volume or a temporary directory
#     on the host; the container is removed when occ exits, also on errors.
#  2. The archive signature for the store's upload form (base64 SHA-512),
#     made with openssl on the host, straight from the key file.
# Defaults: FR_KEY=$SECRETS/folder_retention.key, FR_CRT=$SECRETS/folder_retention.crt
# with SECRETS=$HOME/.nextcloud/certificates (Nextcloud convention).
# If the key or the certificate is missing, the archive stays unsigned.
set -eu
umask 022

APP_ID=folder_retention
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SECRETS="${SECRETS:-$HOME/.nextcloud/certificates}"
FR_KEY="${FR_KEY:-$SECRETS/$APP_ID.key}"
FR_CRT="${FR_CRT:-$SECRETS/$APP_ID.crt}"
SIGN_IMAGE="${SIGN_IMAGE:-nextcloud:35.0.1-apache}"
NODE_IMAGE="${NODE_IMAGE:-node:24-alpine}"
OCC="${OCC:-/usr/src/nextcloud/occ}"
DIST="$ROOT/dist"

TMP=""
SIGN_CT=""

die() {
	echo "Error: $*" >&2
	exit 1
}

warn() {
	echo "    Warning: $*" >&2
}

cleanup() {
	if [ -n "$SIGN_CT" ]; then
		docker rm -f -v "$SIGN_CT" >/dev/null 2>&1 || true
	fi
	if [ -n "$TMP" ]; then
		rm -rf "$TMP"
	fi
}
trap cleanup EXIT
# dash skips the EXIT trap for a signal without its own trap.
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 131' QUIT
trap 'exit 141' PIPE
trap 'exit 143' TERM

relaxed() {
	[ "${ALLOW_UNRELEASED:-}" = 1 ]
}

# Plain node scripts (build/*.js) run with the host's node if it works,
# otherwise in a throw-away container.
run_node() {
	if node --version >/dev/null 2>&1; then
		(cd "$ROOT" && node "$@")
	else
		docker run --rm -v "$ROOT:/app" -w /app "$NODE_IMAGE" node "$@"
	fi
}

# ---------------------------------------------------------------- release state
VERSION="$(sed -n 's#.*<version>\(.*\)</version>.*#\1#p' "$ROOT/appinfo/info.xml" | head -n1)"
[ -n "$VERSION" ] || die "cannot read the version from appinfo/info.xml"
PKG_VERSION="$(sed -n 's#^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*#\1#p' "$ROOT/package.json" | head -n1)"

echo "==> Checking the release state of $VERSION"
RELEASED=1
if [ "$PKG_VERSION" != "$VERSION" ]; then
	echo "    appinfo/info.xml says $VERSION, package.json says $PKG_VERSION" >&2
	RELEASED=""
fi
case "$VERSION" in
	*-*) NOTES="Unreleased" ;;
	*) NOTES="$VERSION" ;;
esac
if ! awk -v h="## [$NOTES]" '
		index($0, h) == 1 && (length($0) == length(h) || substr($0, length(h) + 1, 1) == " ") { found = 1 }
		END { exit !found }' "$ROOT/CHANGELOG.md" 2>/dev/null; then
	echo "    CHANGELOG.md has no \"## [$NOTES]\" section" >&2
	RELEASED=""
fi
if [ -z "$RELEASED" ]; then
	if relaxed; then
		warn "packing an unreleased state (ALLOW_UNRELEASED=1) — do not upload this archive"
	else
		die "$VERSION is not a release state. Give info.xml and package.json the same version and add the CHANGELOG section, or set ALLOW_UNRELEASED=1 for a test archive."
	fi
fi

# ----------------------------------------------------------------------- bundle
BUNDLE="$ROOT/js/$APP_ID-main.mjs"
if [ -z "${SKIP_BUILD:-}" ]; then
	# node_modules/.bin is not executable on some mounts (noexec): then build
	# in a container with the Node version package.json asks for.
	if [ -x "$ROOT/node_modules/.bin/vite" ] && npm --version >/dev/null 2>&1; then
		echo "==> npm run build"
		(cd "$ROOT" && npm run --silent build >/dev/null)
	else
		command -v docker >/dev/null 2>&1 || die "cannot build: no executable vite and no docker"
		echo "==> npm run build (in $NODE_IMAGE)"
		docker run --rm -v "$ROOT:/app" -w /app "$NODE_IMAGE" \
			sh -c '[ -d node_modules ] || npm ci --no-audit --no-fund >/dev/null; npm run --silent build >/dev/null'
	fi
fi
[ -f "$BUNDLE" ] || die "js/$APP_ID-main.mjs is missing — build first (npm run build)"
STALE="$(find "$ROOT/src" "$ROOT/vite.config.js" "$ROOT/package-lock.json" -newer "$BUNDLE" -type f 2>/dev/null | head -n1)"
[ -z "$STALE" ] || die "${STALE#"$ROOT"/} is newer than js/$APP_ID-main.mjs — rebuild the bundle"

# ----------------------------------------------------------------- translations
echo "==> Checking translations"
if ! run_node build/l10n-check.js; then
	if relaxed; then
		warn "translations incomplete (see above) — ALLOW_UNRELEASED=1, packing anyway"
	else
		die "translations incomplete — run npm run l10n and fill in l10n/de.json"
	fi
fi

# ----------------------------------------------------------------- info.xml
echo "==> Validating appinfo/info.xml"
# The schema is only downloaded where that is allowed (CI sets
# INFO_XSD_URL=https://apps.nextcloud.com/schema/apps/info.xsd); otherwise a
# local copy is used: INFO_XSD=<file>, or build/info.xsd (not in git).
SCHEMA="${INFO_XSD:-$ROOT/build/info.xsd}"
if [ -n "${INFO_XSD_URL:-}" ]; then
	TMP_XSD="$(mktemp)"
	if curl -fsSL --max-time 30 -o "$TMP_XSD" "$INFO_XSD_URL" && [ -s "$TMP_XSD" ]; then
		SCHEMA="$TMP_XSD"
	else
		warn "could not download $INFO_XSD_URL — falling back to $SCHEMA"
	fi
fi
if ! command -v xmllint >/dev/null 2>&1; then
	warn "xmllint not installed — schema check skipped"
elif [ ! -s "$SCHEMA" ]; then
	warn "no schema at $SCHEMA — schema check skipped (set INFO_XSD or INFO_XSD_URL)"
else
	xmllint --noout --schema "$SCHEMA" "$ROOT/appinfo/info.xml"
fi
[ -z "${TMP_XSD:-}" ] || rm -f "$TMP_XSD"

# ---------------------------------------------------------------------- staging
echo "==> Staging files"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/$APP_ID-package.XXXXXX")"
STAGE="$TMP/$APP_ID"
mkdir "$STAGE"
for item in appinfo img lib templates README.md INSTALL.md CHANGELOG.md LICENSE; do
	[ -e "$ROOT/$item" ] || die "missing: $item"
	cp -R "$ROOT/$item" "$STAGE/"
done
rm -f "$STAGE/appinfo/signature.json"

mkdir "$STAGE/js"
for f in "$ROOT"/js/*.mjs "$ROOT"/js/*.js "$ROOT"/js/*.css "$ROOT"/js/*.license; do
	[ -e "$f" ] && cp "$f" "$STAGE/js/"
done
# Source maps stay out (they embed the sources), so their references go too:
# a dangling sourceMappingURL makes the browser's dev tools request a 404.
for f in "$STAGE"/js/*.mjs "$STAGE"/js/*.js "$STAGE"/js/*.css; do
	[ -e "$f" ] || continue
	sed -i -e '/^\/\/# sourceMappingURL=/d' -e 's#/\*\# sourceMappingURL=[^*]*\*/##' "$f"
done
if grep -l 'sourceMappingURL=' "$STAGE"/js/* >/dev/null 2>&1; then
	die "a sourceMappingURL is still in: $(grep -l 'sourceMappingURL=' "$STAGE"/js/* | sed "s#$STAGE/##" | tr '\n' ' ')"
fi

# Only the generated translations l10n/<lang>.js and .json; the fragments
# (.php-de.json, .js-de.json) are inputs of npm run l10n.
mkdir "$STAGE/l10n"
for f in "$ROOT"/l10n/*.json "$ROOT"/l10n/*.js; do
	[ -e "$f" ] || continue
	case "$(basename "$f")" in
		.*) ;;
		*) cp "$f" "$STAGE/l10n/" ;;
	esac
done
if [ ! -e "$STAGE/l10n/de.json" ] || [ ! -e "$STAGE/l10n/de.js" ]; then
	if relaxed; then
		warn "l10n/de.json or l10n/de.js missing — the German UI would show English"
	else
		die "l10n/de.json or l10n/de.js missing — run npm run l10n"
	fi
fi
rmdir "$STAGE/l10n" 2>/dev/null || true

# Nothing unexpected in the archive: no sources, no key material, no links.
BAD="$(find "$STAGE" \( -name node_modules -o -name vendor -o -name '*.key' -o -name '*.pem' \
	-o -name '*.crt' -o -name '*.csr' -o -name '*.map' -o -name '.*' -o -name '*.test.js' \
	-o -name '_*.php' -o -type l \) -print)"
[ -z "$BAD" ] || die "the archive would contain files that must not ship:
$BAD"

find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f \( -perm -100 -o -perm -010 -o -perm -001 \) -exec chmod 755 {} +
find "$STAGE" -type f ! \( -perm -100 -o -perm -010 -o -perm -001 \) -exec chmod 644 {} +

# ---------------------------------------------------------------------- signing
SIGN_NOW=""
if [ "${SIGN:-}" = 0 ]; then
	echo "==> Not signing (SIGN=0)"
elif [ ! -e "$FR_KEY" ] || [ ! -e "$FR_CRT" ]; then
	echo "==> Not signing (key $FR_KEY or certificate $FR_CRT missing)"
	echo "    The App Store only accepts signed releases — see docs/APPSTORE.md"
else
	SIGN_NOW=1
	command -v openssl >/dev/null 2>&1 || die "openssl not found — needed to check and sign"
	command -v docker >/dev/null 2>&1 || die "docker not found — occ integrity:sign-app runs in $SIGN_IMAGE"
	[ -r "$FR_KEY" ] || die "private key not readable: $FR_KEY"
	# occ signs with any certificate; a wrong one only shows when an instance
	# refuses the release. So check it here.
	CRT_PUB="$(openssl x509 -in "$FR_CRT" -noout -pubkey)" || die "cannot read the certificate $FR_CRT"
	KEY_PUB="$(openssl pkey -in "$FR_KEY" -pubout)" || die "cannot read the private key $FR_KEY"
	[ "$CRT_PUB" = "$KEY_PUB" ] || die "$FR_CRT does not belong to $FR_KEY"
	openssl x509 -in "$FR_CRT" -noout -subject -nameopt RFC2253 \
		| sed -n 's/^subject= *//p' | tr ',' '\n' | grep -qx "CN=$APP_ID" \
		|| die "the certificate is not issued for CN=$APP_ID"

	echo "==> Signing (occ integrity:sign-app in a throw-away $SIGN_IMAGE container, no network, key only in its tmpfs)"
	SIGN_CT="$APP_ID-sign-$$-$(od -An -N4 -tx4 /dev/urandom | tr -d ' ')"
	# Nothing of the image's entrypoint runs (it would install Nextcloud):
	# only occ, as root — the only user that may write the source's config
	# directory, which occ insists on even for this command.
	docker run --rm -i --name "$SIGN_CT" --network none --entrypoint sh \
		--tmpfs /keys:rw,mode=0700,size=1m \
		-v "$STAGE:/sign/$APP_ID" \
		-v "$FR_CRT:/sign-crt/$APP_ID.crt:ro" \
		"$SIGN_IMAGE" -c 'umask 077 && cat > /keys/sign.key && exec php "$1" integrity:sign-app --path="/sign/$2" --privateKey=/keys/sign.key --certificate="/sign-crt/$2.crt"' \
		sh "$OCC" "$APP_ID" < "$FR_KEY" \
		|| die "occ integrity:sign-app failed"
	SIGN_CT=""
	# occ reports some failures and still exits 0 — only the file tells.
	[ -s "$STAGE/appinfo/signature.json" ] || die "occ did not write appinfo/signature.json"
	chmod 644 "$STAGE/appinfo/signature.json"
fi

# ---------------------------------------------------------------------- packing
echo "==> Packing the archive"
mkdir -p "$DIST"
ARCHIVE="$DIST/$APP_ID-$VERSION.tar.gz"
MTIME="$(cd "$ROOT" && git log -1 --format=%cI 2>/dev/null || true)"
[ -n "$MTIME" ] || warn "no git commit time — the archive carries the current time and is not reproducible"
rm -f "$ARCHIVE" "$ARCHIVE.sha256"
tar -C "$TMP" -cf - \
	--owner=0 --group=0 --numeric-owner --sort=name \
	${MTIME:+--mtime="$MTIME"} \
	"$APP_ID" | gzip -n -9 > "$ARCHIVE"
(cd "$DIST" && sha256sum "$APP_ID-$VERSION.tar.gz" > "$APP_ID-$VERSION.tar.gz.sha256")

echo
echo "Archive: $ARCHIVE"
echo "Size:    $(ls -lh "$ARCHIVE" | awk '{print $5}')"
echo "SHA256:  $(cut -d' ' -f1 "$ARCHIVE.sha256")"
if [ -n "$SIGN_NOW" ]; then
	echo "Signature for the store's upload form (base64, SHA-512 over the archive):"
	openssl dgst -sha512 -sign "$FR_KEY" "$ARCHIVE" | openssl base64 -A
	echo
fi
