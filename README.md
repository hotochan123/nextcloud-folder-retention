# Folder Retention — retention periods for Nextcloud folders

> **Vibe-coded:** Folder Retention was written by AI coding agents (Anthropic's
> Claude Code), not by hand. The maintainer set the goals, made the product
> decisions and tested the app; there has been no independent human code review
> and no security audit. Details: [How this app was built](#how-this-app-was-built-ai-disclosure).

Folder Retention (German UI: "Ordner-Aufbewahrung") lets Nextcloud admins
attach a retention period — "delete after 2 weeks", "never delete" — to
folders: personal folders, folders of shared work-space accounts and Team
folders (groupfolders). Subfolders inherit the rule or carry their own
exceptions. A daily background job evaluates every file by its current
location and moves expired files to the **trash bin**.

- [What it does](#what-it-does)
- [Safety model](#safety-model)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Migration](#migration)
- [occ commands](#occ-commands)
- [Limitations](#limitations)
- [Development](#development)
- [How this app was built (AI disclosure)](#how-this-app-was-built-ai-disclosure)
- [Licence](#licence)

The full administrator guide — installation, update, downgrade, removal — is
[`INSTALL.md`](INSTALL.md).

## What it does

- **Rules on folders.** A rule has a period in days, weeks or months, or
  "never", and applies to the folder and its subfolders or to this level only.
  The nearest rule above a file wins. Two default rules cover everything
  without a folder rule: one for Team folders and work-space accounts, one for
  personal folders.
- **Work-space accounts.** Function accounts whose folders are shared with
  groups can be marked as work-space accounts; the general default rule then
  applies to them instead of the personal one.
- **Reference date.** By default a period counts from the moment the file
  **arrived in Nextcloud**: its upload time, or — if Nextcloud knows none (for
  example after `occ files:scan`) — the moment the app first saw it. The
  creation date a sync client sends along is ignored, because a freshly synced
  old file would otherwise be due at once. Copies count from when the app first
  sees them. Alternatively a rule can count from the last modification.
- **Daily background job** that moves expired files to the trash bin; the
  trash bin's own retention (`trashbin_retention_obligation`) decides when
  they are gone for good.
- **Admin settings page** with a folder tree showing the rule that applies to
  each folder, a preview of the affected files (with the rule each match comes
  from), and a "Deletions" section with two tabs: **Upcoming** – every file of
  every area that becomes due within a chosen time span, by due day and folder,
  calculated live from the current rules – and **Log** – what the runs did,
  grouped by day and folder, with the number of affected files, filters and
  CSV export.
- **Optional system tags** such as "Retention: 2 weeks" on files and folders,
  so users can see the period in the file list — in one language of your
  choice or language-neutral ("⌛ 2 w"). They are purely informative and do
  not control anything.
- **Deletion date in the Files app**: a badge next to each file that has one
  ("Oct 29", "in 3 days", "Due") and a **Deletion** tab in the sidebar with
  date, rule and reference date. Every account sees it for the files it can
  access; it is a forecast from the folder rules. Can be switched off.

**How it differs from "Retention" (files_retention).** Nextcloud's own
Retention app deletes files by collaborative tags and Flow rules. Folder
Retention attaches the periods to folders and checks that every file arrives
in the trash bin. Both work; do not let both delete on the same
instance (see [Migration](#migration)).

## Safety model

The app is built so that a mistake costs a trip to the trash bin, not data.

- **Simulation mode is on after installation.** Expired files are only logged
  as "simulation". Switching simulation off needs password confirmation;
  disabling or removing the app switches it back on, so a reinstall never
  starts deleting by itself.
- **Both default rules start with "never delete"** on a new installation.
- **Trash bin only, verified.** Each file is deleted in its owner's file
  system context, and the app checks afterwards that the trash bin entry
  exists. If it cannot verify it, the log says "permanently deleted — trash bin
  bypassed" and the app **blocks that area**: nothing is deleted there until an
  admin lifts the block (settings page or `occ folder_retention:run
  --unblock=<key>`). Same-named files in the same second, which Nextcloud would
  let overwrite each other in the trash bin, are spaced out and re-checked.
- **Real deletion only from system cron or occ.** In the AJAX and Webcron
  modes the job runs in an anonymous web request whose headers could make
  Nextcloud bypass the trash bin; there the app neither deletes nor simulates
  and shows a banner instead.
- **Fresh check before each deletion.** Location, rule and date are resolved
  again right before a file is moved; a file moved or changed in the meantime
  (modification time, size or etag) is skipped. Files ending in `.part` and folders are never deleted; shares are
  evaluated through the owner, never deleted through a share mount.
- **Quota-aware.** If the trash bin would purge the file at once because of
  quota or `trashbin_size`, the file stays.
- **Restores are respected.** A file restored from the trash bin starts a new
  period from the moment the app sees it again.
- **Optional deletion limit.** At most this many files per run; reaching it
  halts deletion until an admin resumes it.
- **One run at a time.** `occ folder_retention:run` and the background job share
  a lease; switching simulation on takes effect even in a run that is already
  going.

## Requirements

| What | Requirement |
|---|---|
| Nextcloud | 34 or 35 |
| PHP | 8.2 or newer |
| Background jobs | **System cron** — with AJAX or Webcron the app does nothing (see above) |
| Trash bin | "Deleted files" (files_trashbin) enabled for the accounts whose folders have rules |
| Optional | Team folders (groupfolders) |

## Installation

From the App Store: Administration → Apps → "Folder Retention", or
`occ app:install folder_retention`. Manual installation from a release archive,
Docker examples and update notes: [`INSTALL.md`](INSTALL.md#2-install).

Afterwards check:

```bash
occ folder_retention:run --dry-run      # what would be deleted — deletes nothing
```

## Configuration

Everything is in **Administration settings → Folder Retention**:

1. Mark work-space accounts (an account appears after its first login).
2. Set the two default rules — both start with "never delete".
3. Attach rules to folders; choose the period, whether it applies to
   subfolders, and whether it counts from arrival in Nextcloud or from the last
   modification. Add "never delete" exceptions on subfolders.
4. Check the effect with "Show affected files" or `occ folder_retention:run
   --dry-run`.
5. Optionally switch on the retention tags. The deletion date in the Files app
   is on by default.

Settings stored in the app config: `simulation_mode` (boolean),
`log_retention_days` (30, 90, 180, 365, 730 or 1825; default 365),
`deletion_limit` (files per run, 0 = no limit), `files_info` (deletion date in
the Files app, default on) and `job_time_budget` (seconds
per background job call). Details:
[`INSTALL.md`](INSTALL.md#4-set-up).

## Migration

Switching from simulation to real deletion — and from an existing retention
setup — in this order:

1. **Watch the simulation.** Leave simulation mode on for at least one, better
   several daily runs and read the log on the settings page (status "Would
   delete"). `occ folder_retention:run --dry-run` shows the current state
   without writing to the log.
2. **Switch off the old deletion.** If Flow rules or the Retention app
   (files_retention) delete files on this instance, disable them first —
   otherwise two systems delete in parallel, and files the old rules remove
   show up neither in this app's log nor in its safety checks.
3. **Check the cron mode.** Administration settings → Basic settings →
   Background jobs must be "Cron".
4. **Switch simulation off** on the settings page (password confirmation).
   From the next daily run on, expired files go to the trash bin.

To pause again at any time:

```bash
occ config:app:set folder_retention simulation_mode --value=1 --type=boolean
```

Updating from 0.7.x, downgrading and removing are described in
[`INSTALL.md`](INSTALL.md#7-update).

## occ commands

```bash
occ folder_retention:run [--dry-run] [--rule=ID] [--unblock=KEY]
occ folder_retention:tags [--folder=ID] [--remove]
```

- `folder_retention:run` — a complete run now, like the background job.
  `--dry-run` only shows what would be deleted and writes nothing to the log;
  `--rule=ID` limits the run to files governed by that rule; `--unblock=KEY`
  only lifts the safety block of that area (the key is shown by every run,
  repeatable) and deletes nothing. Real deletion follows `simulation_mode`. It
  refuses to start while the background job holds the lease.
- `folder_retention:tags` — sync the retention tags; `--folder=ID` for one
  folder and its contents, `--remove` takes all retention tags off all files.

## Limitations

- **Versions under cron.** When a file is moved to the trash bin, its
  versions go with it in the normal case, and the quota check counts them. In
  a background job without a logged-in user, Nextcloud core may handle
  versions differently; this has not been fully verified.
- **Vetoes are detected only afterwards.** Another app can veto the trash bin
  move (`MoveToTrashEvent`), after which Nextcloud deletes the file
  permanently. The app cannot prevent that; it detects the missing trash
  entry afterwards, logs "permanently deleted" and blocks the area.
- External storages are not evaluated.
- Files from shared folders land in the trash bin of the owning account; for
  Team folders the trash bin and the activity stream name a member as the one
  who deleted (the app's log says who really did).
- Many files with the same name in one trash bin make a run slower (one per
  second).
- No notifications before deletion yet.

## Development

Sources: PHP in `lib/` and `templates/`, Vue 3 frontend in `src/` (built with
Vite into `js/`), translations in `l10n/`. Node and Composer run in
containers, so nothing has to be installed on the host:

```bash
# frontend: install, build, unit tests
docker run --rm -v "$PWD":/app -w /app node:24-alpine sh -c 'npm ci && npm run build && npm test'

# translations: merge l10n/.php-de.json + l10n/.js-de.json into l10n/de.json,
# generate l10n/de.js, then check that every source string is translated
docker run --rm -v "$PWD":/app -w /app node:24-alpine sh -c 'npm run l10n && npm run l10n:check'

# PHP unit tests (against the OCP stubs of nextcloud/ocp)
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer install -q && vendor/bin/phpunit -c tests/phpunit.xml'

# integration harness against a throw-away Nextcloud (SQLite, ~8 minutes)
dev/it/run.sh                                  # nextcloud:34.0.4-apache
IMAGE=nextcloud:35.0.1-apache dev/it/run.sh    # Nextcloud 35
```

The harness ([`dev/it/README.md`](dev/it/README.md)) starts its own containers,
never touches another instance and replays the data-loss scenarios of the
safety review (trash bin per account, restores, sync dates, quota, same-named
files, web cron, updates, encryption in Team folders).

Release archive: `scripts/package.sh` — checks version, changelog, bundle and
translations, validates `appinfo/info.xml` and packs a reproducible
`dist/folder_retention-<version>.tar.gz`; with key and certificate present it
also signs. Store process: [`docs/APPSTORE.md`](docs/APPSTORE.md).

The source language is English. UI strings go through `t('folder_retention',
'…')` / `n('folder_retention', …)` in `src/` and `$l->t('…')` / `->n(…)` in
PHP; the German translation lives in `l10n/de.json` (`l10n/de.js` is
generated).

## How this app was built (AI disclosure)

Folder Retention is vibe-coded. The maintainer
([hotochan123](https://github.com/hotochan123)) did not write the code by hand.
They set the goals, made the product decisions and tested the app on a
Nextcloud instance. Everything else was written by AI coding agents:

- all code — PHP, JavaScript/Vue, CSS and the database migrations;
- the tests (PHPUnit, Node unit tests) and the integration harness;
- the documentation, including this README, and the German translation.

**Tool and model.** The agents ran in Anthropic's Claude Code. Every commit
carries a `Co-Authored-By` trailer naming the model (Claude Opus 5.5 for the
whole history, 25 September to 4 October 2026).

**Verification.** Version 0.8 came out of a safety review of 0.7.4 by AI
agents, followed by several further review rounds of the same kind; the fixes
are listed in [`CHANGELOG.md`](CHANGELOG.md). The app is checked by a PHPUnit
suite, Node unit tests for the frontend logic and an integration harness that
runs the data-loss scenarios against a throw-away Nextcloud 34 and 35. All of
them were written by the same AI agents, so they check the code against the
agents' own reading of the requirements. CI (`.github/workflows/ci.yml`) runs
the build, the unit tests, the translation and schema checks and PHPUnit; the
harness needs Docker and is run by hand. There has been **no independent human
code review and no security audit.** How to report a problem:
[`SECURITY.md`](SECURITY.md).

**What this means for you.** The app deletes files. Evaluate it yourself
before you rely on it, keep simulation mode on until the log shows exactly
what you expect, and keep backups. Issues and reviews are welcome
([issue tracker](https://github.com/hotochan123/nextcloud-folder-retention/issues)).

## Licence

AGPL-3.0-or-later, see [`LICENSE`](LICENSE). Folder Retention comes without any
warranty. The licences of the bundled JavaScript libraries are listed in
`js/folder_retention-main.mjs.license`.
