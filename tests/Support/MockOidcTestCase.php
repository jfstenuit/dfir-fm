<?php
// tests/Support/MockOidcTestCase.php
namespace Tests\Support;

use Controllers\OidcController;

/**
 * Feature tests signing in through mock-oidc (#9): a mock per test, the
 * test server configured for it. Skipped when OIDC_MOCK_BIN isn't
 * set, unless OIDC_MOCK_REQUIRED=1 (CI), which makes that a failure.
 */
abstract class MockOidcTestCase extends FeatureTestCase
{
    protected ?MockOidcServer $idp = null;

    protected function setUp(): void
    {
        if (($why = MockOidcServer::unavailable()) !== null) {
            if (getenv('OIDC_MOCK_REQUIRED') === '1') {
                $this->fail("$why, and OIDC_MOCK_REQUIRED=1");
            }
            $this->markTestSkipped($why);
        }
        $this->idp = new MockOidcServer();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->idp !== null) {
            parent::tearDown();
            $this->idp->stop();
        }
    }

    protected function serverEnv(): array
    {
        return $this->idp->env();
    }

    /** An identity's claims: a verified address, in the admin group if asked */
    protected static function claims(string $email, bool $admin = false, array $extra = []): array
    {
        return $extra + [
            'email' => $email,
            'email_verified' => true,
            'name' => 'Carol Example',
            'groups' => $admin ? ['staff', MockOidcServer::ADMIN_VALUE] : ['staff'],
        ];
    }

    /** GET /login/oidc: the authorization URL it redirects to */
    protected function start(HttpClient $client, array $query = []): string
    {
        $response = $client->get('login/oidc', ['query' => $query]);
        $this->assertSame(302, $response->status, $response->body);
        $location = (string)$response->header('location');
        $this->assertStringStartsWith($this->idp->issuer . '/authorize?', $location);
        return $location;
    }

    /** The round trip from an authorization URL: sign in at the IdP, then the callback */
    protected function complete(HttpClient $client, string $authorizationUrl, string $subject, array $claims): HttpResponse
    {
        $answer = $this->idp->login($authorizationUrl, $subject, $claims);
        return $client->get('login/oidc/callback', ['query' => ['code' => $answer['code'], 'state' => $answer['state']]]);
    }

    /** The whole round trip: start, sign in at the IdP, callback */
    protected function signIn(HttpClient $client, string $subject, array $claims, array $query = []): HttpResponse
    {
        return $this->complete($client, $this->start($client, $query), $subject, $claims);
    }

    protected function assertFailurePage(HttpResponse $response, string $cause, int $status = 403): void
    {
        $this->assertSame($status, $response->status, $response->body);
        $this->assertNull($response->header('location'), 'no automatic redirect');
        $this->assertStringNotContainsString('http-equiv', $response->body);
        $this->assertStringContainsString('Sign-in was not permitted', $response->body);
        $this->assertStringContainsString('href="/login/oidc"', $response->body);
        $this->assertStringContainsString('href="/login?other"', $response->body);
        $this->assertNull($response->setCookie(OidcController::EMAIL_COOKIE), 'no cookie on failure');

        $this->assertMatchesRegularExpression('#<code id="correlationId">([0-9a-f]{16})</code>#', $response->body);
        preg_match('#<code id="correlationId">([0-9a-f]{16})</code>#', $response->body, $m);
        $failures = array_values(array_filter($this->server->logEntries('login_oidc_failure'), fn($e) => ($e['correlation_id'] ?? null) === $m[1]));
        $this->assertCount(1, $failures, 'the log entry has the page\'s correlation ID');
        $this->assertSame($cause, $failures[0]['cause'], $failures[0]['detail'] ?? '');
    }
}
