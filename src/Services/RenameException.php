<?php
// src/Services/RenameException.php
namespace Services;

/**
 * A refused rename, with the HTTP status and the machine-readable code
 * returned to the client.
 */
final class RenameException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $details extra fields for the JSON response
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
?>
