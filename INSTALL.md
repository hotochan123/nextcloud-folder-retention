# Folder Retention (folder_retention) — administrator guide

How to install, set up, update and remove the app. After installation
**simulation mode is on**: nothing is deleted, expired files are only logged.
An overview of the app is in the [README](README.md).

## 1. Requirements

| What | Requirement |
|---|---|
| Nextcloud | **34 or 35** (the app cannot be enabled on other versions) |
| PHP | 8.2 or newer (whatever your Nextcloud version requires) |
| Background jobs | **Cron** (system cron; Administration settings → Basic settings → Background jobs). With "AJAX" or "Webcron" the job runs in an anonymous web request to `cron.php`, and its request header `X-NC-Skip-Trashbin` would let Nextcloud delete past the trash bin. The app then **neither deletes nor simulates** and says so at the top of its settings page; `occ folder_retention:run` keeps working. |
| Trash bin | The "Deleted files" app (files_trashbin) enabled — visible as "Deleted files" at the bottom left of the Files app. The app checks this per account: if files_trashbin is only enabled for certain groups, nothing is deleted in the folders of all other accounts. Space in the trash bin counts as well, see "Quota and trash bin size" below. |
| Access | A shell on the server or `docker exec` into the Nextcloud container, plus a Nextcloud admin account |
| Optional | Team folders (groupfolders) — supported, not required |

**Quota and trash bin size.** After a move, Nextcloud cleans up the trash bin
by itself, and permanently: if a maximum size `trashbin_size` is set, as soon as
the trash bin grows beyond it; otherwise, for accounts with a quota, as soon as
the trash bin takes more than half of the free quota. Team folders with a quota
are emptied by groupfolders as soon as content and trash bin together exceed
the quota. The app calculates this before every deletion, including the files
already moved in the same run. If a file no longer fits, it stays where it is
(log: error "trash bin would purge it at once because of quota/size"). Remedy:
raise the quota or empty the account's trash bin. If `trashbin_size` was set
with `occ trashbin:size`, Nextcloud 34 stores the value with a type that
files_trashbin itself fails on. The app then deletes nothing and reports it;
remedy: `occ config:app:delete files_trashbin trashbin_size`, then set the value
again with `occ config:app:set files_trashbin trashbin_size --value=<bytes>`.

Tip: the daily run starts in the maintenance window
(`maintenance_window_start` in config.php, in UTC). Without one it runs at
some time of the day.

In the examples below `occ` stands for `sudo -u www-data php occ` in the
Nextcloud directory, or `docker exec -u www-data <container> php occ`.

## 2. Install

### a) From the App Store

Administration → Apps → search for "Folder Retention" → Download and enable,
or:

```bash
occ app:install folder_retention
```

### b) From a release archive

Check the archive first:

```bash
sha256sum -c folder_retention-<VERSION>.tar.gz.sha256
```

The archive contains one folder `folder_retention/`. It belongs in a writable
app directory, usually `custom_apps/` (see `apps_paths` in config.php).

Nextcloud directly on the server:

```bash
cd /var/www/nextcloud/custom_apps          # adjust to your instance
sudo tar -xzf /path/to/folder_retention-<VERSION>.tar.gz
sudo chown -R www-data:www-data folder_retention
sudo -u www-data php /var/www/nextcloud/occ app:enable folder_retention
```

Nextcloud in a Docker container (official image):

```bash
CONTAINER=nextcloud-app                     # name of the Nextcloud container
docker cp folder_retention-<VERSION>.tar.gz "$CONTAINER":/tmp/
docker exec "$CONTAINER" sh -c 'cd /var/www/html/custom_apps \
  && tar -xzf /tmp/folder_retention-<VERSION>.tar.gz \
  && chown -R www-data:www-data folder_retention \
  && rm /tmp/folder_retention-<VERSION>.tar.gz'
docker exec -u www-data "$CONTAINER" php occ app:enable folder_retention
```

If `custom_apps` is a volume on the host, you can also unpack the archive
there directly (then set the owner to the web server user, UID 33 in the
official image).

### What happens on first enable

The app creates its tables, switches simulation mode on and creates the two
default rules — both with **"never delete"** (not if the app was installed
before — see [9. Remove](#9-remove)):

- **Default rule** (Team folders and work-space accounts): never delete
- **Default rule for personal folders**: never delete

So nothing is deleted until a period is set on purpose (on a default rule or on
folders). An update leaves existing rules unchanged.

## 3. Check the installation

```bash
occ app:list | grep folder_retention        # shows the version
occ folder_retention:run --dry-run          # shows what would be deleted — deletes nothing
```

In the web interface: **Administration settings → Folder Retention**. The
yellow notice that simulation mode is on must be shown at the top.

If the page stays empty or looks old after an update, reload it (Ctrl+F5) —
otherwise the browser keeps the old script.

## 4. Set up

1. **Mark work-space accounts.** Function accounts whose folders are shared
   with groups first appear under "Personal folders". Select the account in
   the tree and switch on "Work-space account" — then the general default rule
   applies there. Note: an account only appears after it has logged in at
   least once.
2. **Set the default periods** (click "Default rule" and "Personal folders" in
   the tree) — on a new installation both are set to "never delete". Saving and
   removing rules asks for password confirmation.
3. **Attach rules to folders:** select a folder, choose a period and what it
   applies to (subfolders too, or this level only), save. Set exceptions such as
   "never delete" on subfolders.

   **"Period counts from"**: *arrival in Nextcloud* (default) or *last
   modification*.
   - *Arrival in Nextcloud* is the moment the file was uploaded — not the
     creation date the sync client sends along (when old files are synced, it
     would lie years back). If Nextcloud knows no upload time (for example after
     `occ files:scan` or for old files), the app records "first seen" in its
     first run and counts from then.
   - In Nextcloud, copies inherit the upload time of the original. So that a
     copy made today does not count as "stored years ago", the app records the
     highest file ID at install or update time. Every file created after that
     (copies always get a new ID) counts at the earliest from the moment the app
     first sees it. Files that already existed at the update keep their upload
     time; moving and restoring do not change the ID.
   - *Last modification* is the file's modification time; a date in the future
     counts as "now".
   - If someone restores a deleted file from the trash bin, the period starts
     again from the moment the app sees the restored file — it does not vanish
     again the next night.
4. **Check the effect:** "Show affected files" in the detail panel, or
   `occ folder_retention:run --dry-run`.
5. **Optional: tags** (Settings → show the period as a tag on files and
   folders). Every file then shows its period, for example "Retention: 1
   week". For display only.

## 5. Watch the simulation, then go live

Wait for at least one, better several daily runs in simulation mode and check
the log:

```bash
occ folder_retention:run --dry-run          # current state, without writing the log
```

Only then switch simulation mode off in the settings (password confirmation).
From then on the daily run moves expired files to the trash bin. How long they
stay there is up to Nextcloud (`trashbin_retention_obligation`).

**Who is named as the deleting account in the trash bin?** So that Nextcloud
puts the file into the right trash bin, the app deletes in the name of the
account that owns the file — for Team folders in the name of a member (always
the first suitable one in alphabetical order of account names). The trash bin
("Deleted by bob" in the Team folder), the activity stream ("You deleted …" or
"bob deleted …") and the audit log therefore name this account. **The actual
deletion was done by folder_retention.** For attribution the app's log also
names the account ("moved to the trash bin via account bob …"): if that entry
matches the file and the time, it was the app and not that person.

Right before each deletion the app checks the file's location, rule and date
afresh. If the file has been moved or changed in the meantime (modification time,
size or etag), or another rule applies, it is not deleted (log: "skipped — changed"); the next run evaluates
it again.

**Safety block.** After each deletion the app checks that the file has arrived
in the trash bin. If it is permanently gone instead (status "permanently
deleted — trash bin bypassed", plus an error in the Nextcloud log), the app
blocks that area: nothing is deleted there until an admin has found the cause
and lifted the block — at the top of the settings page (lift the block per
area) or with `occ folder_retention:run --unblock=<key>` (every
`occ folder_retention:run` shows the key). Only the block shown is lifted; if
another area has been blocked in the meantime, its block stays. The block
applies to the account's storage — also if the account is later switched
between personal and work-space.

**Same file names.** Nextcloud names trash bin entries `<name>.d<second>` and
permanently overwrites an entry with the same name from the same second. The
app therefore waits until the next second before each further file with the
same name in the same trash bin (many files with the same name, such as
`scan.pdf`, make the run slower accordingly) and then checks that the earlier
entries are still there. If one is missing, its log entry is corrected to
"permanently deleted", the area is blocked and the run stops.

If the trash bin throws an error while moving, the app deletes nothing more for
the rest of the run (Nextcloud could otherwise delete further files past the
trash bin in the same process). The file is logged as an error with "further
deletions stopped for this run", plus a warning in the Nextcloud log; the next
run tries again.

**Deletion limit (optional).** In the settings, "Maximum deletions per run"
caps how many files one run may move to the trash bin (0 = no limit, the
default). When a run reaches it, deletion halts — in that run and every later
one — with a banner at the top of the settings page and a warning in the
Nextcloud log, until an admin clicks "Resume deletion" after checking the log
and the rules. Simulation mode is not affected. **Mind the first live run:**
everything that has piled up during the simulation is due at once, so set the
limit above that number (see the simulation log) or resume after checking.

If Flow rules or the "Retention" app (files_retention) still delete files on
the instance: switch them off first, otherwise two systems delete in parallel.

## 6. Useful commands and settings

```bash
occ folder_retention:run [--dry-run] [--rule=ID]   # full run now (not next to the background job)
occ folder_retention:run --unblock=<key>           # only lift the block of this area, delete nothing
occ folder_retention:tags [--folder=ID] [--remove] # sync or remove the tags

occ config:app:set folder_retention simulation_mode --value=1 --type=boolean   # simulation on
occ config:app:set folder_retention job_time_budget --value=120 --type=integer # seconds per job call
occ config:app:set folder_retention log_retention_days --value=365 --type=integer # 30/90/180/365/730/1825
occ config:app:set folder_retention deletion_limit --value=500 --type=integer   # 0 = no limit
occ config:app:set folder_retention files_info --value=0 --type=boolean        # no deletion date in the Files app
occ config:app:delete folder_retention deletion_halt                         # resume after the limit was reached …
occ config:app:delete folder_retention cycle_deleted                         # … and count the current cycle anew
```

**Log retention.** After each completed daily cycle the app removes log
entries older than `log_retention_days` (default one year). Entries about
real deletions stay as long as the file still exists — in the trash bin or
restored — because a restored file starts its period from the restore; once
the file is gone for good, its entries expire too. When an account is
deleted, its log entries (its personal folder) and its work-space marking are
removed right away; entries in Team folders stay.

**Deletion date in the Files app.** Files with a deletion date show it next to
their name; the sidebar has a **Deletion** tab (date, rule, reference date,
where the rule is set). The value is the WebDAV property
`{http://nextcloud.org/ns}folder-retention` (JSON), computed per folder
listing only when a client requests it — sync clients never do. It is a
forecast: a rule change, simulation mode, the deletion limit or a lock
postpones the real deletion, and the tab says which of these applies.
Accounts that reach a file through a share see the date but not the names of
the owner's folders. Switching `files_info` off (settings page or occ) removes
both the badge and the tab.

"Simulation on" also affects an `occ folder_retention:run` or job that is
already running: before every deletion the app reads the switch afresh from
the database. From then on the rest of the run is only simulated (log
status "Would delete", warning in the Nextcloud log).

## 7. Update

Rules, settings, tags and log are kept; they live in the database, not in the
app folder.

From the App Store: Administration → Apps → Update, or `occ app:update
folder_retention`.

From a release archive: **replace** the old folder, do not unpack over it —
otherwise files remain that no longer exist in the new version. Docker (paths
as for the installation):

```bash
CONTAINER=nextcloud-app
V=<NEW_VERSION>
sha256sum -c folder_retention-$V.tar.gz.sha256
docker cp folder_retention-$V.tar.gz "$CONTAINER":/tmp/
docker exec "$CONTAINER" sh -c "cd /var/www/html/custom_apps \
  && rm -rf folder_retention \
  && tar -xzf /tmp/folder_retention-$V.tar.gz \
  && chown -R www-data:www-data folder_retention \
  && rm /tmp/folder_retention-$V.tar.gz"
docker exec -u www-data "$CONTAINER" php occ upgrade
docker exec -u www-data "$CONTAINER" php occ app:list | grep folder_retention   # new version?
```

Without Docker the same in the `custom_apps` directory, running `occ` as the
web server user.

`occ upgrade` runs the database migrations of the new version (and briefly
switches on maintenance mode for that). Without this step Nextcloud shows
"Update needed". If PHP OPcache runs with `validate_timestamps=0`, reload the
web server or PHP-FPM afterwards so the new code becomes active.

An `occ folder_retention:run` never runs at the same time as the background
job. If one is running, occ says so and stops; after a crash the lease frees
itself 15 minutes later at the latest.

**Updating to 0.8.x** (from 0.7.x or an early 0.8.0 state) — please note:

- "Period counts from creation" is now **"from arrival in Nextcloud"** (see
  section 4). Files without an upload time count from the first run after the
  update. Files created after the update (higher file ID, copies too) count at
  the earliest from when they are first seen — from `occ upgrade` on, not only
  after the first complete run. From the early 0.8.0 state, the update takes
  over its boundary (`seen_since`): copies that were already treated as new
  under 0.8.0 stay protected. As a result some files become due later than
  before — never earlier.
- Real deletions only run from system cron or occ, no longer with
  AJAX/Webcron (section 1).
- Existing rules stay as they are. Only new installations start with "never
  delete".
- New tables `oc_folder_retention_seen` ("first seen"),
  `oc_folder_retention_lock` (run lease) and `oc_folder_retention_block`
  (safety blocks per area); `occ upgrade` creates them.

**Going back to an older version (for example 0.8 → 0.7.x):** Nextcloud does
**not** refuse this — whoever copies the old package back gets "Update
successful" from `occ upgrade`, and the old version runs. Newer database
changes stay, but the old version does not know them. **Careful with 0.7.x:**
it knows neither "first seen" nor restores from the trash bin, and it counts
files without an upload time (`occ files:scan`, old files) by their
modification time again. Whatever 0.8 protected as fresh — such files, copies,
restored files — would be due in the very next nightly run. 0.7.x does not know
the safety blocks after a permanent deletion either. So **before** going back:

```bash
occ config:app:set folder_retention simulation_mode --value=1 --type=boolean   # only simulate
# or stop it completely:
occ app:disable folder_retention
```

Disabling also switches simulation mode on (from 0.8.1, the app's uninstall
step). After another `occ app:enable` nothing is deleted until simulation is
switched off on purpose — also when Nextcloud disabled the app automatically
during a server update.

Then check with `occ folder_retention:run --dry-run` (under the old version)
what it would delete before switching simulation off again.

## 8. Translations

The app's source language is English; German ships with the app. Nextcloud
picks the language of each account.

Texts the app stores for the whole instance — the names of the system tags,
the rule label and message of each log entry, lock reasons — are written in
one fixed **content language**, app config `tag_language`, so that a tag
keeps its name no matter whose request triggers a run. It is set once:
installations updated from a version before 0.9.0 keep German (their
"Aufbewahrung: …" tags keep their names), new installations take the
instance's `default_language` if the app ships it (`de_DE` counts as `de`),
otherwise English. It is never changed automatically, and `forceLanguage`
or `force_language` do not affect it.

A system tag has exactly one name for all accounts — Nextcloud does not
translate tag names. To switch deliberately, use **Language of tags and log
entries** in the settings: one of the shipped languages, or **Neutral** for
instances whose accounts use different languages (tags then read "⌛ 7 d",
"⌛ 2 w", "⌛ 6 m", "⌛ ∞"; log texts are English; app config
`tag_format=neutral`). The change queues a full tag sync (within a few
minutes with system cron): every file moves to the tag with the new name, and
the app's own tags that no file carries anymore are deleted. Tags created by
anyone else are never touched. The same from the command line:

```bash
occ config:app:set folder_retention tag_language --value=en
occ config:app:delete folder_retention tag_format   # or --value=neutral via config:app:set
occ folder_retention:tags   # moves the files to the new tags, deletes the old ones
```

Log entries written before the switch keep their language. Flow rules that
refer to a retention tag by name or ID must be adjusted after a switch.

## 9. Remove

```bash
occ folder_retention:tags --remove    # take the retention tags off all files
occ config:app:set folder_retention simulation_mode --value=1 --type=boolean   # needed before 0.8.1, see below
occ app:disable folder_retention
occ app:remove folder_retention
```

**What stays:** `occ app:remove` only removes the app folder. Nextcloud leaves
in the database: the app settings (`oc_appconfig`, `appid = folder_retention`,
among them `simulation_mode`, the work-space accounts and the boundary
`seen_max_fileid`), the tables `oc_folder_retention_rules`,
`oc_folder_retention_log`, `oc_folder_retention_seen`,
`oc_folder_retention_lock` and `oc_folder_retention_block` (rules with periods,
log) and the migration state in `oc_migrations`.

**Reinstalling later** continues with this state — with **the old rules and
periods**, not with "never delete". From 0.8.1, `occ app:disable` and
`occ app:remove` (without `--keep-data`) switch simulation mode on, so the
reinstall starts in simulation. If the app was removed with `--keep-data` or
under an older version, simulation stays as it was — also **off**: then the
first nightly run already deletes by the old periods. So switch simulation on
before removing (second line above), and after a reinstall check settings and
rules before switching simulation off.

**Removing everything** (for example for a fresh installation with "never
delete" default rules): only after `occ app:remove`, remove everything
together — **dropping the tables alone is not enough**: the migration state
would remain, a reinstall would not create the tables again, and job,
`occ folder_retention:run` and web interface would fail with "no such table".
Back up the database first; table prefix `oc_` here:

```sql
DROP TABLE IF EXISTS oc_folder_retention_rules;
DROP TABLE IF EXISTS oc_folder_retention_log;
DROP TABLE IF EXISTS oc_folder_retention_seen;
DROP TABLE IF EXISTS oc_folder_retention_lock;
DROP TABLE IF EXISTS oc_folder_retention_block;
DELETE FROM oc_migrations WHERE app = 'folder_retention';
DELETE FROM oc_appconfig WHERE appid = 'folder_retention';
```

Instead of the last line, `occ config:app:delete folder_retention <key>` per
key works too (list: `occ config:list folder_retention`). Afterwards a
reinstall creates everything anew: tables, simulation mode on, both default
rules "never delete".

## Limits

- Files are only deleted into the trash bin, never permanently on purpose;
  folders are never deleted. Files ending in `.part` are left alone (Nextcloud
  would delete them past the trash bin).
- External storages are not evaluated; shared files are evaluated through the
  owner's account.
- Files from shared folders land in the trash bin of the owning (function)
  account.
- Notifications before deletion are not implemented yet.
