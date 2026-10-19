<?php
// tests/Support/HttpClient.php
namespace Tests\Support;

/**
 * Minimal browser-like HTTP client: keeps its own cookies, never follows
 * redirects, and knows the application's login and CSRF conventions.
 */
final class HttpClient
{
    /** @var array<string, string> */
    public array $cookies = [];

    /** @var array<string, string> sent with every request */
    public array $defaultHeaders = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    /**
     * @param array{headers?: array<string,string>, form?: array<string,mixed>, query?: array<string,mixed>, raw?: string} $options
     */
    public function request(string $method, string $path, array $options = []): HttpResponse
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if (!empty($options['query'])) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
        }

        $headers = array_merge($this->defaultHeaders, $options['headers'] ?? []);
        if ($this->cookies) {
            $headers['Cookie'] = implode('; ', array_map(
                fn($name, $value) => "$name=$value",
                array_keys($this->cookies),
                $this->cookies
            ));
        }

        $responseHeaders = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if (isset($options['form'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($options['form']));
        } elseif (isset($options['raw'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $options['raw']);
        }

        $body = curl_exec($curl);
        if ($body === false) {
            throw new \RuntimeException("HTTP request to $url failed: " . curl_error($curl));
        }
        $response = new HttpResponse(curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $responseHeaders, $body);

        foreach ($response->headerValues('set-cookie') as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
            $this->cookies[$name] = $value;
        }

        return $response;
    }

    public function get(string $path, array $options = []): HttpResponse
    {
        return $this->request('GET', $path, $options);
    }

    public function post(string $path, array $form = [], array $options = []): HttpResponse
    {
        return $this->request('POST', $path, $options + ['form' => $form]);
    }

    /** POST with the CSRF token of the page at $tokenPage (fetched first) */
    public function postWithCsrf(string $path, array $form = [], string $tokenPage = '/', array $options = []): HttpResponse
    {
        $token = $this->get($tokenPage, $options)->csrfToken();
        $options['headers'] = ($options['headers'] ?? []) + ['X-CSRF-TOKEN' => (string)$token];
        return $this->post($path, $form, $options);
    }

    /** Log in through the login form, as the browser does (CSRF included) */
    public function login(string $username, string $password, array $options = []): HttpResponse
    {
        return $this->postWithCsrf('login', ['action' => 'login', 'username' => $username, 'password' => $password], 'login', $options);
    }
}
