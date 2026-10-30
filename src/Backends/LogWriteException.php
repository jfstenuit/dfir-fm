<?php
// src/Backends/LogWriteException.php
namespace Backends;

/**
 * An event could not be written to every destination (D29). The action it
 * records may already be done; the request ends here and the application
 * stops serving until logging works again (LogHalt).
 */
class LogWriteException extends \RuntimeException {
    /** @param array<string,string> $failures destination name => error */
    public function __construct(public readonly array $failures) {
        parent::__construct('Logging failed: ' . self::describe($failures));
    }

    /** @param array<string,string> $failures */
    public static function describe(array $failures): string {
        return implode('; ', array_map(fn($name, $error) => "$name: $error", array_keys($failures), $failures));
    }

    /**
     * For the bin/ scripts: a failed write ends the script with exit code 1
     * and a message on stderr; other uncaught exceptions as PHP would.
     */
    public static function handleInCli(): void {
        set_exception_handler(function (\Throwable $e) {
            if ($e instanceof self) {
                fwrite(STDERR, 'Error: ' . $e->getMessage() . ". The action may be done, but its event was not logged.\n");
                exit(1);
            }
            fwrite(STDERR, 'PHP Fatal error:  Uncaught ' . $e . "\n");
            exit(255);
        });
    }
}
?>
