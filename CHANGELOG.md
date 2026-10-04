# Changelog

All notable changes to Folder Retention are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

Release notes on the Nextcloud App Store are taken from the topmost sections of
this file, so every published version needs an entry here.

## [Unreleased]

Preparation for the Nextcloud App Store.

### Added
- English is now the source language of the whole app (settings page, log,
  occ commands, background job messages); the German translation ships in
  `l10n/de.json`. `npm run l10n` builds the translation files and
  `npm run l10n:check` reports missing, orphaned or malformed translations.
- Nextcloud 35 is supported (`max-version="35"`); the safety harness passes on
  Nextcloud 34.0.4 and 35.0.1.
- App Store metadata: English name, summary and description with German
  variants, licence `AGPL-3.0-or-later`, website, issue tracker and repository.
- `README.md`, `SECURITY.md`, this changelog, the `LICENSE` file and a CI
  workflow (bundle, JavaScript tests, translations, schema check of
  `info.xml`, test archive, PHPUnit on Nextcloud 34 and 35).
- `scripts/package.sh` packs a reproducible release archive (fixed file
  times, sorted, owned by 0:0), checks versions, changelog, bundle and
  translations, leaves source maps out, and signs the release when the key
  and the certificate are present; `scripts/certificate.sh` creates the key and
  the certificate request.
- Log retention: entries older than a selectable period (30 days to 5 years,
  default 1 year; app config `log_retention_days`) are removed after each
  daily cycle. Real deletions of files that still exist (in the trash bin or
  restored) stay, because a restored file starts its period from the restore.
  "First seen" entries of files that no longer exist are removed as well.
- Deleting an account removes its log entries (its personal folder) and its
  work-space marking.
- Optional deletion limit (emergency brake, app config `deletion_limit`, off
  by default): once a cycle has moved that many files to the trash bin,
  deletion halts until an admin resumes it on the settings page. Guards
  against a period set far too short on a large folder or a wrong server
  clock.

### Fixed
- Error labels in the log were white on a light background and hard to read.
- The check right before a deletion also compares size and etag, not only the
  modification time: a client can upload new content and keep the old mtime.
- The same error or skip reason for a file is no longer added to the log on
  every nightly run; the existing entry moves to the latest occurrence.
- "Expand all" also opens the personal folders (their folder trees up to 50
  accounts), and opens at least the first level of a folder too large to load
  at once.
- The table of affected files no longer pushes the due date and size out of
  view when file paths are long.

### Changed
- Texts that are shared by the whole instance — system tag names, the log's
  rule labels and messages, lock reasons — are written in one fixed content
  language (app config `tag_language`). Existing installations keep German,
  so their tags keep their names; new installations use the instance's
  `default_language` if the app ships it (`de_DE` counts as `de`), or
  English. These texts come only from the app's own translation file, so
  `forceLanguage` and `force_language` cannot change them, and a broken
  translation falls back to English instead of failing. The settings page
  and occ follow the language of the user.
- The log is grouped by day and, within a day, by folder, each with the number
  of files per status; a folder opens to its files. A status shown twice
  ("Would delete · Simulation") is shown once, the source of the reference
  date only when it is not the usual one, the date range sits behind
  "Date range", and the mode filter is gone (the status says it). New
  endpoints `/api/log/days` and `/api/log/folders` (needs `from` and `to`,
  at most 31 days apart), `/api/log` takes `folder`. The account that moved
  a file to the trash bin is shown under the file name.
- Dates, sizes and sorting on the settings page follow the user's locale.
- The rule objects in the API no longer carry `label` by themselves; the
  endpoints add it in the request language.
- `INSTALL.md` is now an English administrator guide.
- Author and links point to the public project on GitHub.

## [0.8.2] - 2026-10-04

### Fixed
- A file restored from the trash bin counts from the moment the app sees it
  again, not from its last deletion. A file restored later than one retention
  period after it was deleted is no longer moved back to the trash bin the next
  night.
- If moving a file to the trash bin threw an error, its path stays blocked for
  the rest of the process — also in a long-running `occ background-job:worker` —
  without blocking parent folders by mistake.
- At the end of a run the app checks this run's trash bin entries again. If a
  user overwrote one within the same second, the log entry is corrected to
  "permanently deleted".
- The copy boundary is derived without turning unseen copies into old files;
  simulation entries are no longer logged again for every rule; deleting from
  encrypted Team folders is no longer reported as a permanent deletion.

## [0.8.1] - 2026-10-04

### Fixed
- Files with the same name deleted within one second no longer overwrite each
  other in the trash bin (Nextcloud names trash entries `<name>.d<second>`),
  also with shortened long names and across runs. The app waits for the next
  second before the next file with the same name and checks afterwards that
  the earlier entries are still there.
- Real deletions only run from system cron or `occ`. With AJAX or Webcron the
  job runs in an anonymous web request in which a request header could make
  Nextcloud bypass the trash bin; in these modes the app now neither deletes
  nor simulates and shows a banner on the settings page.
- The trash bin is always resumed after an error, so later jobs in the same
  process keep using it.
- Copies are recognised by a file ID boundary set at install or update.
- Safety blocks live in their own table and are read fresh, not from the
  process-wide app config cache.
- The trash space check includes versions and object-storage accounting.

### Changed
- Disabling or removing the app switches simulation mode back on, so a
  reinstall never starts deleting by itself.
- `INSTALL.md` covers downgrade, removal and reinstall.

## [0.8.0] - 2026-10-04

Safety rework after a review of 0.7.4.

### Security
- **No silent permanent deletion.** Each file is deleted in its owner's
  file system context, and the trash bin entry is verified afterwards. If it
  cannot be verified, the log says "permanently deleted — trash bin bypassed",
  and the app blocks that area until an admin lifts the block (settings page
  or `occ folder_retention:run --unblock=<key>`).
- Rule changes need password confirmation.
- Never deletes through share mounts.

### Changed
- The retention period counts from the moment the file **arrived in
  Nextcloud** (upload time, or the time the app first saw it), no longer from
  the creation date a sync client sends along. Files without an upload time get
  a "first seen" date instead of falling back to the modification time.
- New installations create both default rules with "never delete".
- Location, rule and date are resolved again right before each deletion; a
  file that changed in the meantime is skipped (`skipped_changed`).
- A restored file is not due again right away.
- `occ folder_retention:run` and the background job share a run lease and
  never run at the same time.
- Errors are logged per file instead of aborting the run.

### Added
- Integration harness `dev/it/run.sh` against a throw-away Nextcloud.

## [0.7.4] - 2026-09-28

### Added
- The preview shows which rule applies to each file and explains matches from
  subfolders.

## [0.7.3] - 2026-09-28

### Added
- Log view with filters (mode, status, period, path), paging and CSV export.

## Earlier versions (0.1.0 to 0.6.0, 25 to 28 September 2026)

### Added
- Folder rules with periods in days, weeks or months, or "never", applying to
  subfolders or to one level only; resolver and daily background job with
  simulation mode; admin settings page with folder tree and detail panel.
- `occ folder_retention:run`, optional retention system tags
  (`occ folder_retention:tags`), immediate tagging of uploads through shares.
- Work-space accounts: function accounts with shared folders are treated like
  Team folders.
- Separate default rule for personal folders.
- `scripts/package.sh` and `INSTALL.md`.
