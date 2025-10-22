<?php

namespace App;

class Helpers
{
    public static function getMimeType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $mimeTypes = [
            // Text
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',

            // Programming languages
            'php' => 'text/x-php',
            'py' => 'text/x-python',
            'rb' => 'text/x-ruby',
            'java' => 'text/x-java',
            'c' => 'text/x-c',
            'cpp' => 'text/x-c++',
            'sh' => 'text/x-shellscript',
            'bash' => 'text/x-shellscript',

            // Images
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',

            // Documents
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    public static function sanitizeFilename(string $filename): string
    {
        // Remove any path components
        $filename = basename($filename);

        // Replace potentially dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        return $filename;
    }

    public static function redirect(string $url, int $code = 302): void
    {
        http_response_code($code);
        header('Location: ' . $url);
        exit;
    }

    public static function show404(): void
    {
        http_response_code(404);
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
        $twig = new \Twig\Environment($loader);

        $basePath = defined('BASE_PATH') ? BASE_PATH : '';
        $twig->addGlobal('basePath', $basePath);

        // Only show login link if on the root path
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $isRootPath = ($requestUri === '/' || $requestUri === $basePath || $requestUri === $basePath . '/');

        echo $twig->render('404.html.twig', [
            'isLoggedIn' => Auth::isLoggedIn(),
            'showLoginLink' => $isRootPath
        ]);
        exit;
    }

    public static function show500(): void
    {
        http_response_code(500);
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
        $twig = new \Twig\Environment($loader);

        $basePath = defined('BASE_PATH') ? BASE_PATH : '';
        $twig->addGlobal('basePath', $basePath);

        echo $twig->render('500.html.twig');
        exit;
    }
}
