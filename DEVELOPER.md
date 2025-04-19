# Developer Documentation

This document outlines the technical structure and internal workings of the DFIR File Manager (DFIR FM), providing guidance for contributors and maintainers.

## Bootstrapping & Application Flow

The application uses a single entrypoint: `index.php`. It handles routing manually and initializes all required components (middleware, controller dispatch, view rendering). There are currently no CLI entrypoints, but ad-hoc scripts for testing and cleanup may exist during development.

Middleware (such as authentication or CSRF protection) must be applied explicitly per route.

## Directory structure
```
project-root/
│
├── index.php                  # Single entry point for the application
├── .htaccess                  # Apache mod_rewrite rules for routing (if using Apache)
├── assets/                    # Static assets like images, JavaScript, and CSS
│   ├── css/
│   │   └── style.css          # Main CSS file
│   ├── js/
│   │   └── site.js            # Main JavaScript file
│   └── images/                # Image files
│       ├── icons/             # Icon files (SVGs, PNGs, etc.)
│       └── logos/             # Logos for branding
├── src/                       # Source code directory
│   ├── Backends/              # Dynamically selected backends
│   │   ├── MailEngine*.php    # Handles mail sending
│   │   └── StorageEngine*.php # Handles backend storage
│   ├── Controllers
│   │   ├── AdminController.php
│   │   ├── DownloadController.php
│   │   ├── FileManagerController.php
│   │   ├── LoginController.php
│   │   ├── LogoutController.php
│   │   └── UploadController.php
│   ├── Core
│   │   ├── Config.php
│   │   ├── Database.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   └── Session.php
│   ├── Middleware
│   │   └── AccessMiddleware.php
│   ├── Views/                 # Templates for rendering HTML
│   │   ├── AdminView.php
│   │   ├── FileManagerView.php
│   │   ├── InvitationView.php
│   │   └── LoginView.php
│   │   ├── layouts/           # Layout files (e.g., header, footer)
│   │   ├── file/              # Views for file operations
│   │   ├── auth/              # Views for authentication
│   │   └── errors/            # Error pages
│   │       ├── 404.php
│   │       ├── 403.php
│   │       └── 500.php
│   ├── Core/                  # Core framework-like components
│   │   ├── Router.php         # Handles routing
│   │   ├── Request.php        # Manages request data
│   │   ├── Response.php       # Manages responses
│   │   ├── Session.php        # Session management
│   │   └── Config.php         # Configuration loader
│   └── Middleware/            # Middleware for request/response handling
│       ├── AuthMiddleware.php # Authentication checks
│       └── CsrfMiddleware.php # CSRF protection
├── storage/                   # Storage directory (not publicly accessible directly)
│   ├── files/                 # Secure storage for uploaded files
│   ├── temp/                  # Temporary storage (e.g., for partial TUS uploads)
│   ├── database/              # SQLite database files
│   │   └── app.sqlite         # Main SQLite database
│   ├── logs/                  # Log files
│   │   └── app.log
│   └── cache/                 # Cache files
├── tests/                     # Automated tests
│   ├── Unit/                  # Unit tests
│   ├── Feature/               # Feature/integration tests
│   └── bootstrap.php          # Test setup/bootstrap file
├── vendor/                    # Composer dependencies (auto-generated)
├── composer.json              # Composer configuration file
├── composer.lock              # Composer lock file
├── .env                       # Environment variables
├── .gitignore                 # Git ignore rules
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

## Security Roadmap

The application currently implements session-based authentication but lacks several security hardening features such as:

- CSRF protection
- Rate limiting
- Brute-force prevention
- Session hijack detection

Middleware stubs (e.g., `CsrfMiddleware.php`) are present, and developers are encouraged to contribute to these areas as the project matures.

## Logging & Forensic Integrity

At present, logging is minimal and performed via standard PHP `error_log`. A future iteration will introduce a pluggable logging backend (interface-based), supporting granular file operation audit trails.

All future developers **must ensure**:
- Users are authenticated before performing file operations.
- Forensic constraints like SHA256 hashing are not bypassed.
- Logging (once implemented) is integrated into new file actions (upload, delete, download, etc.).

## Authorization logic

Access rights : 
- a user is in one or more groups
- a group has access to one or more directories, either in read-only, in read-write or upload-only

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

There is currently no migration framework in place. On upgrade, developers should:
- Backup existing data (if necessary)
- Delete the existing SQLite DB in `storage/database/app.sqlite`
- Re-run the application to trigger automatic DB re-creation

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
    description TEXT
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
- Local for the local filesystem
- Amazon S3
- Azure Blob storage

## Backend Extensibility

Backends for storage and mail are dynamically chosen based on `.env` variables.

- **Mail backends** must implement `MailEngineInterface`
- **Storage backends** must implement `StorageEngineInterface`

To add a new backend:
1. Create a class in `src/Backends/` implementing the appropriate interface.
2. Register the backend by modifying the relevant factory logic.
3. Add any required `.env` variables and document them.

Each backend is responsible for parsing its configuration from the `.env` file.

## Known Limitations / To-Do

- No migration strategy is implemented for the database
- Logging is not yet pluggable or centralized
- Security middleware stubs (e.g., CSRF) need full implementation
- No automated tests currently in place

## Contributing

When contributing to DFIR FM:
- Follow the architecture and naming conventions established in the project.
- Document any new `.env` variables or backends.
- Ensure your changes do not compromise forensic integrity (e.g., hashing, traceability).
- All contributions should aim to be auditable and security-aware.

See the main `README.md` for contribution workflow.
