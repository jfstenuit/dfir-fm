# Developer Documentation

This document outlines the technical structure and internal workings of the DFIR File Manager (DFIR FM), providing guidance for contributors and maintainers.

## Bootstrapping & Application Flow

The application uses a single entrypoint: `index.php`. It handles routing manually and initializes all required components (middleware, controller dispatch, view rendering).

Command-line tools live in `bin/` and refuse to run outside the CLI SAPI:
- `php bin/create-admin.php <username>`: creates an administrator (or resets an existing user's password and adds them to the admin group) and prints a random password once. This is how the first administrator is created; no default account exists. It's also the break-glass tool when every administrator is locked out: it needs shell access only, and no identity provider.
- `php bin/send-test-mail.php <recipient>`: sends a test e-mail through the configured mail engine, exactly as invitations are sent. Exit code 0 when the engine accepted the message, 1 with the reason on stderr otherwise. With `mail_engine=none` it sends nothing, says so and exits 0. Run it as the web server user (e.g. `sudo -u www-data php bin/send-test-mail.php …`) to also check its permissions.
- `php bin/check-integrity.php`: re-hashes every stored file and compares it with its `files` record (SHA-256 and size). Also reports `files` rows that share a name or a SHA-256 in one directory, rows that refer to a missing row (`PRAGMA foreign_key_check`), and, with the local storage engine, files in storage without a record. Read-only; exit code 0 when everything matches, 1 otherwise.
- `php bin/find-deleted-user.php <email>`: lists the deleted accounts of an e-mail address (see *Deleted accounts*), with their id, so that `files.uploaded_by`, `directories.created_by` and the log can be searched by id. Read-only; each lookup is logged with the hash of the address, not the address. Exit code 0 when an account was found, 1 otherwise.
- `php bin/expire-uploads.php`: expires uploads without activity for `upload_stale_after` seconds and deletes their staging files. The application also does this whenever an upload starts; the script is for a cron job.

Routes (`index.php`). Authentication and CSRF checks are global, in `index.php`: every route but `/login` requires a session (otherwise a redirect to `login?redirect=…`), and every POST a valid CSRF token. Authorization (admin, folder rights) is checked by each controller action, through `AccessMiddleware`. POST requests select their action with the field `a`.

| Path | Controller | Purpose |
|---|---|---|
| `/` | `FileManagerController` | GET: folder listing (`?p=/path`). POST: `createFolder`, `deleteItem` |
| `/dl` | `DownloadController` | File download, with `Range` |
| `/upload` | `UploadController` | Chunked upload (see "Upload protocol") |
| `/admin` | `AdminController` | Admin page and its actions (users, groups, invitations, access rights); admins only |
| `/profile` | `ProfileController` | `updatePassword` |
| `/login` | `LoginController` | Two-step password login, invitation token login |
| `/logout` | `LogoutController` | |

## Directory structure
```
project-root/
│
├── index.php                  # Single entry point for the application
├── .htaccess                  # Apache mod_rewrite rules for routing (if using Apache)
├── assets/                    # Static assets (CSS, JavaScript)
│   ├── css/
│   │   ├── login.css          # Login page styles
│   │   └── style.css          # Main CSS file
│   └── js/                    # ES modules, no build step (see "Front end")
│       ├── site.js            # Entry module of the app pages, loads the page's module
│       ├── utils.js           # Shared helpers: api(), h(), modals, delegation, group suggestions
│       ├── sortable-table.js  # Click-to-sort table columns
│       ├── filemanager.js     # File manager page (imports Dropzone)
│       ├── admin.js           # Admin page entry: tabs, password change
│       ├── admin-users.js     # Admin: user management
│       ├── admin-groups.js    # Admin: group management
│       └── login.js           # Login page
├── tests/                     # PHPUnit tests (see "Testing")
│   ├── Unit/                  # Pure logic, no server
│   ├── Feature/               # HTTP-level, isolated app instance per test
│   └── Support/               # TestServer, HttpClient, router for php -S, fake SMTP/sendmail
├── phpunit.xml.dist           # PHPUnit configuration
├── bin/                       # CLI tools (not web-accessible)
│   ├── create-admin.php       # Create the first / an additional administrator
│   ├── send-test-mail.php     # Check the mail configuration
│   ├── check-integrity.php    # Re-hash stored files against the database
│   ├── find-deleted-user.php  # Deleted accounts of an e-mail address
│   └── expire-uploads.php     # Expire abandoned uploads (cron)
├── .dockerignore              # Build context of the image: only what it needs
├── docker/                    # Container deployment (see "Docker")
│   ├── Dockerfile             # PHP-FPM 8.4 (Alpine) + APCu + lighttpd
│   ├── docker-compose.yml     # Mounts and port chosen by files_dir, log_dir, http_port
│   ├── lighttpd.conf          # Routing equivalent to .htaccess, MIME types
│   ├── php.ini                # Upload sizes for the 2 MiB chunks
│   ├── php-fpm.conf           # Pool: localhost only, environment passed to PHP
│   └── start.sh               # Runs both processes; stops when either exits
├── src/                       # Source code directory
│   ├── Backends/              # Dynamically selected backends
│   │   ├── LogEngine*.php     # Handles logging
│   │   ├── LogEntry.php       # The log record shared by the log engines
│   │   ├── MailEngine*.php    # Handles mail sending
│   │   ├── MailText.php       # Plain-text alternative of the HTML mails
│   │   └── StorageEngine*.php # Handles backend storage
│   ├── Controllers/
│   │   ├── AdminController.php
│   │   ├── DownloadController.php
│   │   ├── FileManagerController.php
│   │   ├── LoginController.php
│   │   ├── LogoutController.php
│   │   ├── ProfileController.php
│   │   └── UploadController.php
│   ├── Core/
│   │   ├── App.php            # Singleton pattern to store $db, $config and $logger
│   │   ├── Config.php
│   │   ├── Database.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   └── Session.php
│   ├── Middleware/
│   │   ├── AccessMiddleware.php
│   │   └── SecurityMiddleware.php
│   ├── Services/
│   │   ├── ChunkedUpload.php  # Upload state machine, verification, commit
│   │   └── UploadException.php # Refusal with HTTP status and error code
│   └── Views/                 # Templates for rendering HTML
│       ├── Modals/            # Bootstrap modals
│       │   ├── AccessRightsModal.php
│       │   ├── DeleteConfirmationModal.php
│       │   ├── InviteModal.php
│       │   ├── NewFolderModal.php
│       │   ├── OperationResultModal.php
│       │   ├── ProfileModal.php
│       │   ├── UploadModal.php
│       │   └── UserGroupModal.php
│       ├── AdminView.php
│       ├── FileManagerView.php
│       ├── InvitationView.php
│       ├── Layout.php         # <head> shared by the pages (asset lists)
│       └── LoginView.php
├── storage/                   # Created at runtime, not in git; must not be web-accessible
│   ├── database/              # SQLite database files
│   │   └── app.sqlite         # Main SQLite database (default; database_path)
│   ├── files/                 # Storage for uploaded files (local storage engine)
│   │   └── .uploads/          # Staging: uploads in progress (reserved name)
│   └── logs/                  # Log files (file log engine)
├── vendor/                    # Composer dependencies (auto-generated)
├── composer.json              # Composer configuration file
├── composer.lock              # Composer lock file
├── env.sample                 # Configuration template, copy to .env
├── .env                       # Environment variables (not in git)
├── .gitignore                 # Git ignore rules
├── DEVELOPER.md               # This document
├── LICENSE.md
└── README.md                  # Documentation
```

## Development & Debugging

- Set up Apache (`mod_rewrite`, `AllowOverride` for `.htaccess`; `mod_headers` recommended) with PHP 8.4 and the extensions `pdo_sqlite`, `apcu`, `curl`, `gmp` (required by `sendgrid/sendgrid`) and `mbstring`, then `composer install`. Or use the Docker image (see "Docker"). Other web servers need the `.htaccess` rules translated, as `docker/lighttpd.conf` does for lighttpd.
- Two kinds of logs: the application's events (logins, uploads, downloads, admin actions) go to the log engine (`log_engine`, see "Logging & Forensic Integrity"); PHP errors and configuration problems go to the PHP error log.
- No framework or build system is used at this time.
- JavaScript and CSS files are managed manually (see "Front end").

To debug:
- Use `error_log()` for PHP
- Use browser dev tools for JS

## Front end

- **Libraries**, from Composer, served from `vendor/` as built: Bootstrap 5.3 (`twbs/bootstrap`, the bundle with Popper, exposes `window.bootstrap`), Bootstrap Icons (`twbs/bootstrap-icons`, `bi bi-*` classes) and Dropzone 6 (`enyo/dropzone`, imported by `filemanager.js` as `dist/dropzone.mjs`). No jQuery.
- **Pages:** `Views/Layout.php` prints every page's `<head>`; `Layout::appHead()` is the asset list of the file manager and admin pages, whose entry module is `site.js`. The login page loads `login.js` only.
- **Modules** are plain ES modules (`type="module"`), loaded without a bundler. They run once the document is parsed.
- **Requests** go through `api(url, {data | json | body})` in `utils.js`: it sends the CSRF token, parses JSON and throws an `ApiError` (`status`, `code`, server `message`) on a network failure, an HTTP error, a non-JSON body or `{success: false}`. A redirect to the login page or a CSRF 403 (`code: csrf_invalid`) means the session is gone: the page reloads.
- **DOM:** build elements with `h(tag, attributes, ...children)` (text children are text nodes, never parsed as HTML) and `icon(name)`; no HTML strings with data in them. Table actions use event delegation (`on(root, type, selector, handler)`), bound once.
- **Dialogs:** Bootstrap 5 modals through `showModal()` / `hideModal()` (the latter resolves once hidden); results in `showResultMessage()`. Dialog forms are `<form novalidate>` submitted by their footer button (`form="…"`), so Enter submits them and the script validates.
- **Group names:** `groupAutocomplete(input)` attaches a `<datalist>` filled from `admin?a=listGroups`.
- **CSP:** no inline `<script>`, `style=` or `<style>` in pages (see *Content Security Policy*). Styles go in `assets/css/`; scripts may set styles through the CSSOM (`element.style`).

## Testing

Tests use PHPUnit (`require-dev`). Run them with Composer, which enables APCu for the CLI:

```bash
composer test            # everything
composer test:unit       # fast, no server
composer test:feature    # HTTP-level, ~1 min
```

- **`tests/Unit/`**: no web server. Client IP resolution, config parsing and `database_path`, `Content-Disposition`, Range parsing, login redirect targets, storage path validation and staging, backend factories, log records, mail engine configuration and message building, schema and migrations, admin checks, throttle settings, and the upload state machine (`ChunkedUploadTest`: chunks out of order or sent twice, missing chunks, tampered staging, name and content conflicts, rights, geometry, sizes, abort), on a real SQLite database and local storage in a temporary directory.
- **`tests/Feature/`**: the application over HTTP: login and rate limiting, sessions and cookies, admin authorization, account deletion, file listing, uploads and downloads, mail, HTTP headers and routing, the `bin/` scripts, `database_path`. Each test starts a fresh, isolated instance on PHP's built-in server (`Tests\Support\TestServer`) in a temporary directory, with its own `.env`, SQLite database, storage, log file and APCu counters. The project's own `.env`, database and storage are never touched.
  - Fixtures: an admin created through `bin/create-admin.php` and a regular user `bob@test.local`. The root directory (id 1) is created by the schema setup, as on a real install.
  - `tests/Support/router.php` mirrors `.htaccess` for the built-in server, so `.htaccess` itself still needs checking on a real Apache.
  - `Tests\Support\HttpClient` behaves like a browser: it keeps cookies and handles the CSRF token and login form.
  - Every feature test fails if the server logged a PHP warning, notice, deprecation or fatal error.
  - Mail never leaves the machine: the test servers and CLI scripts run with `sendmail_path` pointing to `tests/Support/fake-sendmail.php`, and SMTP tests use `Tests\Support\FakeSmtpServer` (a minimal local SMTP sink with `AUTH PLAIN`, no TLS). Both keep the raw messages for assertions. TLS (STARTTLS/SMTPS) is not covered: check it against a real server with `bin/send-test-mail.php`.
- Security fixes come with a test that fails on the vulnerable code (e.g. `LoginRateLimitTest` for the `X-Forwarded-For` bypass, `AdminAuthorizationTest` for the missing admin guard).

Not covered by PHPUnit: the JavaScript front end and the Apache and lighttpd rules (`router.php` stands in for them). Check those in a browser and with `curl` against a real server after changing them.

**Continuous integration:** `.github/workflows/ci.yml` (GitHub Actions) runs on every push and pull request, and every Monday for new advisories: PHP 8.4 with the required extensions, `composer install` from the lock file, `php -l` on every PHP file outside `vendor/`, `composer audit` and `composer test`. Run `composer test` before committing anyway: it is the same check, sooner.

Production installs should use `composer install --no-dev`, which leaves PHPUnit out of `vendor/`.

## Security Architecture

The application now implements several layers of security to defend against common web application threats:

- **CSRF Protection**  
  A CSRF token is generated per session; pages carry it in a `csrf-token` meta tag and send it as the `X-CSRF-TOKEN` header (`api()` in `utils.js`, and Dropzone's chunk requests). All POST requests are verified globally in `index.php` before reaching controller logic, **including the login form** (login CSRF). The token is rotated at login. Tokens are managed via `Session::getCsrfToken()` and validated via `SecurityMiddleware::validateCsrfToken()`. A refused token answers 403 with `code: csrf_invalid`, which happens when the session expired while the page was open; `api()` then reloads the page (a plain 403, e.g. a refused right, doesn't).

- **Brute-force Protection**  
  Login attempts are rate-limited with **APCu in-memory counters**, using two independent limits within a window of `login_lockout_duration` seconds (default 300):
  - per client IP (see *Client IP resolution* below): `max_login_attempts`, default 5;
  - per username, from any IP: `max_login_attempts_per_user`, default 10. It's higher because anyone can use it to lock a known username out for the window.

  A request already blocked by the IP limit doesn't count against the username. A successful login resets the username counter only: resetting the IP counter would let an attacker clear it by logging into their own account. Invalid values fall back to the defaults. If APCu is unavailable, login attempts fail with an exception (HTTP 500) rather than running without a limit; a clearer startup check is #14. With `apc.enabled=0` the counters silently do nothing. Counters are per server: several containers or servers don't share them.

- **Client IP resolution**  
  `Request::getClientIp()` is the only source of the client address, used for rate limiting, audit logs and forensic records (`uploaded_from`, `created_from`). It is controlled by the `trusted_proxies` setting: a comma-separated list of IPv4/IPv6 addresses or CIDR ranges.
  - **Empty (default):** the client is `REMOTE_ADDR`. Use this for direct connections and for **Apache with mod_remoteip**, which already rewrites `REMOTE_ADDR`. Configure mod_remoteip with explicit `RemoteIPTrustedProxy`/`RemoteIPInternalProxy` addresses, never a range that includes untrusted hosts.
  - **Set:** only when `REMOTE_ADDR` is in the list, `X-Forwarded-For` is walked **from right to left**, skipping trusted proxies; the first untrusted address is the client. Entries further left were written by the client and are never trusted. If every entry is a trusted proxy, the leftmost is used. Ports (`a.b.c.d:port`, `[v6]:port`) are stripped and IPv4-mapped IPv6 addresses are normalised to IPv4.
  - `Client-IP` is never read. `X-Forwarded-Proto` (`Request::isHttps()`) is only honoured from a trusted proxy.
  - **`force_https=true`** makes `Request::isHttps()` always true: `Secure` session cookies and `https://` links in generated e-mails. Use it when TLS is terminated before Apache and PHP can't see it. That includes setups with mod_remoteip: once mod_remoteip has rewritten `REMOTE_ADDR`, the proxy is no longer the visible peer, so its `X-Forwarded-Proto` is never trusted. Don't enable it if the site is also served over plain HTTP: browsers drop `Secure` cookies there and logins fail. An invalid value stops the application, like an invalid `trusted_proxies` entry.
  - Using mod_remoteip *and* `trusted_proxies` together is harmless: after mod_remoteip, `REMOTE_ADDR` is the client, which isn't a trusted proxy, so the header isn't read twice.
  - An invalid entry stops the application (HTTP 500, reason in the PHP error log) instead of running with a wrong trust list.
  - Examples: Cloudflare Tunnel with `cloudflared` on the same host: `127.0.0.1, ::1`. Reverse proxy on a Docker network: `172.16.0.0/12`.
  - Log entries carry `ip` (the client) and, when a trusted proxy relayed the request, `peer_ip` (the proxy). The record is built once in `Backends\LogEntry` for all log engines.

- **Anti-Enumeration**  
  Login flow avoids leaking user presence. The two-step login screen always advances to password input, regardless of user existence, and response timing is normalized.

- **Content Security Policy (CSP)**  
  A strict `Content-Security-Policy` header is emitted from `index.php`: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'`. No inline scripts or styles are allowed; `data:` images are the SVG icons in Bootstrap's own CSS. It is sent together with `X-Content-Type-Options: nosniff` and `Referrer-Policy: same-origin`, before anything else, so redirects and error pages carry them too. `.htaccess` sets the latter two for static files when `mod_headers` is available.

- **Static files**  
  `.htaccess` serves only existing static files (CSS, JS including `.mjs`, fonts, images) under `assets/` and the build folders of the three front-end packages (`vendor/twbs/bootstrap/dist/`, `vendor/twbs/bootstrap-icons/font/`, `vendor/enyo/dropzone/dist/`); anything else under `assets/` or `vendor/` is a 404, including PHP scripts, sources and build scripts shipped by Composer packages. `storage/`, `src/`, `bin/`, `docker/`, `tests/` and dotfiles are refused, and every other path goes through `index.php`. A front-end package added later needs its folder added there, in `tests/Support/router.php` and in `docker/lighttpd.conf`, which has the same rules (and a MIME type for each served extension).

- **Downloads**  
  `GET /dl` checks the read right on the file's folder for every request. `Response::contentDisposition()` builds the `Content-Disposition` header: an ASCII fallback `filename` with quotes, backslashes, `%`, `;` and control characters replaced, plus the exact name in `filename*=UTF-8''…` (RFC 6266). A single `Range` (`bytes=a-b`, `a-`, `-n`) gets a `206`, an unsatisfiable one a `416`; multiple ranges get the whole file. Logged as `download_success`, `download_partial` or `download_failure`.

- **Session Hijack Protection**  
Session cookies are `HttpOnly`, `SameSite=Lax`, scoped to the application's base path, and `Secure` when the request is HTTPS (directly, `X-Forwarded-Proto` from a trusted proxy, or `force_https=true`). `Lax` rather than `Strict`: `Strict` drops the cookie on navigations coming from another site, which breaks invitation links opened from an e-mail and the planned OIDC callback. Cross-site POSTs remain blocked by `Lax` and the CSRF tokens. `session.use_strict_mode` rejects session IDs the server didn't issue, and the session ID is regenerated at login (session fixation). Sessions end after 1 hour without a request (hardcoded in `Session::isStillValid()`) and on logout. Revocation is immediate: on every request, `index.php` reads the session's account (`Session::revocationCause()`) and ends the session when the account no longer exists, is deleted, or its `session_version` differs from the one stored at login. Locking or deleting an account, a reset by `bin/create-admin.php` and a password change increment it; the session that changed its own password is kept. A revoked session is answered like an expired one (a redirect to `login`) and logged as `session_revoked`. PHP's own `session.gc_maxlifetime` (1440 s by default) can delete an idle session earlier; a configurable timeout is part of #16.

Future improvements may include:
- Device/session management
- Multi-factor authentication
- OIDC enhancements

## Logging & Forensic Integrity

The application now includes a **pluggable logging backend system**, abstracted via `LogEngineInterface`. 

Log engines are configured via `.env` using the `log_engine` key.

The logger is globally available via `App::getLogger()` and all controller-level events are expected to log security-sensitive actions. Every record carries `ts` (UTC), `action` (the event), `username`, the client `ip` (and `peer_ip`, see *Client IP resolution*), plus the event's own fields. Events logged today:

- Login and logout: `login_password_success`, `login_password_failure`, `login_token_success`, `login_token_failure`, `logout`, `session_revoked` (with `cause`: `deleted`, `missing`, or `revoked` for a lock or a password change)
- Uploads: `upload_start`, `upload_verifying`, `upload_completed` (with size and SHA-256), `upload_name_conflict`, `upload_duplicate_refused`, `upload_denied`, `upload_chunk_rejected`, `upload_failed`, `upload_aborted`, `upload_expired`, `upload_verify_restarted`
- Downloads: `download_success`, `download_partial`, `download_failure`
- Folders and files: `directory_created`, `directory_deleted`, `file_deleted`, `filemanager_failure` (a missing folder requested)
- Administration: `user_invited` (with `invitation_sent`), `user_locked`, `user_deleted` (with `target_user_id` and `pseudonym`), `group_created`, `group_deleted` (with the number of `members` it had), `group_user_added`, `group_user_removed`, `access_granted`, `access_revoked`, `admin_access_denied`
- Mail failures: `mail_send_failure`
- Command line: `cli_admin_created` (`bin/create-admin.php`, also when it resets a password), `cli_test_mail_sent` and `cli_deleted_user_lookup` (with `address_hash` and the account ids found), with `username` set to `cli:` + the system user; `upload_expired` from `bin/expire-uploads.php`, without a username

Not logged yet: password changes from the profile dialog.

**Forensic constraints:**
- Every uploaded file is assembled in staging, then SHA-256-hashed by reading it back from storage, and only then moved to its final path and recorded with its real size. A partial or unverified file is never visible
- A name that already exists in a folder is refused, never overwritten or appended to; the same content twice in one folder is refused too (unique indexes back both rules)
- File metadata (upload timestamp, user, and origin IP) is persisted in the database
- File deletion is logged
- All access control decisions are enforced via middleware (see `AccessMiddleware`)

Developers must **never bypass or disable** these integrity mechanisms. When adding new features, log every user-facing change or file operation.

## Authorization logic

Access rights : 
- a user is in one or more groups
- a group has access to one or more directories, with three rights per directory (`access_rights`): `can_view` (list and download every file), `can_write` (upload, create and delete folders and files) and `can_upload` (upload, and list only one's own uploads). Admins manage them from the file manager's access-rights dialog
- rights are set on each folder and are not inherited by its subfolders. A subfolder appears in its parent's listing only to users who have a right on it
- a group flagged `is_admin = 1` makes its members administrators: full access to every directory, plus the admin interface
- `AccessMiddleware::isAdmin()` is the single admin check. It queries the database on every request (no session cache), so revoking admin membership takes effect immediately
- every action of `AdminController` is admin-only (one guard at the top of `handle()`). File operations open to regular users (`createFolder`, `deleteItem`) live in `FileManagerController` (`POST /`, field `a`) and check the caller's rights on the target directory
- safeguards: the admin group can't be deleted, an admin can't lock or delete their own account, and the last active administrator can't be removed, locked or deleted

### 1. **User Management**
| Feature | Description | Location |
|--------|-------------|-------------|
| 🧑 View Users | List all users, their groups and their status (active: a password or a valid invitation; otherwise locked) | Admin UI, Users tab |
| ➕ Create User | Not available: a contributor is created by inviting them. Administrators are created with `bin/create-admin.php` | — |
| ✉️ Invite User | Creates the user, adds them to the group `<their e-mail domain> users`, gives that group the chosen rights on the current folder and optionally e-mails a tokenized login link | File Manager UI (admins), Invite dialog |
| 🔁 Resend Invitation | A new invitation link | Admin UI, Users tab |
| 👥 Group memberships | Add or remove a user's groups | Admin UI, Users tab |
| 🔒 Lock User | Makes the password and any pending invitation unusable | Admin UI, Users tab |
| 🗑️ Delete User | Disables the account for good and replaces its address by a pseudonym (see *Deleted accounts*). Its files, folders and log entries are kept | Admin UI, Users tab |

Changing a user's name or e-mail address isn't available. The invitation workflow is under review (#22).

#### Deleted accounts

Accounts are never removed from `users`, so that `files.uploaded_by` and `directories.created_by` keep pointing to an account (chain of custody). Deleting a user, in one transaction:
- replaces `username` and `email` by the pseudonym `deleted:<SHA-256 of the lowercased address>:<user id>` (the id lets one address be deleted more than once; both columns are `UNIQUE`);
- sets `password = '!'`, clears any invitation token, records `deleted_at` (UTC) and `deleted_by`;
- removes the account's group memberships.

Listings show the uploader of its files as `deleted user #<id>` (and `unknown user #<id>` for an id without a `users` row; migration v4 gives such ids a deleted account). A deleted account is hidden from the Users tab, refused by the login (password and token), by the admin actions on users and by `bin/create-admin.php`. Inviting the same address again creates a new account, with a new id.

The pseudonym is pseudonymous, not anonymous: anyone who has the address can compute its hash and find the account. That is the purpose: an investigation about an address can still find what the account did, with `bin/find-deleted-user.php`. Under the GDPR it remains personal data. The application log keeps clear addresses in the events logged before the deletion, for the log's own retention period.

---

### 2. **User Self-Service**
| Feature | Description | Location |
|--------|-------------|-------------|
| 🔑 Change Password | New password, typed once; the current one isn't asked for | Profile dialog (File Manager and Admin UI) |

---

### 3. **Group Management**
| Feature | Description | Location |
|--------|-------------|-----------|
| 👥 View Groups | List all groups, their members and their folders | Admin UI, Groups tab |
| ➕ Create Group | By name | File Manager UI (admins): typing a new group name in the access-rights dialog creates it |
| 👤 Add/Remove Users | Assign or remove users from a group | Admin UI, Users tab |
| 🗑️ Delete Group | Deletes the group, its memberships and its access rights: its members lose the access it granted. Refused for the admin group | Admin UI, Groups tab |

---

### 4. **Directory Permissions UI**
| Feature | Description | Location |
|--------|-------------|----------|
| 📁 Folder browser | Browse the folders the user has a right on | File Manager UI |
| 👁️ View Group Access | See which groups can do what in the current folder | File Manager UI (admins), access-rights dialog |
| 🔧 Edit Access Rights | Add/remove group access and set the rights (read, write, upload) | File Manager UI (admins), access-rights dialog |

---

### 5. **Audit & Logs (Planned for Later, #23)**
| Feature | Description | Location |
|--------|-------------|----------|
| 📜 View User Actions | Filterable logs: uploads, downloads, logins | Admin UI, Logs tab (an empty placeholder today) |
| 🧾 Group/Permission Changes | Audit trail for admin actions (access revokes, user changes) | Admin UI |

Until then, the events are in the log engine's output (see "Logging & Forensic Integrity").


## Database Bootstrapping

Database initialization is handled programmatically in `src/Core/Database.php`.

`createSchema()` creates the current schema on a fresh database. Upgrades of existing databases are handled by `Database::migrate()`, versioned with SQLite's `PRAGMA user_version`. Each step must be idempotent, because fresh databases already have the current schema:
- **v1:** adds `groups.is_admin` and flags group `id = 1`, the group the former hardcoded admin check trusted. It also locks the formerly seeded `admin` account if it still has the default password `admin`; the lock is reported in the PHP error log. Create a new administrator with `php bin/create-admin.php <username>`.
- **v2:** replaces the v1 `uploads` table (in-progress v1 uploads are discarded; their partial files, left at their final path by the v1 code, are listed in the PHP error log) with `uploads` and `upload_chunks`, and adds the unique indexes `files(directory_id, name)` and `files(directory_id, sha256)`. The v1 upload code could create two rows for one name; the migration never drops such evidence records. If it finds any, it stops, lists them in the PHP error log, and the application refuses to run until an operator resolves them. `bin/check-integrity.php` lists them too, along with stored files that no longer match their hash.

- **v3:** adds `users.deleted_at` and `users.deleted_by` (soft deletion of accounts). Existing accounts stay active.
- **v4:** repairs the rows the foreign keys would refuse. Ids of accounts deleted before v3, still referenced by files, folders or uploads, get a deleted account named `deleted:unknown:<id>`. Memberships of missing or deleted accounts and of missing groups, access rights of missing groups or folders, uploads into missing folders and chunks of missing uploads are deleted. Files and folders whose folder is missing are kept (evidence records) and listed as "to review". Every change is reported in the PHP error log.

- **v5:** adds `users.session_version` (immediate revocation of sessions). Sessions opened before v5 match the default 0 and stay valid.

To add a migration, add a `if ($version < N)` block in `migrate()` that ends with `PRAGMA user_version = N`. Back up the database file (`database_path`, by default `storage/database/app.sqlite`) before upgrading.

No user is seeded. A fresh install has an empty admin group: create the first administrator with `bin/create-admin.php`.

All database logic uses raw SQL for performance and transparency.

## Database structure

Extract the database structure with `sqlite3 <database_path> '.schema'` (by default `storage/database/app.sqlite`; in the Docker container, `/data/database/app.sqlite`).

The `FOREIGN KEY` clauses are enforced: `Database::initialize()` sets `PRAGMA foreign_keys = ON` on every connection, after the migrations (which repair rows the constraints would refuse, see v4). So:
- a `users` row referenced by a file, a folder or an upload can't be deleted (accounts are soft-deleted, see *Deleted accounts*);
- deleting a group deletes its `user_group` and `access_rights` rows;
- deleting a folder (empty ones only) deletes its `access_rights` rows and its `uploads` records; it is refused while an upload into it is in progress;
- a `files` row keeps its folder: a folder with files can't be deleted.

The read-only scripts (`bin/check-integrity.php`, `bin/find-deleted-user.php`) open the database themselves without the pragma, which only matters for writes. Rows that predate the enforcement and still refer to a missing row are listed by `bin/check-integrity.php` (`REFERENCE`).

Database structure is : 
```
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    email TEXT UNIQUE,
    invitation_token TEXT,
    token_expiry DATETIME,
    deleted_at DATETIME,         -- soft deletion (see Deleted accounts)
    deleted_by INTEGER,
    session_version INTEGER NOT NULL DEFAULT 0  -- incremented to end the account's sessions
);
CREATE TABLE IF NOT EXISTS 'groups' (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    is_admin INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS 'user_group' (
    user_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS 'directories' (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    path TEXT NOT NULL,
    parent_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER,
    created_from TEXT,
    FOREIGN KEY (parent_id) REFERENCES directories(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS 'files' (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    directory_id INTEGER,
    name TEXT NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    uploaded_by INTEGER,
    uploaded_from TEXT,
    size INTEGER NOT NULL DEFAULT (0),
    sha256 TEXT,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    FOREIGN KEY (directory_id) REFERENCES directories(id)
);
CREATE TABLE IF NOT EXISTS 'access_rights' (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    directory_id INTEGER NOT NULL,
    can_view BOOLEAN NOT NULL DEFAULT FALSE,
    can_write BOOLEAN NOT NULL DEFAULT FALSE,
    can_upload BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
    UNIQUE (group_id, directory_id)
);
CREATE UNIQUE INDEX files_dir_name ON files(directory_id, name);
CREATE UNIQUE INDEX files_dir_sha256 ON files(directory_id, sha256);
CREATE TABLE uploads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    client_uuid TEXT NOT NULL,          -- Dropzone's dzuuid: unique per user only
    directory_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    size INTEGER NOT NULL,              -- declared; checked against the staged file
    chunk_size INTEGER NOT NULL,
    chunk_count INTEGER NOT NULL,
    staging_key TEXT NOT NULL UNIQUE,   -- server-generated
    sha256 TEXT,                        -- from the staged file, at completion
    verified_bytes INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL CHECK (status IN ('in_progress', 'verifying', 'completed', 'aborted', 'failed', 'expired')),
    error TEXT,
    file_id INTEGER,                    -- the files row, once completed
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_update DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, client_uuid),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
);
CREATE INDEX uploads_active ON uploads(status, last_update);
CREATE TABLE upload_chunks (           -- chunks received; deleted when the upload ends
    upload_id INTEGER NOT NULL,
    chunk_index INTEGER NOT NULL,
    size INTEGER NOT NULL,
    PRIMARY KEY (upload_id, chunk_index),
    FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE
);
```

## Upload protocol

Dropzone 6's chunked upload, with a server that makes it safe for evidence (`Controllers\UploadController` for HTTP, `Services\ChunkedUpload` for the logic). One endpoint, `POST /upload`, `multipart/form-data`, with the CSRF token in `X-CSRF-TOKEN`. The field `a` selects the action. Every response is JSON with `Cache-Control: no-store`.

| `a` | Fields | Success |
|---|---|---|
| `checkRights` | `cwd`, `name`, `size` (name and size optional) | `200 {"status":"success",…}`. Refusals as below, before any byte is transferred |
| *(none)*: a chunk | `file` (the bytes, with the file name), `dzuuid`, `dzchunkindex`, `dztotalchunkcount`, `dztotalfilesize`, `dzchunksize`, `dzchunkbyteoffset`, `cwd` | `200 {"status":"progress","received":n,"chunk_count":N}` |
| `complete` | `dzuuid` | `200 {"status":"completed","file":{id,name,size,sha256,uploaded_at,uploaded_by,uploaded_from},…}`, or `202 {"status":"verifying",…}` while another request verifies it. Idempotent |
| `status` | `dzuuid` | `200 {"status","name","size","verified_bytes","message"?,"file"?}` |
| `abort` | `dzuuid` | `200 {"status":"aborted",…}`; `409` if already completed |

`cwd` is the target folder's path (`/` or empty for the root). `dzuuid` is a UUID chosen by the client.

**Errors** have a 4xx/5xx status and `{"status":"error","code":…,"message":…}`, sometimes with more fields (`missing`: the missing chunk indexes; `upload`: the upload's state; `file`: the existing file with the same content):

| Status | Codes |
|---|---|
| 400 | `InvalidChunk`, `InvalidName`, `InvalidUpload` (bad `dzuuid`), `InvalidArgument`, `MissingChunks` |
| 403 | `AccessDenied` |
| 404 | `NoSuchDirectory`, `NoSuchUpload` |
| 405 | `MethodNotAllowed` |
| 409 | `NameConflict`, `DuplicateContent`, `InvalidState` |
| 413 | `EntityTooLarge` (over `upload_max_size`, or over PHP's request limits) |
| 422 | `SizeMismatch` |
| 500 | `StorageError`, `ServerError`, `InternalError` |

As on every route, an expired session gets a redirect to the login page and a refused CSRF token a `403` with `code: csrf_invalid` (see *CSRF Protection*).

**Server behaviour:**
- An upload is keyed by (user, `dzuuid`): another user's uuid is another upload. Its first chunk (any index) creates it, after the rights, size and name checks; a new upload of the same name in the same folder by the same user replaces their unfinished one.
- The geometry (size, chunk size, chunk count, name, folder) is fixed by the first chunk and checked on every chunk: every chunk but the last has exactly `dzchunksize` bytes, at offset `index × chunk size`. An empty file is one empty chunk.
- Each chunk is written at its offset into the staging element (`.uploads/<server key>` with the local engine) and recorded in `upload_chunks`, so a chunk sent twice or out of order is harmless. Rights are re-checked on every request.
- `complete` checks that every chunk is present and the staged size, then hashes the staged file read back from storage (progress in `verified_bytes`), re-checks the rights, the name and duplicate content, then inserts `files` with the real size and moves the file into place in one transaction. A verification without progress for 120 s (the request running it died) is restarted by the next `complete` or `status`.
- States: `in_progress → verifying → completed`, or `aborted`, `failed`, `expired`. Terminal rows are kept for the audit trail (until their folder is deleted; the upload events stay in the log); their chunk rows and staging files are deleted. Uploads without activity for `upload_stale_after` seconds expire (when an upload starts, or with `bin/expire-uploads.php`).

**Client** (`filemanager.js`): `forceChunking`, 2 MiB chunks, one at a time, `retryChunks` ×3. Dropzone's `accept` hook sends `checkRights`; `chunksUploaded` sends `complete` and polls `status` every second, showing "Verifying stored file… n%". If the `complete` response is lost (e.g. a proxy timeout on a long verification), the polls deliver the outcome. Dropzone's `canceled` event sends `abort`. A refusal's message stays visible under the file's preview.

## Backends

The application uses an Interface/Factory pattern to dynamically select backends.

### Mail engine

Mail is only used for invitation links. `MailEngineFactory::create()` reads the flat `.env` keys (see `env.sample`) and throws `InvalidArgumentException` on a missing or invalid setting:
- `mail` (default): PHPMailer with `isMail()`, i.e. the local sendmail binary. PHPMailer encodes headers and rejects header injection; `default_mail_from` is also the envelope sender (`-f`).
- `smtp`: PHPMailer over SMTP. `smtp_secure=none|starttls|smtps`. Not set means `none` (plain SMTP, no opportunistic STARTTLS), except on port 465, which gets `smtps`. `starttls` is required, not opportunistic: the send fails if the server doesn't offer it. Authentication only when `smtp_user` is set. `SMTPDebug` stays at 0: its output would end up in the HTTP response, and from level 2 it includes the `AUTH` exchange.
- `sendgrid`: SendGrid Web API v3. An HTTP status ≥ 400 counts as a failure, with SendGrid's error body in the reason.
- `none`: no mail, for hosts that cannot send any (air-gapped, no relay). `default_mail_from` is not required. `MailEngineFactory::isManual()` tells callers that the link is to be handed to the administrator instead (the invitation dialog does so from Phase 10 Step 3 of `PLAN.md`; until then an invitation with `none` reports a send failure). `MailEngineNone::send()` always returns `false`, so a caller that skips the check fails visibly. `bin/send-test-mail.php` says that mail is disabled and exits 0.

All engines send UTF-8 HTML with a plain-text alternative (`MailText::fromHtml()`), from `default_mail_from` / `default_mail_from_name`. `send()` returns `false` on failure and `lastError()` gives the reason; it doesn't throw. The caller logs failures as `mail_send_failure` (recipient, engine, error). An invitation whose e-mail fails still creates the contributor; the admin gets `success: false` with a generic message, and the details go to the log only.

### Storage engine

Storage engine can be :
- Local for the local filesystem (`base_storage_directory` must be an existing directory)
- Amazon S3 and Azure Blob storage: planned, not implemented. `storage_engine=amazon_s3|azure_blob` is refused with an explicit error.

Besides reading, uploads need a **staging** area (`stagingCreate`, `stagingWriteAt`, `stagingSize`, `stagingOpen`, `stagingCommit`, `stagingRemove`), keyed by 32 hex digits generated by the application. `stagingWriteAt` writes a chunk at its offset, so a chunk written twice leaves the same content. `stagingCommit` moves the staged element to its final path and must never replace an existing element. The local engine stages in `.uploads/` under its root, on the same filesystem, so the commit is an atomic `rename()`; that name is reserved in the root folder (and folder names can't start with a dot).

### Logging

`log_engine` (`log_dest` is still accepted as an older name):
- `file` (default): appends to `log_file_path`, one event per line, `log_format=jsonl` (default) or `csv`. The application refuses to serve requests (HTTP 500) if the file's directory isn't writable by the PHP user.
- `syslog`: JSON, with the identity `syslog_tag` (default `dfir-fm`).
- `stderr`: JSON lines on the PHP process's stderr; for containers (see "Docker").

## Configuration

`Core\Config::load()` reads `.env` (template: `env.sample`):
- one `key=value` per line; blank lines and lines starting with `#` are ignored;
- a value wrapped in matching `"…"` or `'…'` is unquoted, and an optional `export ` prefix is accepted;
- a `#` inside a value is kept, because passwords may contain one. There are no inline comments;
- malformed lines are ignored and reported in the PHP error log by line number only, never their content.

**Environment overrides:** `DFIRFM_<KEY>` overrides or adds `<key>` (lowercased), e.g. `DFIRFM_STORAGE_ENGINE=local`. Unprefixed variables are ignored. `.env` may be absent only if at least one `DFIRFM_` variable is set, as in containers. Otherwise a missing file stops the application.

**Permissions:** `.env` holds secrets and must be readable by the PHP user but not by everyone, e.g. owner you, group `www-data`, mode `640`. An unreadable `.env` stops the application with a 500 ("Environment file not readable"), rather than silently running on defaults as earlier versions did.

**Keys.** `env.sample` documents each one; this is the index:

| Area | Keys |
|---|---|
| Mail | `mail_engine`, `smtp_host`, `smtp_secure`, `smtp_port`, `smtp_user`, `smtp_password`, `smtp_timeout`, `sendgrid_api_key`, `default_mail_from`, `default_mail_from_name` |
| Storage | `storage_engine`, `base_storage_directory` |
| Database | `database_path` |
| Uploads | `upload_max_size`, `upload_stale_after` |
| Security | `trusted_proxies`, `force_https`, `max_login_attempts`, `max_login_attempts_per_user`, `login_lockout_duration` |
| Logging | `log_engine`, `log_file_path`, `log_format`, `syslog_tag` |
| Docker only (read by Compose, ignored by the application) | `uid`, `gid`, `http_port`, `files_dir`, `log_dir` |

**Request size limits.** Uploads arrive in chunks of 2 MiB (set in `filemanager.js`), each in a `multipart/form-data` request a little larger than that. Every layer must accept such a request:
- PHP: `upload_max_filesize` and `post_max_size`, e.g. `8M` (the Docker image's `docker/php.ini`). PHP's defaults are 2M and 8M: a 2 MiB chunk is over `upload_max_filesize=2M`. A refused chunk gets `413 EntityTooLarge`.
- Apache: `LimitRequestBody`, if set, at least that size.
- A reverse proxy: e.g. nginx's `client_max_body_size`, 1m by default, which is too small.

The largest file is set by `upload_max_size`, not by these limits.

## Docker

`docker/docker-compose.yml` builds the image from the repository root (`.dockerignore` keeps `.env`, `storage/`, `vendor/` and `.git` out of the build context) and runs one container:

- **Processes:** PHP-FPM (listening on 127.0.0.1:9000 only) and lighttpd on port **8080**, both started in the foreground by `docker/start.sh`. When either exits, the script stops the other and exits, so the container stops and its restart policy applies. The image's stop signal is `SIGTERM` (the PHP base image's `SIGQUIT` is ignored by bash as PID 1, which made `docker compose stop` wait 10 s for a SIGKILL).
- **Routing:** `docker/lighttpd.conf` mirrors `.htaccess`: the same static allowlist, 404 for `storage/`, `src/`, `bin/`, `docker/`, `tests/`, dotfiles and the rest of `assets/` and `vendor/`, everything else to `index.php`. lighttpd has no default MIME types: the file lists one per served extension, including `.mjs` (browsers refuse a module script with another type).
- **Configuration:** `docker/.env`, created by the administrator from `env.sample` (the same keys as a host install, plus its "Docker" section uncommented). It is not in git (`.gitignore`) and the repository ships no copy of it. It is mounted read-only as the application's `.env`; Compose refuses to start if it is missing, rather than creating a directory in its place. It must be readable by the container's `www-data`: uid/gid 82 (Alpine) by default, not the host's (33 on Debian), so `chgrp 82 docker/.env` with mode `640`; with `uid`/`gid` set (below), their owner. The file is read at each request, but the bind mount follows the file's inode: an editor that replaces the file (`sed -i`, some editors' save) leaves the container on the old one until `docker compose restart`. PHP-FPM passes the environment to PHP (`clear_env = no`), so a `DFIRFM_*` variable added to the compose file overrides a key.
- **Compose reads `docker/.env` too:** it loads the `.env` of the compose file's directory for every command. Five keys, ignored by the application, choose the mounts, the port and the user: `files_dir`, `log_dir`, `http_port` (the host port, 8080 if unset), `uid` and `gid` (see "User"). A volume source that is an absolute path is a bind mount, a plain name is a named volume, so `${files_dir:-dfir-fm-files}` gives the host directory when the key is set and a volume otherwise. Compose expands `$` in unquoted or double-quoted values while reading the file: it only warns, the application still reads the file as written; single quotes avoid the warnings.
- **Paths:** the image sets the three paths to its own locations, outside the web root, through `DFIRFM_*` variables, which win over the `.env`'s host paths:

  | Key | In the container | Mounted from |
  |---|---|---|
  | `base_storage_directory` | `/data/files` | `files_dir` if set (a host directory owned by `uid`, 82 by default), otherwise the volume `dfir-fm-files`. Nothing to set with a storage engine that doesn't use local files. |
  | `database_path` | `/data/database/app.sqlite` | always the volume `dfir-fm-database` (back it up) |
  | `log_file_path` | `/data/logs/dfir-fm.log` | `log_dir` if set (a host directory owned by `uid`, 82 by default), otherwise the volume `dfir-fm-logs`, disposable: `docker volume rm dfir-fm-logs`; never `docker compose down -v`, which also removes the database |

  Host directories must exist before the first start: Docker would create a missing one owned by root, which the application can't write to (uploads and downloads then fail with a 500, "The storage is unavailable").
  Sessions live in the container's `/tmp`: a restart logs everyone out.
- **User:** `uid` and `gid` (default 82, the image's) set the ids of the container's `www-data`, so that a host directory is mounted as it is, owned by its user (`id -u`, `id -g`), with no `chown` or ACL on the host. At start, as root, `start.sh` changes `www-data`'s ids (`usermod`/`groupmod`), then `chown -R`s the Docker volumes under `/data` that aren't owned by them yet (the database always; the files and the logs when they are volumes: Compose passes `files_dir` and `log_dir` as `APP_FILES_DIR` and `APP_LOG_DIR`). Host directories are never changed. lighttpd and the PHP-FPM workers then run as `www-data`; the code stays owned by root. 0, or a value that isn't a number, stops the container with a message.
- **Logs:** with `log_engine=file` (the default), in `/data/logs`. With `log_engine=stderr` (in the `.env`, or as `DFIRFM_LOG_ENGINE` in the compose file to keep a file on a host install), the JSON events go to the container output through PHP-FPM (`log_limit` raised so that lines aren't cut). `syslog` has no daemon to talk to in the container. lighttpd's errors go to stderr and its access log to stdout. lighttpd opens its logs after dropping to `www-data`, who may not open the container's stdout or stderr by path (`/dev/stdout` fails or is silently lost): the error log is left unset (stderr as inherited) and the access log is a piped logger (`cat`, started before the drop) writing to fd 3, which `start.sh` points at its stdout.
- **Code:** owned by root and read-only for `www-data`, who only writes to `/data`. Dependencies are installed with `composer install --no-dev`. The image adds the `apcu` (pecl) and `gmp` (required by `sendgrid/sendgrid`) extensions.
- **First administrator:** `docker compose exec -u www-data app php bin/create-admin.php you@yourdomain.com`.
- **HTTP only:** the compose file publishes the port (`http_port`, default 8080) on 127.0.0.1. Put a TLS-terminating reverse proxy in front and set `trusted_proxies` (and `force_https` if needed). A proxy on the host (e.g. `cloudflared` pointed at `http://localhost:7080`) reaches the container through Docker's port publishing, so the application sees the gateway of the Compose network (e.g. `172.21.0.1`), not `127.0.0.1`: list Docker's default range, `172.16.0.0/12`, rather than `127.0.0.1, ::1`.

## Backend Extensibility

Backends for storage and mail are dynamically chosen based on `.env` variables.

- **Mail backends** must implement `MailEngineInterface`
- **Storage backends** must implement `StorageEngineInterface`
- **Logging backends** must implement `LogEngineInterface`

To add a new backend:
1. Create a class in `src/Backends/` implementing the appropriate interface.
2. Register the backend by modifying the relevant factory logic.
3. Add any required `.env` variables and document them.

Each backend is responsible for parsing its configuration from the `.env` file.

## Known Limitations / To-Do

- Database migrations are minimal (`PRAGMA user_version` steps in `Database::migrate()`), with no down-migrations
- Only the local storage engine exists (Azure Blob and S3: #6, #7)
- Session idle timeout hardcoded to 1 hour; no absolute lifetime (#16)
- Password changes aren't logged, and the profile dialog doesn't ask for the current password
- No forgotten-password reset (#1); the Logs tab of the admin page is empty (#23)
- Login rate-limit counters are per server (APCu)

## Contributing

When contributing to DFIR FM:
- Follow the architecture and naming conventions established in the project.
- Document any new `.env` variables or backends.
- Ensure your changes do not compromise forensic integrity (e.g., hashing, traceability).
- All contributions should aim to be auditable and security-aware.

See the main `README.md` for contribution workflow.
