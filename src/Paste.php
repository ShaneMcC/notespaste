<?php

namespace App;

class Paste
{
    private static ?string $notesDir = null;
    private string $id;
    private string $basePath;
    private array $meta;

    public static function setNotesDir(string $dir): void
    {
        self::$notesDir = $dir;
    }

    private static function getNotesDir(): string
    {
        return self::$notesDir ?? __DIR__ . '/../public/notes';
    }

    public function __construct(string $id)
    {
        $this->id = $id;
        $this->basePath = self::getNotesDir() . '/' . $id;
        $this->loadMeta();
    }

    public static function generateId(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $length = rand(20, 30);
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $chars[rand(0, strlen($chars) - 1)];
        }

        return $id;
    }

    public static function exists(string $id): bool
    {
        $basePath = self::getNotesDir() . '/' . $id;
        return is_dir($basePath) && file_exists($basePath . '/_meta.json');
    }

    public static function listAll(bool $publicOnly = false): array
    {
        $notesDir = self::getNotesDir();
        $pastes = [];

        if (!is_dir($notesDir)) {
            return $pastes;
        }

        $dirs = scandir($notesDir);

        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === '.gitkeep') {
                continue;
            }

            $dirPath = $notesDir . '/' . $dir;

            if (is_dir($dirPath) && file_exists($dirPath . '/_meta.json')) {
                try {
                    $paste = new self($dir);
                    $meta = $paste->getMeta();

                    // Skip private pastes if publicOnly is true
                    if ($publicOnly && !($meta['public'] ?? false)) {
                        continue;
                    }

                    $pastes[] = [
                        'id' => $dir,
                        'paste' => $paste,
                        'title' => $meta['title'] ?? 'Untitled',
                        'summary' => $meta['summary'] ?? '',
                        'author' => $meta['author'] ?? 'Anonymous',
                        'public' => $meta['public'] ?? false,
                        'createdAt' => $meta['createdAt'] ?? null,
                        'updatedAt' => $meta['updatedAt'] ?? null,
                    ];
                } catch (\Exception $e) {
                    // Skip invalid pastes
                    continue;
                }
            }
        }

        // Sort by most recently updated first
        usort($pastes, function($a, $b) {
            $timeA = strtotime($a['updatedAt'] ?? $a['createdAt'] ?? '1970-01-01');
            $timeB = strtotime($b['updatedAt'] ?? $b['createdAt'] ?? '1970-01-01');
            return $timeB - $timeA;
        });

        return $pastes;
    }

    public static function create(array $data): self
    {
        $id = self::generateId();
        $paste = new self($id);

        if (!is_dir($paste->basePath)) {
            if (!@mkdir($paste->basePath, 0755, true)) {
                throw new \RuntimeException("Failed to create paste directory: {$paste->basePath}");
            }
            if (!@mkdir($paste->basePath . '/files', 0755, true)) {
                throw new \RuntimeException("Failed to create files directory");
            }
        }

        $paste->meta = [
            'title' => $data['title'] ?? 'Untitled',
            'description' => $data['description'] ?? '',
            'summary' => $data['summary'] ?? '',
            'author' => $data['author'] ?? 'Anonymous',
            'public' => $data['public'] ?? false,
            'displayMode' => $data['displayMode'] ?? 'multi-normal',
            'selectedFile' => $data['selectedFile'] ?? '',
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
            'files' => []
        ];

        $paste->saveMeta();
        return $paste;
    }

    private function loadMeta(): void
    {
        $metaFile = $this->basePath . '/_meta.json';

        if (file_exists($metaFile)) {
            $this->meta = json_decode(file_get_contents($metaFile), true) ?? [];
        } else {
            $this->meta = [];
        }
    }

    private function saveMeta(): void
    {
        $metaFile = $this->basePath . '/_meta.json';
        $result = @file_put_contents($metaFile, json_encode($this->meta, JSON_PRETTY_PRINT));

        if ($result === false) {
            throw new \RuntimeException("Failed to save metadata file: {$metaFile}");
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getTitle(): string
    {
        return $this->meta['title'] ?? 'Untitled';
    }

    public function getSlug(): string
    {
        $title = $this->getTitle();
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9\-_.]/', '_', $slug);
        return $slug;
    }

    public function getHtmlPath(): string
    {
        return $this->basePath . '/' . $this->getSlug() . '.html';
    }

    public function getHtmlUrl(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : '';
        return $basePath . '/notes/' . $this->id . '/' . $this->getSlug() . '.html';
    }

    public function update(array $data): void
    {
        $this->meta['title'] = $data['title'] ?? $this->meta['title'];
        $this->meta['description'] = $data['description'] ?? $this->meta['description'];
        $this->meta['summary'] = $data['summary'] ?? $this->meta['summary'] ?? '';
        $this->meta['author'] = $data['author'] ?? $this->meta['author'];
        $this->meta['public'] = $data['public'] ?? $this->meta['public'] ?? false;
        $this->meta['displayMode'] = $data['displayMode'] ?? $this->meta['displayMode'] ?? 'multi-normal';
        $this->meta['selectedFile'] = $data['selectedFile'] ?? $this->meta['selectedFile'] ?? '';
        $this->meta['updatedAt'] = date('c');

        $this->saveMeta();
    }

    public function isPublic(): bool
    {
        return $this->meta['public'] ?? false;
    }

    public function addFile(string $filename, string $content, array $fileMeta): void
    {
        $filePath = $this->basePath . '/files/' . $filename;
        $result = @file_put_contents($filePath, $content);

        if ($result === false) {
            throw new \RuntimeException("Failed to save file: {$filePath}");
        }

        $this->meta['files'][$filename] = $fileMeta;
        $this->meta['updatedAt'] = date('c');
        $this->saveMeta();
    }

    public function updateFile(string $filename, ?string $content, array $fileMeta): void
    {
        if ($content !== null) {
            $filePath = $this->basePath . '/files/' . $filename;
            $result = @file_put_contents($filePath, $content);

            if ($result === false) {
                throw new \RuntimeException("Failed to update file: {$filePath}");
            }
        }

        $this->meta['files'][$filename] = $fileMeta;
        $this->meta['updatedAt'] = date('c');
        $this->saveMeta();
    }

    public function removeFile(string $filename): void
    {
        $filePath = $this->basePath . '/files/' . $filename;

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        unset($this->meta['files'][$filename]);
        $this->meta['updatedAt'] = date('c');
        $this->saveMeta();
    }

    public function reorderFiles(array $orderedFilenames): void
    {
        $newFilesArray = [];

        // Rebuild files array in the specified order
        foreach ($orderedFilenames as $filename) {
            if (isset($this->meta['files'][$filename])) {
                $newFilesArray[$filename] = $this->meta['files'][$filename];
            }
        }

        $this->meta['files'] = $newFilesArray;
        $this->meta['updatedAt'] = date('c');
        $this->saveMeta();
    }

    public function getFile(string $filename): ?string
    {
        $filePath = $this->basePath . '/files/' . $filename;

        if (file_exists($filePath)) {
            $fileMeta = $this->meta['files'][$filename] ?? [];
            $renderMode = $fileMeta['render'] ?? '';

            // For image mode, never load content (always binary)
            if ($renderMode === 'image') {
                return '';
            }

            // For file and file-link modes, check if content is binary
            if ($renderMode === 'file' || $renderMode === 'file-link') {
                $content = file_get_contents($filePath);
                // Only return empty if it's actually binary
                if (Helpers::isBinaryContent($content)) {
                    return '';
                }
                return $content;
            }

            return file_get_contents($filePath);
        }

        return null;
    }

    public function isFileBinary(string $filename): bool
    {
        $filePath = $this->basePath . '/files/' . $filename;

        if (!file_exists($filePath)) {
            return false;
        }

        $fileMeta = $this->meta['files'][$filename] ?? [];
        $renderMode = $fileMeta['render'] ?? '';

        // Images are always binary
        if ($renderMode === 'image') {
            return true;
        }

        // For file/file-link modes, check actual content
        if ($renderMode === 'file' || $renderMode === 'file-link') {
            $content = file_get_contents($filePath);
            return Helpers::isBinaryContent($content);
        }

        return false;
    }

    public function getFilePath(string $filename): ?string
    {
        $filePath = $this->basePath . '/files/' . $filename;

        if (file_exists($filePath)) {
            return $filePath;
        }

        return null;
    }

    public function getFiles(): array
    {
        return $this->meta['files'] ?? [];
    }

    public function render(): string
    {
        $renderer = new PasteRenderer($this);
        $html = $renderer->render();

        $htmlPath = $this->getHtmlPath();
        $result = @file_put_contents($htmlPath, $html);

        if ($result === false) {
            throw new \RuntimeException("Failed to write HTML file to: {$htmlPath}");
        }

        return $html;
    }
}
