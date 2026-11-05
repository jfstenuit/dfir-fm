<?php
// tests/Support/MockOidcServer.php
namespace Tests\Support;

/**
 * Runs meigma/mock-oidc (DFIRFM_MOCK_OIDC_BIN, see fetch-mock-oidc.sh) on
 * free local ports, like FakeSmtpServer:
 *   - HTTPS with a certificate for 127.0.0.1 from a throwaway CA, given to
 *     the test server as oidc_ca_file ($caFile)
 *   - the /_mock control plane on its own plain-HTTP listener, behind a
 *     random token
 *   - interactive login: login() posts the identity (sub and claims) to
 *     the authorization URL, as a user would through the login page
 *
 * What it doesn't check (v0.1.0): the client secret, redirect_uri,
 * max_age; registered claims (iss, aud, exp, nonce) can't be overridden.
 */
final class MockOidcServer
{
    public const ISSUER_ID = 'dfirfm';
    public const CLIENT_ID = 'dfir-fm-test';
    public const CLIENT_SECRET = 'test-secret';
    /** Never served: the mock redirects there, and the test calls the test server's callback itself */
    public const REDIRECT_URI = 'https://dfirfm.test/login/oidc/callback';
    public const DOMAIN = 'oidc.test';
    public const ADMIN_CLAIM = 'groups';
    public const ADMIN_VALUE = 'dfir-fm-admins';

    public readonly string $issuer;
    public readonly string $caFile;
    private readonly string $dir;
    private readonly int $port;
    private readonly int $controlPort;
    private readonly string $controlToken;
    private $process = null;

    /** Why the mock can't run here, or null */
    public static function unavailable(): ?string
    {
        $binary = getenv('DFIRFM_MOCK_OIDC_BIN');
        if ($binary === false || $binary === '') {
            return 'DFIRFM_MOCK_OIDC_BIN is not set (tests/Support/fetch-mock-oidc.sh downloads mock-oidc)';
        }
        if (!is_file($binary) || !is_executable($binary)) {
            return "DFIRFM_MOCK_OIDC_BIN is not an executable file: $binary";
        }
        return null;
    }

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/dfirfm-oidc-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->caFile = "$this->dir/ca.pem";
        $this->makeCertificates();

        $this->port = TestServer::freePort();
        $this->controlPort = TestServer::freePort();
        $this->controlToken = bin2hex(random_bytes(16));
        $this->issuer = "https://127.0.0.1:$this->port/" . self::ISSUER_ID;

        $this->process = proc_open([
            getenv('DFIRFM_MOCK_OIDC_BIN'), 'serve',
            '--addr', "127.0.0.1:$this->port",
            '--control-addr', "127.0.0.1:$this->controlPort",
            '--control-token', $this->controlToken,
            '--metrics-addr', '',
            '--tls-cert-file', "$this->dir/server.pem",
            '--tls-key-file', "$this->dir/server.key",
            '--log-format', 'text',
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', "$this->dir/server.log", 'a'],
            2 => ['file', "$this->dir/server.log", 'a'],
        ], $pipes, $this->dir, ['JSON_CONFIG' => json_encode(['interactiveLogin' => true])]);

        TestServer::waitForPort($this->port, fn() => $this->stop());
        TestServer::waitForPort($this->controlPort, fn() => $this->stop());
    }

    /** The test server's oidc_* settings for this mock */
    public function env(array $overrides = []): array
    {
        return array_merge([
            'oidc_issuer' => $this->issuer,
            'oidc_client_id' => self::CLIENT_ID,
            'oidc_client_secret' => self::CLIENT_SECRET,
            'oidc_redirect_uri' => self::REDIRECT_URI,
            'oidc_email_domain' => self::DOMAIN,
            'oidc_admin_claim' => self::ADMIN_CLAIM,
            'oidc_admin_value' => self::ADMIN_VALUE,
            'oidc_ca_file' => $this->caFile,
        ], $overrides);
    }

    /**
     * Sign in at the authorization URL (the Location of /login/oidc) as
     * $subject with these claims
     * @return array{code:?string, state:?string, error:?string} the parameters it redirects to oidc_redirect_uri with
     */
    public function login(string $authorizationUrl, string $subject, array $claims): array
    {
        $response = $this->https('POST', $authorizationUrl, http_build_query(['username' => $subject, 'claims' => json_encode($claims)]));
        $location = $response['location'] ?? throw new \RuntimeException("mock-oidc login: no redirect (HTTP {$response['status']}): {$response['body']}");
        if (!str_starts_with($location, self::REDIRECT_URI . '?')) {
            throw new \RuntimeException("mock-oidc login: unexpected redirect $location");
        }
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        return ['code' => $query['code'] ?? null, 'state' => $query['state'] ?? null, 'error' => $query['error'] ?? null];
    }

    /**
     * Open the IdP's logout URL (the Location of /logout), as the browser would
     * @return array{status:int, location:?string}
     */
    public function endSession(string $url): array
    {
        $response = $this->https('GET', $url);
        return ['status' => $response['status'], 'location' => $response['location']];
    }

    /** Freeze the mock's clock (tokens issued at that instant), or unfreeze it with null */
    public function freezeClock(?\DateTimeInterface $instant): void
    {
        $this->control('PUT', '/_mock/clock', $instant === null ? ['frozen' => false]
            : ['frozen' => true, 'instant' => $instant->format(\DateTimeInterface::RFC3339)]);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        foreach (glob("$this->dir/*") ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function control(string $method, string $path, array $body): array
    {
        $curl = curl_init("http://127.0.0.1:$this->controlPort$path");
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Mock-Control-Token: $this->controlToken"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $answer = curl_exec($curl);
        if ($answer === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new \RuntimeException("mock-oidc $method $path failed: " . ($answer === false ? curl_error($curl) : $answer));
        }
        return json_decode($answer, true);
    }

    /** @return array{status:int, body:string, location:?string} */
    private function https(string $method, string $url, ?string $form = null): array
    {
        $location = null;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CAINFO => $this->caFile,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, strlen('Location:')));
                }
                return strlen($header);
            },
        ] + ($form === null ? [] : [CURLOPT_POSTFIELDS => $form]));
        $body = curl_exec($curl);
        if ($body === false) {
            throw new \RuntimeException("mock-oidc $method $url failed: " . curl_error($curl));
        }
        return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body, 'location' => $location];
    }

    /** A throwaway CA, and a certificate it signs for 127.0.0.1 and localhost */
    private function makeCertificates(): void
    {
        $d = $this->dir;
        file_put_contents("$d/server.ext", "subjectAltName=IP:127.0.0.1,DNS:localhost\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n");
        $ec = ['-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:P-256', '-nodes'];
        self::openssl(['req', '-x509', ...$ec, '-keyout', "$d/ca.key", '-out', "$d/ca.pem", '-days', '1', '-subj', '/CN=DFIR FM test CA']);
        self::openssl(['req', ...$ec, '-keyout', "$d/server.key", '-out', "$d/server.csr", '-subj', '/CN=127.0.0.1']);
        self::openssl(['x509', '-req', '-in', "$d/server.csr", '-CA', "$d/ca.pem", '-CAkey', "$d/ca.key", '-CAcreateserial',
            '-out', "$d/server.pem", '-days', '1', '-extfile', "$d/server.ext"]);
    }

    private static function openssl(array $args): void
    {
        $process = proc_open(['openssl', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('openssl ' . implode(' ', $args) . " failed: $output");
        }
    }
}
