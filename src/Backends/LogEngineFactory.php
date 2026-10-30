<?php
// src/Backends/LogEngineFactory.php
namespace Backends;

use Core\Config;

class LogEngineFactory {
    /**
     * The application's logger: the events database (D27), plus a copy to
     * the configured log engine unless log_engine=none.
     *
     * @param ?string $basePath The application directory, which relative
     *   paths in the configuration are relative to (default: BASE_PATH)
     */
    public static function create(array $config, ?string $basePath = null): LogEngineTee {
        $basePath ??= defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

        // `log_engine` is the documented key; `log_dest` is still accepted for
        // installations configured before it was honoured
        $engine = $config['log_engine'] ?? $config['log_dest'] ?? 'file';

        $copy = match ($engine) {
            'none' => null,
            'file' => new LogEngineFile($config['log_file_path'] ?? '/tmp/dfir.log', self::format($config)),
            'syslog' => new LogEngineSyslog($config['syslog_tag'] ?? 'dfir-fm'),
            'stderr' => new LogEngineStderr(),
            default => throw new \RuntimeException("Unsupported log engine: $engine")
        };

        $events = new LogEngineEvents(Config::eventsDatabasePath($config, $basePath));
        // The halt is the web server's: a CLI script ends with exit code 1 instead
        $halt = PHP_SAPI === 'cli' ? null : new LogHalt($events->path());

        return new LogEngineTee($copy ? [$events, $copy] : [$events], $halt);
    }

    private static function format(array $config): string {
        $format = $config['log_format'] ?? 'jsonl';
        if (!in_array($format, ['jsonl', 'csv'], true)) {
            throw new \RuntimeException("Unsupported log format: $format (expected jsonl or csv)");
        }
        return $format;
    }
}
?>
