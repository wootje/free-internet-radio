# Changelog

## 1.6.9

### English interface and documentation

- Change the document language to English and translate the remaining station-detail label.
- Correct the total-stations label. Preserve broadcaster-supplied names and track metadata unchanged.
- Add English installation, upgrade, operation, recovery, security, and publishing documentation.
- Add an optional `index.php`, Apache access rules, and repository exclusions for private/runtime data.
- Replace the inherited hard-coded sender with an empty, configurable `MAIL_FROM`; sending stays disabled until configured.
- Match the actual site's hostname when rejecting self-referential station URLs instead of hard-coding the original deployment domain.
- Provide a dark background when the optional image is missing.

### One-time administrator initialization

- Seed a genuinely new database with one `admin` account using the documented initial password.
- Store the password as a hash and mark the account for a required password change.
- Remove the complete initial-credential function from the deployed PHP source using a same-directory replacement.
- Use an installation lock, a persistent database record and a separate credential-free marker.
- Stop with an English setup error when the source cannot be cleaned; resume without duplicating/resetting accounts after the problem is corrected.
- Adopt existing databases without resetting users, passwords, roles, stations, history or favorites.
- Remove permanent emergency logins and username-based automatic role elevation.
- Never recreate defaults simply because all users have been deleted.

### Account lifecycle

- Add **Account → My account** with current-password verification, new-password confirmation, and optional recovery email.
- Require the initial password to be changed before normal use of that account, including protected AJAX actions.
- Invalidate old login sessions after password changes and replace/clear legacy password fields.
- Require CSRF tokens for login, registration, password recovery/reset, account changes and user-administration forms.
- Authorize administration pages before HTML output.
- Set HTTP-only, SameSite session cookies, with Secure cookies on HTTPS requests detected by PHP.
- Hide the inactive player on authentication/account forms to avoid covering inputs.

### Preserved behavior

The 1.6.8 track scroller, cache coordination/revisions, favorites, strict dead-stream scan, quarantine after one playback failure, deletion after an independent second failure, and restoration after a successful retest remain in place. No audio-recognition service or automatic station catalog is added.
