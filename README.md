# Online Radio++

A single-file PHP online radio directory with a floating player, personal favorites, live track metadata, a strict dead-stream scanner, and an English administration interface.

This release adds English-only application text, one-time administrator initialization, removal of initialization credentials from the installed source, and an account password-change page. It preserves the existing station, playback-health, metadata, scrolling-title, and caching behavior.

> **Important:** On a new installation the initial username and password are both `admin`. Restrict access to the website during installation, sign in immediately, and change this password. The application requires the initial administrator to change it before using the account. Removing credentials from a PHP file is not a substitute for changing a known default password.

## Contents

- [Requirements](#requirements)
- [Fresh installation](#fresh-installation)
- [How one-time setup works](#how-one-time-setup-works)
- [Upgrading an existing site](#upgrading-an-existing-site)
- [Configuration](#configuration)
- [Using the application](#using-the-application)
- [Caching and performance](#caching-and-performance)
- [Security and privacy](#security-and-privacy)
- [Backups and recovery](#backups-and-recovery)
- [Publishing on GitHub](#publishing-on-github)
- [Troubleshooting](#troubleshooting)
- [Testing and limitations](#testing-and-limitations)

## Requirements

The intended deployment is **PHP 8.2 or later** with **PDO SQLite**, sessions, and a writable local database directory. The code is intended for a single application instance using local filesystem locks, not a cluster with separate storage for each server.

Additional requirements and optional components:

| Component | Purpose |
| --- | --- |
| Apache 2.4, or another PHP-capable web server | Serve the PHP application. An Apache `.htaccess` is included. Other servers need equivalent access rules. |
| PHP `pdo_sqlite` | Required database driver. There is no MySQL/MariaDB configuration in this release. |
| PHP OpenSSL and outbound HTTP/HTTPS connectivity | Read HTTPS radio metadata and perform server-side stream checks. |
| `allow_url_fopen=On` | Required by the existing stream-wrapper network lookups. |
| PHP `mbstring` / `iconv` | Recommended for multilingual station names and metadata. Existing fallbacks remain available. |
| PHP `mail()` and a verified sender address | Optional: password-recovery messages and administrator-generated account emails. |
| A modern browser | Audio playback, JavaScript, cookies, and the player interface. |

No Composer installation, Node.js build, Python runtime, audio-recognition API key, or cron job is required on the web host. Scans and imports progress through requests from an open administration page; they are not independent scheduled workers.

The supplied database integration uses PDO SQLite in production. See [TESTING.md](TESTING.md) for the limitations of the release's local test environment.

## Fresh installation

1. **Restrict access first.** Keep the installation private, or use your hosting provider's temporary directory-password protection. Do not leave an unconfigured public site accessible with the known initial password.
2. Extract the release into a suitable PHP-enabled website directory. Include `bootstrap.php` and `index.php`. The small `index.php` loads the main file and makes the existing root-relative application routes work. Do not replace a custom entry point on an existing installation without reviewing it.
3. Install the supplied Apache `.htaccess`, or merge its access restrictions into your existing server configuration. Protect the SQLite database, its WAL/SHM/journal files, runtime files, backups, and version-control metadata against HTTP downloads. On another web server, configure equivalent rules **before** opening the site. PHP alone cannot protect a database file served directly by the web server.
4. For the initial setup, allow the PHP process to read and replace `bootstrap.php` and to write its containing directory. The directory needs enough free space to create the database, an installation lock, a marker, and a temporary cleaned source file. Correct ownership is preferable to broad permissions; do not use `chmod 777`.
5. Open the website, or open `bootstrap.php` directly. On a new database the application creates the administrator once, stores a password hash, and removes the complete one-time credential block from the installed PHP source. It then sends the first visitor to the login page.
6. Log in:

   ```text
   Username: admin
   Password: admin
   ```

7. On **Account → My account**, enter the current password and choose a new, unique password. Use at least 12 characters and at most 72 UTF-8 bytes. An email address is optional, but is needed for email-based password recovery. Normal use of the initial administrator account remains blocked until the password is changed.
8. Restore normal deployment permissions/ownership for the PHP source. The database, its directory, `orppx-cache`, and the existing installation-lock file must remain usable by PHP. A self-cleaning source replacement can change file ownership to the PHP process's user; account for this in your deployment procedure.
9. Configure email as described below, import stations, and remove the temporary external access restriction only after setup is complete.

The optional background image is not included. An existing `background/lightblue.jpg` continues to work; otherwise the player uses a readable dark background.

### Files created during setup

```text
orppx.sqlite                 Database: accounts, stations, history, favorites and state
orppx.sqlite-wal             SQLite write-ahead log, when present
orppx.sqlite-shm             SQLite shared-memory file, when present
orppx-installed.php          Durable installation marker; contains no credentials
orppx-install.lock.php       Installation/migration coordination lock
orppx-cache/                 Cached responses and coordination locks
robots.txt                  Generated when the directory is writable
```

Keep the installation marker with the database. These are **runtime files**, not release or repository content.

## How one-time setup works

The distributed, not-yet-installed `bootstrap.php` contains a small block delimited by `ORPPX_FIRST_RUN_SEED_BEGIN` and `ORPPX_FIRST_RUN_SEED_END`. This block provides the initial credentials **only to database initialization**, never as a login fallback.

Under an exclusive installation lock, the application creates or migrates the schema and checks a persistent `app_installation` record. A genuinely new database receives exactly one initial administrator. Its password is generated with PHP's `password_hash()`; the plaintext password is not stored in the database.

The account and initialization record are committed first. The application then creates a credential-free marker and removes the **entire seed function and its values** from `bootstrap.php`. It writes a complete cleaned file in the same directory and replaces the original with `rename()`, rather than truncating the live source. The cleaned source is checked, and OPcache invalidation is requested when available. No backup copy containing the original seed is deliberately retained by the application.

If the hosting does not permit that rewrite, setup stops at an English **“Setup needs attention”** page. The application does **not** silently claim that the credentials have been removed. Correct the permissions and reload; a committed initial account is reused rather than created again. Database and source updates are not a single cross-filesystem transaction, so the recorded pending-cleanup state allows this interruption to be recovered safely.

After cleanup, authentication uses accounts in the database only. There is no permanent emergency-account list, no hard-coded password bypass, and no automatic administrator promotion based on a username. Changing a password replaces supported legacy password fields and invalidates older login sessions.

Deleting users, clearing the cache, or uploading a pristine release again does not reopen default-account creation. A pristine file uploaded onto an initialized site has its seed removed again without resetting existing passwords. A marker left behind after a database is lost blocks automatic recreation; restore a backup instead.

**The distributed release and this README intentionally document the initial credentials.** Only the deployed copy removes its seed. Keep an unexecuted release copy for future clean installations; do not use a sanitized production file as the master installation package.

## Upgrading an existing site

Pause active scans and imports. Make a consistent database backup and a source backup outside the public website directory. Replace `bootstrap.php` with the release file and reload. Refresh open browser tabs to obtain new JavaScript and action tokens.

Existing accounts, passwords, roles, stations, favorites, and play history are retained. The update does **not** inject a new `admin/admin` account into a database that already has users. An existing administrator still using the original default password is required to change it, but other passwords are not reset. Historical backup accounts already in your database are not deleted; review unwanted accounts in **Admin → Users**.

The update creates `app_installation` and adds `users.must_change_password` and `users.auth_revision`. Existing 1.6.8 caching fields remain in use. These migrations run automatically.

Because this release removes hard-coded site-specific configuration, `MAIL_FROM` is blank in the distributed file. Set your own verified sender again when email is needed. There are no embedded private email-account passwords or API tokens.

Keep your existing `index.php`, background assets, and server rules where appropriate. Merge the supplied `.htaccess` restrictions instead of overwriting customized routing or hosting settings. The self-cleaning source still requires temporary rewrite permission when a pristine release with a seed is uploaded.

## Configuration

Edit the configuration near the top of `bootstrap.php` before installation, or edit the cleaned configuration afterward. The seed cleanup preserves unrelated configuration edits.

| Setting | Default | Meaning |
| --- | --- | --- |
| `SITE_URL` | Empty | Auto-detect the site origin and directory. Set the public base URL explicitly behind a proxy or on a nonstandard deployment. |
| `DB_PATH` | `__DIR__ . '/orppx.sqlite'` | SQLite database location. Update your server protection and backup procedures when changing it. |
| `MAIL_FROM` | Empty | Set a sender authorized by your mail provider, such as `radio@example.org`. An empty/invalid value disables sending. |
| `PAGE_SIZE` | `60` | Stations displayed per normal listing page. |
| `CACHE_ENABLED` | `true` | Enable the existing shared server cache. |
| `CACHE_DIR` | `orppx-cache` beside the application | Cache and station-coordination files; must be writable. |
| `STREAM_CHECK_ATTEMPT_SECONDS` | `4.0` | Network budget for the strict server-side direct-audio check. DNS behavior may exceed this budget. |
| `PLAYBACK_START_TIMEOUT_MS` | `8000` | Visitor playback-start watchdog. |
| `PLAYBACK_SUCCESS_SECONDS` | `2` | Minimum advancing playback time used by the player to confirm a successful attempt. |
| `DEBUG` | `false` | Additional application logging to the PHP error log. Keep debug details out of public responses. |

Set HTTPS and trusted-proxy handling correctly at your web server. This release does not blindly trust forwarded headers from arbitrary visitors.

## Using the application

### Player and track information

Search for a station and choose **Play**. The floating player provides play/stop controls, volume, a favorite star, and Spotify, YouTube Music, and Last.fm search buttons. **Open URL** opens a direct stream outside the site's player; the site cannot observe playback in that external window.

The server attempts ICY `StreamTitle` metadata and the existing Icecast/Shoutcast status fallbacks. Long artist/title text scrolls only when it overflows. Hover pauses the animation; clicking or tapping expands the full text. Reduced-motion preferences are respected. Metadata and song names supplied by broadcasters are not translated.

Music-service buttons use the full current artist/title text, not the truncated visible portion. These are search URLs, not guaranteed direct links to an identified recording. **This release does not include Shazam/AudD-style audio recognition.** Without station metadata, it cannot infer an artist or title from the audio.

### Favorites and accounts

Logged-in users can add or remove a station using the card's star or the star in the player. Anonymous visitors are prompted to log in. **Account** lets a signed-in user change their password and recovery email. Account/authentication forms hide the inactive floating player so it does not cover the form.

### Playback failures and rechecks

The existing requested policy is intentionally strict:

- A failed player attempt hides the station and marks it as possibly defective. Quarantine retains favorites and history.
- A subsequent successful test clears the active failure state and restores the station to normal listings.
- A subsequent failed test from a different account/browser deletes the station and its associated history and favorites.
- Repeated failures from the same account or browser do not count as an independent second visitor. Stale and duplicate results are rejected.

**Recheck stations** exposes quarantined stations for deliberate retesting. Anonymous visitors are distinguished by a first-party browser cookie, not proof of a unique person. Clearing cookies or using another browser can change this identity. Browser policy, network problems, unsupported codecs, or geographic restrictions can cause a failure even when a broadcaster is operational. Deliberate Stop and source changes are not counted as failed attempts.

### Administration

**Admin** contains station imports, cache information, strict scanning, and destructive catalog controls. **Users** provides account creation, password changes, role management, and deletion. User management requires an administrator and a valid form token. Do not remove your last usable administrator.

Import `.m3u`, `.m3u8`, or supported text lists by uploading them or selecting files already in the application directory. Imports are resumable and deduplicate by stream URL. No station list is bundled, and `.m3u8` acceptance as an import format does not imply that every HLS stream will pass the strict playback scanner.

The **strict dead-stream scan** keeps only timely, recognizable direct-audio responses. It can delete stations after timeouts, DNS/TLS problems, HTTP errors, playlists, or unrecognized responses. It is not a full browser playback test. Scan logs distinguish processed, working/kept, and removed stations. Review a small batch and keep a restorable backup before scanning a large catalog.

**Danger zone: delete all radio stations** requires typing `DELETE` and confirming the operation. It deletes stations, related favorites/history and health records, resets scans, and cancels active imports. User accounts are kept. Deletions are permanent unless restored from a backup or a new import.

## Caching and performance

This release retains the 1.6.8 cache design:

| Data | Typical cache lifetime |
| --- | --- |
| Successful current-track metadata | 12 seconds |
| Empty/failed metadata lookup | 45 seconds |
| Station/search listings and counts | 300 seconds |
| Recently played listings | 120 seconds, with play-version invalidation |
| Per-user favorite IDs | 300 seconds, with user-specific revision invalidation |
| Sitemap helper data | 3,600 seconds |

An exclusive, bounded metadata lock prevents simultaneous listeners from repeatedly fetching the same station's expired metadata. Catalog and favorite revisions make state changes visible to subsequent requests without clearing unrelated stations' metadata. Cache hits preserve the original metadata age. Incremental cleanup runs during normal traffic; it does not require a cron job. Personalized HTML and AJAX are not intended for shared browser/CDN caching.

Keep `orppx-cache` writable even when response caching is disabled, because station coordination also uses its lock files. **Clear cache** does not remove the installation marker or reopen first-run setup.

## Security and privacy

Serve the site over HTTPS. Protect runtime data and backups at the web-server level, configure request limits suitable for your host, and review the inherited application before opening a public service. This release is **not** a complete security audit or a guarantee against abuse.

Sessions use HTTP-only cookies and `SameSite=Lax`; the Secure flag is set when PHP sees an HTTPS request. Password changes and the account-administration forms use CSRF tokens. Existing scan/playback/favorite protections remain in place. This is not a claim that every inherited route has been redesigned or independently audited.

The application has no comprehensive new rate-limiting or multi-factor authentication system in this release. Use appropriate hosting-level controls for public login and resource-intensive endpoints. Administrator-generated passwords can still be emailed by an inherited feature; consider that exposure when deciding how to distribute credentials.

The database can contain usernames, email addresses, password hashes, favorites, IP addresses and user-agent strings in play history, and pseudonymous playback-test identifiers. Metadata lookup contacts broadcaster servers. Clicking a music-service button opens that external service. Set a retention policy and privacy notice appropriate to your deployment; no user tracking or account data is included in this release package.

## Backups and recovery

Back up `bootstrap.php`, the database, the installation marker, server configuration, and any assets you supplied. Keep backups outside the public web directory.

For an active WAL-mode SQLite database, use your host's consistent SQLite backup facility or SQLite's backup mechanism. Copying only the main `.sqlite` file while writes continue can omit committed changes still in the WAL. Alternatively stop application writes, perform a checkpoint and consistent backup, and resume only after the backup finishes. Consult the official SQLite backup/WAL documentation linked below.

**Forgotten password:** use **Forgot password** when the account has a recovery email and outbound mail is configured, or ask another administrator to change it. Without either, restore a known-good backup or perform a controlled account recovery with your hosting administrator. There is deliberately no permanent emergency login in the source.

**Lost database or empty users table:** do not remove the marker or upload old default-account code as an account-recovery shortcut. Restore the database and marker together. Completed installations never recreate default users just because accounts are deleted.

**Intentional fresh installation:** deploy an unexecuted release into a new, empty directory and initialize a new database while access is restricted. An already-cleaned production PHP file contains no seed and will not initialize a fresh database by itself.

**Rollback:** restore a consistent source/database/marker backup. Older versions contained emergency-account behavior; restoring old PHP can reintroduce that behavior. Do not assume an older file is security-equivalent to this release.

## Publishing on GitHub

Publish the **unexecuted release source**, not a copy of your live website. The supplied `.gitignore` excludes common databases, cache files, installation markers, import files, backups, logs and secrets. Review the actual staging list before every commit; `.gitignore` does not remove files already tracked by Git.

Recommended repository contents:

```text
bootstrap.php
index.php
README.md
CHANGELOG.md
TESTING.md
.htaccess
.gitignore
```

No database, station catalog, session data, generated installation marker, third-party image, or production API token is included. Keep personal server settings out of commits. The initial `admin/admin` seed is intentionally part of the installer and documentation; it must never remain the password of a publicly accessible initialized account.

No license file is assigned by this package. The repository owner should select and add the appropriate `LICENSE` before presenting it as a licensed open-source distribution. The existing application attribution is preserved.

## Troubleshooting

**“PDO SQLite is required.”** Enable `pdo_sqlite` for the PHP version serving this website. Enabling it for a different CLI or PHP-FPM version is not sufficient.

**“Setup is paused.”** The hashed initial account may already exist, but PHP could not remove the seed from its source. Temporarily correct ownership and permissions for both `bootstrap.php` and its directory, and reload. Do not delete the database to retry. The application intentionally does not continue with an unremoved seed after a cleanup error.

**Setup is complete but the host appears to use old PHP code.** Reload the PHP worker/OPcache through the hosting control panel where required. The installer requests invalidation, but it cannot override host-level OPcache restrictions. The durable installation record still prevents reinitializing default accounts.

**Login no longer accepts the original password.** That is expected after changing it. Use the new password. A user's role is now controlled only by the stored database value, not by the name `admin` or any old emergency-account entry.

**Password-reset email does not arrive.** Set `MAIL_FROM` to a verified sender, configure PHP `mail()` at the host, and check mail-provider logs/spam handling. The public reset form does not reveal whether an account exists.

**The root URL does not open the application.** Install the included `index.php` on a new site, or preserve/configure your existing entry point. Requests to `/?page=...` must reach the application in its directory.

**Apache returns HTTP 500 after adding `.htaccess`.** Check the server error log and your host's allowed overrides/modules. Merge the required protection into the host configuration rather than disabling access protection to make the error disappear.

**A station plays locally but the scanner removes it.** The strict scanner tests from the host's network, not the visitor's device. Different connectivity, TLS support, access restrictions, and playlist handling can change the result. Stop the scan and restore/reimport from a backup when necessary.

## Testing and limitations

See [TESTING.md](TESTING.md) for exactly what was checked. The release has not been deployed on your host or tested against your live database. PHP 8.2 is the compatibility target, not the runtime used for these local checks. No universal radio-playback guarantee or performance percentage is claimed.

### Technical references

- [PHP password_hash](https://www.php.net/manual/en/function.password-hash.php)
- [PHP password_verify](https://www.php.net/manual/en/function.password-verify.php)
- [PHP flock](https://www.php.net/manual/en/function.flock.php)
- [PHP rename](https://www.php.net/manual/en/function.rename.php)
- [PHP opcache_invalidate](https://www.php.net/manual/en/function.opcache-invalidate.php)
- [SQLite online backup API](https://www.sqlite.org/backup.html)
- [SQLite write-ahead logging](https://www.sqlite.org/wal.html)

- <img alt="GitHub all releases" src="https://img.shields.io/github/downloads/wootje/free-internet-radio/total">
