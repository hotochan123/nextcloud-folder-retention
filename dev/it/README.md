# Integration harness (dev/it)

Replays the data-loss scenarios from the 0.7.4 audit against a **throwaway Nextcloud**
(container `fret-it-<pid>`, SQLite, no published port). The production instance is never
touched; neither is the App Store (groupfolders comes as a source tarball from GitHub).

## Usage

```bash
dev/it/run.sh                                   # working tree against nextcloud:34.0.4-apache
IMAGE=nextcloud:35.0.1-apache dev/it/run.sh     # against NC 35 (groupfolders v23.x)
APP_SRC=/path/to/unpacked/tree dev/it/run.sh    # a different tree, e.g. the baseline:
#   git archive 5823086 | tar -x -C /tmp/fret-baseline && APP_SRC=/tmp/fret-baseline dev/it/run.sh
ONLY=S1,S2 dev/it/run.sh                        # only selected scenarios
KEEP=1 dev/it/run.sh                            # keep the container + logs
```

More switches: `GF_TAG=v22.0.6` (pin the groupfolders release), `NO_GF=1` (S8 = SKIP),
`OLD_REV=fb4395c` (starting state of the update scenario S24, taken from the repo via `git archive`;
the harness raises its max-version in the copy to that of `APP_SRC` so S24 also runs on NC 35).
If `APP_SRC` has no `js/`, the harness builds it with `node:24-alpine` (`npm ci && npm run build`,
writes `node_modules/` and `js/` into `APP_SRC`).

Output goes to stdout, one line per scenario, then the totals; diagnostics go to stderr:

```
PASS|FAIL|SKIP <id> <text>
Harness <pass>/<fail>/<skip>
```

Exit code 1 as soon as any scenario FAILs. On failures the occ output of every run stays in
`$TMPDIR/fret-it.*` (the path is printed at the end on stderr).

**Runtime:** about 7.5 minutes (04.10.: 34.0.4 459 s, 35.0.1 460 s, 27/0/0 each; with S28/S29 34.0.4 455 s, 29/0/0; with S30/S31 34.0.4 504 s, 31/0/0; baseline 0.7.4: 184 s) (NC installation ~20 s, S10 creates 600 files, S23, S24, S26, S27 and S31 each start their own instance). With a baseline
without `js/`, the npm build adds ~1 min.

## Scenarios

| ID  | checks | finding |
|-----|-------|--------|
| S1  | alice, bob, carol one due file each, ONE run → all three in their own trash bin, log `deleted` | B1 |
| S2  | run → `occ trashbin:restore` → next run does not delete again | B2 |
| S28 | dave: run moves the file, deletion time in the log set to 10 days ago (rule 1 day), `occ trashbin:restore` → next run: file stays, `first_seen` = time of that run; with `first_seen` 2 days ago due again → trash bin | Review 0.8.1 (restore later than one retention period) |
| S29 | erin: `proc.php trashthrow` – two runs in ONE process (like `occ background-job:worker`), in the first the locking backend throws in `Trashbin::move2trash` → both `error`, the second with "Prozessneustart" (German log text), file stays (without a block: `deleted_final`, LegacyTrashBackend::deletedFiles) | Review 0.8.1 (exception in the trash bin, long-lived process) |
| S3  | uploaded today via WebDAV with `X-OC-CTime` 2015 → not due | B3 |
| S4  | via the file system + `occ files:scan` (no upload_time), mtime 2015 → not due in the first run | B3/F4 |
| S5  | fresh installation: both default rules "never", simulation mode off + run → 0 deletions | B4 |
| S6  | files_trashbin only for one group, owner not in it → file stays (or is in the trash bin), never permanently gone | B6 |
| S7  | simulation mode on → nothing deleted, log `simulation` | – |
| S30 | simulation mode on, file due → 1 entry; entry set to the 0.7.x state (reference `mtime`, different date) → next run logs again, the one after does not; default rules 1 → 2 days (same rule ID) → new again, then no repetition | Review 0.8.1 (simulation deduplicates across a stale evaluation) |
| S8  | team folder (groupfolders) → file in the groupfolders trash bin (`oc_group_folders_trash` + disk); earlier in the same run a personal file of bob (account switch) | B1 |
| S11 | team folder file additionally shared directly with a member → in the groupfolders trash bin, not just the share gone | B7 |
| S9  | alice shares with bob, file due → ends up in alice's trash bin | B7 |
| S12 | quota almost full (ivan, 20 MB): a small due file fits in the trash bin, a large one would be cleared by expire right away → stays (log "Quota"); expire commands run afterwards → nothing permanently gone | Review 0.8 |
| S13 | `occ trashbin:size 1GB` (value of the wrong type), three due files in two accounts → no file missing on disk and in the trash bin | Review 0.8 |
| S14 | original 700 days old, copied via WebDAV COPY (the copy inherits the upload time) → original in the trash bin, copy stays | Review 0.8 |
| S18 | `seen_max_fileid` boundary set after installation; "update now" simulated (boundary = MAX(fileid), no cycle completed), then a copy of a 700-day-old file → copy stays for two runs, original in the trash bin | Review 0.8 (transition window) |
| S16 | peggy: dA/dB/dC/Bericht.txt due, one run → all three as `Bericht.txt.d*` in the trash bin, each with its own content; with groupfolders also two files of the same name in the team folder | Review 0.8 (trash bin name per second) |
| S19 | two blocks (A, B) in `oc_folder_retention_block`; the API lifts only A, B with a stale timestamp stays; `occ folder_retention:run --unblock=it:b` lifts B; initial state restored afterwards | Review 0.8 (lifting a block) |
| S20 | process P (`proc.php wait`, like cron.php) has read the block list, Q sets a block → P sees it, P's own block leaves Q's in place; a block in the old app config value `blocked_roots` moves into the table on the next access | Review 0.8 (block list in the process cache) |
| S21 | walter: two 245-character names that differ only in the middle (files_trashbin truncates them identically), one run → both in the trash bin with their own content, log `deleted` × 2 | Review 0.8 (long names in the trash bin) |
| S22 | rupert: move s22A/Protokoll.pdf, end of run (`releaseContext`, the run's memory empty), immediately s22B/Protokoll.pdf – at the start of a second, i.e. without protection within the same second (`proc.php samesecond`) → both in the trash bin with their own content | Review 0.8 (trash bin name across run boundaries) |
| S15 | quota tight (oscar, 20 MB), due 1 MB file with 3 × 1 MB versions: the versions move into the trash bin too → expire would clear it → file stays (log "Versionen", German log text); never permanently gone | Review 0.8 (versions) |
| S23 | separate instance `$C-os` with an object store as primary storage (`objectstore/`: FretDirObjectStore, objects as files, without S3). olga: quota 10 MB, 3 MB staying, 1.4 MB already in the trash bin, 2 MB due → after a run + `trashbin:expire` not permanently gone (stays with log "Quota") | Review 0.8 (usage with an object store) |
| S24 | separate instance `$C-up`: app from `OLD_REV` (fb4395c, 0.8.0), cycle in simulation mode → `seen_since`, then WebDAV COPY of a 400-day-old file (protected there) → working tree + `occ upgrade` → `seen_since` deleted, `seen_max_fileid` < ID of the copy, real run: copy stays, original in the trash bin | Review 0.8 (update fb4395c → 0.8.1) |
| S10 | `background-job:execute --force-execute` in the background + `occ folder_retention:run` in parallel → no duplicate log, nothing lost, block message in the occ text | B9 |
| S17 | `background:ajax`, only the RetentionJob due, anonymous call of `cron.php` with `X-NC-Skip-Trashbin: true` → job runs, file stays, no log, no new block; `/api/settings` reports `cronMode: ajax`; then back to `background:cron` | Review 0.8 (web cron) |
| S25 | team folder with carol and bob, due file; earlier in the same run carol's own file (context carol) → team file deleted via bob (first member when sorted): `oc_group_folders_trash.deleted_by = bob`, log `deleted (… über Konto bob … gelöscht hat folder_retention)` (German log text); carol's file with "über Konto carol" | Review 0.8 (deleting account in trash bin/activity) |
| S26 | separate instance `$C-rm`: rules 1 day, simulation mode off → `occ app:remove` (without `--keep-data`) → reinstalled: `simulation_mode` = 1 (uninstall step), rules unchanged, due file stays (log `simulation:would_delete`) | Review 0.8 (reinstall after app:remove) |
| S27 | separate instance `$C-rs`: `occ app:remove`, then exactly the SQL block from INSTALL.md §9 ("clean up completely") → reinstalled: 5 tables, simulation mode on, both default rules "never", `--dry-run` without SQL errors | Review 0.8 (tables deleted by hand) |
| S31 | separate instance `$C-enc`: groupfolders + `occ encryption:enable-master-key` + `groupfolders enable_encryption=true`, team folder file and personal file due, one run → both `deleted`, team folder file in the groupfolders trash bin (there under a NEW fileid, `oc_group_folders_trash.file_id` = the old one), no block | Review 0.8.1 (encryption in the team folder) |
| S32 | log overview via the API: four entries (folder with `%`, `_`, `[` in its name, a subfolder, a neighbouring folder, a path without a folder) → `/api/log?folder=` returns only the direct files each, `/api/log/folders` and `/api/log/days` count correctly | Log by day and folder (`notLike()` without `ESCAPE` on SQLite) |
| S33 | log retention: old entries removed after a completed cycle, an old real `deleted` of a file that still exists and a recent error stay; "first seen" of a vanished file removed | Audit 05.10. (log retention) |
| S34 | deleting an account removes its log entries (home storage) and its work-space marking; entries of other areas stay | Audit 05.10. (account cleanup) |
| S35 | the same error twice moves the entry instead of adding one (`proc.php repeat`); deletion limit 2: run 1 deletes two and halts, run 2 deletes nothing, after "resume" the rest | Audit 05.10. (repeats, deletion limit) |
| S36 | two areas with the same name stay apart in `/api/log/folders` (`root`) and the folder filter; real runs write `root_key`; CSV export streamed with BOM and attachment headers; migration 1004 as a single step on an existing installation | Audit 05.10. (stable area key, streamed CSV) |
| S37 | superseded simulated hits: rule change marks them at once, overview groups and status filter keep them apart, a new simulated run logs fresh hits, a file moved to the trash bin is marked by `purge`, a real run marks deleted ones; migration 1005 marks existing entries whose rule changed or is gone | "Would delete" that no longer applies |
| S38 | language of the tags from the settings: German, neutral ("⌛ 1 d"), English; each switch queues a full tag sync, files carry exactly the new tag, the app's old tags are deleted, a foreign tag stays; an unknown language is rejected (400) | Tag language |

Expectations are deliberately tolerant (file present / in the trash bin / somewhere on disk instead
of exact status strings) so the 0.8.0 implementation is checked fairly. S10 detects the block
message by `gesperrt|läuft bereits|Sperre|locked|already running` (German or English log text)
outside the result table.

## How files "age"

Directly in the SQLite DB (`data/nextcloud.db`, prefix `oc_`), helper `age()`:

- `oc_filecache.mtime` → reference for basis=modified (and fallback of the 0.7.x chain)
- `oc_filecache_extended.upload_time` → "since stored in Nextcloud"
- `oc_filecache_extended.creation_time` → client creation time (`X-OC-CTime`)
- `oc_folder_retention_seen.first_seen` → "first seen" (since 0.8.0). Without that entry, a freshly
  created file with an old upload time would count as a copy and be counted from the first run.

`storage_mtime` stays unchanged so no scanner resets the entry. S4 instead ages the real
file (`touch -d 2015-01-01`) and lets `files:scan` pick it up.
The harness also sets rules via SQL (`oc_folder_retention_rules`, `folder_id IS NULL`:
both default rules at 1 day from creation) so the API's password confirmation does not get in the way.

## Pitfalls

- **AJAX cron off:** the harness sets `background:cron`, otherwise every WebDAV request would
  start the RetentionJob along with it, uncontrolled. Only S17 briefly switches to AJAX and for that sets
  `last_checked` of all other jobs to now, so that `cron.php` picks exactly the RetentionJob.
- **Files with the same name:** `fid` finds only the newest file of a name; S16 ages via
  `fid_path`/`age_id` using the path in the home.
- **"Seen" accounts:** RootProvider only knows accounts with `lastLogin` and mounts in `oc_mounts`.
  A WebDAV PROPFIND (`touch_fs`) takes care of both – needed again for new team folder mounts too.
- **files_trashbin for groups:** `occ app:enable --groups` refuses that for filesystem apps
  (and switches the app to `no` while doing so!). S6 therefore sets `files_trashbin enabled` directly via
  `config:app:set` and restores `yes` afterwards – which is why S6 runs last.
- **groupfolders without JS:** the GitHub source tarball has no built frontend; that is enough for occ and
  WebDAV. Release choice: newest `v(NC-12).x` according to the GitHub API.
- **S10 needs overlap:** the occ run only starts once the job has written the first line to the log.
  If the job does not start at all or finishes before that → FAIL with a hint.
- **Order:** S5 first (state right after installation), S6 last. Every scenario has
  its own file names; later runs also process leftover due files of earlier scenarios.
- **Second process:** `proc.php` boots Nextcloud (`lib/base.php`) inside the container and keeps the
  app config in its own process cache – this lets it simulate a long-running cron.php (S20).
- On the development machine, host binaries are not executable and there is no python3: everything runs via
  `docker exec` (curl, php) in the throwaway container; `sql.php` and `proc.php` are copied in for that.
