<?php
// src/Backends/LogEngineFactory.php
namespace Backends;

class LogEngineFactory {
    public static function create(array $config): LogEngineInterface {
        $format = $config['log_format'] ?? 'jsonl';
        if (!in_array($format, ['jsonl', 'csv'], true)) {
            throw new \RuntimeException("Unsupported log format: $format (expected jsonl or csv)");
        }

        // `log_engine` is the documented key; `log_dest` is still accepted for
        // installations configured before it was honoured
        $engine = $config['log_engine'] ?? $config['log_dest'] ?? 'file';

        return match ($engine) {
            'file' => new LogEngineFile($config['log_file_path'] ?? '/tmp/dfir.log', $format),
            'syslog' => new LogEngineSyslog($config['syslog_tag'] ?? 'dfir-fm'),
            'stderr' => new LogEngineStderr(),
            default => throw new \RuntimeException("Unsupported log engine: $engine")
        };
    }
}
?>
