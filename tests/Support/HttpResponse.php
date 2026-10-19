<?php
// tests/Support/HttpResponse.php
namespace Tests\Support;

final class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers lowercase name => values
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    /** CSRF token from the page's `csrf-token` meta tag, if any */
    public function csrfToken(): ?string
    {
        return preg_match('/<meta name="csrf-token" content="([^"]*)"/', $this->body, $m) ? $m[1] : null;
    }

    /** The Set-Cookie header for the given cookie, if the response sets it */
    public function setCookie(string $name): ?string
    {
        foreach ($this->headerValues('set-cookie') as $value) {
            if (str_starts_with($value, $name . '=')) {
                return $value;
            }
        }
        return null;
    }
}
