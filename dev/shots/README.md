# App Store screenshots

`run.sh` produces the store images in `screenshots/` from the working tree:

```sh
dev/shots/run.sh                 # ~3 min, writes screenshots/01-…08-*.png
KEEP=1 dev/shots/run.sh          # leave the instance running to look around
```

What it does:

1. starts a throwaway Nextcloud (`nextcloud:34.0.4-apache`, SQLite, no published port) in
   its own container `fret-shots-<pid>`, removed on exit; it never touches other containers;
2. installs groupfolders from the GitHub source tarball (never from apps.nextcloud.com – its
   rate limit blocks the whole IP) and this app from the working tree (`js/` must be built,
   otherwise it is built with `node:24-alpine`);
3. creates English demo content: four accounts, the Team folders Finance, HR, Projects and
   Marketing with subfolders and files aged via SQL (as in `dev/it`), rules (Finance 120 months,
   Bank Exports 3 months from last modification, HR never, HR/Applications 6 months with
   notification, Projects/Archive 24 months, Marketing/Drafts 30 days), simulation mode and tags on;
4. runs `occ folder_retention:run` (simulation) and `occ folder_retention:tags`, spreads the log
   entries over the last nights;
5. photographs the admin page and the Files app with the host's headless Firefox through
   geckodriver (WebDriver over HTTP, no npm package – same approach as Pulse), 1440×900 CSS px
   at 2× density;
6. shrinks the PNGs with pngquant + oxipng in a throwaway `alpine` container (`NO_OPTIMIZE=1` skips it).

Requirements on the host: Docker, `firefox`, Node ≥ 18, `curl`. geckodriver 0.35 is downloaded
into `dev/shots/.tools/` on first use (or set `GECKODRIVER=`).

Other variables: `IMAGE`, `GF_TAG`, `OUT` (output directory), `FRET_SHOTS_PORT` (geckodriver port).
The browser reaches the container through its Docker bridge IP; the address never appears in
the pictures. `appinfo/info.xml` points at the files under `main/screenshots/` on GitHub.
