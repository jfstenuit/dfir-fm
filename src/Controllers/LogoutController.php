<?php
// src/Controllers/LogoutController.php
namespace Controllers;

use Core\Request;
use Core\Session;
use Core\App;
use Services\Oidc\OidcConfig;
use Services\Oidc\OidcException;
use Services\Oidc\OidcProvider;

class LogoutController
{
    public static function handle($config, $db)
    {
        if (Session::isOidc()) {
            self::oidcLogout($config);
            return;
        }

        App::getLogger()->log('logout');

        Session::logout();

        $redirectUrl = Request::getBasePath() . '/';

        header("Location: $redirectUrl");
        exit;
    }

    /**
     * A session opened through the IdP (#9): the local session ends, then
     * the IdP's (RP-initiated logout), which comes back to
     * /login?signed_out. Otherwise the IdP session would sign the user
     * straight back in. Without an end_session_endpoint (or with the IdP
     * unreachable), only the local session ends. The remembered e-mail
     * cookie is kept.
     */
    private static function oidcLogout(array $config): void
    {
        $idToken = $_SESSION['oidc_id_token'] ?? null;
        $oidc = OidcConfig::fromConfig($config);
        $signedOut = self::baseUrl($oidc) . '/login?signed_out';

        $endpoint = null;
        if ($oidc->isEnabled() && is_string($idToken)) {
            try {
                $endpoint = OidcProvider::create($oidc)->metadata()['end_session_endpoint'] ?? null;
            } catch (OidcException $e) {
                error_log('DFIR FM: OIDC logout without the IdP: ' . $e->getMessage());
            }
            if (!is_string($endpoint) || strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
                $endpoint = null;
            }
        }

        App::getLogger()->log('logout_oidc', ['idp_logout' => $endpoint !== null]);
        Session::logout();

        if ($endpoint === null) {
            header('Location: ' . Request::getBasePath() . '/login?signed_out');
            exit;
        }
        $query = http_build_query([
            'id_token_hint' => $idToken,
            'client_id' => $oidc->clientId(),
            'post_logout_redirect_uri' => $signedOut,
        ], '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query);
        exit;
    }

    /**
     * The application's public URL, from oidc_redirect_uri (registered at
     * the IdP, right behind a proxy), else from the request
     */
    private static function baseUrl(OidcConfig $oidc): string
    {
        $suffix = '/login/oidc/callback';
        if ($oidc->isEnabled() && str_ends_with($oidc->redirectUri(), $suffix)) {
            return substr($oidc->redirectUri(), 0, -strlen($suffix));
        }
        return Request::getBaseUrl();
    }
}
?>
