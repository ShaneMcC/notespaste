<?php

namespace App;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * OpenID Connect login using the authorization code flow (with PKCE where the
 * provider supports it).
 *
 * The feature is configured entirely through environment variables and stays
 * switched off unless all of OIDC_ISSUER, OIDC_CLIENTID, OIDC_SECRET and
 * OIDC_NAME are set - see config/config.php.
 */
class Oidc
{
    /** Signature algorithms we accept on an id_token - asymmetric only, never HMAC or "none". */
    private const ALLOWED_ALGS = ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384', 'ES256K', 'EdDSA'];

    /** "alg" is optional on a JWK; RS256 is the OIDC default for keys that omit it. */
    private const DEFAULT_JWK_ALG = 'RS256';

    private const SCOPE = 'openid profile email';
    private const CALLBACK_PATH = '/login/oidc/callback';
    private const WELL_KNOWN = '/.well-known/openid-configuration';

    private const HTTP_TIMEOUT = 10;
    private const DISCOVERY_TTL = 3600;
    private const CLOCK_LEEWAY = 60;
    private const MAX_USERNAME_LENGTH = 128;

    private const SESSION_KEY = 'oidc';

    /** Claims to try, in order, when working out what to call the user. */
    private const USERNAME_CLAIMS = ['preferred_username', 'email', 'name', 'sub'];

    private static array $config = [
        'issuer' => '',
        'client_id' => '',
        'client_secret' => '',
        'name' => '',
        'redirect_uri' => '',
    ];

    public static function init(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * OIDC is only offered when every required setting is present.
     */
    public static function isConfigured(): bool
    {
        foreach (['issuer', 'client_id', 'client_secret', 'name'] as $key) {
            if (trim((string)self::$config[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    /** Display name of the provider, used for the "Login with ..." button. */
    public static function getName(): string
    {
        return trim((string)self::$config['name']);
    }

    /**
     * Build the provider URL to send the user to, remembering the state, nonce
     * and PKCE verifier we need to check when they come back.
     */
    public static function getAuthorizationUrl(): string
    {
        $discovery = self::discover();

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = self::base64UrlEncode(random_bytes(32));

        $params = [
            'response_type' => 'code',
            'client_id' => self::$config['client_id'],
            'redirect_uri' => self::getRedirectUri(),
            'scope' => self::SCOPE,
            'state' => $state,
            'nonce' => $nonce,
        ];

        // Only send a challenge if the provider says it understands one.
        if (in_array('S256', $discovery['code_challenge_methods_supported'] ?? [], true)) {
            $params['code_challenge'] = self::base64UrlEncode(hash('sha256', $verifier, true));
            $params['code_challenge_method'] = 'S256';
        } else {
            $verifier = null;
        }

        self::sessionSet('pending', [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
        ]);

        $endpoint = $discovery['authorization_endpoint'];
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint . $separator . http_build_query($params);
    }

    /**
     * Handle the provider's redirect back to us and return the username to log in.
     *
     * @param array<string, mixed> $query Query string parameters ($_GET)
     * @throws \RuntimeException if anything about the response fails to check out
     */
    public static function handleCallback(array $query): string
    {
        $pending = self::sessionGet('pending');

        // Whatever happens from here the login attempt is spent.
        self::sessionUnset('pending');

        if (isset($query['error'])) {
            throw new \RuntimeException(sprintf(
                'Provider returned error "%s": %s',
                (string)$query['error'],
                (string)($query['error_description'] ?? 'no description given')
            ));
        }

        if (!is_array($pending)) {
            throw new \RuntimeException('No login in progress - the session probably expired');
        }

        $state = (string)($query['state'] ?? '');
        if ($state === '' || !hash_equals($pending['state'], $state)) {
            throw new \RuntimeException('State mismatch - stale login attempt or CSRF');
        }

        $code = (string)($query['code'] ?? '');
        if ($code === '') {
            throw new \RuntimeException('Provider did not return an authorization code');
        }

        $tokens = self::exchangeCode($code, $pending['verifier']);

        if (empty($tokens['id_token']) || !is_string($tokens['id_token'])) {
            throw new \RuntimeException('Token response did not contain an id_token');
        }

        $claims = self::verifyIdToken($tokens['id_token'], $pending['nonce']);

        // An id_token only has to carry "sub", so ask userinfo for something friendlier.
        if (!isset($claims['preferred_username']) && !isset($claims['email']) && !empty($tokens['access_token'])) {
            $claims = array_merge($claims, self::fetchUserInfo((string)$tokens['access_token'], $claims['sub']));
        }

        return self::resolveUsername($claims);
    }

    /**
     * Absolute URL the provider redirects back to. Honours OIDC_REDIRECT_URI when
     * set, otherwise it is derived from the request (including proxy headers).
     */
    public static function getRedirectUri(): string
    {
        $override = trim((string)self::$config['redirect_uri']);
        if ($override !== '') {
            return $override;
        }

        $https = ($_SERVER['HTTPS'] ?? 'off') !== 'off';
        $scheme = self::firstForwardedValue('HTTP_X_FORWARDED_PROTO') ?? ($https ? 'https' : 'http');
        $host = self::firstForwardedValue('HTTP_X_FORWARDED_HOST') ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $basePath = defined('BASE_PATH') ? BASE_PATH : '';

        return $scheme . '://' . $host . $basePath . self::CALLBACK_PATH;
    }

    /**
     * Swap the authorization code for tokens over the back channel.
     *
     * @return array<string, mixed>
     */
    private static function exchangeCode(string $code, ?string $verifier): array
    {
        $discovery = self::discover();

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::getRedirectUri(),
        ];

        if ($verifier !== null) {
            $params['code_verifier'] = $verifier;
        }

        $headers = [];
        $authMethods = $discovery['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];

        if (in_array('client_secret_basic', $authMethods, true)) {
            // RFC 6749 2.3.1 - form-encode the credentials before base64.
            $headers[] = 'Authorization: Basic ' . base64_encode(
                rawurlencode((string)self::$config['client_id']) . ':' .
                rawurlencode((string)self::$config['client_secret'])
            );
        } else {
            $params['client_id'] = self::$config['client_id'];
            $params['client_secret'] = self::$config['client_secret'];
        }

        return self::decodeJson(self::request($discovery['token_endpoint'], $params, $headers));
    }

    /**
     * Check the id_token signature and every claim we rely on.
     *
     * @return array<string, mixed> The validated claims
     */
    private static function verifyIdToken(string $idToken, string $nonce): array
    {
        $discovery = self::discover();

        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new \RuntimeException('id_token is not a well formed JWT');
        }

        // Pin the algorithm before we hand the token to the JWT library, so a
        // forged header can never talk us into an HMAC or unsigned token.
        $header = self::decodeJson(JWT::urlsafeB64Decode($segments[0]));
        $alg = (string)($header['alg'] ?? '');
        if (!in_array($alg, self::ALLOWED_ALGS, true)) {
            throw new \RuntimeException('id_token uses unsupported signing algorithm "' . $alg . '"');
        }

        $keys = JWK::parseKeySet(self::decodeJson(self::request($discovery['jwks_uri'])), self::DEFAULT_JWK_ALG);

        // Covers the signature plus the exp/nbf/iat checks.
        JWT::$leeway = self::CLOCK_LEEWAY;
        $claims = (array)JWT::decode($idToken, $keys);

        if (rtrim((string)($claims['iss'] ?? ''), '/') !== rtrim($discovery['issuer'], '/')) {
            throw new \RuntimeException('id_token issuer does not match the provider');
        }

        $audience = $claims['aud'] ?? [];
        $audience = is_array($audience) ? $audience : [$audience];
        if (!in_array(self::$config['client_id'], $audience, true)) {
            throw new \RuntimeException('id_token is not addressed to this client');
        }

        // With more than one audience the spec requires azp to name us.
        if (count($audience) > 1 && ($claims['azp'] ?? null) !== self::$config['client_id']) {
            throw new \RuntimeException('id_token azp does not name this client');
        }

        if (!isset($claims['nonce']) || !hash_equals($nonce, (string)$claims['nonce'])) {
            throw new \RuntimeException('id_token nonce mismatch - possible replay');
        }

        if (empty($claims['sub']) || !is_string($claims['sub'])) {
            throw new \RuntimeException('id_token has no usable "sub" claim');
        }

        return $claims;
    }

    /**
     * Pull extra profile claims from the userinfo endpoint. A failure here is not
     * fatal - we fall back to whatever the id_token gave us.
     *
     * @return array<string, mixed>
     */
    private static function fetchUserInfo(string $accessToken, string $sub): array
    {
        $discovery = self::discover();

        if (empty($discovery['userinfo_endpoint'])) {
            return [];
        }

        try {
            $info = self::decodeJson(self::request(
                $discovery['userinfo_endpoint'],
                null,
                ['Authorization: Bearer ' . $accessToken]
            ));
        } catch (\Exception $e) {
            error_log('OIDC: userinfo lookup failed, using id_token claims instead: ' . $e->getMessage());
            return [];
        }

        // OIDC Core 5.3.2 - the subject must match the one we just authenticated.
        if (($info['sub'] ?? null) !== $sub) {
            throw new \RuntimeException('userinfo "sub" does not match the id_token');
        }

        return $info;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function resolveUsername(array $claims): string
    {
        foreach (self::USERNAME_CLAIMS as $claim) {
            if (!isset($claims[$claim]) || !is_string($claims[$claim])) {
                continue;
            }

            $username = self::sanitizeUsername($claims[$claim]);
            if ($username !== '') {
                return $username;
            }
        }

        throw new \RuntimeException('No usable username claim in the OIDC response');
    }

    /**
     * Usernames end up in session data, paste metadata and pages, so keep them
     * to printable, sanely sized UTF-8.
     */
    private static function sanitizeUsername(string $username): string
    {
        $username = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $username));

        if ($username === '' || !mb_check_encoding($username, 'UTF-8')) {
            return '';
        }

        return mb_substr($username, 0, self::MAX_USERNAME_LENGTH);
    }

    /**
     * Fetch (and cache for the session) the provider's discovery document.
     *
     * @return array<string, mixed>
     */
    private static function discover(): array
    {
        $issuer = self::getIssuer();
        $cached = self::sessionGet('discovery');

        if (is_array($cached)
            && ($cached['issuer'] ?? null) === $issuer
            && (time() - (int)($cached['fetchedAt'] ?? 0)) < self::DISCOVERY_TTL
        ) {
            return $cached['data'];
        }

        $discovery = self::decodeJson(self::request($issuer . self::WELL_KNOWN));

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
            if (empty($discovery[$required]) || !is_string($discovery[$required])) {
                throw new \RuntimeException('Discovery document is missing "' . $required . '"');
            }
        }

        if (rtrim($discovery['issuer'], '/') !== $issuer) {
            throw new \RuntimeException(sprintf(
                'Discovery issuer "%s" does not match OIDC_ISSUER "%s"',
                $discovery['issuer'],
                $issuer
            ));
        }

        self::sessionSet('discovery', [
            'issuer' => $issuer,
            'fetchedAt' => time(),
            'data' => $discovery,
        ]);

        return $discovery;
    }

    /**
     * Configured issuer, normalised. Tolerates someone pasting the full
     * .well-known URL into OIDC_ISSUER.
     */
    private static function getIssuer(): string
    {
        $issuer = rtrim(trim((string)self::$config['issuer']), '/');

        if (str_ends_with($issuer, self::WELL_KNOWN)) {
            $issuer = substr($issuer, 0, -strlen(self::WELL_KNOWN));
        }

        return $issuer;
    }

    /**
     * @param array<string, mixed>|null $postFields Form-encoded as a POST body when given
     * @param list<string> $headers
     */
    private static function request(string $url, ?array $postFields = null, array $headers = []): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Refusing to contact non-HTTP(S) URL "' . $url . '"');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);

        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        }

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Request to ' . $url . ' failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'Request to %s returned HTTP %d: %s',
                $url,
                $status,
                substr($body, 0, 200)
            ));
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Expected a JSON object, got: ' . substr($json, 0, 200));
        }

        return $decoded;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Proxy headers may carry a comma separated list - the first entry is the client.
     */
    private static function firstForwardedValue(string $serverKey): ?string
    {
        $value = trim((string)($_SERVER[$serverKey] ?? ''));

        if ($value === '') {
            return null;
        }

        return trim(explode(',', $value)[0]);
    }

    private static function sessionGet(string $key): mixed
    {
        return $_SESSION[self::SESSION_KEY][$key] ?? null;
    }

    private static function sessionSet(string $key, mixed $value): void
    {
        $_SESSION[self::SESSION_KEY][$key] = $value;
    }

    private static function sessionUnset(string $key): void
    {
        unset($_SESSION[self::SESSION_KEY][$key]);
    }
}
