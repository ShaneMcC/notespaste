<?php

namespace App;

class Helpers
{
    public static function getMimeType(string $filePath): string
    {
        $mimeType = mime_content_type($filePath);
        return $mimeType !== false ? $mimeType : 'application/octet-stream';
    }

    public static function sanitizeFilename(string $filename): string
    {
        // Remove any path components
        $filename = basename($filename);

        // Replace potentially dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        return $filename;
    }

    /**
     * Check if content appears to be binary or text
     * Returns true if the content is likely text, false if binary
     */
    public static function isBinaryContent(string $content): bool
    {
        // Empty content is considered text
        if (empty($content)) {
            return false;
        }

        // Check for null bytes (strong indicator of binary)
        if (strpos($content, "\0") !== false) {
            return true;
        }

        // Sample first 8192 bytes for performance
        $sample = substr($content, 0, 8192);
        $sampleLength = strlen($sample);

        if ($sampleLength === 0) {
            return false;
        }

        // Count non-text characters
        $nonText = 0;
        for ($i = 0; $i < $sampleLength; $i++) {
            $ord = ord($sample[$i]);
            // Allow: tab (9), newline (10), carriage return (13), printable ASCII (32-126), common UTF-8 ranges
            if ($ord < 9 || ($ord > 13 && $ord < 32) || $ord === 127) {
                $nonText++;
            }
        }

        // If more than 30% non-text characters, consider it binary
        return ($nonText / $sampleLength) > 0.3;
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
