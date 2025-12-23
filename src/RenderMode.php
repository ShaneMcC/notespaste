<?php

namespace App;

enum RenderMode: string
{
    case PLAIN = 'plain';
    case HIGHLIGHTED = 'highlighted';
    case RENDERED = 'rendered';
    case IMAGE = 'image';
    case FILE = 'file';
    case FILE_LINK = 'file-link';
    case LINK = 'link';

    /**
     * Get human-readable label for the render mode
     */
    public function label(): string
    {
        return match($this) {
            self::PLAIN => 'Plain',
            self::HIGHLIGHTED => 'Highlighted',
            self::RENDERED => 'Rendered Markdown',
            self::IMAGE => 'Image',
            self::FILE => 'File Download',
            self::FILE_LINK => 'File Link',
            self::LINK => 'List of Links',
        };
    }

    /**
     * Check if this mode hides the type selector
     */
    public function hidesTypeSelector(): bool
    {
        return match($this) {
            self::IMAGE, self::FILE, self::FILE_LINK, self::LINK, self::RENDERED => true,
            default => false,
        };
    }

    /**
     * Check if this mode hides the content field (for binary files)
     */
    public function hidesContentField(): bool
    {
        return match($this) {
            self::IMAGE, self::FILE, self::FILE_LINK => true,
            default => false,
        };
    }

    /**
     * Check if this mode shows line numbers option
     */
    public function showsLineNumbers(): bool
    {
        return $this === self::HIGHLIGHTED;
    }

    /**
     * Get the type value to auto-set for this render mode
     */
    public function autoType(): ?string
    {
        return match($this) {
            self::RENDERED => 'markdown',
            self::IMAGE => 'image',
            self::FILE => 'file',
            self::FILE_LINK => 'file-link',
            self::LINK => 'link',
            default => null,
        };
    }

    /**
     * Get all render modes as options for select elements
     * @return array<string, string> [value => label]
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }

    /**
     * Get all render mode values
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid render mode
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
