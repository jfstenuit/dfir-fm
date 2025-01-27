project-root/
│
├── index.php                  # Single entry point for the application
├── .htaccess                  # Apache mod_rewrite rules for routing (if using Apache)
├── assets/                    # Static assets like images, JavaScript, and CSS
│   ├── css/
│   │   ├── app.css            # Main CSS file
│   │   ├── reset.css          # CSS reset (optional)
│   │   └── themes/            # Theme-specific styles (if needed)
│   ├── js/
│   │   ├── app.js             # Main JavaScript file
│   │   ├── helpers.js         # Utility/helper functions
│   │   ├── uppy.js            # Uppy-related scripts
│   │   └── plugins/           # Additional JavaScript plugins
│   └── images/                # Image files
│       ├── icons/             # Icon files (SVGs, PNGs, etc.)
│       └── logos/             # Logos for branding
├── src/                       # Source code directory
│   ├── Controllers/           # Controllers for handling requests
│   │   ├── FileController.php # Handles file operations
│   │   ├── AuthController.php # Handles authentication
│   │   └── ApiController.php  # Handles API requests for AJAX
│   ├── Models/                # Models for business logic
│   │   ├── File.php           # File-related operations
│   │   ├── User.php           # User-related operations
│   │   └── Directory.php      # Directory-related operations
│   ├── Views/                 # Templates for rendering HTML
│   │   ├── layouts/           # Layout files (e.g., header, footer)
│   │   │   ├── header.php
│   │   │   ├── footer.php
│   │   │   └── sidebar.php
│   │   ├── file/              # Views for file operations
│   │   │   ├── list.php
│   │   │   ├── upload.php
│   │   │   └── details.php
│   │   ├── auth/              # Views for authentication
│   │   │   ├── login.php
│   │   │   └── register.php
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



Database structure

[Table] directories
[Table] files
[Table] groups
[Table] user_group
[Table] users

Access rights : 
- a user is in one or more groups
- a group has access to one or more directories, either in read-only or in read-write
