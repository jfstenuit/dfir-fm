# Developer Documentation

This document outlines the technical structure and internal workings of the DFIR File Manager (DFIR FM), providing guidance for contributors and maintainers.

## Bootstrapping & Application Flow

The application uses a single entrypoint: `index.php`. It handles routing manually and initializes all required components (middleware, controller dispatch, view rendering).

Command-line tools live in `bin/` and refuse to run outside the CLI SAPI:
- `php bin/create-admin.php <username>`: creates an administrator (or resets an existing user's password and adds them to the admin group) and prints a random password once. This is how the first administrator is created; no default account exists. It's a temporary tool until admin login moves to OIDC (see `PLAN.md`, Phase 2b).

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
│   └── js/
│       ├── site.js            # Entry module, loads page-specific modules
│       ├── utils.js           # Shared helpers (AJAX, CSRF, escaping, modals)
│       ├── filemanager.js     # File manager page
│       ├── admin.js           # Admin page entry
│       ├── admin-users.js     # Admin: user management
│       ├── admin-groups.js    # Admin: group management
│       └── login.js           # Login page (classic script)
├── bin/                       # CLI tools (not web-accessible)
│   └── create-admin.php       # Create the first / an additional administrator
├── docker/                    # Container deployment
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── lighttpd.conf
│   └── .env.example
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
│       └── LoginView.php
├── storage/                   # Created at runtime, not in git; must not be web-accessible
│   ├── database/              # SQLite database files
│   │   └── app.sqlite         # Main SQLite database
│   ├── files/                 # Storage for uploaded files (local storage engine)
│   └── logs/                  # Log files (file log engine)
├── vendor/                    # Composer dependencies (auto-generated)
├── composer.json              # Composer configuration file
├── composer.lock              # Composer lock file
├── .env.sample                # Configuration template, copy to .env
├── .env                       # Environment variables (not in git)
├── .gitignore                 # Git ignore rules
├── DEVELOPER.md               # This document
├── PLAN.md                    # Remediation & modernisation plan
├── LICENSE.md
└── README.md                  # Documentation
```

## Development & Debugging

- Set up a standard Apache + PHP 8.x + SQLite environment.
- All logs go to the default PHP error log.
- No framework or build system is used at this time.
- JavaScript and CSS files are managed manually.

To debug:
- Use `error_log()` for PHP
- Use browser dev tools for JS

## Testing

This project is in early development. No automated testing framework is currently in place.

When the architecture stabilizes, PHPUnit will likely be used for:
- Unit testing of backends and core logic
- Integration testing for controllers

Future developers are encouraged to lay the foundation for testing once core features are complete.

## Security Architecture

The application now implements several layers of security to defend against common web application threats:

- **CSRF Protection**  
  A CSRF token is generated per session and injected into all AJAX requests via `X-CSRF-TOKEN` headers. All POST requests are verified globally in `index.php` before reaching controller logic, **including the login form** (login CSRF), which carries the token in a `csrf-token` meta tag like the other pages. The token is rotated at login. Tokens are managed via `Session::getCsrfToken()` and validated via `SecurityMiddleware::validateCsrfToken()`. `login.js` reloads the page on a 403, which happens when the session expired while the page was open.

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
  A strict `Content-Security-Policy` header is emitted from `index.php`, together with `X-Content-Type-Options: nosniff` and `Referrer-Policy: same-origin`. They are sent before anything else, so redirects and error pages carry them too. `.htaccess` sets the latter two for static files when `mod_headers` is available.

- **Static files**  
  `.htaccess` serves only existing static files (CSS, JS, fonts, images) under `assets/` and `vendor/`; anything else there is a 404, including PHP scripts shipped by Composer packages. `storage/`, `src/`, `bin/`, `docker/` and dotfiles are refused, and every other path goes through `index.php`.

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
- Uploads / deletions
- Password changes
- Group/user permission changes (WIP)

**Forensic constraints:**
- Every uploaded file is SHA256-hashed at write time
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

`createTables()` creates the current schema on a fresh database. Upgrades of existing databases are handled by `Database::migrate()`, versioned with SQLite's `PRAGMA user_version`. Each step must be idempotent, because fresh databases already have the current schema:
- **v1:** adds `groups.is_admin` and flags group `id = 1`, the group the former hardcoded admin check trusted. It also locks the formerly seeded `admin` account if it still has the default password `admin`; the lock is reported in the PHP error log. Create a new administrator with `php bin/create-admin.php <username>`.

To add a migration, add a `if ($version < N)` block in `migrate()` that ends with `PRAGMA user_version = N`. Back up `storage/database/app.sqlite` before upgrading.

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
CREATE TABLE uploads (
    uuid TEXT PRIMARY KEY,
    file_name TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    total_chunks INTEGER NOT NULL,
    last_chunk_index INTEGER NOT NULL DEFAULT 0,
    hash_state TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    status TEXT CHECK (status IN ('pending', 'in_progress', 'completed', 'failed')) DEFAULT 'pending',
    last_update DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

## Backends

The application uses an Interface/Factory pattern to dynamically select backends.

### Mail engine

Mail engine can be 
- Standard PHPMailer (uses built-in `mail()` command)
- PHPMailer for SMTP sending
- Sendgrid

### Storage engine

Storage engine can be :
- Local for the local filesystem (`base_storage_directory` must be an existing directory)
- Amazon S3 and Azure Blob storage: planned, not implemented. `storage_engine=amazon_s3|azure_blob` is refused with an explicit error.

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
- No automated tests currently in place

## Contributing

When contributing to DFIR FM:
- Follow the architecture and naming conventions established in the project.
- Document any new `.env` variables or backends.
- Ensure your changes do not compromise forensic integrity (e.g., hashing, traceability).
- All contributions should aim to be auditable and security-aware.

See the main `README.md` for contribution workflow.
