# Publishing Folder Retention in the Nextcloud App Store

What the store requires, what the repository already has for it, and what has
to be done by hand — in this order, because the store accepts no release for
an app ID that has not been registered with a certificate.

Planned public repository: `hotochan123/nextcloud-folder-retention`.

## Status

| Requirement | Status |
|---|---|
| `appinfo/info.xml` valid against the store schema | done — checked with `xmllint` (locally against `build/info.xsd`, in CI against the store's current schema) |
| Metadata | done — English name/summary/description with German variants, `AGPL-3.0-or-later`, author `hotochan123` with homepage, website, issue tracker, repository, Nextcloud 34–35 |
| Licence | `LICENSE` (AGPL-3.0-or-later) |
| `CHANGELOG.md` (the store's release notes) | done — one `## [x.y.z] - date` section per release |
| English source + German translation | `l10n/de.json` / `l10n/de.js` via `npm run l10n`, checked by `npm run l10n:check` |
| Release archive | `scripts/package.sh` (reproducible, without sources and source maps) |
| CI | `.github/workflows/ci.yml` — check it is green before tagging |
| Public repository | **open** — create `hotochan123/nextcloud-folder-retention`, push, make public, enable Issues and private vulnerability reporting |
| Certificate | **open** — see below |
| App ID registered | **open** |
| Screenshots | **open** — none yet; add PNGs under `screenshots/` and `<screenshot>` tags in `info.xml` (after `<repository>`), URLs via `raw.githubusercontent.com` |
| Public e-mail address | none, as for Pulse — commits use the GitHub no-reply address, contact through the issue tracker |

## Once

1. **Make the repository public.** Before that, check that no personal e-mail
   address is in the files (`git grep -n "@"`) or the history
   (`git log --format="%ae %ce"`), and that no internal host names are in docs
   or screenshots.
2. **Key and certificate request:**
   ```sh
   scripts/certificate.sh      # key + CSR on persistent storage (see the script)
   ```
   The key must not live on a file system that is lost on reboot.
3. **Certificate pull request.** Fork
   <https://github.com/nextcloud/app-certificate-requests>, add
   `folder_retention/folder_retention.csr`, and open the pull request yourself
   (Nextcloud's policy on AI-generated contributions: the request comes from the
   owner, not from an agent). After the merge, save
   `folder_retention/folder_retention.crt` next to the key and check it:
   ```sh
   openssl x509 -in folder_retention.crt -noout -subject -enddate   # CN=folder_retention
   ```
4. **Register the app ID** on <https://apps.nextcloud.com> (account → "Register
   app") with the certificate and the signature over the app ID:
   ```sh
   printf '%s' folder_retention | openssl dgst -sha512 -sign folder_retention.key | openssl base64
   ```

## For every release

1. Bump the version in `appinfo/info.xml` **and** `package.json`; a new
   migration needs a new version (see `tests/Unit/AppInfo/VersionTest.php`).
2. Move the `[Unreleased]` notes in `CHANGELOG.md` to `## [x.y.z] - YYYY-MM-DD`.
3. `npm run l10n && npm run l10n:check`, PHPUnit, `npm test`, and the harness
   on Nextcloud 34 and 35 (`dev/it/run.sh`, `IMAGE=nextcloud:35.0.1-apache dev/it/run.sh`).
4. Commit, tag `vx.y.z`, push; CI must be green.
5. `scripts/package.sh` — builds, checks, packs and, with key and certificate
   present, signs (`appinfo/signature.json` inside the archive plus the archive
   signature printed at the end). The key goes into the signing container only
   through stdin into a tmpfs; nothing is left in a container or temp directory.
6. Attach `dist/folder_retention-x.y.z.tar.gz` to a GitHub release of the tag.
7. Store → "Upload app release": the release's download URL and the printed
   signature (paste it without line breaks).
8. After publishing: install from the store on a throw-away instance and check
   `occ integrity:check-app folder_retention` is clean.
