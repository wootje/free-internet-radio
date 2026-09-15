# Release validation — Online Radio++ 1.6.9

These notes describe the checks actually performed on this release. They do not certify every inherited feature, every hosting configuration, or every broadcaster.

## Environment

- PHP CLI 8.4.23, with its normal tokenizer, password-hashing, session and filesystem functions.
- Node.js 22.16.0 for JavaScript syntax validation.
- The local system's real SQLite library, accessed through a test-only adapter described below.
- Python HTTP requests to a local PHP development server.
- Chromium through Playwright for rendering actual PHP-generated account-page HTML.
- A local Apache installation for isolated static-file access-rule checks.

PHP 8.2 is the code's intended minimum deployment version. A PHP 8.2 runtime was not available for this validation.

## Database-test limitation

The test environment did not have the native `pdo_sqlite` extension. In isolated test copies only, the PDO driver-availability check and connection constructor were replaced with a PDO-compatible test adapter. This adapter executed the application SQL, parameter binding, transactions and queries against the actual `libsqlite3` engine through FFI.

The adapter does not prove complete equivalence with every native PDO SQLite behavior. It was used to exercise the application's database logic instead of claiming an unperformed native-driver test. **Neither the adapter nor these test-only replacements are included in the release.** The shipped `bootstrap.php` still requires native PDO SQLite and contains a clear missing-driver error page.

Production code was syntax-checked without executing or sanitizing the distributed installer. Source rewrite, permission and concurrency tests used isolated copies, never the release master or a user database.

## Results

**112 recorded assertions passed** in the final run, grouped as follows:

| Group | Assertions | Coverage |
| --- | ---: | --- |
| Local HTTP/database workflow | 70 | Setup, source cleanup, authentication, account changes, upgrade behavior, legacy credentials, reset/registration, favorites and account/admin rendering. |
| Filesystem/concurrency/regression checks | 19 | Unprivileged permissions, resumable cleanup, simultaneous setup, source preservation, playback-health decisions and the real missing-driver response. |
| Chromium rendering | 11 | English page metadata, account labels, mandatory-change explanation, input attributes, token field, mobile layout and absence of uncaught script errors in the rendered page. |
| Apache access rules | 12 | Database/WAL/SHM/journal protection, backups, runtime files, cache/VCS paths, directory index and README access. |

The PHP syntax checks and rendered JavaScript syntax check passed. The HTTP group includes checks on the cleaned PHP file and rendered JavaScript; these are not additional cases added to the assertion total.

### First-run setup and source cleanup

The checks verified that a fresh installation creates exactly one administrator, stores a password hash instead of plaintext, marks the password for replacement, creates a durable marker, removes the complete seed block from the installed source, and records completed cleanup. The rewritten source passes PHP syntax validation and no temporary rewrite files remain after normal completion.

A permissions test ran PHP under an unprivileged operating-system user against a read-only source file. Setup stopped with the expected English message, left the source intact, and retained a single pending account. Correcting ownership/permissions allowed cleanup to finish without account duplication. The configured source-file mode was preserved across replacement.

Eight separate PHP processes then initialized the same new test directory concurrently. There was one administrator, one completed installation record, and a syntactically valid cleaned source file afterward. These were actual concurrent processes with separate test sessions, not eight serialized requests to the single-process development server.

Re-upload and interruption tests checked that a pristine file does not reset accounts on an initialized site, the seed is removed again after re-upload, and a pending-cleanup record can be recovered. The cleanup check also inspects the file header so a re-uploaded seed is not ignored merely because cached code still lacks its function.

### Authentication and account lifecycle

The checks covered valid/invalid login, CSRF rejection, the required password-change redirect, blocking access to administration and AJAX before that change, current-password verification, short or mismatched replacement rejection, successful replacement, email storage, and older-session invalidation.

The initial password stops authenticating after replacement; the new password works. An ordinary member named `admin` is not automatically promoted. Existing roles and password hashes survive an upgrade unchanged, except that an unchanged original administrator default is flagged for replacement.

A legacy schema containing both modern hashes and obsolete password fields was tested. An old alternate hash could not bypass a modern canonical password. Changing the password replaced supported hash columns and cleared the legacy plaintext field.

Registration and password-reset checks verified that ordinary users remain non-administrators, reset forms retain CSRF protection, valid reset tokens can update the password, used tokens cannot be reused, old sessions are invalidated, and the former password no longer authenticates.

### Retained functionality

Favorite mutation and favorite-state lookup were exercised through the actual HTTP handlers. The existing station-detail, player-star, scrolling-title and administration markup was checked.

The source bodies of **41 metadata/cache/stream-check/playback-health routines** were compared with the supplied 1.6.8 baseline and were identical. This is one grouped source-preservation assertion, not 41 independent functional stream tests.

Playback-health handlers were also exercised: a first failure hid the station, a subsequent successful independent test restored it, and a new first failure followed by an independent second failure deleted it. No real radio stream was accessed for those handler tests.

### Browser and Apache limitations

Direct browser navigation to HTTP addresses was blocked by the test environment. Chromium therefore rendered the real HTML returned by the local HTTP tests using `page.set_content()`. Account and password-manager fields, mobile overflow, player elements and script initialization were inspected, including desktop/mobile screenshots. These are **rendering checks**, not an end-to-end browser navigation or real audio-playback test. Authentication requests were tested separately through the local HTTP client.

The Apache checks used harmless static fixtures, not a PHP-FPM deployment. The included rules denied HTTP access to the fixture database files, WAL/SHM/journal files, a backup ZIP, installation marker/lock, cache directory and `.git/config`. The directory index and README remained accessible. Hosting-specific `AllowOverride`, modules, reverse proxies and existing routing still require deployment checks.

## Not tested or claimed

The release was not tested on the user's host, live database, actual email transport, PHP 8.2 runtime, or real broadcaster audio. No exhaustive security audit, anti-abuse guarantee, cluster behavior, benchmark improvement or universal stream compatibility is claimed.

The inherited strict scanner remains deliberately destructive. The validation did not scan or delete any user's production station catalog.

## Deployment smoke check

On an isolated copy or restricted staging host, verify the following before making the site public:

1. Run `php -l bootstrap.php` and confirm the web-serving PHP version has PDO SQLite enabled.
2. Initialize a new installation, verify that the installed source no longer contains the one-time seed block, and change the initial password.
3. Confirm that the old password is rejected and your new password grants the expected role.
4. Check the web-server denial of database/runtime paths without downloading or sharing any production database.
5. On an upgrade, confirm existing accounts, favorites and station counts against your backup.
6. Play a known-good stream, inspect its metadata and favorite star, and test the scanner only against disposable copies until satisfied.

Keep a consistent, restorable backup before testing destructive operations.
