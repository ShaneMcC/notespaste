<?php

namespace App;

class TwigFactory
{
    private static ?\Twig\Environment $instance = null;
    private static string $templatesDir = __DIR__ . '/../templates';

    public static function create(?string $basePath = null): \Twig\Environment
    {
        if (self::$instance === null) {
            $loader = new \Twig\Loader\FilesystemLoader(self::$templatesDir);
            self::$instance = new \Twig\Environment($loader);
        }

        // Always update basePath if provided (it may change between requests in testing)
        if ($basePath !== null) {
            self::$instance->addGlobal('basePath', $basePath);
        }

        return self::$instance;
    }

    public static function getInstance(): \Twig\Environment
    {
        if (self::$instance === null) {
            return self::create();
        }

        return self::$instance;
    }

    public static function setTemplatesDir(string $dir): void
    {
        self::$templatesDir = $dir;
        // Reset instance so it gets recreated with new dir
        self::$instance = null;
    }
}
