<?php

// src/Core/Config.php
namespace Core;

class Config
{
    /** Prefix of environment variables that override .env settings */
    public const ENV_PREFIX = 'DFIRFM_';

    /**
     * Load the configuration from the .env file, then apply environment
     * overrides: DFIRFM_<KEY> sets <key> (lowercase), e.g.
     * DFIRFM_STORAGE_ENGINE=local sets storage_engine.
     *
     * The .env file may be omitted only if at least one DFIRFM_ variable is
     * set (containerised deployments).
     */
    public static function load($envFile)
    {
        $overrides = self::environmentOverrides();

        if (!file_exists($envFile)) {
            if (empty($overrides)) {
                throw new \Exception("Environment file not found: $envFile");
            }
            return $overrides;
        }

        return array_merge(self::parseFile($envFile), $overrides);
    }

    /**
     * The SQLite database file: database_path, or storage/database/app.sqlite
     * under the application directory. A relative path is relative to the
     * application directory.
     */
    public static function databasePath(array $config, string $basePath): string
    {
        $path = trim($config['database_path'] ?? '');
        if ($path === '') {
            return $basePath . '/storage/database/app.sqlite';
        }
        return str_starts_with($path, '/') ? $path : $basePath . '/' . $path;
    }

    /**
     * Parse KEY=value lines. Blank lines and lines starting with # are
     * skipped; a value wrapped in matching single or double quotes is
     * unquoted. A # inside a value is kept (passwords may contain one).
     * Malformed lines are reported in the PHP error log (line number only,
     * never the content, which may be a secret) and ignored.
     */
    public static function parseFile(string $envFile): array
    {
        $config = [];
        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \Exception("Environment file not readable: $envFile");
        }

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line); // UTF-8 BOM
            }
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            $key = trim(preg_replace('/^export\s+/', '', $parts[0]));
            if (count($parts) !== 2 || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                error_log(sprintf('DFIR FM configuration: ignoring malformed line %d in %s', $index + 1, $envFile));
                continue;
            }

            $config[$key] = self::unquote(trim($parts[1]));
        }

        return $config;
    }

    private static function environmentOverrides(): array
    {
        $overrides = [];
        foreach (getenv() as $name => $value) {
            if (str_starts_with($name, self::ENV_PREFIX) && strlen($name) > strlen(self::ENV_PREFIX)) {
                $overrides[strtolower(substr($name, strlen(self::ENV_PREFIX)))] = self::unquote(trim($value));
            }
        }
        return $overrides;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }
}
?>
