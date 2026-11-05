# Developer Documentation

This document outlines the technical structure and internal workings of the DFIR File Manager (DFIR FM), providing guidance for contributors and maintainers.

## Bootstrapping & Application Flow

The application uses a single entrypoint: `index.php`. It handles routing manually and initializes all required components (middleware, controller dispatch, view rendering).

Command-line tools live in `bin/` and refuse to run outside the CLI SAPI:
- `php bin/create-admin.php <username>`: creates an administrator (or resets an existing user's password, lifts a lock, and adds them to the admin group) and prints a random password once. This is how the first administrator is created; no default account exists. It's also the break-glass tool when every administrator is locked out: it needs shell access only, and no identity provider.
- `php bin/send-test-mail.php <recipient>`: sends a test e-mail through the configured mail engine, exactly as invitations are sent. Exit code 0 when the engine accepted the message, 1 with the reason on stderr otherwise. With `mail_engine=none` it sends nothing, says so and exits 0. Run it as the web server user (e.g. `sudo -u www-data php bin/send-test-mail.php …`) to also check its permissions.
- `php bin/check-integrity.php`: re-hashes every stored file and compares it with its `files` record (SHA-256 and size). Also reports `files` rows that share a name or a SHA-256 in one directory, rows that refer to a missing row (`PRAGMA foreign_key_check`), and, with a storage engine that has named files (local), what the storage holds without a record: files (`ORPHAN`), symbolic links and special files, folders it can't read (`UNREADABLE`), and a record stored as a symbolic link (`LINK`). It walks the storage through the engine (`Services\DirectoryRefresh::walk()`). The database or log file configured inside the storage is printed on a `RESERVED` line, not counted as a problem (see *Storage engine*). The whole storage, read-only; the file manager's "Refresh" does one folder and records what it finds (see *Refresh from storage*). Exit code 0 when everything matches, 1 otherwise.
- `php bin/report-domain-groups.php`: lists the `<domain> users` groups left by invitations made before default groups (DFM-01), with their members and the folders they have rights on, and flags those whose members share several folders. Review and split them from the admin page; the script changes nothing. Read-only; exit code 0, 2 on error.
- `php bin/find-deleted-user.php <email>`: lists the deleted accounts of an e-mail address (see *Deleted accounts*), with their id, so that `files.uploaded_by`, `directories.created_by` and the log can be searched by id. Read-only; each lookup is logged with the hash of the address, not the address. Exit code 0 when an account was found, 1 otherwise.
- `php bin/expire-uploads.php`: expires uploads without activity for `upload_stale_after` seconds and deletes their staging files, and expires refresh jobs left as long. The application also does this whenever an upload or a refresh starts; the script is for a cron job.
- `php bin/archive-events.php --list | --before <date> --out <file> | --purge <id>`: archives the events before a date to a JSONL file and purges an archive, as the Logs tab does (see *Archive and purge*). For a cron job or a cold storage handled on the server. `--out` must not exist yet. Exit code 0, 1 when refused, 2 on a usage or configuration error.
- `php bin/import-log.php <file>...`: imports JSONL log files written before the events database existed (see *Importing older log files*). Exit code 0, 1 when a file is refused, 2 on a usage or configuration error.

Routes (`index.php`). Authentication and CSRF checks are global, in `index.php`: every route but `/login` requires a session (otherwise a redirect to `login?redirect=…`), and every POST a valid CSRF token. Authorization (admin, folder rights) is checked by each controller action, through `AccessMiddleware`. POST requests select their action with the field `a`.

| Path | Controller | Purpose |
|---|---|---|
| `/` | `FileManagerController` | GET: folder listing (`?p=/path`). POST: `createFolder`, `deleteItem`, `rename` (see "Rename"); `refresh`, `refreshStep`, `refreshStatus`, `refreshCancel` (`RefreshController`, admins only; see "Refresh from storage") |
| `/dl` | `DownloadController` | File download, with `Range` |
| `/upload` | `UploadController` | Chunked upload (see "Upload protocol") |
| `/admin` | `AdminController` | Admin page and its actions (users, groups, invitations, access rights; Logs tab: `listEvents`, `listEventActions`, `exportEvents`, `listArchives`, `archivePreview`, `archiveEvents`, `purgeArchive`); admins only |
| `/profile` | `ProfileController` | `updatePassword` |
| `/login` | `LoginController` | Two-step password login; the invitation link's password form (`setPassword`) |
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
│       ├── admin-logs.js      # Admin: Logs tab (search, record, export)
│       ├── admin-archives.js  # Admin: Logs tab, archive and purge dialog
│       ├── login.js           # Login page
│       └── set-password.js    # Invitation link page: set a password
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
│   ├── report-domain-groups.php # Groups of older invitations, to review
│   ├── expire-uploads.php     # Expire abandoned uploads (cron)
│   ├── archive-events.php     # Archive events to a file, purge an archive
│   ├── import-log.php         # Import log files written before the events database
│   └── oidc-check.php         # Check the OIDC provider and the client registration (#9)
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
│   │   ├── LogEngine*.php     # Logging: the tee, the events database, the file/syslog/stderr copies
│   │   ├── LogDestination.php # One place events are written to
│   │   ├── LogHalt.php        # The halt flag when an event can't be logged (D29)
│   │   ├── LogWriteException.php
│   │   ├── LogEntry.php       # The log record shared by the destinations
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
│   │   ├── RefreshController.php # "Refresh from storage" actions of the file manager
│   │   └── UploadController.php
│   ├── Core/
│   │   ├── App.php            # Singleton pattern to store $db, $config and $logger
│   │   ├── Config.php
│   │   ├── Database.php
│   │   ├── EventStore.php     # The events database: connection and schema
│   │   ├── PasswordPolicy.php # The password rule (12 characters, never trimmed)
│   │   ├── Request.php
│   │   ├── Response.php
│   │   └── Session.php
│   ├── Middleware/
│   │   ├── AccessMiddleware.php
│   │   └── SecurityMiddleware.php
│   ├── Services/
│   │   ├── ChunkedUpload.php  # Upload state machine, verification, commit
│   │   ├── UploadException.php # Refusal with HTTP status and error code
│   │   ├── DirectoryRefresh.php # Storage against records: one folder, or the whole tree
│   │   ├── RefreshJobs.php    # Refresh jobs: import, re-hash, flag
│   │   ├── RefreshException.php
│   │   ├── ItemRename.php     # Rename a file or a folder (#21)
│   │   ├── RenameException.php
│   │   ├── NameRules.php      # File and folder name rules
│   │   ├── EventLog.php       # Events database: search, export, archive, purge
│   │   ├── EventImport.php    # Import of older log files
│   │   └── Oidc/              # OIDC sign-in (#9, optional)
│   │       ├── OidcConfig.php # The oidc_* settings: state, validation, domain match
│   │       ├── OidcProvider.php # Discovery document and JWKS, cached in APCu; userinfo
│   │       ├── IdTokenValidator.php # ID token: signature, iss, aud/azp, lifetime, nonce, max_age, MFA
│   │       ├── Claims.php     # Claims of a validated token: dotted paths, address, admin claim, userinfo fallback
│   │       ├── HttpsClient.php  # Interface of every IdP call (stubbed in tests)
│   │       ├── CurlHttpsClient.php # Verified HTTPS, short timeouts, no redirects
│   │       ├── HttpsResponse.php
│   │       ├── ProviderCheck.php # The checks of bin/oidc-check.php
│   │       ├── OidcException.php
│   │       └── OidcRefusal.php # A refused sign-in, with its log cause
│   └── Views/                 # Templates for rendering HTML
│       ├── Modals/            # Bootstrap modals
│       │   ├── AccessRightsModal.php
│       │   ├── ArchiveModal.php    # Logs tab: archive and purge
│       │   ├── DeleteConfirmationModal.php
│       │   ├── EventRecordModal.php # Logs tab: one event's full record
│       │   ├── InviteModal.php
│       │   ├── NewFolderModal.php
│       │   ├── OperationResultModal.php
│       │   ├── ProfileModal.php
│       │   ├── RefreshModal.php
│       │   ├── UploadModal.php
│       │   └── UserGroupModal.php
│       ├── AdminView.php
│       ├── FileManagerView.php
│       ├── InvitationView.php
│       ├── Layout.php         # <head> shared by the pages (asset lists)
│       ├── LoginView.php
│       └── SetPasswordView.php # Invitation link page
├── storage/                   # Created at runtime, not in git; must not be web-accessible
│   ├── database/              # SQLite database files
│   │   └── app.sqlite         # Main SQLite database (default; database_path)
│   ├── files/                 # Storage for uploaded files: base_storage_directory=storage/files,
│   │                          #   never storage/ itself, which holds the database
│   │   └── .uploads/          # Staging: uploads in progress (reserved name)
│   ├── events/                # The events database (default; events_database_path)
│   │   └── events.sqlite
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

- Set up Apache (`mod_rewrite`, `AllowOverride` for `.htaccess`; `mod_headers` recommended) with PHP 8.4 and the extensions `pdo_sqlite`, `apcu`, `curl`, `gmp` (required by `sendgrid/sendgrid`) and `mbstring`, then `composer install`. APCu must be enabled for the web server (`apc.enabled=1`, the default): without the extension, or with it disabled, `index.php` answers every request with a `503` saying what is missing (JSON `code: apcu_unavailable` for POST requests) and writes `DFIR FM configuration error: APCu unavailable, …` to the PHP error log. The `bin/` scripts don't need it (in the CLI, `apc.enable_cli` is usually 0). Or use the Docker image (see "Docker"). Other web servers need the `.htaccess` rules translated, as `docker/lighttpd.conf` does for lighttpd.
- OIDC sign-in (#9) is optional and off unless the `oidc_*` keys are set. It uses `firebase/php-jwt` (D11) for the JWKS and the ID-token signatures, with PHP's `openssl` extension, and a code-flow client of our own (`src/Services/Oidc/`, ext-curl). A partial or invalid `oidc_*` configuration gets the same treatment as a missing APCu: a `503` on every request (JSON `code: oidc_misconfigured`), the reason in the PHP error log as `DFIR FM configuration error: OIDC …` (never the client secret).
- Two kinds of logs: the application's events (logins, uploads, downloads, admin actions) go to the events database and to the log engine's copy (`log_engine`, see "Logging & Forensic Integrity"); PHP errors, configuration problems and logging failures go to the PHP error log.
- No framework or build system is used at this time.
- JavaScript and CSS files are managed manually (see "Front end").

To debug:
- Use `error_log()` for PHP
- Use browser dev tools for JS

## Front end

- **Libraries**, from Composer, served from `vendor/` as built: Bootstrap 5.3 (`twbs/bootstrap`, the bundle with Popper, exposes `window.bootstrap`), Bootstrap Icons (`twbs/bootstrap-icons`, `bi bi-*` classes) and Dropzone 6 (`enyo/dropzone`, imported by `filemanager.js` as `dist/dropzone.mjs`). No jQuery.
- **Pages:** `Views/Layout.php` prints every page's `<head>`; `Layout::appHead()` is the asset list of the file manager and admin pages, whose entry module is `site.js`. The login page loads `login.js` only, the invitation link page `set-password.js` only.
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

The tests need APCu, so `tests/bootstrap.php` stops at once with a message when it isn't enabled. In the CLI it is off unless `apc.enable_cli=1`, which `composer test` passes but can't be set from `phpunit.xml.dist` (it is a startup-only setting). Running PHPUnit directly, or from an IDE or another test runner, needs it too: `php -d apc.enable_cli=1 vendor/bin/phpunit --filter …`, or `apc.enable_cli=1` in the CLI's `php.ini`. The test servers get it from `TestServer`.

- **`tests/Unit/`**: no web server. Client IP resolution, config parsing and `database_path`, `Content-Disposition`, Range parsing, login redirect targets, storage path validation, staging and rename, backend factories, log records, mail engine configuration and message building, schema and migrations, admin checks, throttle settings, the upload state machine (`ChunkedUploadTest`: chunks out of order or sent twice, missing chunks, tampered staging, name and content conflicts, rights, geometry, sizes, abort), the storage listing and reserved paths, the name rules, renaming files and folders (`ItemRenameTest`: subfolders and rights, conflicts, names, rights, uploads and refreshes in progress, storage and database failures), the refresh from storage (`DirectoryRefreshTest`, `RefreshJobsTest`: import, mismatch, missing, refused names, symlinks, duplicates, cancel, progress, stalled steps), and the events database (`LogEngineTeeTest`: one record for every destination, the halt and its recovery; `EventLogTest`: export, archives; `EventImportTest`), on a real SQLite database and local storage in a temporary directory.
- **`tests/Feature/`**: the application over HTTP: login and rate limiting, sessions and cookies, admin authorization, account deletion, file listing, uploads and downloads, renaming (`RenameTest`), the refresh from storage and its markers in the listing, reserved paths, mail, the events database and the halt (`EventLogTest`), the Logs tab (`LogsTabTest`, `LogsExportTest`, `LogsArchiveTest`), HTTP headers and routing, the `bin/` scripts, `database_path`. Each test starts a fresh, isolated instance on PHP's built-in server (`Tests\Support\TestServer`) in a temporary directory, with its own `.env`, SQLite database, storage, log file and APCu counters. The project's own `.env`, database and storage are never touched.
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

  The first step (`checkUser`, the e-mail) has its own per-IP counter, allowing twice `max_login_attempts` in the same window: a blocked source gets `429` with `Retry-After` at once, without the 100–300 ms delay that otherwise holds a PHP worker, and is logged as `login_password_failure` with `step: user`.

  A request already blocked by the IP limit doesn't count against the username. A successful login resets the username counter only: resetting the IP counter would let an attacker clear it by logging into their own account. Invalid values fall back to the defaults. APCu is checked at startup (`SecurityMiddleware::apcuProblem()`): if the extension isn't loaded, or is loaded but disabled (`apc.enabled=0`, where every `apcu_*` call silently fails and no attempt would be counted), the application doesn't run (see *Development & Debugging*). Counters are per server: several containers or servers don't share them.

- **Client IP resolution**  
  `Request::getClientIp()` is the only source of the client address, used for rate limiting, audit logs and forensic records (`uploaded_from`, `created_from`). It is controlled by the `trusted_proxies` setting: a comma-separated list of IPv4/IPv6 addresses or CIDR ranges.
  - **Empty (default):** the client is `REMOTE_ADDR`. Use this for direct connections and for **Apache with mod_remoteip**, which already rewrites `REMOTE_ADDR`. Configure mod_remoteip with explicit `RemoteIPTrustedProxy`/`RemoteIPInternalProxy` addresses, never a range that includes untrusted hosts.
  - **Set:** only when `REMOTE_ADDR` is in the list, `X-Forwarded-For` is walked **from right to left**, skipping trusted proxies; the first untrusted address is the client. Entries further left were written by the client and are never trusted. If every entry is a trusted proxy, the leftmost is used. Ports (`a.b.c.d:port`, `[v6]:port`) are stripped and IPv4-mapped IPv6 addresses are normalised to IPv4.
  - `Client-IP` is never read. `X-Forwarded-Proto` (`Request::isHttps()`) is only honoured from a trusted proxy.
  - **`force_https=true`** makes `Request::isHttps()` always true: `Secure` session cookies and `https://` links in generated e-mails. Use it when TLS is terminated before Apache and PHP can't see it. That includes setups with mod_remoteip: once mod_remoteip has rewritten `REMOTE_ADDR`, the proxy is no longer the visible peer, so its `X-Forwarded-Proto` is never trusted. Don't enable it if the site is also served over plain HTTP: browsers drop `Secure` cookies there and logins fail. An invalid value stops the application, like an invalid `trusted_proxies` entry.
  - Using mod_remoteip *and* `trusted_proxies` together is harmless: after mod_remoteip, `REMOTE_ADDR` is the client, which isn't a trusted proxy, so the header isn't read twice.
  - An invalid entry stops the application (HTTP 500, reason in the PHP error log) instead of running with a wrong trust list.
  - **Missing configuration warning:** a request with `X-Forwarded-For` while `trusted_proxies` is empty usually means an unconfigured proxy, where every client appears with the proxy's address. It is logged as `config_warning` (`setting`: `trusted_proxies`) at most once per hour (APCu key `config_warning_untrusted_proxy`, added atomically), and the admin page shows a warning while that key exists or the current request carries the header. The header alone triggers it, so a client can raise it by sending one, and so can a mod_remoteip setup when a client adds entries mod_remoteip doesn't consume; in both cases it is only a warning, nothing is trusted.
  - Examples: Cloudflare Tunnel with `cloudflared` on the same host: `127.0.0.1, ::1`. Reverse proxy on a Docker network: `172.16.0.0/12`.
  - Log entries carry `ip` (the client) and, when a trusted proxy relayed the request, `peer_ip` (the proxy). The record is built once in `Backends\LogEntry` for all log engines.

- **Anti-Enumeration**  
  Login flow avoids leaking user presence. The two-step login screen always advances to password input, regardless of user existence, and response timing is normalized.  
  Folders don't leak either (#24). A non-admin may know a folder exists only if it is the root, a folder they have a right on, or a parent of one (`AccessMiddleware::canSeeDirectory()`). Any other folder answers exactly like a missing one: the same 404 page for `?p=`, the same `404 NoSuchDirectory` from `/upload`. Only the log tells them apart (`filemanager_failure` cause "Tried to access directory without rights", `upload_denied` cause "Access denied"). `createFolder` checks the write right on `cwd` before saying a name is taken, and `deleteItem` and `rename` give the same answer for a missing id and one the user can't change.  
  Who created or uploaded something, and from which address, is shown to the readers of its folder only (#25). A contributor without the read right sees a subfolder's name without its creator, and in their folder only their own uploads.

- **Content Security Policy (CSP)**  
  A strict `Content-Security-Policy` header is emitted from `index.php`: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'`. No inline scripts or styles are allowed; `data:` images are the SVG icons in Bootstrap's own CSS. It is sent together with `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and `Cache-Control: no-store`, before anything else, so redirects and error pages carry them too. Downloads replace the latter with `private, no-store`; `Session::start()` turns off PHP's session cache limiter, which would otherwise overwrite it. `.htaccess` sets the latter two for static files when `mod_headers` is available.

- **Static files**  
  `.htaccess` serves only existing static files (CSS, JS including `.mjs`, fonts, images) under `assets/` and the build folders of the three front-end packages (`vendor/twbs/bootstrap/dist/`, `vendor/twbs/bootstrap-icons/font/`, `vendor/enyo/dropzone/dist/`); anything else under `assets/` or `vendor/` is a 404, including PHP scripts, sources and build scripts shipped by Composer packages. `storage/`, `src/`, `bin/`, `docker/`, `tests/` and dotfiles are refused, and every other path goes through `index.php`. A front-end package added later needs its folder added there, in `tests/Support/router.php` and in `docker/lighttpd.conf`, which has the same rules (and a MIME type for each served extension).

- **Downloads**  
  `GET /dl` checks the read right on the file's folder for every request. `Response::contentDisposition()` builds the `Content-Disposition` header: an ASCII fallback `filename` with quotes, backslashes, `%`, `;` and control characters replaced, plus the exact name in `filename*=UTF-8''…` (RFC 6266). A single `Range` (`bytes=a-b`, `a-`, `-n`) gets a `206`, an unsatisfiable one a `416`; multiple ranges get the whole file. The file is opened once and sent in 64 KiB blocks through that one handle (`readStream()`, `fseek()` for a range), with no time limit (`set_time_limit(0)`; a client that goes away ends the request, freeing the worker). Logged as `download_success`, `download_partial` or `download_failure` (also when a read fails mid-way: the headers are sent, so the client gets a short body).

- **Invitation links**  
  The link (`login?email=…&token=…&directory=…`) only shows a form asking for a new password, twice: opening it neither logs in nor uses up the token, so mail scanners that follow links do no harm. The form's POST (`action=setPassword`, CSRF-protected) checks the token again (`hash_equals()`, not expired), applies the password rule, then sets the password, clears the token and increments `session_version` in one `UPDATE … WHERE invitation_token = :token`: the token works once, even with two concurrent POSTs, and the account's older sessions end. The invitee is then logged in and sent to the invitation folder (`/` if it no longer exists). The page and the answer carry `Referrer-Policy: no-referrer` and `Cache-Control: no-store`, so the token doesn't leak into `Referer` headers or caches. Tokens are 128 random bits, valid one hour (D23). An unusable link always gets the same page; the log says why (`login_token_failure` with `cause`: `unknown` account, `used` (no pending token), `wrong` token, `expired`). The Docker image's lighttpd access log leaves the query string out; with Apache, use a `LogFormat` with `%U` rather than `%r` (or `%q`) for the same effect.

- **Passwords**  
  One rule (`Core\PasswordPolicy`, D26) for the invitation form and the profile dialog: at least 12 characters, no complexity rule (accounts mostly live for the duration of an investigation). Passwords are never trimmed, neither when set nor at login. Setting a password never asks for the current one (D24): the session, or the invitation token, is the proof.

- **Session Hijack Protection**  
Session cookies are `HttpOnly`, `SameSite=Lax`, scoped to the application's base path, and `Secure` when the request is HTTPS (directly, `X-Forwarded-Proto` from a trusted proxy, or `force_https=true`). `Lax` rather than `Strict`: `Strict` drops the cookie on navigations coming from another site, which breaks invitation links opened from an e-mail and the planned OIDC callback. Cross-site POSTs remain blocked by `Lax` and the CSRF tokens. `session.use_strict_mode` rejects session IDs the server didn't issue, and the session ID is regenerated at login (session fixation). Sessions end on logout, after `session_idle_timeout` seconds without a request (default 3600, 1 hour), and `session_max_lifetime` seconds after the login, active or not (default 604800, 1 week): `Session::expiryCause()`, checked on every authenticated request, logs `session_expired` (`cause`: `idle` or `max_lifetime`) and answers like any expired session. Administrators have the same timeouts as everyone (a shorter idle timeout for them was considered and dropped, #16). The session files are in a directory of the application's own (`session_save_path`, default `storage/sessions`, created with mode 0700; Docker `/data/sessions`), with PHP's garbage collection enabled there (`gc_probability`/`gc_divisor` 1/100) and `session.gc_maxlifetime` set to the idle timeout. In PHP's shared directory, the idle timeout wasn't the effective one: Debian's `phpsessionclean` deletes files older than php.ini's `gc_maxlifetime` (24 minutes) every 30 minutes, whatever the application sets at runtime, and another application's garbage collection would apply its own lifetime. An unwritable session directory stops the application (500, "session directory not writable" in the PHP error log). Revocation is immediate: on every request, `index.php` reads the session's account (`Session::revocationCause()`) and ends the session when the account no longer exists, is deleted or locked (`locked_at`), or its `session_version` differs from the one stored at login. Locking or deleting an account, resending its invitation, a reset by `bin/create-admin.php` and a password change (profile or invitation link) increment it; the session that changed its own password is kept. A revoked session is answered like an expired one (a redirect to `login`) and logged as `session_revoked`. Sessions are not bound to the client's IP address or User-Agent: mobile networks, proxies with several egress addresses and IPv6 privacy addresses change the IP legitimately, and a thief who has the cookie can copy the User-Agent. A change of client IP within a session is logged instead (`session_ip_changed`, with `previous_ip`; `ip` is the new one), and the session goes on.

Future improvements may include:
- Device/session management
- Multi-factor authentication
- OIDC enhancements

## Logging & Forensic Integrity

Every event is written to the **events database** (D27), a SQLite file of its own (`events_database_path`, see *Events database*), separate from the application database: the log never shares a resource with the control plane (users, rights, files). The admin page's Logs tab reads it, whatever `log_engine` is. `log_engine` adds a copy: `file`, `syslog`, `stderr`, or `none` for no copy. A forward-only destination (syslog, stderr into a container log) can't be read back, but its events stay viewable in the Logs tab.

The logger is globally available via `App::getLogger()`: a `LogEngineTee` that builds each record once (`LogEntry::build()`) and writes the same record, `ts` included, to the events database, then to the configured engine (`LogEngineFactory::create()`). All controller-level events are expected to log security-sensitive actions. Every record carries `ts` (UTC), `action` (the event), `username`, the client `ip` (and `peer_ip`, see *Client IP resolution*), plus the event's own fields. Events logged today:

- Login and logout: `login_password_success`, `login_password_failure`, `login_token_success` (after the invitation form, once logged in), `login_token_failure` (with `cause`: `unknown`, `used`, `wrong`, `expired`, and `step`: `link` or `password`), `password_changed` (with `via`: `invitation` or `profile`), `logout`, `session_revoked` (with `cause`: `deleted`, `locked`, `missing`, or `revoked` for a password change or a resent invitation), `session_expired` (with `cause`: `idle` or `max_lifetime`; an idle session already removed by PHP's garbage collection ends without an event, the user simply finds the login page), `session_ip_changed` (with `previous_ip`)
- Uploads: `upload_start`, `upload_verifying`, `upload_completed` (with size and SHA-256), `upload_name_conflict`, `upload_duplicate_refused`, `upload_denied`, `upload_chunk_rejected`, `upload_failed`, `upload_aborted`, `upload_expired`, `upload_verify_restarted`
- Downloads: `download_success`, `download_partial`, `download_failure`
- Folders and files: `directory_created`, `directory_deleted`, `file_deleted`, `file_renamed` (`file_id`, `from` and `to` paths, `size`, `sha256`: the content is unchanged), `folder_renamed` (`directory_id`, `from`, `to`, the number of `subfolders` whose path changed), `rename_denied` (`type`, `id`, `cause`: no such item, or no write right on its folder), `filemanager_failure` (a missing folder requested)
- Refresh from storage: `directory_refresh_started` (with `refresh_id`, `directory` and the number of `files` to hash), `directory_imported` (a new subfolder recorded), `file_imported` (with `file`, `file_id`, `size`, `sha256` and the file's `mtime` in storage, UTC), `integrity_mismatch` (with `recorded_size`, `stored_size`, `recorded_sha256`, `stored_sha256`), `integrity_missing` (the record's `size` and `sha256`), `directory_refreshed` (the counts: `new_folders`, `imported`, `verified`, `mismatches`, `missing`, `skipped`, `failed`, `remaining`), `directory_refresh_canceled` (same counts), `directory_refresh_expired`, `directory_refresh_step_restarted`, `directory_refresh_denied` (not an administrator)
- Administration: `user_invited` (with `directory`, the `group` joined or null, `delivery`: `mail` or `manual`, and `invitation_sent`; the link itself is never logged), `invitation_resent` (same fields, without group), `user_locked`, `user_unlocked`, `user_deleted` (with `target_user_id` and `pseudonym`), `group_created` (with `default_group_of` when an invitation created it), `group_deleted` (with the number of `members` it had), `group_user_added`, `group_user_removed`, `access_granted`, `access_revoked`, `admin_access_denied`
- Mail failures: `mail_send_failure`
- Configuration: `config_warning` (with `setting` and `message`; see *Client IP resolution*)
- Logs tab: `logs_viewed` (each new search, with its `filters`; "Load more" is not logged), `logs_exported` (`filters`, `format`, `file`, `events`, `sha256` of the file, `last_event_id`, `complete`), `events_archived` (`archive_id`, `before_ts`, `max_event_id`, `file`, `events`, `sha256`, `complete`), `events_purged` (same fields, without `complete`), `events_imported` (`bin/import-log.php`, per file: `file`, `sha256`, and the counts `imported`, `already_stored`, `too_recent`, `purged_period`, `malformed`)
- Logging itself: `logging_failure` (`failed_from`, `failed_until`, `failures` per destination, `message`: "logging failed between … and … - application was stopped"), written when the application resumes after a halt (see *No logging, no service*)
- Command line: `cli_admin_created` (`bin/create-admin.php`, also when it resets a password), `cli_test_mail_sent` and `cli_deleted_user_lookup` (with `address_hash` and the account ids found), `events_archived`, `events_purged` and `events_imported`, with `username` set to `cli:` + the system user; `upload_expired` and `directory_refresh_expired` from `bin/expire-uploads.php`, without a username

**No logging, no service (D29).** A forensic application that can't record what it does stops doing anything:
- At startup, `healthCheck()` requires the events database to be writable (and the log file's directory with `log_engine=file`); otherwise every request gets a 500.
- At runtime, every destination reports a failed write instead of ignoring it (an insert that throws, including a lock not obtained within `busy_timeout`, 5 s; `file_put_contents`, `syslog()` or the write to stderr returning short). A forward-only engine can only report a local failure.
- The tee still writes the event to the other destinations, then sets a **halt flag** in APCu (memory: it works with a full disk; keyed by installation), writes the failure to PHP's error log and throws `LogWriteException`. The request ends with a 503 (JSON `code: logging_failed` for POST requests, a page otherwise; nothing if the answer had started, e.g. a download). The action during which the event was logged may already be done (an upload concluded) and stays done: a log call inside a transaction (`ChunkedUpload`, `RefreshJobs`) commits what was done before letting the exception through, since a rollback would leave events of changes undone. `UploadController` and `RefreshController` let it through to `index.php`'s exception handler.
- While the flag is set, every request gets the 503 page before doing anything.
- **Recovery is automatic.** At most once per 30 s (`LogHalt::PROBE_INTERVAL`, one request through `apcu_add`), a request writes the `logging_failure` event to every destination that doesn't hold it yet. When all of them took it, the flag is cleared and the application serves again; the gap is on the record with its start and end. To recover, fix the destination (disk space, permissions, a lock held by a `sqlite3` shell, syslog); the PHP error log says which one failed and why. Restarting PHP clears the flag too, and with it the start time (still in the PHP error log).
- Without APCu (the `bin/` scripts, in the CLI) the halt can't outlast the run whose write failed. The web application doesn't run without APCu.
- CLI scripts have no halt: a failed write ends the script with a message on stderr and exit code 1 (`LogWriteException::handleInCli()`).

**Forensic constraints:**
- Every uploaded file is assembled in staging, then SHA-256-hashed by reading it back from storage, and only then moved to its final path and recorded with its real size. A partial or unverified file is never visible
- A name that already exists in a folder is refused, never overwritten or appended to; the same content twice in one folder is refused too (unique indexes back both rules)
- File metadata (upload timestamp, user, and origin IP) is persisted in the database
- A file placed in storage outside the application enters the records only through "Refresh from storage", by an administrator: hashed from storage like an upload, recorded with `origin = 'import'`, the administrator as uploader and their address as origin, and its storage mtime in the log. A stored file that no longer matches its record is flagged (`files.integrity`) and logged; the record, which is the evidence, is never changed
- File deletion is logged
- All access control decisions are enforced via middleware (see `AccessMiddleware`)

Developers must **never bypass or disable** these integrity mechanisms. When adding new features, log every user-facing change or file operation.

## Authorization logic

Access rights : 
- a user is in one or more groups
- a group has access to one or more directories, with three rights per directory (`access_rights`): `can_view` (list and download every file), `can_write` (upload, create, rename and delete folders and files) and `can_upload` (upload, and list only one's own uploads). Admins manage them from the file manager's access-rights dialog
- rights are set on each folder and are not inherited by its subfolders. A subfolder appears in its parent's listing only to users who have a right on it
- a group flagged `is_admin = 1` makes its members administrators: full access to every directory, plus the admin interface
- `AccessMiddleware::isAdmin()` is the single admin check. It queries the database on every request (no session cache), so revoking admin membership takes effect immediately
- every action of `AdminController` is admin-only (one guard at the top of `handle()`). File operations open to regular users (`createFolder`, `deleteItem`, `rename`) live in `FileManagerController` (`POST /`, field `a`) and check the caller's rights on the target directory
- safeguards: the admin group can't be deleted, an admin can't lock or delete their own account, and the last active administrator can't be removed, locked or deleted
- **default groups** (D15, D20, D21): every subfolder can have one, named `<folder path> contributors` (the full path: two `evidence` folders under different cases get two groups). `AccessMiddleware::defaultGroupFor()` finds the group of that name, or `… contributors (n)`, that has an `access_rights` row on the folder. A group of that name with rights only elsewhere (left by a rename, or by a deleted folder of the same path) is never reused; a new group then takes the next free number. A group created this way gets upload access only (contributors see only their own uploads). Default groups are otherwise ordinary groups: an administrator may change their rights or delete them (a deleted one is created again at the next invitation into the folder). Invitations never set or change a group's rights

### 1. **User Management**
| Feature | Description | Location |
|--------|-------------|-------------|
| 🧑 View Users | List all users, their groups and their status: Active (has a password), Invited (no password yet; the tooltip says whether the link is pending or expired), Locked | Admin UI, Users tab |
| ➕ Create User | Not available: a contributor is created by inviting them. Administrators are created with `bin/create-admin.php` | — |
| ✉️ Invite User | Into the current folder, never the root (D14; the Invite link is hidden there). Creates the account if needed and, unless "Add to the default group of this folder" is unticked, adds it to that folder's default group (created with upload access if missing). Always delivers a link to a page where the invitee sets their password (single use, one hour): by e-mail, or with `mail_engine=none` shown once in the dialog, with a Copy button, to pass on through another channel | File Manager UI (admins), Invite dialog |
| 🔁 Resend Invitation | After a confirmation: a new link, to `/` (group memberships lead the user to their folders); never touches groups or rights. The previous link stops working and the account's open sessions end at once. The link sets a new password, so this is also how an administrator resets a forgotten one. With `mail_engine=none` the link is shown once in a dialog with a Copy button. Refused for locked accounts | Admin UI, Users tab |
| 👥 Group memberships | Add or remove a user's groups | Admin UI, Users tab |
| 🔒 Lock User | Removes the password and any pending invitation, ends open sessions, and records `locked_at`: the account can't be re-invited until unlocked | Admin UI, Users tab |
| 🔓 Unlock User | Clears the lock without issuing credentials; resend the invitation for the owner to set a password | Admin UI, Users tab |
| 🗑️ Delete User | Disables the account for good and replaces its address by a pseudonym (see *Deleted accounts*). Its files, folders and log entries are kept | Admin UI, Users tab |

Changing a user's name or e-mail address isn't available. There is no invitation form in the admin page (D22): invitations are made from the folder they are for.

#### Deleted accounts

Accounts are never removed from `users`, so that `files.uploaded_by` and `directories.created_by` keep pointing to an account (chain of custody). Deleting a user, in one transaction:
- replaces `username` and `email` by the pseudonym `deleted:<SHA-256 of the lowercased address>:<user id>` (the id lets one address be deleted more than once; both columns are `UNIQUE`);
- sets `password = '!'`, clears any invitation token, records `deleted_at` (UTC) and `deleted_by`;
- removes the account's group memberships.

Listings show the uploader of its files as `deleted user #<id>` (and `unknown user #<id>` for an id without a `users` row; migration v4 gives such ids a deleted account). A deleted account is hidden from the Users tab, refused by the login (password and token), by the admin actions on users and by `bin/create-admin.php`. Inviting the same address again creates a new account, with a new id.

The pseudonym is pseudonymous, not anonymous: anyone who has the address can compute its hash and find the account. That is the purpose: an investigation about an address can still find what the account did, with `bin/find-deleted-user.php`. Under the GDPR it remains personal data. The application log keeps clear addresses in the events logged before the deletion, for the log's own retention period.

#### Invitation workflow

1. **Invite** (D14, D15, D21). An administrator opens a subfolder, clicks Invite, enters the address and leaves "Add to the default group of this folder" ticked (or unticks it to grant access later by hand). The account is created if needed; with the box ticked it joins `<folder path> contributors`, created with upload-only access if missing. No group's rights are changed. Logged: `user_invited`, and `group_created` for a new default group.
2. **Delivery.** `deliverInvitation()` issues a token (128 random bits, one hour, D23) and either mails the link (`InvitationView`: subject "Invitation to contribute to <folder> on <host>", the account, the folder, the expiry time in UTC, the 12-character rule, a warning not to forward it) or, with `mail_engine=none`, returns it to the administrator, who copies it from the dialog and passes it on through another channel. The link is shown once and never logged. If the mail fails, the account and its membership stay; the administrator is told what was done and to resend once the mail configuration works (`bin/send-test-mail.php`).
3. **First login** (D24). The link opens a form asking for a password, twice; nothing happens until it is submitted (mail scanners following links neither log in nor use the token). The form sets the password, uses up the token, ends any older session of the account and logs the invitee in, in the invitation folder. An expired, used or replaced link shows one message: ask for a new invitation.
4. **Afterwards.** The contributor logs in with address and password. Group memberships decide what they see: an upload-only contributor sees only their own uploads. Administrators widen or narrow a default group's rights in the folder's Access Rights dialog.
5. **Resend** (D22). From the Users tab, after a confirmation: a new link to `/` (memberships lead to the folders), the previous link stops working and the account's open sessions end. Since the link sets a new password, this is also the password reset for a contributor who forgot theirs. Mailed with the subject "Your new DFIR FM link for <host>", or shown once in a dialog with `mail_engine=none`.
6. **Lock, unlock, delete.** Lock removes the password and any pending link and ends sessions; a locked account can't be re-invited until an administrator unlocks it (no credentials are issued: resend afterwards). Delete is final (see *Deleted accounts*); the address can be invited again as a new account.

Installations upgraded from invitations by e-mail domain (groups `<domain> users`, DFM-01) keep those groups and their rights: `bin/report-domain-groups.php` lists them for review.

---

### 2. **User Self-Service**
| Feature | Description | Location |
|--------|-------------|-------------|
| 🔑 Change Password | New password (at least 12 characters), typed once; the current one isn't asked for (D24). Other sessions of the account end | Profile dialog (File Manager and Admin UI) |

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

### 5. **Audit & Logs (#23)**
| Feature | Description | Location |
|--------|-------------|----------|
| 📜 Browse events | Newest first, 100 at a time with "Load more" (keyset paging on the id: events logged meanwhile never shift a page). Filters: from (inclusive) and before (exclusive), UTC; action (from the actions present); username (exact); IP (client or relaying proxy); text anywhere in the record (a file name, a SHA-256; case-insensitive). A row opens the full record, as stored, with Copy | Admin UI, Logs tab |
| ⬇️ Export | The events of the search shown, all pages, oldest first: JSONL (the records as stored, as in the log file) or CSV (`ts,action,username,ip,peer_ip,record`, RFC 4180). Streamed by a form POST; logged with the SHA-256 of the file | Admin UI, Logs tab |
| 🗄️ Archive and purge | See *Archive and purge* | Admin UI, Logs tab; `bin/archive-events.php` |

The search filters are checked by `EventLog::filters()`: a value sent as an array, a time that can't be read or a bad page parameter answers 400. A text search also finds the `logs_viewed` events of earlier searches for that text (their filters are in the record).

#### Archive and purge

Events are kept forever unless archived, then purged (D28):
1. **Archive** (`archivePreview`, then `archiveEvents {before}`, a form POST). `EventLog::startArchive()` fixes the last event id before the cutoff and records the archive, not yet complete; the file `dfir-fm-archive-<id>_before_<cutoff>.jsonl` is streamed, oldest first; once the last block is sent, `finishArchive()` stores the count and the SHA-256 of the file. An archive whose client went away is removed: only a file the administrator could have received completely can be purged. Logged as `events_archived`.
2. **Keep the file** in cold storage and check it against the SHA-256 shown.
3. **Purge** (`purgeArchive {id}`), after confirming that the file is kept. In one `BEGIN IMMEDIATE` transaction, the archive's events are exported again into a hash (same query, same order, same bytes): the purge goes ahead only if the count and the SHA-256 are those of the archive, then deletes exactly those events (`id <= max_event_id AND ts < before_ts`) and records `purged_at`/`purged_by`. An event altered or deleted since, or an overlapping archive already purged, refuses it (409: make a new archive). Each archive is purged once. Logged as `events_purged`, after the commit (the logger's own connection would wait for this one's lock).

Events stored later with an older time (an import) have higher ids and are never part of an earlier archive. The SQLite file doesn't shrink after a purge: its free pages are reused.

#### Importing older log files

`bin/import-log.php <file>...` brings into the events database the events of an installation upgraded from a version without one (`log_engine=file`, `log_format=jsonl`; rotated and `.gz` files accepted, in any order: files are sorted by their first event). Each line is stored exactly as read. Imported: the events older than the store's `created_at` (later ones were written live), not older than the cutoff of a purged archive (a purged period stays closed), and not already stored (same `ts` and same record), so a second run or overlapping files import nothing. A line that isn't a JSON object with a `ts` as the application writes it and an `action` is counted as malformed. A CSV log (`log_format=csv`: no field names, fields differing per action) is refused before anything is imported. Writes go by transactions of 500 rows, so live requests never wait long for the lock. Each file is logged as `events_imported`.


## Database Bootstrapping

Database initialization is handled programmatically in `src/Core/Database.php`.

`createSchema()` creates the current schema on a fresh database. Upgrades of existing databases are handled by `Database::migrate()`, versioned with SQLite's `PRAGMA user_version`. Each step must be idempotent, because fresh databases already have the current schema:
- **v1:** adds `groups.is_admin` and flags group `id = 1`, the group the former hardcoded admin check trusted. It also locks the formerly seeded `admin` account if it still has the default password `admin`; the lock is reported in the PHP error log. Create a new administrator with `php bin/create-admin.php <username>`.
- **v2:** replaces the v1 `uploads` table (in-progress v1 uploads are discarded; their partial files, left at their final path by the v1 code, are listed in the PHP error log) with `uploads` and `upload_chunks`, and adds the unique indexes `files(directory_id, name)` and `files(directory_id, sha256)`. The v1 upload code could create two rows for one name; the migration never drops such evidence records. If it finds any, it stops, lists them in the PHP error log, and the application refuses to run until an operator resolves them. `bin/check-integrity.php` lists them too, along with stored files that no longer match their hash.

- **v3:** adds `users.deleted_at` and `users.deleted_by` (soft deletion of accounts). Existing accounts stay active.
- **v4:** repairs the rows the foreign keys would refuse. Ids of accounts deleted before v3, still referenced by files, folders or uploads, get a deleted account named `deleted:unknown:<id>`. Memberships of missing or deleted accounts and of missing groups, access rights of missing groups or folders, uploads into missing folders and chunks of missing uploads are deleted. Files and folders whose folder is missing are kept (evidence records) and listed as "to review". Every change is reported in the PHP error log.

- **v5:** adds `users.session_version` (immediate revocation of sessions). Sessions opened before v5 match the default 0 and stay valid.
- **v6:** adds `users.locked_at` / `locked_by`. A lock used to be recorded only as `password = '!'` with `token_expiry = 0`, which can't be told from an invitee whose link expired unused; those accounts get `locked_at` (the migration time, UTC) and lose any leftover token.
- **v7:** adds `files.origin` (`upload` for every existing file), `files.integrity` and `files.checked_at` (refresh from storage). The `refresh_jobs` and `refresh_job_files` tables come from `createSchema()`.

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
    session_version INTEGER NOT NULL DEFAULT 0, -- incremented to end the account's sessions
    locked_at DATETIME,             -- UTC; set by Lock, cleared by Unlock
    locked_by INTEGER
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
    origin TEXT NOT NULL DEFAULT 'upload',  -- or 'import': found in storage by a refresh
    integrity TEXT CHECK (integrity IN ('mismatch', 'missing')), -- the last refresh's finding; NULL: as recorded
    checked_at DATETIME,                    -- UTC, last re-hash or check by a refresh
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
CREATE TABLE refresh_jobs (            -- "Refresh from storage", kept once ended for the audit trail
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,           -- the administrator; only they can drive the job
    directory_id INTEGER NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('in_progress', 'completed', 'canceled', 'expired')),
    new_folders INTEGER NOT NULL DEFAULT 0, -- counts from the comparison
    missing INTEGER NOT NULL DEFAULT 0,
    skipped INTEGER NOT NULL DEFAULT 0,
    current_file TEXT,                  -- being hashed
    hashed_bytes INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_update DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
);
CREATE TABLE refresh_job_files (       -- the files of a job, in hashing order, with their result
    job_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    name TEXT NOT NULL,
    file_id INTEGER,                    -- the record of an existing file (no foreign key); NULL: new
    size INTEGER NOT NULL,              -- as listed in storage
    mtime INTEGER,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'hashing', 'imported', 'verified', 'mismatch', 'skipped', 'failed')),
    sha256 TEXT,
    reason TEXT,
    PRIMARY KEY (job_id, position),
    FOREIGN KEY (job_id) REFERENCES refresh_jobs(id) ON DELETE CASCADE
);
CREATE INDEX refresh_jobs_active ON refresh_jobs(status, directory_id);
```

## Events database

`Core\EventStore::open()` opens the events database (`events_database_path`, default `storage/events/events.sqlite`; Docker `/data/logs/events.sqlite`), creating its directory (mode 0750) and schema, with `journal_mode=WAL` (the Logs tab reads while requests write) and `busy_timeout` 5 s. It is never reached through `App::getDb()`. Schema, versioned with its own `PRAGMA user_version`:
- **v1:** `events (id, ts, action, username, ip, peer_ip, record)`, `record` being the JSON record exactly as the JSON engines write it (`LogEntry::encode()`: unescaped slashes, invalid UTF-8 replaced by U+FFFD rather than losing the event), with indexes on `ts`, `(action, ts)`, `(username, ts)` and `(ip, ts)`; `archives (id, before_ts, max_event_id, events, sha256, created_at, created_by, purged_at, purged_by)`, `sha256` empty while an archive is being sent.
- **v2:** `store (key, value)` with `created_at`, when the store started receiving events (a v1 store takes its oldest event): the bound of `bin/import-log.php`.

Inside the storage it is a reserved path, with its directory (see *Storage engine*). Back it up like the application database: it is the chain-of-custody record.

## Upload protocol

Dropzone 6's chunked upload, with a server that makes it safe for evidence (`Controllers\UploadController` for HTTP, `Services\ChunkedUpload` for the logic). One endpoint, `POST /upload`, `multipart/form-data`, with the CSRF token in `X-CSRF-TOKEN`. The field `a` selects the action. Every response is JSON with `Cache-Control: no-store`.

| `a` | Fields | Success |
|---|---|---|
| `checkRights` | `cwd`, `name`, `size` (name and size optional) | `200 {"status":"success",…}`. Refusals as below, before any byte is transferred |
| *(none)*: a chunk | `file` (the bytes, with the file name), `dzuuid`, `dzchunkindex`, `dztotalchunkcount`, `dztotalfilesize`, `dzchunksize`, `dzchunkbyteoffset`, `cwd` | `200 {"status":"progress","received":n,"chunk_count":N}` |
| `complete` | `dzuuid` | `200 {"status":"completed","file":{id,name,size,sha256,uploaded_at,uploaded_by,uploaded_from},…}`, or `202 {"status":"verifying",…}` while another request verifies it. Idempotent |
| `status` | `dzuuid` | `200 {"status","name","size","verified_bytes","queued"?,"message"?,"file"?}`; starts a verification that waits for a free slot |
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
| 507 | `InsufficientStorage` (the file doesn't fit in the storage's free space; see below) |

As on every route, an expired session gets a redirect to the login page and a refused CSRF token a `403` with `code: csrf_invalid` (see *CSRF Protection*).

**Server behaviour:**
- An upload is keyed by (user, `dzuuid`): another user's uuid is another upload. Its first chunk (any index) creates it, after the rights, size and name checks; a new upload of the same name in the same folder by the same user replaces their unfinished one.
- The geometry (size, chunk size, chunk count, name, folder) is fixed by the first chunk and checked on every chunk: every chunk but the last has exactly `dzchunksize` bytes, at offset `index × chunk size`. An empty file is one empty chunk.
- Each chunk is written at its offset into the staging element (`.uploads/<server key>` with the local engine) and recorded in `upload_chunks`, so a chunk sent twice or out of order is harmless. Rights are re-checked on every request.
- `complete` checks that every chunk is present and the staged size, then hashes the staged file read back from storage (progress in `verified_bytes`), re-checks the rights, the name and duplicate content, then inserts `files` with the real size and moves the file into place in one transaction. A verification without progress for 120 s (the request running it died) is restarted by the next `complete` or `status`.
- Each verification holds a PHP worker until the hash is done, so a user runs at most `upload_max_verifications` at once (default 1; a verification without progress for 120 s doesn't count). A `complete` over the cap leaves the upload `in_progress` and answers `202` with `status: verifying, queued: true`; the client's `status` polls (`Waiting for your other uploads to be verified…`) start it once a slot is free, and keep it from expiring meanwhile. A stalled verification restarted while the cap is full goes back to the queue the same way.
- States: `in_progress → verifying → completed`, or `aborted`, `failed`, `expired`. Terminal rows are kept for the audit trail (until their folder is deleted; the upload events stay in the log); their chunk rows and staging files are deleted. Uploads without activity for `upload_stale_after` seconds expire (when an upload starts, or with `bin/expire-uploads.php`).

**Client** (`filemanager.js`): `forceChunking`, 2 MiB chunks, one at a time, `retryChunks` ×3. Dropzone's `accept` hook sends `checkRights`; `chunksUploaded` sends `complete` and polls `status` every second, showing "Verifying stored file… n%". If the `complete` response is lost (e.g. a proxy timeout on a long verification), the polls deliver the outcome. Dropzone's `canceled` event sends `abort`. A refusal's message stays visible under the file's preview.

## Rename

`POST /` with the CSRF token, `a=rename`, `type` (`f` a file, `d` a folder), `id` and `name` (#21, D25). The logic is in `Services\ItemRename`. Answer: `{"success":true,"item":{type, id, name, path, renamed}}` (`renamed` false: the name was already that one, nothing done nor logged), or a 4xx/5xx status and `{"success":false,"code":…,"message":…}`:

| Status | Codes |
|---|---|
| 400 | `InvalidArgument` (a bad type or id, the root folder), `InvalidName` |
| 404 | `NotFound` (no such item, or no write right on its folder: the same answer, #24; logged as `rename_denied`) |
| 409 | `NameConflict` (a file or a folder of that name in the folder, in the records or only in storage: never replaced, D2), `UploadInProgress`, `RefreshRunning` |
| 500 | `StorageError` (the storage refused, or a file missing from storage), `InternalError` |

- **Rights:** `write` on the item's folder, or admin, as for delete. The pencil is shown on those rows only.
- **Names:** a file follows the upload rules (`NameRules::validFileName()`, `.uploads` reserved in the root folder), a folder the "New folder" rules (`NameRules::validFolderName()`: no leading dot); reserved paths are refused (`isReserved()`).
- **Refused while busy:** for a folder, while an upload into it or a subfolder is `in_progress` or `verifying` (uploads past `upload_stale_after` are expired first); for any item, while a refresh hashes files in the folder concerned (the renamed folder and its subfolders, or the file's folder). A refresh left for 120 s expires instead.
- **Order:** in one `BEGIN IMMEDIATE` transaction, the checks, then the storage element is renamed (`rename()`, which never replaces), then the records. If the records can't be updated, or the commit fails, the storage rename is undone. A folder with nothing stored under it has no storage element: only its records change. The event is logged after the commit.
- **Folders:** the folder's `name` and `path` and the `path` of every subfolder (matched on the old path followed by `/`, so `/case-2` is not part of `/case`) change; ids don't. Access rights, files, uploads and refresh jobs point to directory ids, so they follow. The folder's default group keeps its old name (D20): the next invitation into the renamed folder creates a group under the new name.
- **Content:** never touched; `sha256` and `size` stay as recorded. The events keep the old and new paths for the chain of custody.

**Client** (`filemanager.js`): the row's pencil opens a dialog built with `h()` on first use, the name selected without its extension for a file. Enter or Rename sends it; a refusal is shown in the dialog, which stays open; on success the row's name, link and `data-name` are updated in place and the listing re-sorted.

## Refresh from storage

For files placed in a folder's storage outside the application (large images copied with `rsync`, a disk from an air-gapped host, a restore from backup), and to check a folder's stored files against their records. Administrators only, on a storage engine with named files; the direct content of the current folder only (a subfolder is refreshed by opening it). `Controllers\RefreshController` for HTTP, `Services\RefreshJobs` for the jobs, `Services\DirectoryRefresh` for the comparison (shared with `bin/check-integrity.php`).

`POST /` with the CSRF token and the field `a`. Every response is JSON with `Cache-Control: no-store`: `{"success":true,…}`, or a 4xx/5xx status and `{"success":false,"code":…,"message":…}`.

| `a` | Fields | Success |
|---|---|---|
| `refresh` | `cwd` | The job (below) and `comparison`: `new_folders` (names, recorded at once), `new_files` and `existing_files` (`name`, `size`), `missing_files` (flagged at once), `skipped` (`name`, `type`, `reason`) |
| `refreshStep` | `refresh_id` | Hashes the next file inside the request (no time limit; it goes on if the client leaves). The job, with `file`: this step's result (`position`, `name`, `kind`: `new` or `existing`, `status`, `size`, `sha256`, `reason`), or null, and `busy`: true while another request hashes a file of the job |
| `refreshStatus` | `refresh_id`, `after` | The job, with `files`: the results of the files done after position `after` |
| `refreshCancel` | `refresh_id` | The job, `canceled`; the step in progress stops, the remaining files are left as they are |

The job: `refresh_id`, `status` (`in_progress`, `completed`, `canceled`, `expired`), `directory`, `total` (files to hash), `counts` (`new_folders`, `imported`, `verified`, `mismatches`, `missing`, `skipped`, `failed`, `remaining`) and `current` (`name`, `size`, `hashed_bytes`, or null).

| Status | Codes |
|---|---|
| 400 | `InvalidArgument` (a `cwd` that isn't a path, a missing or invalid `refresh_id`) |
| 403 | `Forbidden` (not an administrator; logged as `directory_refresh_denied`) |
| 404 | `NoSuchFolder`, `NoSuchRefresh` (unknown, or another administrator's) |
| 409 | `RefreshRunning` (with its `refresh_id`), `NotSupported` (an engine without named files) |
| 500 | `StorageError`, `InternalError` |

**Server behaviour:**
- The comparison lists the folder in storage (`listDirectory()`) and reads its `files` and `directories` rows. Ignored and reported, with a reason: symbolic links, special files, names refused by the name rules (a subfolder must pass both the folder and the file rules: `NameRules`), and an entry whose name a record of the other kind uses (a file where a folder is recorded, or the reverse). The staging folder and the reserved paths never appear.
- New subfolders are recorded at once (`created_by` the administrator, `created_from` their address, no access rights of their own), missing files flagged (`integrity = 'missing'`, record kept), and the job lists the files to hash: new ones first, then existing ones.
- Each step hashes one file read through `readStream()`, with its progress in the job row (every 16 MiB or second), then, in one `BEGIN IMMEDIATE` transaction:
  - a new file is recorded with its hashed size and SHA-256, `origin = 'import'`, the administrator as uploader, unless its size changed since the listing (still being copied), its name was taken meanwhile, or the folder already holds the same content (D2, D6): then skipped, with the reason;
  - an existing file is compared with its record: equal, `verified` (and any earlier flag cleared); different, `mismatch` (`integrity = 'mismatch'`), the record unchanged. `checked_at` is set either way.
- One refresh per folder at a time. A job without activity for 120 s was left (page closed) and is replaced by a new refresh, or expires when a rename needs its folder (`expired`); while it runs, a rename in its folder is refused; a step without progress for 120 s died and is restarted by the next `refreshStep`. Jobs without activity for `upload_stale_after` seconds expire (when a refresh starts, or with `bin/expire-uploads.php`).

**Client** (`filemanager.js`, `RefreshModal`): the "Refresh" toolbar item opens a dialog that explains the operation and starts it on demand. It shows the comparison, then calls `refreshStep` until the job ends, polling `refreshStatus` every second meanwhile for the progress bar; a step cut by a proxy timeout (Cloudflare's 100 s, see *Behind Cloudflare Tunnel*) goes on on the server and the polls deliver its result. While a job runs the dialog has Stop (`refreshCancel`) and no Close (static backdrop, no Escape), and leaving the page asks for confirmation. Closing it reloads the listing, where `FileManagerView::fileFlags()` marks imported files, mismatches (red row) and missing files (yellow row), with the last check's date in the tooltip.

## Backends

The application uses an Interface/Factory pattern to dynamically select backends.

### Mail engine

Mail is only used for invitation links. `MailEngineFactory::create()` reads the flat `.env` keys (see `env.sample`) and throws `InvalidArgumentException` on a missing or invalid setting:
- `mail` (default): PHPMailer with `isMail()`, i.e. the local sendmail binary. PHPMailer encodes headers and rejects header injection; `default_mail_from` is also the envelope sender (`-f`).
- `smtp`: PHPMailer over SMTP. `smtp_secure=none|starttls|smtps`. Not set means `none` (plain SMTP, no opportunistic STARTTLS), except on port 465, which gets `smtps`. `starttls` is required, not opportunistic: the send fails if the server doesn't offer it. Authentication only when `smtp_user` is set. `SMTPDebug` stays at 0: its output would end up in the HTTP response, and from level 2 it includes the `AUTH` exchange.
- `sendgrid`: SendGrid Web API v3. An HTTP status ≥ 400 counts as a failure, with SendGrid's error body in the reason.
- `none`: no mail, for hosts that cannot send any (air-gapped, no relay). `default_mail_from` is not required. `MailEngineFactory::isManual()` tells callers that the link is to be handed to the administrator instead (the invite dialog shows it with a Copy button; the answer carries `Cache-Control: no-store`). `MailEngineNone::send()` always returns `false`, so a caller that skips the check fails visibly. `bin/send-test-mail.php` says that mail is disabled and exits 0.

All engines send UTF-8 HTML with a plain-text alternative (`MailText::fromHtml()`), from `default_mail_from` / `default_mail_from_name`. `send()` returns `false` on failure and `lastError()` gives the reason; it doesn't throw. The caller logs failures as `mail_send_failure` (recipient, engine, error). Invitations go through `AdminController::deliverInvitation()`, which issues the token and either mails the link or returns it. An invitation whose e-mail fails still creates the contributor (and its membership); the admin gets `success: false` with a message saying what was done and to resend once `bin/send-test-mail.php` works, while the details (SMTP replies, configuration problems) go to the log only.

### Storage engine

Storage engine can be :
- Local for the local filesystem (`base_storage_directory` must be an existing directory)
- Amazon S3 and Azure Blob storage: planned, not implemented. `storage_engine=amazon_s3|azure_blob` is refused with an explicit error.

All access to the storage goes through the engine (`StorageEngineFactory::create()`); no other class touches the storage's file system. Code that needs a folder tree asks the engine, never the `storage_engine` setting:
- `supportsNamedFiles()`: whether stored elements have names in a folder tree that can be browsed (local: yes; a blob store such as S3 has keys only). The refresh from storage and the orphan search of `bin/check-integrity.php` need it.
- `rename($from, $to)`: renames a file, or a directory with its content, never replacing an existing element (false if the target exists, the source doesn't, or either path is refused). The local engine checks the target, then calls `rename()`; the check runs under the application database's write lock, so only a process outside the application could create the target in between. The staging area and the root can't be renamed.
- `listDirectory($path)`: the direct entries of one folder, sorted by name, each with `name`, `type` (`file`, `directory`, `link`, `other`), `size` (files) and `mtime`. Symbolic links are reported, never followed (`lstat()`); the staging folder is left out. A folder with nothing stored lists as empty: folders are created in storage with their first file and pruned with their last. An engine without named files throws `LogicException`.
- **Reserved paths:** the factory passes the database file, the events database (D27), their directories, and the log file (with `log_engine=file`). Those inside the storage are refused by every engine method (`Reserved path`), left out of listings, and refused to uploads and "New folder" through `isReserved()`; `reservedPaths()` lists them for warnings. A reserved file also covers its companions (SQLite's `-wal`, `-shm`, `-journal`; rotated logs `.1`, `.2.gz`), a reserved directory its content. A configuration that keeps them apart, as the README asks, has none.

Besides reading, uploads need a **staging** area (`stagingCreate`, `stagingWriteAt`, `stagingSize`, `stagingOpen`, `stagingCommit`, `stagingRemove`), keyed by 32 hex digits generated by the application. `stagingWriteAt` writes a chunk at its offset, so a chunk written twice leaves the same content. `stagingCommit` moves the staged element to its final path and must never replace an existing element. The local engine stages in `.uploads/` under its root, on the same filesystem, so the commit is an atomic `rename()`; that name is reserved in the root folder (and folder names can't start with a dot).

### Logging

The events database (`LogEngineEvents`) is always written. `log_engine` chooses a copy (`log_dest` is still accepted as an older name):
- `none`: no copy, the events database only; `log_file_path` and `log_format` are ignored.
- `file` (default): appends to `log_file_path`, one event per line, `log_format=jsonl` (default) or `csv`. The application refuses to serve requests (HTTP 500) if the file's directory isn't writable by the PHP user. The CSV is positional, without a header (null as an empty field, nested values as JSON): it can't be read back, so `bin/import-log.php` refuses it.
- `syslog`: JSON, with the identity `syslog_tag` (default `dfir-fm`).
- `stderr`: JSON lines on the PHP process's stderr; for containers (see "Docker").

Each destination implements `LogDestination` (`name()`, `write(array $entry)`, which throws on any failure, and `healthCheck()`); `LogEngineTee` (the `LogEngineInterface` the application uses) writes to them and handles failures (see *No logging, no service*).

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
| Uploads | `upload_max_size`, `upload_stale_after`, `upload_max_verifications` |
| Security | `trusted_proxies`, `force_https`, `max_login_attempts`, `max_login_attempts_per_user`, `login_lockout_duration` |
| Sessions | `session_idle_timeout`, `session_max_lifetime`, `session_save_path` |
| OIDC (#9, optional) | `oidc_issuer`, `oidc_client_id`, `oidc_client_secret`, `oidc_redirect_uri`, `oidc_email_domain`, `oidc_admin_claim`, `oidc_admin_value` (required, all or none); `oidc_scopes`, `oidc_email_claim`, `oidc_require_email_verified`, `oidc_require_mfa`, `oidc_max_age`, `oidc_display_name`, `oidc_remember_days` |
| Logging | `events_database_path`, `log_engine`, `log_file_path`, `log_format`, `syslog_tag` |
| Docker only (read by Compose, ignored by the application) | `uid`, `gid`, `http_port`, `files_dir`, `log_dir` |

**Request size limits.** Uploads arrive in chunks of 2 MiB (set in `filemanager.js`), each in a `multipart/form-data` request a little larger than that. Every layer must accept such a request:
- PHP: `upload_max_filesize` and `post_max_size`, e.g. `8M` (the Docker image's `docker/php.ini`). PHP's defaults are 2M and 8M: a 2 MiB chunk is over `upload_max_filesize=2M`. A refused chunk gets `413 EntityTooLarge`.
- Apache: `LimitRequestBody`, if set, at least that size.
- A reverse proxy: e.g. nginx's `client_max_body_size`, 1m by default, which is too small.

The largest file is set by `upload_max_size`, not by these limits.

**File size and disk space (D16).** `upload_max_size` is empty by default, on purpose: evidence such as disk images has no natural size limit, so files are limited only by the disk. The resource-exhaustion risk this leaves (DFM-09) is accepted; a deployment that wants a limit sets one. Dropzone's own client-side limit (256 MiB by default) is turned off (`maxFilesize: null`), so the server decides. What the server does check, at the `checkRights` probe and again at an upload's first chunk, is that the declared size fits: `StorageEngineInterface::freeSpace()` (`disk_free_space()` of the storage directory for the local engine; `null` if unknown, and then nothing is checked), minus the bytes still to come for the other uploads in progress (declared size minus the chunks received; the same user's earlier attempt at the same file isn't counted, since a new start replaces it), minus `ChunkedUpload::FREE_SPACE_MARGIN` (64 MiB, kept for the database, logs and the system). A file that doesn't fit gets `507 InsufficientStorage` with the sizes in decimal units, as the upload dialog shows them, and is logged as `upload_denied` with `cause: Insufficient storage`. This is a check at the start, not a reservation: a disk filled by something else later still makes a chunk write fail (`500 StorageError`). Monitor the storage's free space.

## Docker

`docker/docker-compose.yml` runs one container from the released image, `ghcr.io/jfstenuit/dfir-fm:${image_tag:-<release>}`. From a checkout, `docker compose up -d --build` builds that image from the repository root instead, under the same name (`.dockerignore` keeps `.env`, `storage/`, `vendor/` and `.git` out of the build context); the next `docker compose pull` brings back the released one.

**Releases.** `gh release create vX.Y.Z --generate-notes` (or "Draft a new release" on GitHub) publishes the release and its tag; `.github/workflows/release.yml` then runs the CI workflow, builds the image for `linux/amd64` and `linux/arm64`, pushes it to GHCR with the tags `X.Y.Z`, `X.Y`, `X` and `latest` (a prerelease, `vX.Y.Z-rc1`, gets its own tag only), and attaches to the release the install bundle `dfir-fm-docker.tar.gz` (README, LICENSE, `env.sample`, and `docker/docker-compose.yml` with this release as the default `image_tag` and without its `build` section) and the `amd64` image as `dfir-fm-image-X.Y.Z-amd64.tar.gz`. Before tagging, set the default of `image_tag` in `docker/docker-compose.yml` to the new version, so that `main` follows the latest release (the bundle gets the right one either way). GHCR creates the package private: after the first release, make it public (the repository's Packages → dfir-fm → Package settings → Change visibility), or `docker compose pull` asks for a login.

The container:

- **Processes:** PHP-FPM (listening on 127.0.0.1:9000 only) and lighttpd on port **8080**, both started in the foreground by `docker/start.sh`. When either exits, the script stops the other and exits, so the container stops and its restart policy applies. The image's stop signal is `SIGTERM` (the PHP base image's `SIGQUIT` is ignored by bash as PID 1, which made `docker compose stop` wait 10 s for a SIGKILL).
- **Routing:** `docker/lighttpd.conf` mirrors `.htaccess`: the same static allowlist, 404 for `storage/`, `src/`, `bin/`, `docker/`, `tests/`, dotfiles and the rest of `assets/` and `vendor/`, everything else to `index.php`. lighttpd has no default MIME types: the file lists one per served extension, including `.mjs` (browsers refuse a module script with another type).
- **Configuration:** `docker/.env`, created by the administrator from `env.sample` (the same keys as a host install, plus its "Docker" section uncommented). It is not in git (`.gitignore`) and the repository ships no copy of it. It is mounted read-only as the application's `.env`; Compose refuses to start if it is missing, rather than creating a directory in its place. It must be readable by the container's `www-data`: uid/gid 82 (Alpine) by default, not the host's (33 on Debian), so `chgrp 82 docker/.env` with mode `640`; with `uid`/`gid` set (below), their owner. The file is read at each request, but the bind mount follows the file's inode: an editor that replaces the file (`sed -i`, some editors' save) leaves the container on the old one until `docker compose restart`. PHP-FPM passes the environment to PHP (`clear_env = no`), so a `DFIRFM_*` variable added to the compose file overrides a key.
- **Compose reads `docker/.env` too:** it loads the `.env` of the compose file's directory for every command. Five keys, ignored by the application, choose the mounts, the port and the user: `files_dir`, `log_dir`, `http_port` (the host port, 8080 if unset), `uid` and `gid` (see "User"). A volume source that is an absolute path is a bind mount, a plain name is a named volume, so `${files_dir:-dfir-fm-files}` gives the host directory when the key is set and a volume otherwise. Compose expands `$` in unquoted or double-quoted values while reading the file: it only warns, the application still reads the file as written; single quotes avoid the warnings.
- **Paths:** the image sets the paths to its own locations, outside the web root, through `DFIRFM_*` variables, which win over the `.env`'s host paths:

  | Key | In the container | Mounted from |
  |---|---|---|
  | `base_storage_directory` | `/data/files` | `files_dir` if set (a host directory owned by `uid`, 82 by default), otherwise the volume `dfir-fm-files`. Nothing to set with a storage engine that doesn't use local files. |
  | `database_path` | `/data/database/app.sqlite` | always the volume `dfir-fm-database` (back it up) |
  | `events_database_path` | `/data/logs/events.sqlite` | `log_dir` if set (a host directory owned by `uid`, 82 by default), otherwise the volume `dfir-fm-logs`. It holds the events (chain of custody): back it up; never `docker compose down -v`, which removes the volumes |
  | `log_file_path` | `/data/logs/dfir-fm.log` | the same directory as `events_database_path` |
  | `session_save_path` | `/data/sessions` | not mounted: the container's own layer. A restart keeps the sessions; recreating the container (a new image, `docker compose up` after a change to the compose file) logs everyone out |

  Host directories must exist before the first start: Docker would create a missing one owned by root, which the application can't write to (uploads and downloads then fail with a 500, "The storage is unavailable").
- **User:** `uid` and `gid` (default 82, the image's) set the ids of the container's `www-data`, so that a host directory is mounted as it is, owned by its user (`id -u`, `id -g`), with no `chown` or ACL on the host. At start, as root, `start.sh` changes `www-data`'s ids (`usermod`/`groupmod`), then `chown -R`s the Docker volumes under `/data` that aren't owned by them yet, and the session directory (the database and the sessions always; the files and the logs when they are volumes: Compose passes `files_dir` and `log_dir` as `APP_FILES_DIR` and `APP_LOG_DIR`). Host directories are never changed. lighttpd and the PHP-FPM workers then run as `www-data`; the code stays owned by root. 0, or a value that isn't a number, stops the container with a message.
- **Logs:** the events database is in `/data/logs` (`events.sqlite`), whatever `log_engine` is; `log_engine=none` keeps nothing else. With `log_engine=file` (the default), the log file is there too. With `log_engine=stderr` (in the `.env`, or as `DFIRFM_LOG_ENGINE` in the compose file to keep a file on a host install), the JSON events go to the container output through PHP-FPM (`log_limit` raised so that lines aren't cut). `syslog` has no daemon to talk to in the container. lighttpd's errors go to stderr and its access log to stdout. lighttpd opens its logs after dropping to `www-data`, who may not open the container's stdout or stderr by path (`/dev/stdout` fails or is silently lost): the error log is left unset (stderr as inherited) and the access log is a piped logger (`cat`, started before the drop) writing to fd 3, which `start.sh` points at its stdout.
- **Code:** owned by root and read-only for `www-data`, who only writes to `/data`. Dependencies are installed with `composer install --no-dev`. The image adds the `apcu` (pecl) and `gmp` (required by `sendgrid/sendgrid`) extensions.
- **First administrator:** `docker compose exec -u www-data app php bin/create-admin.php you@yourdomain.com`.
- **Workers:** `docker/php-fpm.conf` sets `pm.max_children = 20` (the base image's 5 is used up by a few concurrent downloads). Each download, each upload verification and each request in progress holds a worker for its duration: size it for concurrent downloads + concurrent verifications + a few for pages and status polls. An idle worker uses ~20–40 MB. `request_terminate_timeout` stays 0: verifying a large disk image takes longer than any fixed limit, and a killed verification restarts from zero.
- **HTTP only:** the compose file publishes the port (`http_port`, default 8080) on 127.0.0.1. Put a TLS-terminating reverse proxy in front and set `trusted_proxies` (and `force_https` if needed). A proxy on the host (e.g. `cloudflared` pointed at `http://localhost:7080`) reaches the container through Docker's port publishing, so the application sees the gateway of the Compose network (e.g. `172.21.0.1`), not `127.0.0.1`: list Docker's default range, `172.16.0.0/12`, rather than `127.0.0.1, ::1`. For Cloudflare Tunnel, see the checklist below.

### Behind Cloudflare Tunnel

A checklist for publishing the container through `cloudflared` (from `CODE_AUDIT.md`, section 7). The application handles a proxy correctly, but several defaults are only right for a direct connection.

**Application (`docker/.env`)**
- [ ] **`trusted_proxies`**: the address `cloudflared` connects from, *as the container sees it*, and nothing wider. With `cloudflared` on the host, through the published port: the gateway of the Compose network (`docker network inspect docker_default -f '{{(index .IPAM.Config 0).Gateway}}'`, e.g. `172.21.0.1`; the network is named after the compose file's directory, `docker`, unless `COMPOSE_PROJECT_NAME` says otherwise). With `cloudflared` as a container on the same network: that container's address. `172.16.0.0/12` works too, but then any container on a Docker network could set its own `X-Forwarded-For`. Check: the admin page shows no "Proxy not configured" warning, and the log's `ip` fields hold the clients' addresses, not the gateway's. Left empty, every client appears as the gateway: one person's failed logins lock everyone out, and `uploaded_from` records the gateway.
- [ ] **`force_https=true`**: TLS ends at Cloudflare, `cloudflared` talks plain HTTP to the container. A trusted `cloudflared` sends `X-Forwarded-Proto: https`, which is enough, but `force_https` doesn't depend on it. Check: `curl -sI https://host/login | grep -i set-cookie` shows `secure`, and invitation links start with `https://`.
- [ ] **Disk space (D16)**: there is no upload size limit by default (`upload_max_size` empty). A file larger than the free space is refused before it is sent, but nothing stops the disk from filling up file after file: monitor the free space of the files directory or volume, and set `upload_max_size` if this deployment needs a limit. Abandoned uploads keep their partial data for `upload_stale_after` (one day by default).
- [ ] **PHP-FPM workers**: see *Workers* above. 20 by default; raise `pm.max_children` in `docker/php-fpm.conf` (and rebuild) for more concurrent downloads or verifications.

**Cloudflare (dashboard)**
- [ ] **Cloudflare Access** on at least `/admin`, better on the whole hostname: authentication, MFA and device checks before the login form. Protecting the whole hostname means that invitees must also pass the Access policy (e.g. a policy allowing their e-mail addresses with one-time PINs), or their invitation links are blocked.
- [ ] **Cache rule**: bypass the cache for the whole hostname. The application sends `Cache-Control: no-store` on every page and answer, and `private, no-store` on downloads, but a "Cache Everything" rule or an Edge TTL override would still store authenticated pages and evidence at the edge.
- [ ] **HSTS** at the edge (SSL/TLS → Edge Certificates: HSTS, Always Use HTTPS): the application doesn't send `Strict-Transport-Security` itself, since it can't know whether it is only served over HTTPS.
- [ ] **Rate limiting rules**: on `POST /login` (e.g. 20 requests per minute per IP; the application has its own limits, per IP and per username, which this complements), and on `/upload` with a threshold above what a legitimate upload needs: one request per 2 MiB chunk, so about 6 per second on a 100 Mbit/s uplink (360 per minute), plus one status poll per second per file being verified.
- [ ] **WAF and Bot Fight Mode**: check that they don't challenge the application's `fetch` requests (chunk uploads, status polls): a challenge answers HTML instead of JSON, which the upload dialog reports as an error.
- [ ] **Limits of the plan**: the request body limit (100 MB on Free and Pro) is far above the 2 MiB chunks. The 100-second origin timeout (time to the first byte of the answer) cuts a `complete` request whose verification takes longer (`524`): the client treats this as a cut request and the status polls deliver the outcome, while the verification goes on in the container. A refresh step on a large file is cut the same way and recovered the same way (`refreshStatus`). Downloads send their headers at once, so they aren't affected.

**Host**
- [ ] The container's port published on `127.0.0.1` only (the compose file's default), so that only `cloudflared` reaches it.
- [ ] `docker/.env` mode `640`, readable by the container's `www-data` (see *Configuration*).
- [ ] The database volume backed up; never `docker compose down -v` on a production host.
- [ ] Audit events kept outside the container (`log_dir`, or `log_engine=stderr` with a Docker logging driver pointed at the SIEM), and the first administrator created with `bin/create-admin.php`, its password changed at first login.

## Backend Extensibility

Backends for storage and mail are dynamically chosen based on `.env` variables.

- **Mail backends** must implement `MailEngineInterface`
- **Storage backends** must implement `StorageEngineInterface`
- **Logging backends** must implement `LogDestination` and throw on any failed write (registered in `LogEngineFactory`, which puts them behind the events database)

To add a new backend:
1. Create a class in `src/Backends/` implementing the appropriate interface.
2. Register the backend by modifying the relevant factory logic.
3. Add any required `.env` variables and document them.

Each backend is responsible for parsing its configuration from the `.env` file.

## Known Limitations / To-Do

- Database migrations are minimal (`PRAGMA user_version` steps in `Database::migrate()`), with no down-migrations
- Only the local storage engine exists (Azure Blob and S3: #6, #7)
- Refresh from storage: one folder at a time, not its subfolders; a missing file's record can't be forgotten from the UI; jobs belong to the administrator who started them
- Password changes aren't logged, and the profile dialog doesn't ask for the current password
- No self-service password reset or registration, by design (#1, D30): an administrator resets a forgotten password with "Resend invitation"
- The events database file doesn't shrink after a purge (no `VACUUM`)
- Login rate-limit counters are per server (APCu)

## Contributing

When contributing to DFIR FM:
- Follow the architecture and naming conventions established in the project.
- Document any new `.env` variables or backends.
- Ensure your changes do not compromise forensic integrity (e.g., hashing, traceability).
- All contributions should aim to be auditable and security-aware.

See the main `README.md` for contribution workflow.
