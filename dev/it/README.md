# Integrations-Harness (dev/it)

Spielt die Datenverlust-Szenarien aus dem Audit zu 0.7.4 gegen eine **Wegwerf-Nextcloud**
nach (Container `fret-it-<pid>`, SQLite, keine Port-Freigabe). Die Produktivinstanz wird nie
angefasst; der App-Store auch nicht (groupfolders kommt als Quell-Tarball von GitHub).

## Aufruf

```bash
dev/it/run.sh                                   # Arbeitsbaum gegen nextcloud:34.0.4-apache
IMAGE=nextcloud:35.0.1-apache dev/it/run.sh     # gegen NC 35 (groupfolders v23.x)
APP_SRC=/pfad/zu/entpacktem/stand dev/it/run.sh # anderer Stand, z. B. Baseline:
#   git archive 5823086 | tar -x -C /tmp/fret-baseline && APP_SRC=/tmp/fret-baseline dev/it/run.sh
ONLY=S1,S2 dev/it/run.sh                        # nur einzelne Szenarien
KEEP=1 dev/it/run.sh                            # Container + Protokolle stehen lassen
```

Weitere Schalter: `GF_TAG=v22.0.6` (groupfolders-Release fest), `NO_GF=1` (S8 = SKIP),
`OLD_REV=fb4395c` (Ausgangsstand des Update-Szenarios S24, per `git archive` aus dem Repo; seine
max-version hebt der Harness in der Kopie auf die von `APP_SRC`, damit S24 auch auf NC 35 läuft).
Hat `APP_SRC` kein `js/`, baut der Harness es mit `node:24-alpine` (`npm ci && npm run build`,
schreibt `node_modules/` und `js/` in `APP_SRC`).

Ausgabe auf stdout, eine Zeile je Szenario, dann die Summe; Diagnose geht nach stderr:

```
PASS|FAIL|SKIP <id> <text>
Harness <pass>/<fail>/<skip>
```

Exitcode 1, sobald ein Szenario FAIL ist. Bei Fehlern bleiben die occ-Ausgaben jedes Laufs in
`$TMPDIR/fret-it.*` liegen (Pfad steht am Ende auf stderr).

**Laufzeit:** ca. 7,5 Minuten (04.10.: 34.0.4 459 s, 35.0.1 460 s, je 27/0/0; mit S28/S29 34.0.4 455 s, 29/0/0; mit S30/S31 34.0.4 504 s, 31/0/0; Baseline 0.7.4: 184 s) (NC-Installation ~20 s, S10 legt 600 Dateien an, S23, S24, S26, S27 und S31 starten je eine eigene Instanz). Mit Baseline
ohne `js/` kommt der npm-Build dazu (~1 min).

## Szenarien

| ID  | prüft | Befund |
|-----|-------|--------|
| S1  | alice, bob, carol je eine fällige Datei, EIN Lauf → alle drei im eigenen Papierkorb, Log `deleted` | B1 |
| S2  | Lauf → `occ trashbin:restore` → nächster Lauf löscht nicht erneut | B2 |
| S28 | dave: Lauf verschiebt, Löschzeitpunkt im Log auf vor 10 Tagen gesetzt (Regel 1 Tag), `occ trashbin:restore` → nächster Lauf: Datei bleibt, `first_seen` = Zeitpunkt dieses Laufs; mit `first_seen` vor 2 Tagen erneut fällig → Papierkorb | Review 0.8.1 (Wiederherstellung später als eine Frist) |
| S29 | erin: `proc.php trashthrow` – zwei Läufe in EINEM Prozess (wie `occ background-job:worker`), im ersten wirft das Locking-Backend in `Trashbin::move2trash` → beide `error`, zweiter mit „Prozessneustart“, Datei bleibt (ohne Sperre: `deleted_final`, LegacyTrashBackend::deletedFiles) | Review 0.8.1 (Ausnahme im Papierkorb, langlebiger Prozess) |
| S3  | heute per WebDAV mit `X-OC-CTime` 2015 hochgeladen → nicht fällig | B3 |
| S4  | per Dateisystem + `occ files:scan` (keine upload_time), mtime 2015 → im ersten Lauf nicht fällig | B3/F4 |
| S5  | frische Installation: beide Standardregeln „nie“, Simulation aus + Lauf → 0 Löschungen | B4 |
| S6  | files_trashbin nur für eine Gruppe, Besitzer nicht drin → Datei bleibt (oder liegt im Papierkorb), nie endgültig weg | B6 |
| S7  | Simulation an → nichts gelöscht, Log `simulation` | – |
| S30 | Simulation an, Datei fällig → 1 Eintrag; Eintrag auf 0.7.x-Stand gesetzt (Bezug `mtime`, anderes Datum) → nächster Lauf protokolliert neu, übernächster nicht; Standardregeln 1 → 2 Tage (gleiche Regel-ID) → wieder neu, danach keine Wiederholung | Review 0.8.1 (Simulation dedupliziert über veraltete Bewertung) |
| S8  | Team-Ordner (groupfolders) → Datei im groupfolders-Papierkorb (`oc_group_folders_trash` + Platte); im selben Lauf vorher eine persönliche Datei von bob (Kontowechsel) | B1 |
| S11 | Team-Ordner-Datei zusätzlich direkt an ein Mitglied geteilt → im groupfolders-Papierkorb, nicht nur Freigabe weg | B7 |
| S9  | alice teilt an bob, Datei fällig → liegt in alices Papierkorb | B7 |
| S12 | Quota fast voll (ivan, 20 MB): kleine fällige Datei passt in den Papierkorb, große würde Expire sofort räumen → bleibt liegen (Log „Quota“); danach Expire-Befehle ausgeführt → nichts endgültig weg | Review 0.8 |
| S13 | `occ trashbin:size 1GB` (Wert mit falschem Typ), drei fällige Dateien in zwei Konten → keine Datei fehlt auf Platte und im Papierkorb | Review 0.8 |
| S14 | Original 700 Tage alt, per WebDAV-COPY kopiert (Kopie erbt die Upload-Zeit) → Original im Papierkorb, Kopie bleibt | Review 0.8 |
| S18 | Grenze `seen_max_fileid` nach Installation gesetzt; „Update jetzt“ nachgestellt (Grenze = MAX(fileid), kein Zyklus abgeschlossen), danach Kopie einer 700 Tage alten Datei → Kopie bleibt in zwei Läufen, Original im Papierkorb | Review 0.8 (Übergangsfenster) |
| S16 | peggy: dA/dB/dC/Bericht.txt fällig, ein Lauf → alle drei als `Bericht.txt.d*` im Papierkorb, jeweils eigener Inhalt; mit groupfolders zusätzlich zwei gleichnamige Dateien im Team-Ordner | Review 0.8 (Papierkorb-Name je Sekunde) |
| S19 | zwei Sperren (A, B) in `oc_folder_retention_block`; API hebt nur A auf, B mit veraltetem Zeitpunkt bleibt; `occ folder_retention:run --unblock=it:b` hebt B auf; Ausgangszustand danach wiederhergestellt | Review 0.8 (Sperre aufheben) |
| S20 | Prozess P (`proc.php wait`, wie cron.php) hat die Sperrliste gelesen, Q setzt eine Sperre → P sieht sie, P's eigene Sperre lässt Q's stehen; Sperre im alten App-Config-Wert `blocked_roots` wandert beim nächsten Zugriff in die Tabelle | Review 0.8 (Sperrliste im Prozess-Cache) |
| S21 | walter: zwei 245-Zeichen-Namen, die sich nur in der Mitte unterscheiden (files_trashbin kürzt sie gleich), ein Lauf → beide mit eigenem Inhalt im Papierkorb, Log `deleted` × 2 | Review 0.8 (lange Namen im Papierkorb) |
| S22 | rupert: s22A/Protokoll.pdf verschieben, Laufende (`releaseContext`, Speicher des Laufs leer), sofort s22B/Protokoll.pdf – am Anfang einer Sekunde, also ohne Schutz in derselben Sekunde (`proc.php samesecond`) → beide mit eigenem Inhalt im Papierkorb | Review 0.8 (Papierkorb-Name über Laufgrenzen) |
| S15 | Quota knapp (oscar, 20 MB), fällige 1-MB-Datei mit 3 × 1 MB Versionen: Versionen wandern mit in den Papierkorb → Expire würde räumen → Datei bleibt (Log „Versionen“); nie endgültig weg | Review 0.8 (Versionen) |
| S23 | eigene Instanz `$C-os` mit Objektspeicher als Primärspeicher (`objectstore/`: FretDirObjectStore, Objekte als Dateien, ohne S3). olga: Quota 10 MB, 3 MB bleibend, 1,4 MB schon im Papierkorb, 2 MB fällig → nach Lauf + `trashbin:expire` nicht endgültig weg (bleibt mit Log „Quota“) | Review 0.8 (Belegung bei Objektspeicher) |
| S24 | eigene Instanz `$C-up`: App aus `OLD_REV` (fb4395c, 0.8.0), Zyklus in Simulation → `seen_since`, danach WebDAV-COPY einer 400 Tage alten Datei (dort geschützt) → Arbeitsbaum + `occ upgrade` → `seen_since` gelöscht, `seen_max_fileid` < ID der Kopie, echter Lauf: Kopie bleibt, Original im Papierkorb | Review 0.8 (Update fb4395c → 0.8.1) |
| S10 | `background-job:execute --force-execute` im Hintergrund + `occ folder_retention:run` parallel → kein Doppel-Log, nichts verloren, Sperrmeldung im occ-Text | B9 |
| S17 | `background:ajax`, nur der RetentionJob fällig, anonymer Aufruf von `cron.php` mit `X-NC-Skip-Trashbin: true` → Job läuft, Datei bleibt, kein Log, keine neue Sperre; `/api/settings` meldet `cronMode: ajax`; danach wieder `background:cron` | Review 0.8 (Web-Cron) |
| S25 | Team-Ordner mit carol und bob, fällige Datei; im selben Lauf vorher carols eigene Datei (Kontext carol) → Team-Datei über bob gelöscht (erstes Mitglied sortiert): `oc_group_folders_trash.deleted_by = bob`, Log `deleted (… über Konto bob … gelöscht hat folder_retention)`; carols Datei mit „über Konto carol“ | Review 0.8 (Löschender in Papierkorb/Aktivität) |
| S26 | eigene Instanz `$C-rm`: Regeln 1 Tag, Simulation aus → `occ app:remove` (ohne `--keep-data`) → neu installiert: `simulation_mode` = 1 (Uninstall-Schritt), Regeln unverändert, fällige Datei bleibt (Log `simulation:would_delete`) | Review 0.8 (Neuinstallation nach app:remove) |
| S27 | eigene Instanz `$C-rs`: `occ app:remove`, dann genau der SQL-Block aus INSTALL.md §9 („Vollständig aufräumen“) → neu installiert: 5 Tabellen, Simulation an, beide Standardregeln „nie“, `--dry-run` ohne SQL-Fehler | Review 0.8 (Tabellen von Hand gelöscht) |
| S31 | eigene Instanz `$C-enc`: groupfolders + `occ encryption:enable-master-key` + `groupfolders enable_encryption=true`, Team-Datei und persönliche Datei fällig, ein Lauf → beide `deleted`, Team-Datei im groupfolders-Papierkorb (dort unter NEUER fileid, `oc_group_folders_trash.file_id` = alte), keine Sperre | Review 0.8.1 (Verschlüsselung im Team-Ordner) |
| S32 | Protokoll-Übersicht über die API: vier Einträge (Ordner mit `%`, `_`, `[` im Namen, ein Unterordner, ein Nachbarordner, ein Pfad ohne Ordner) → `/api/log?folder=` liefert je nur die direkten Dateien, `/api/log/folders` und `/api/log/days` zählen richtig | Protokoll nach Tag und Ordner (`notLike()` ohne `ESCAPE` auf SQLite) |

Erwartungen sind bewusst tolerant (Datei da / im Papierkorb / irgendwo auf der Platte statt
exakter Statusstrings), damit die 0.8.0-Umsetzung fair geprüft wird. Die Sperrmeldung erkennt
S10 an `gesperrt|läuft bereits|Sperre|locked|already running` außerhalb der Ergebnistabelle.

## Wie Dateien „altern“

Direkt in der SQLite-DB (`data/nextcloud.db`, Präfix `oc_`), Helfer `age()`:

- `oc_filecache.mtime` → Bezug für basis=modified (und Rückfall der 0.7.x-Kette)
- `oc_filecache_extended.upload_time` → „seit Ablage in Nextcloud“
- `oc_filecache_extended.creation_time` → Client-Erstellzeit (`X-OC-CTime`)
- `oc_folder_retention_seen.first_seen` → „zuerst gesehen“ (ab 0.8.0). Ohne den Eintrag gälte
  eine frisch angelegte Datei mit alter Upload-Zeit als Kopie und zählte ab dem ersten Lauf.

`storage_mtime` bleibt unverändert, damit kein Scanner den Eintrag zurücksetzt. S4 altert
stattdessen die echte Datei (`touch -d 2015-01-01`) und lässt `files:scan` sie aufnehmen.
Regeln setzt der Harness ebenfalls per SQL (`oc_folder_retention_rules`, `folder_id IS NULL`:
beide Standardregeln auf 1 Tag ab Erstellung), damit die Passwort-Bestätigung der API nicht stört.

## Fallen

- **AJAX-Cron aus:** der Harness stellt `background:cron` ein, sonst startet jede WebDAV-Anfrage
  den RetentionJob unkontrolliert mit. Nur S17 schaltet kurz auf AJAX und setzt dafür
  `last_checked` aller anderen Jobs auf jetzt, damit `cron.php` gerade den RetentionJob nimmt.
- **Gleichnamige Dateien:** `fid` findet nur die neueste Datei eines Namens; S16 altert per
  `fid_path`/`age_id` über den Pfad im Home.
- **„Gesehene“ Konten:** RootProvider kennt nur Konten mit `lastLogin` und Mounts in `oc_mounts`.
  Ein WebDAV-PROPFIND (`touch_fs`) erledigt beides – auch für neue Team-Ordner-Mounts erneut nötig.
- **files_trashbin für Gruppen:** `occ app:enable --groups` verweigert das für Filesystem-Apps
  (und schaltet die App dabei auf `no`!). S6 setzt `files_trashbin enabled` deshalb direkt per
  `config:app:set` und stellt danach `yes` wieder her – S6 läuft darum zuletzt.
- **groupfolders ohne JS:** der GitHub-Quell-Tarball hat kein gebautes Frontend; für occ und
  WebDAV reicht das. Release-Wahl: neueste `v(NC-12).x` laut GitHub-API.
- **S10 braucht Überlappung:** der occ-Lauf startet erst, wenn der Job die erste Zeile ins Log
  geschrieben hat. Startet der Job gar nicht oder wird vorher fertig → FAIL mit Hinweis.
- **Reihenfolge:** S5 zuerst (Zustand direkt nach Installation), S6 zuletzt. Jedes Szenario hat
  eigene Dateinamen; spätere Läufe bearbeiten liegengebliebene fällige Dateien früherer Szenarien mit.
- **Zweiter Prozess:** `proc.php` bootet Nextcloud (`lib/base.php`) im Container und hält die
  App-Config im eigenen Prozess-Cache – so lässt sich ein lange laufender cron.php nachstellen (S20).
- Auf dem Entwicklungsrechner sind Host-Binaries nicht ausführbar und es gibt kein python3: alles läuft per
  `docker exec` (curl, php) im Wegwerf-Container; `sql.php` und `proc.php` werden dafür hineinkopiert.
