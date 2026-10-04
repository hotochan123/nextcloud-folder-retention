<!--
SPDX-FileCopyrightText: 2026 hotochan123
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Security policy

## Status of the code

Folder Retention was written by AI coding agents (Anthropic's Claude Code), not
by hand. It has had no independent human code review and no security audit.
In October 2026 AI agents reviewed the code for data-loss and security
problems; the fixes are listed in [`CHANGELOG.md`](CHANGELOG.md) (0.8.0 to
0.8.2), and the known limits are described under
[Limitations](README.md#limitations) in the README. Please evaluate the app
yourself before you rely on it. Details:
[How this app was built](README.md#how-this-app-was-built-ai-disclosure).

## Reporting a vulnerability

Please do not describe a security problem in a public issue.

- **Preferred:** GitHub's private vulnerability reporting — "Report a
  vulnerability" on the repository's
  [Security tab](https://github.com/hotochan123/nextcloud-folder-retention/security).
- **If that button is not there:** open an
  [issue](https://github.com/hotochan123/nextcloud-folder-retention/issues)
  that only says you have found a security problem and asks for a private way
  to send the details — no description, no proof of concept. The maintainer
  will answer with a contact.

Helpful in a report: the app version or commit, the Nextcloud version and
database, the background job mode, whether Team folders, encryption or object
storage are involved, what an attacker needs, the steps to reproduce and the
effect.

The app is maintained by one person. There is no bug bounty and no guaranteed
response time; fixes go into the next release, and only the newest release is
supported.

## Scope

In scope:

- the app in this repository: the PHP code, the templates, the JavaScript
  bundle and its sources, the background jobs and the occ commands;
- **any way to make the app delete a file permanently** (past the trash bin)
  without it being logged as "permanently deleted" and the area blocked, or to
  make it delete a file that no rule makes due — this counts as a security
  problem here, not just a bug;
- the admin API (missing admin check, missing password confirmation, CSRF);
- the release tooling (`scripts/package.sh`) and the CI configuration, as far
  as they affect what gets shipped.

Out of scope:

- Nextcloud server itself and other apps (files_trashbin, groupfolders, …) —
  please report those to Nextcloud (<https://nextcloud.com/security/>);
- the configuration of your server, cron or proxy;
- the limits the README documents as known, as long as they stay within the
  stated bounds, and the tools under `dev/` and `tests/`, which are not part
  of a release.
