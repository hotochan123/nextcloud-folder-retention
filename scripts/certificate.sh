#!/bin/sh
# SPDX-FileCopyrightText: 2026 hotochan123
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Creates the private key and the certificate signing request (CSR) for
# signing the app.
#
#   scripts/certificate.sh              # -> $OUT/folder_retention.{key,csr}
#   OUT=/path scripts/certificate.sh
#
# Default OUT is $HOME/.nextcloud/certificates. Point OUT (and SECRETS for
# package.sh) at persistent storage —
# do not use a home directory on a volatile file system
# (Unraid and other appliances keep / in RAM): the key would be gone after the
# next reboot, and with it every signed release.
#
# The Common Name MUST be the app ID, otherwise Nextcloud rejects the
# certificate. The private key never goes into the repository or an archive.
set -eu
umask 077

APP_ID=folder_retention
OUT="${OUT:-$HOME/.nextcloud/certificates}"

command -v openssl >/dev/null 2>&1 || { echo "openssl not found" >&2; exit 1; }

mkdir -p "$OUT"
chmod 700 "$OUT"

if [ -f "$OUT/$APP_ID.key" ]; then
	echo "A key already exists: $OUT/$APP_ID.key"
	echo "Not overwriting it — a new key invalidates every release signed so far."
	echo "To start over, move the file away deliberately."
	exit 1
fi

openssl req -nodes -newkey rsa:4096 -keyout "$OUT/$APP_ID.key" \
	-out "$OUT/$APP_ID.csr" -subj "/CN=$APP_ID"
chmod 600 "$OUT/$APP_ID.key"
chmod 644 "$OUT/$APP_ID.csr"

cat <<EOF

Key:      $OUT/$APP_ID.key   (secret, local only, never in a repository)
Request:  $OUT/$APP_ID.csr   (this goes into the pull request)

Next (details in docs/APPSTORE.md):
  1. Make the source repository public — the certificate request links to it.
  2. Fork https://github.com/nextcloud/app-certificate-requests, add the
     request as $APP_ID/$APP_ID.csr and open the pull request yourself.
  3. Once it is merged, the repository has $APP_ID/$APP_ID.crt — save it as
     $OUT/$APP_ID.crt.
  4. Register the app ID on https://apps.nextcloud.com with the certificate
     and a signature over the app ID:
       printf '%s' $APP_ID | openssl dgst -sha512 -sign $OUT/$APP_ID.key | openssl base64
  5. Sign and pack (picks up key and certificate from $OUT by default):
     scripts/package.sh

Check that the request carries the app ID:
  openssl req -in $OUT/$APP_ID.csr -noout -subject
EOF
