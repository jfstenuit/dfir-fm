<?php
// src/Backends/LogEngineTee.php
namespace Backends;

/**
 * The application's logger: builds each record once (LogEntry::build) and
 * writes the same record to every destination, the events database first,
 * then the configured log engine if any.
 *
 * No logging, no service (D29): when a destination fails, the others still
 * get the event, the halt starts and LogWriteException ends the request.
 * While halted, resume() retries at most once per LogHalt::PROBE_INTERVAL
 * by writing the logging_failure event itself.
 */
class LogEngineTee implements LogEngineInterface {
    /** @param list<LogDestination> $destinations */
    public function __construct(private readonly array $destinations, private readonly ?LogHalt $halt = null) {
    }

    /** @return list<LogDestination> */
    public function destinations(): array {
        return $this->destinations;
    }

    public function log(string $action, array $context = []): void {
        $entry = LogEntry::build($action, $context);
        $failures = $this->writeTo($this->destinations, $entry);
        if ($failures) {
            $halt = $this->halt?->start($failures);
            error_log(sprintf('DFIR FM: logging failed (%s); event %s of %s missing there; %s',
                LogWriteException::describe($failures), $action, $entry['ts'],
                $halt ? "application stopped since {$halt['since']}" : 'request ended'));
            throw new LogWriteException($failures);
        }
    }

    public function healthCheck(): bool {
        foreach ($this->destinations as $destination) {
            if (!$destination->healthCheck()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the application may serve this request: not halted, or the
     * probe of this request ended the halt. The probe writes the
     * logging_failure event to every destination that doesn't hold it yet.
     */
    public function resume(): bool {
        $halt = $this->halt?->current();
        if ($halt === null) {
            return true;
        }
        if (!$this->halt->claimProbe()) {
            return false;
        }

        $until = gmdate('c');
        $entry = LogEntry::build('logging_failure', [
            'failed_from' => $halt['since'],
            'failed_until' => $until,
            'failures' => $halt['failures'],
            'message' => "logging failed between {$halt['since']} and $until - application was stopped",
        ]);
        $pending = array_filter($this->destinations, fn($d) => !in_array($d->name(), $halt['recorded'], true));
        $failures = $this->writeTo($pending, $entry);
        foreach ($pending as $destination) {
            if (!isset($failures[$destination->name()])) {
                $halt['recorded'][] = $destination->name();
            }
        }

        if ($failures) {
            $halt['failures'] = $failures + $halt['failures'];
            $this->halt->update($halt);
            error_log('DFIR FM: logging still failing (' . LogWriteException::describe($failures) . "); application stopped since {$halt['since']}");
            return false;
        }
        $this->halt->clear();
        error_log("DFIR FM: logging works again; application stopped from {$halt['since']} to $until");
        return true;
    }

    /** @return array<string,string> destination name => error, for the destinations that failed */
    private function writeTo(array $destinations, array $entry): array {
        $failures = [];
        foreach ($destinations as $destination) {
            try {
                $destination->write($entry);
            } catch (\Throwable $e) {
                $failures[$destination->name()] = $e->getMessage();
            }
        }
        return $failures;
    }
}
?>
