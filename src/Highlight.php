<?php

namespace App;

/**
 * Settings for the self-hosted highlight.js in public/static/highlight/.
 *
 * Those files are built by scripts/update-highlight.sh, which also keeps
 * VERSION in step with them. The version is used as a cache-busting query
 * string, since the files are served with a long-lived cache header.
 *
 * Themes are paths under styles/ without the extension, e.g. "github-dark"
 * or "base16/dracula". "base16-dracula" is accepted for the latter too.
 */
class Highlight
{
    public const VERSION = '11.12.0';
    public const DEFAULT_THEME = 'github-dark';

    // Offered in the edit form unless the config supplies its own list
    public const DEFAULT_THEMES = [
        'github-dark',
        'base16/dracula',
        'base16/apprentice',
        'base16/ashes',
        'base16/bright',
        'base16/chalk',
        'base16/colors',
        'base16/eva',
        'base16/gigavolt',
        'base16/porple',
        'base16/qualia',
        'base16/seti-ui',
        'base16/snazzy',
        'base16/tender',
        'synth-midnight-terminal-dark',
        'devibeans',
        'hybrid',
        'monokai',
    ];

    private const STYLES_DIR = __DIR__ . '/../public/static/highlight/styles';

    private static string $theme = self::DEFAULT_THEME;

    /** @var array<string, string> theme => label */
    private static array $themes = [];

    /**
     * Set the site-wide default theme (empty means DEFAULT_THEME) and the themes
     * offered per paste (empty means DEFAULT_THEMES). Throws on any unknown theme.
     */
    public static function init(string $theme, array $themes = []): void
    {
        $theme = self::normalize($theme ?: self::DEFAULT_THEME);
        $themes = array_map(self::normalize(...), $themes);

        foreach ([$theme, ...$themes] as $candidate) {
            if (!self::isValidTheme($candidate)) {
                throw new \RuntimeException(
                    "Unknown highlight theme '$candidate' - it must match a file in public/static/highlight/styles/"
                );
            }
        }

        self::$theme = $theme;

        // The default is always on offer, even if the list leaves it out
        $offered = array_unique([$theme, ...($themes ?: self::DEFAULT_THEMES)]);
        self::$themes = [];
        foreach ($offered as $candidate) {
            self::$themes[$candidate] = self::label($candidate);
        }
        asort(self::$themes, SORT_NATURAL | SORT_FLAG_CASE);
    }

    public static function getTheme(): string
    {
        return self::$theme;
    }

    /**
     * @return array<string, string> theme => label, sorted by label
     */
    public static function getThemes(): array
    {
        return self::$themes;
    }

    /**
     * Map "base16-name" to "base16/name", the way highlight.js's own demo names them.
     */
    public static function normalize(string $theme): string
    {
        if (str_starts_with($theme, 'base16-') && !self::isValidTheme($theme)) {
            return 'base16/' . substr($theme, strlen('base16-'));
        }

        return $theme;
    }

    public static function isValidTheme(string $theme): bool
    {
        return preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)?$#', $theme)
            && is_file(self::STYLES_DIR . "/$theme.min.css");
    }

    /**
     * The theme to render a paste with: its own if still valid, otherwise the site default.
     */
    public static function resolve(string $theme): string
    {
        $theme = self::normalize($theme);

        return $theme !== '' && self::isValidTheme($theme) ? $theme : self::$theme;
    }

    /**
     * Clean up a submitted per-paste theme. Empty means "use the site default".
     *
     * Any existing theme is accepted, not just the offered ones, so a paste keeps
     * its theme if the list is later trimmed.
     */
    public static function sanitize(string $theme): string
    {
        $theme = self::normalize($theme);

        return self::isValidTheme($theme) ? $theme : '';
    }

    public static function label(string $theme): string
    {
        $label = ucwords(str_replace('-', ' ', basename($theme)));

        return str_starts_with($theme, 'base16/') ? "$label (base16)" : $label;
    }
}
