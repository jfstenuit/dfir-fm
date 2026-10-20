# Developer Documentation

This document outlines the technical structure and internal workings of the DFIR File Manager (DFIR FM), providing guidance for contributors and maintainers.

## Bootstrapping & Application Flow

The application uses a single entrypoint: `index.php`. It handles routing manually and initializes all required components (middleware, controller dispatch, view rendering).

Command-line tools live in `bin/` and refuse to run outside the CLI SAPI:
- `php bin/create-admin.php <username>`: creates an administrator (or resets an existing user's password and adds them to the admin group) and prints a random password once. This is how the first administrator is created; no default account exists. It's also the break-glass tool when every administrator is locked out: it needs shell access only, and no identity provider.
- `php bin/send-test-mail.php <recipient>`: sends a test e-mail through the configured mail engine, exactly as invitations are sent. Exit code 0 when the engine accepted the message, 1 with the reason on stderr otherwise. Run it as the web server user (e.g. `sudo -u www-data php bin/send-test-mail.php …`) to also check its permissions.
- `php bin/check-integrity.php`: re-hashes every stored file and compares it with its `files` record (SHA-256 and size). Also reports `files` rows that share a name or a SHA-256 in one directory, and, with the local storage engine, files in storage without a record. Read-only; exit code 0 when everything matches, 1 otherwise.
- `php bin/expire-uploads.php`: expires uploads without activity for `upload_stale_after` seconds and deletes their staging files. The application also does this whenever an upload starts; the script is for a cron job.

Middleware (such as authentication or CSRF protection) must be applied explicitly per route.

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
│   └── expire-uploads.php     # Expire abandoned uploads (cron)
├── .dockerignore              # Build context of the image: only what it needs
├── docker/                    # Container deployment (see "Docker")
│   ├── Dockerfile             # PHP-FPM 8.4 (Alpine) + APCu + lighttpd
│   ├── docker-compose.yml     # Mounts chosen by files_dir and log_dir in .env
│   ├── lighttpd.conf          # Routing equivalent to .htaccess, MIME types
│   ├── php.ini                # Upload sizes for the 2 MiB chunks
│   ├── php-fpm.conf           # Pool: localhost only, environment passed to PHP
│   └── start.sh               # Runs both processes; stops when either exits
├── src/                       # Source code directory
│   ├── Backends/              # Dynamically selected backends
│   │   ├── LogEngine*.php     # Handles logging
│   │   ├── MailEngine*.php    # Handles mail sending
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
│   │   └── ChunkedUpload.php  # Upload state machine, verification, commit
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
├── .env.sample                # Configuration template, copy to .env
├── .env                       # Environment variables (not in git)
├── .gitignore                 # Git ignore rules
├── DEVELOPER.md               # This document
├── LICENSE.md
└── README.md                  # Documentation
```

## Development & Debugging

- Set up a standard Apache + PHP 8.x + SQLite environment.
- All logs go to the default PHP error log.
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

- **`tests/Unit/`**: pure logic. Client IP resolution, config parsing, `Content-Disposition`, Range parsing, storage path validation, backend factories, mail engine configuration and message building, schema and migrations, admin checks, throttle settings.
- **`tests/Feature/`**: the application over HTTP. Each test starts a fresh, isolated instance on PHP's built-in server (`Tests\Support\TestServer`) in a temporary directory, with its own `.env`, SQLite database, storage, log file and APCu counters. The project's own `.env`, database and storage are never touched.
  - Fixtures: an admin created through `bin/create-admin.php` and a regular user `bob@test.local`. The root directory (id 1) is created by the schema setup, as on a real install.
  - `tests/Support/router.php` mirrors `.htaccess` for the built-in server, so `.htaccess` itself still needs checking on a real Apache.
  - `Tests\Support\HttpClient` behaves like a browser: it keeps cookies and handles the CSRF token and login form.
  - Every feature test fails if the server logged a PHP warning, notice, deprecation or fatal error.
  - Mail never leaves the machine: the test servers and CLI scripts run with `sendmail_path` pointing to `tests/Support/fake-sendmail.php`, and SMTP tests use `Tests\Support\FakeSmtpServer` (a minimal local SMTP sink with `AUTH PLAIN`, no TLS). Both keep the raw messages for assertions. TLS (STARTTLS/SMTPS) is not covered: check it against a real server with `bin/send-test-mail.php`.
- Security fixes come with a test that fails on the vulnerable code (e.g. `LoginRateLimitTest` for the `X-Forwarded-For` bypass, `AdminAuthorizationTest` for the missing admin guard).

Production installs should use `composer install --no-dev`, which leaves PHPUnit out of `vendor/`.

## Security Architecture

The application now implements several layers of security to defend against common web application threats:

- **CSRF Protection**  
  A CSRF token is generated per session; pages carry it in a `csrf-token` meta tag and send it as the `X-CSRF-TOKEN` header (`api()` in `utils.js`, and Dropzone's chunk requests). All POST requests are verified globally in `index.php` before reaching controller logic, **including the login form** (login CSRF). The token is rotated at login. Tokens are managed via `Session::getCsrfToken()` and validated via `SecurityMiddleware::validateCsrfToken()`. A refused token answers 403 with `code: csrf_invalid`, which happens when the session expired while the page was open; `api()` then reloads the page (a plain 403, e.g. a refused right, doesn't).

- **Brute-force Protection**  
  Login attempts are rate-limited with **APCu in-memory counters**, using two independent limits within a window of `login_lockout_duration` seconds (default 300):
  - per client IP (see *Client IP resolution* below): `max_login_attempts`, default 5;
  - per username, from any IP: `max_login_attempts_per_user`, default 10. It's higher because anyone can use it to lock a known username out for the window.

  A request already blocked by the IP limit doesn't count against the username. A successful login resets the username counter only: resetting the IP counter would let an attacker clear it by logging into their own account. Invalid values fall back to the defaults. If APCu is unavailable, the app triggers a fatal error to avoid silent exposure. Counters are per server: several containers or servers don't share them.

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
  `.htaccess` serves only existing static files (CSS, JS including `.mjs`, fonts, images) under `assets/` and the build folders of the three front-end packages (`vendor/twbs/bootstrap/dist/`, `vendor/twbs/bootstrap-icons/font/`, `vendor/enyo/dropzone/dist/`); anything else under `assets/` or `vendor/` is a 404, including PHP scripts, sources and build scripts shipped by Composer packages. `storage/`, `src/`, `bin/`, `docker/` and dotfiles are refused, and every other path goes through `index.php`. A front-end package added later needs its folder added there, in `tests/Support/router.php` and in `docker/lighttpd.conf`, which has the same rules (and a MIME type for each served extension).

- **Downloads**  
  `Response::contentDisposition()` builds the `Content-Disposition` header: an ASCII fallback `filename` with quotes, backslashes, `%`, `;` and control characters replaced, plus the exact name in `filename*=UTF-8''…` (RFC 6266).


- **Session Hijack Protection**  
Session cookies are `HttpOnly`, `SameSite=Lax`, scoped to the application's base path, and `Secure` when the request is HTTPS (directly, `X-Forwarded-Proto` from a trusted proxy, or `force_https=true`). `Lax` rather than `Strict`: `Strict` drops the cookie on navigations coming from another site, which breaks invitation links opened from an e-mail and the planned OIDC callback. Cross-site POSTs remain blocked by `Lax` and the CSRF tokens. `session.use_strict_mode` rejects session IDs the server didn't issue, and the session ID is regenerated at login (session fixation). Sessions are validated against inactivity timeout and invalidated on logout.

Future improvements may include:
- Device/session management
- Multi-factor authentication
- OIDC enhancements

## Logging & Forensic Integrity

The application now includes a **pluggable logging backend system**, abstracted via `LogEngineInterface`. 

Log engines are configured via `.env` using the `log_engine` key.

The logger is globally available via `App::getLogger()` and all controller-level events are expected to log security-sensitive actions, including:

- Login success/failure
- Logout
- Uploads (`upload_start`, `upload_verifying`, `upload_completed` with size and SHA-256, `upload_name_conflict`, `upload_duplicate_refused`, `upload_denied`, `upload_chunk_rejected`, `upload_failed`, `upload_aborted`, `upload_expired`, `upload_verify_restarted`) and deletions
- Password changes
- Group/user permission changes (WIP)
- Invitations (`user_invited`, with `invitation_sent`) and mail failures (`mail_send_failure`)

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
- a group has access to one or more directories, either in read-only, in read-write or upload-only
- a group flagged `is_admin = 1` makes its members administrators: full access to every directory, plus the admin interface
- `AccessMiddleware::isAdmin()` is the single admin check. It queries the database on every request (no session cache), so revoking admin membership takes effect immediately
- every action of `AdminController` is admin-only (one guard at the top of `handle()`). File operations open to regular users (`createFolder`, `deleteItem`) live in `FileManagerController` (`POST /`, field `a`) and check the caller's rights on the target directory
- safeguards: the admin group can't be deleted, an admin can't lock or delete their own account, and the last active administrator can't be removed, locked or deleted

### 1. **User Management**
| Feature | Description | Location |
|--------|-------------|-------------|
| 🧑 View Users | List all registered users | Admin UI |
| ➕ Create User | Manually create a user (name, email, password) | This will not be implemented - Creating a user implies inviting him/her |
| ✉️ Invite User | Generate & send a tokenized invitation link | Admin UI - the user must be part of at least one group upon creation|
| ✏️ Edit User | Change user details, add/remove group memberships | Admin UI |
| 🗑️ Deactivate/Delete User | Either soft-disable or remove entirely | Admin UI |

---

### 2. **User Self-Service**
| Feature | Description | Location |
|--------|-------------|-------------|
| ✏️ Edit User | Change user details, add/change password | File Manager UI - in a modal|

---

### 3. **Group Management**
| Feature | Description | Location |
|--------|-------------|-----------|
| 👥 View Groups | List all groups and their descriptions | Admin UI |
| ➕ Create Group | Name, description | Admin UI, but also during directory access assignment (see below) |
| 👤 Add/Remove Users | Assign or remove users from a group | Admin UI |
| 🗑️ Delete Group | Remove a group (with warning if it still has members) | Admin UI |

---

### 5. **Directory Permissions UI**
| Feature | Description | Location |
|--------|-------------|----------|
| 🔍 Directory Tree View | Browse directories and manage group permissions | File Manager UI |
| 👁️ View Group Access | See which groups can do what in a selected directory |  File Manager UI |
| 🔧 Edit Access Rights | Add/remove group access and set permission levels (read, write, upload) | File Manager UI, should allow on-the-fly group creation |


### 6. **Audit & Logs (Planned for Later)**
| Feature | Description | Location |
|--------|-------------|----------|
| 📜 View User Actions | Filterable logs: uploads, downloads, logins | Admin UI |
| 🧾 Group/Permission Changes | Audit trail for admin actions (access revokes, user changes) | Admin UI |


## Database Bootstrapping

Database initialization is handled programmatically in `src/Core/Database.php`.

`createSchema()` creates the current schema on a fresh database. Upgrades of existing databases are handled by `Database::migrate()`, versioned with SQLite's `PRAGMA user_version`. Each step must be idempotent, because fresh databases already have the current schema:
- **v1:** adds `groups.is_admin` and flags group `id = 1`, the group the former hardcoded admin check trusted. It also locks the formerly seeded `admin` account if it still has the default password `admin`; the lock is reported in the PHP error log. Create a new administrator with `php bin/create-admin.php <username>`.
- **v2:** replaces the v1 `uploads` table (in-progress v1 uploads are discarded; their partial files, left at their final path by the v1 code, are listed in the PHP error log) with `uploads` and `upload_chunks`, and adds the unique indexes `files(directory_id, name)` and `files(directory_id, sha256)`. The v1 upload code could create two rows for one name; the migration never drops such evidence records. If it finds any, it stops, lists them in the PHP error log, and the application refuses to run until an operator resolves them. `bin/check-integrity.php` lists them too, along with stored files that no longer match their hash.

To add a migration, add a `if ($version < N)` block in `migrate()` that ends with `PRAGMA user_version = N`. Back up the database file (`database_path`, by default `storage/database/app.sqlite`) before upgrading.

No user is seeded. A fresh install has an empty admin group: create the first administrator with `bin/create-admin.php`.

All database logic uses raw SQL for performance and transparency.

## Database structure

Extract database structure with `sqlite3 ./storage/database/app.sqlite '.schema'`.

Database structure is : 
```
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    email TEXT UNIQUE,
    invitation_token TEXT,
    token_expiry DATETIME
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
    FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
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

## Backends

The application uses an Interface/Factory pattern to dynamically select backends.

### Mail engine

Mail is only used for invitation links. `MailEngineFactory::create()` reads the flat `.env` keys (see `.env.sample`) and throws `InvalidArgumentException` on a missing or invalid setting:
- `mail` (default): PHPMailer with `isMail()`, i.e. the local sendmail binary. PHPMailer encodes headers and rejects header injection; `default_mail_from` is also the envelope sender (`-f`).
- `smtp`: PHPMailer over SMTP. `smtp_secure=none|starttls|smtps`. Not set means `none` (plain SMTP, no opportunistic STARTTLS), except on port 465, which gets `smtps`. `starttls` is required, not opportunistic: the send fails if the server doesn't offer it. Authentication only when `smtp_user` is set. `SMTPDebug` stays at 0: its output would end up in the HTTP response, and from level 2 it includes the `AUTH` exchange.
- `sendgrid`: SendGrid Web API v3. An HTTP status ≥ 400 counts as a failure, with SendGrid's error body in the reason.

All engines send UTF-8 HTML with a plain-text alternative (`MailText::fromHtml()`), from `default_mail_from` / `default_mail_from_name`. `send()` returns `false` on failure and `lastError()` gives the reason; it doesn't throw. The caller logs failures as `mail_send_failure` (recipient, engine, error). An invitation whose e-mail fails still creates the contributor; the admin gets `success: false` with a generic message, and the details go to the log only.

### Storage engine

Storage engine can be :
- Local for the local filesystem (`base_storage_directory` must be an existing directory)
- Amazon S3 and Azure Blob storage: planned, not implemented. `storage_engine=amazon_s3|azure_blob` is refused with an explicit error.

Besides reading, uploads need a **staging** area (`stagingCreate`, `stagingWriteAt`, `stagingSize`, `stagingOpen`, `stagingCommit`, `stagingRemove`), keyed by 32 hex digits generated by the application. `stagingWriteAt` writes a chunk at its offset, so a chunk written twice leaves the same content. `stagingCommit` moves the staged element to its final path and must never replace an existing element. The local engine stages in `.uploads/` under its root, on the same filesystem, so the commit is an atomic `rename()`; that name is reserved in the root folder (and folder names can't start with a dot).

### Logging

- Syslog
- File
- Stderr : For Docker and containerized deployments

## Configuration

`Core\Config::load()` reads `.env` (template: `.env.sample`):
- one `key=value` per line; blank lines and lines starting with `#` are ignored;
- a value wrapped in matching `"…"` or `'…'` is unquoted, and an optional `export ` prefix is accepted;
- a `#` inside a value is kept, because passwords may contain one. There are no inline comments;
- malformed lines are ignored and reported in the PHP error log by line number only, never their content.

**Environment overrides:** `DFIRFM_<KEY>` overrides or adds `<key>` (lowercased), e.g. `DFIRFM_STORAGE_ENGINE=local`. Unprefixed variables are ignored. `.env` may be absent only if at least one `DFIRFM_` variable is set, as in containers. Otherwise a missing file stops the application.

**Permissions:** `.env` holds secrets and must be readable by the PHP user but not by everyone, e.g. owner you, group `www-data`, mode `640`. An unreadable `.env` stops the application with a 500 ("Environment file not readable"), rather than silently running on defaults as earlier versions did.

## Docker

`docker/docker-compose.yml` builds the image from the repository root (`.dockerignore` keeps `.env`, `storage/`, `vendor/` and `.git` out of the build context) and runs one container:

- **Processes:** PHP-FPM (listening on 127.0.0.1:9000 only) and lighttpd on port **8080**, both started in the foreground by `docker/start.sh`. When either exits, the script stops the other and exits, so the container stops and its restart policy applies.
- **Routing:** `docker/lighttpd.conf` mirrors `.htaccess`: the same static allowlist, 404 for `storage/`, `src/`, `bin/`, `docker/`, `tests/`, dotfiles and the rest of `assets/` and `vendor/`, everything else to `index.php`. lighttpd has no default MIME types: the file lists one per served extension, including `.mjs` (browsers refuse a module script with another type).
- **Configuration:** `docker/.env`, created by the administrator from `.env.sample` (the same keys as a host install, plus its "Docker" section uncommented). It is not in git (`.gitignore`) and the repository ships no copy of it. It is mounted read-only as the application's `.env`; Compose refuses to start if it is missing, rather than creating a directory in its place. It must be readable by the container's `www-data`, uid/gid 82 (Alpine), not the host's (33 on Debian): `chgrp 82 docker/.env` with mode `640`. PHP-FPM passes the environment to PHP (`clear_env = no`), so a `DFIRFM_*` variable added to the compose file overrides a key.
- **Compose reads `docker/.env` too:** it loads the `.env` of the compose file's directory for every command. Two keys, ignored by the application, choose the mounts: `files_dir` and `log_dir`. A volume source that is an absolute path is a bind mount, a plain name is a named volume, so `${files_dir:-dfir-fm-files}` gives the host directory when the key is set and a volume otherwise. Compose expands `$` in unquoted or double-quoted values while reading the file: it only warns, the application still reads the file as written; single quotes avoid the warnings.
- **Paths:** the image sets the three paths to its own locations, outside the web root, through `DFIRFM_*` variables, which win over the `.env`'s host paths:

  | Key | In the container | Mounted from |
  |---|---|---|
  | `base_storage_directory` | `/data/files` | `files_dir` if set (a host directory owned by uid 82), otherwise the volume `dfir-fm-files`. Nothing to set with a storage engine that doesn't use local files. |
  | `database_path` | `/data/database/app.sqlite` | always the volume `dfir-fm-database` (back it up) |
  | `log_file_path` | `/data/logs/dfir-fm.log` | `log_dir` if set (a host directory owned by uid 82), otherwise the volume `dfir-fm-logs`, disposable: `docker volume rm dfir-fm-logs`; never `docker compose down -v`, which also removes the database |

  Host directories must exist before the first start (`sudo install -d -o 82 -g 82 -m 750 <dir>`): Docker would create a missing one owned by root, which the application can't write to.
  Sessions live in the container's `/tmp`: a restart logs everyone out.
- **Logs:** with `log_engine=file` (the default), in `/data/logs`. With `log_engine=stderr` (in the `.env`, or as `DFIRFM_LOG_ENGINE` in the compose file to keep a file on a host install), the JSON events go to the container output through PHP-FPM (`log_limit` raised so that lines aren't cut). `syslog` has no daemon to talk to in the container. lighttpd's access log goes to stdout.
- **Code:** owned by root and read-only for `www-data`, who only writes to `/data`. Dependencies are installed with `composer install --no-dev`.
- **First administrator:** `docker compose exec -u www-data app php bin/create-admin.php you@yourdomain.com`.
- **HTTP only:** the compose file publishes the port on 127.0.0.1. Put a TLS-terminating reverse proxy in front and set `trusted_proxies` (and `force_https` if needed).

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
- No continuous integration yet: run `composer test` before committing

## Contributing

When contributing to DFIR FM:
- Follow the architecture and naming conventions established in the project.
- Document any new `.env` variables or backends.
- Ensure your changes do not compromise forensic integrity (e.g., hashing, traceability).
- All contributions should aim to be auditable and security-aware.

See the main `README.md` for contribution workflow.
