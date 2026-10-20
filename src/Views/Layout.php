<?php
// src/Views/Layout.php - The <head> shared by every page
namespace Views;

use Core\Session;

class Layout
{
    public const BOOTSTRAP_CSS = 'vendor/twbs/bootstrap/dist/css/bootstrap.min.css';
    public const ICONS_CSS = 'vendor/twbs/bootstrap-icons/font/bootstrap-icons.min.css';
    // Includes Popper (dropdowns); sets window.bootstrap
    public const BOOTSTRAP_JS = 'vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js';

    /**
     * Print <head>: charset, viewport, CSRF token, title, then the given
     * stylesheets, classic scripts and ES modules, in that order.
     */
    public static function head(string $title, array $styles = [], array $scripts = [], array $modules = []): void
    {
        $e = fn(string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        echo "<head>\n";
        echo "  <meta charset=\"UTF-8\">\n";
        echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
        echo '  <meta name="csrf-token" content="' . $e(Session::getCsrfToken()) . "\">\n";
        echo '  <title>' . $e($title) . "</title>\n";
        foreach ($styles as $href) {
            echo '  <link rel="stylesheet" href="' . $e($href) . "\">\n";
        }
        foreach ($scripts as $src) {
            echo '  <script src="' . $e($src) . "\"></script>\n";
        }
        foreach ($modules as $src) {
            echo '  <script type="module" src="' . $e($src) . "\"></script>\n";
        }
        echo "</head>\n";
    }

    /** The application pages (file manager, admin) */
    public static function appHead(string $title): void
    {
        self::head($title,
            [
                self::BOOTSTRAP_CSS,
                self::ICONS_CSS,
                'vendor/enyo/dropzone/dist/dropzone.css',
                'assets/css/style.css',
            ],
            [self::BOOTSTRAP_JS],
            // filemanager.js imports Dropzone (dropzone.mjs) itself
            ['assets/js/site.js']
        );
    }
}
