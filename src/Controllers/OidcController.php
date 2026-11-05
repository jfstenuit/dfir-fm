<?php
// src/Controllers/OidcController.php
namespace Controllers;

use Core\App;
use Core\Request;
use Core\Response;
use Core\Session;
use Middleware\SecurityMiddleware;
use Services\Oidc\AccountResolver;
use Services\Oidc\Claims;
use Services\Oidc\CodeFlow;
use Services\Oidc\OidcConfig;
use Services\Oidc\OidcException;
use Services\Oidc\OidcProvider;
use Services\Oidc\OidcRefusal;
use Views\OidcFailureView;

use PDO;

/**
 * GET /login/oidc and GET /login/oidc/callback (#9, D31, D32): the sign-in
 * through the IdP. Both are a 404 while OIDC is disabled. The callback is
 * a GET, outside the CSRF check: its state is its CSRF protection.
 */
class OidcController
{
    /** The remembered login e-mail (D32): the address only, set after a successful OIDC login */
    public const EMAIL_COOKIE = 'dfirfm_login_email';

    public static function handle(array $config, PDO $db, string $route): void
    {
        $oidc = OidcConfig::fromConfig($config);
        if (!$oidc->isEnabled()) {
            Response::triggerNotFound();
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            header('Allow: GET');
            echo 'Method Not Allowed';
            return;
        }
        $flow = new CodeFlow($oidc, OidcProvider::create($oidc));
        if ($route === '/login/oidc') {
            self::start($oidc, $flow);
        } else {
            self::callback($oidc, $flow, $db);
        }
    }

    private static function start(OidcConfig $oidc, CodeFlow $flow): void
    {
        // Per client IP, with checkUser's limits (twice the password
        // step's): every sign-in costs the IdP a round trip
        $limits = SecurityMiddleware::loginThrottleSettings(App::getConfig());
        $ipKey = 'login_oidc_ip_' . hash('sha256', (string)Request::getClientIp());
        if (!SecurityMiddleware::throttle($ipKey, 2 * $limits['per_ip'], $limits['window'])) {
            App::getLogger()->log('login_oidc_failure', ['cause' => 'throttled', 'limit' => 'ip']);
            http_response_code(429);
            header('Retry-After: ' . $limits['window']);
            echo 'Too many sign-in attempts. Try again later.';
            return;
        }

        // Only an in-domain address goes to the IdP; anything else is dropped
        $hint = is_string($_GET['login_hint'] ?? null) ? trim($_GET['login_hint']) : '';
        $hint = $oidc->inDomain($hint) && filter_var($hint, FILTER_VALIDATE_EMAIL) !== false ? $hint : null;
        $redirect = LoginController::sanitizeRedirect(is_string($_GET['redirect'] ?? null) ? $_GET['redirect'] : '');

        try {
            [$url, $pending] = $flow->start($hint);
        } catch (OidcException $e) {
            self::fail($oidc, 'provider', $e->getMessage());
            return;
        }
        $store = $_SESSION['oidc_pending'] ?? [];
        CodeFlow::remember($store, $pending, $redirect);
        $_SESSION['oidc_pending'] = $store;

        App::getLogger()->log('login_oidc_start', ['login_hint' => $hint]);
        header("Location: $url", true, 302);
    }

    private static function callback(OidcConfig $oidc, CodeFlow $flow, PDO $db): void
    {
        $store = $_SESSION['oidc_pending'] ?? [];
        $pending = CodeFlow::take($store, $_GET['state'] ?? null);
        $_SESSION['oidc_pending'] = $store;

        try {
            if ($pending === null) {
                throw new OidcRefusal('state', 'No sign-in started in this session matches the state (missing, expired or already used)');
            }
            [$claims, $idToken] = $flow->finish($pending, $_GET);
            $user = (new AccountResolver($db, $oidc, App::getLogger()))->resolve($claims);
        } catch (OidcRefusal $e) {
            self::fail($oidc, $e->cause, $e->getMessage());
            return;
        } catch (OidcException $e) {
            self::fail($oidc, 'provider', $e->getMessage());
            return;
        }

        // The other started sign-ins are moot once one has succeeded
        unset($_SESSION['oidc_pending']);
        Session::authenticate(['user_id' => $user['id'], 'username' => $user['username'], 'session_version' => $user['session_version']]);
        $_SESSION['auth_method'] = 'oidc';
        $_SESSION['oidc_id_token'] = $idToken;
        self::rememberEmail($oidc, $claims->email($oidc->emailClaim()));

        App::getLogger()->log('login_oidc_success', ['iss' => $claims->issuer(), 'sub' => $claims->subject(), 'amr' => self::amr($claims)]);
        header('Location: ' . $pending['redirect'], true, 302);
    }

    /**
     * The generic failure page, which never redirects by itself. The
     * cause and detail go to the log only, under the correlation ID the
     * page shows.
     */
    private static function fail(OidcConfig $oidc, string $cause, string $detail): void
    {
        $correlationId = bin2hex(random_bytes(8));
        App::getLogger()->log('login_oidc_failure', ['cause' => $cause, 'correlation_id' => $correlationId, 'detail' => $detail]);
        http_response_code($cause === 'provider' ? 502 : 403);
        OidcFailureView::render($correlationId, $oidc->displayName());
    }

    /** Secure under the session cookie's rule; HttpOnly; Lax, so the IdP's redirect back carries it */
    private static function rememberEmail(OidcConfig $oidc, string $email): void
    {
        setcookie(self::EMAIL_COOKIE, $email, [
            'expires' => time() + $oidc->rememberDays() * 86400,
            'path' => Request::getBasePath() . '/',
            'secure' => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** amr for the log: a list of strings, or null */
    private static function amr(Claims $claims): ?array
    {
        $amr = $claims->get('amr');
        return is_array($amr) && array_is_list($amr) ? array_values(array_filter($amr, 'is_string')) : null;
    }
}
