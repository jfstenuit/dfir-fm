<?php
// src/Services/UploadException.php
namespace Services;

/**
 * A refused or failed upload operation, with the HTTP status and the
 * machine-readable code returned to the client.
 */
final class UploadException extends \RuntimeException
{
    /**
     * @param bool $terminal the upload can't continue: it is marked failed
     *                       and its staging file removed
     * @param array<string,mixed> $details extra fields for the JSON response
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly bool $terminal = false,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
