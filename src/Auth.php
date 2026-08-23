<?php

namespace App;

class Auth
{
    public const SOURCE_LOCAL = 'local';
    public const SOURCE_OIDC = 'oidc';

    private static ?string $currentUser = null;
    private static ?string $htpasswdPath = null;

    public static function init(string $htpasswdPath = null): void
    {
        if ($htpasswdPath !== null) {
            self::$htpasswdPath = $htpasswdPath;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['username'])) {
            self::$currentUser = $_SESSION['username'];
        }
    }

    public static function authenticate(string $username, string $password): bool
    {
        // First, check environment variable authentication
        $envUser = getenv('AUTH_USER');
        $envPasswordHash = getenv('AUTH_PASSWORD_HASH');
        $envPassword = getenv('AUTH_PASSWORD');

        if ($envUser && $username === $envUser) {
            // Check if AUTH_PASSWORD_HASH is set (takes priority)
            if ($envPasswordHash) {
                if (password_verify($password, $envPasswordHash)) {
                    self::loginAs($username);
                    return true;
                }
            }
            // Otherwise check AUTH_PASSWORD (plain text)
            elseif ($envPassword) {
                if ($password === $envPassword) {
                    self::loginAs($username);
                    return true;
                }
            }
        }

        // Then check .htpasswd file
        foreach (self::htpasswdEntries() as [$storedUser, $storedHash]) {
            if ($storedUser === $username && password_verify($password, $storedHash)) {
                self::loginAs($username);
                return true;
            }
        }

        return false;
    }

    /**
     * Start a logged in session for a user that has already been authenticated
     * (by a password check, or by an identity provider).
     */
    public static function loginAs(string $username, string $source = self::SOURCE_LOCAL): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_regenerate_id(true);

        self::$currentUser = $username;
        $_SESSION['username'] = $username;
        $_SESSION['auth_source'] = $source;
    }

    /**
     * Whether username/password logins are possible at all - either the AUTH_*
     * environment variables are set, or the .htpasswd file has entries in it.
     */
    public static function hasLocalLogin(): bool
    {
        if (getenv('AUTH_USER') && (getenv('AUTH_PASSWORD_HASH') || getenv('AUTH_PASSWORD'))) {
            return true;
        }

        return self::htpasswdEntries() !== [];
    }

    /**
     * Parse the .htpasswd file into [username, hash] pairs, skipping comments
     * and anything that isn't a well formed entry.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function htpasswdEntries(): array
    {
        $htpasswdFile = self::$htpasswdPath ?? __DIR__ . '/../config/.htpasswd';

        if (!is_readable($htpasswdFile) || !is_file($htpasswdFile)) {
            return [];
        }

        $lines = file($htpasswdFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }

            $entries[] = $parts;
        }

        return $entries;
    }

    public static function logout(): void
    {
        self::$currentUser = null;
        unset($_SESSION['username'], $_SESSION['auth_source']);
        session_destroy();
    }

    public static function isLoggedIn(): bool
    {
        return self::$currentUser !== null;
    }

    public static function getCurrentUser(): ?string
    {
        return self::$currentUser;
    }

    /** How the current user logged in - see the SOURCE_* constants. */
    public static function getAuthSource(): ?string
    {
        return $_SESSION['auth_source'] ?? null;
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            http_response_code(401);
            $basePath = defined('BASE_PATH') ? BASE_PATH : '';
            header('Location: ' . $basePath . '/login');
            exit;
        }
    }
}
