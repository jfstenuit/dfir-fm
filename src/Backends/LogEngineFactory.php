<?php
// src/Backends/LogEngineFactory.php
namespace Backends;

class LogEngineFactory {
    public static function create(array $config): LogEngineInterface {
        $format = $config['log_format'] ?? 'jsonl';
        $dest = $config['log_dest'] ?? 'file';

        return match ($dest) {
            'file' => new LogEngineFile($config['log_file_path'] ?? '/tmp/dfir.log', $format),
            'syslog' => new LogEngineSyslog($config['syslog_tag'] ?? 'dfir-fm'),
            'stderr' => new LogEngineStderr(),
            default => throw new \RuntimeException("Unsupported log destination: $dest")
        };
    }
}
?>