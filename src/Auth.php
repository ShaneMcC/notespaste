<?php

namespace App;

class Auth
{
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
        $htpasswdFile = self::$htpasswdPath ?? __DIR__ . '/../config/.htpasswd';

        if (!file_exists($htpasswdFile)) {
            return false;
        }

        $lines = file($htpasswdFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$storedUser, $storedHash] = $parts;

            if ($storedUser === $username) {
                if (password_verify($password, $storedHash)) {
                    self::$currentUser = $username;
                    $_SESSION['username'] = $username;
                    return true;
                }
            }
        }

        return false;
    }

    public static function logout(): void
    {
        self::$currentUser = null;
        unset($_SESSION['username']);
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
