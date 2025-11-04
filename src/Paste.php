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

        // Retry up to 10 times to find a non-conflicting ID
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $id = '';
            for ($i = 0; $i < $length; $i++) {
                $id .= $chars[rand(0, strlen($chars) - 1)];
            }

            // Check if ID is available (not a paste ID or alias)
            if (!self::idExists($id)) {
                return $id;
            }
        }

        throw new \RuntimeException("Failed to generate unique ID after 10 attempts");
    }

    /**
     * Check if an ID exists as either a real paste or an alias
     */
    public static function idExists(string $id): bool
    {
        $basePath = self::getNotesDir() . '/' . $id;
        return is_dir($basePath);
    }

    /**
     * Check if an ID is a real paste (has _meta.json)
     */
    public static function exists(string $id): bool
    {
        $basePath = self::getNotesDir() . '/' . $id;
        return is_dir($basePath) && file_exists($basePath . '/_meta.json');
    }

    /**
     * Check if an ID is an alias (has _alias.json)
     */
    public static function isAlias(string $id): bool
    {
        $basePath = self::getNotesDir() . '/' . $id;
        return is_dir($basePath) && file_exists($basePath . '/_alias.json');
    }

    /**
     * Resolve an alias to its parent ID
     * Returns null if not an alias
     */
    public static function resolveAlias(string $id): ?string
    {
        if (!self::isAlias($id)) {
            return null;
        }

        $aliasFile = self::getNotesDir() . '/' . $id . '/_alias.json';
        $data = json_decode(file_get_contents($aliasFile), true);
        return $data['parent'] ?? null;
    }

    /**
     * Get the real ID, resolving aliases if necessary
     * Returns the same ID if it's not an alias
     */
    public static function getRealId(string $id): string
    {
        $realId = self::resolveAlias($id);
        return $realId ?? $id;
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
        // Use custom ID if provided, otherwise generate random
        if (isset($data['id']) && $data['id'] !== '') {
            $id = $data['id'];

            // Validate custom ID
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
                throw new \RuntimeException("Paste ID must contain only alphanumeric characters, hyphens, and underscores");
            }

            // Check if ID already exists
            if (self::idExists($id)) {
                throw new \RuntimeException("Paste ID '{$id}' already exists");
            }
        } else {
            $id = self::generateId();
        }

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
            'aliases' => [],
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

    public function renameFile(string $oldFilename, string $newFilename): void
    {
        $oldPath = $this->basePath . '/files/' . $oldFilename;
        $newPath = $this->basePath . '/files/' . $newFilename;

        // Rename physical file
        if (file_exists($oldPath)) {
            if (!rename($oldPath, $newPath)) {
                throw new \RuntimeException("Failed to rename file from {$oldFilename} to {$newFilename}");
            }
        }

        // Update metadata - preserve file metadata under new filename
        if (isset($this->meta['files'][$oldFilename])) {
            $this->meta['files'][$newFilename] = $this->meta['files'][$oldFilename];
            unset($this->meta['files'][$oldFilename]);
        }

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

    /**
     * Get all aliases for this paste
     */
    public function getAliases(): array
    {
        return $this->meta['aliases'] ?? [];
    }

    /**
     * Add a new alias to this paste
     * @param string|null $aliasId If null, generates a random alias
     * @return string The alias ID that was created
     * @throws \RuntimeException if alias already exists or creation fails
     */
    public function addAlias(?string $aliasId = null): string
    {
        // Generate random alias if not provided
        if ($aliasId === null) {
            $aliasId = self::generateId();
        } else {
            // Validate user-provided alias
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $aliasId)) {
                throw new \RuntimeException("Alias ID must contain only alphanumeric characters, hyphens, and underscores");
            }

            // Check if alias already exists
            if (self::idExists($aliasId)) {
                throw new \RuntimeException("Alias ID '$aliasId' already exists");
            }
        }

        // Create alias directory
        $aliasPath = self::getNotesDir() . '/' . $aliasId;
        if (!@mkdir($aliasPath, 0755, true)) {
            throw new \RuntimeException("Failed to create alias directory: {$aliasPath}");
        }

        // Create _alias.json file
        $aliasData = ['parent' => $this->id];
        $aliasFile = $aliasPath . '/_alias.json';
        $result = @file_put_contents($aliasFile, json_encode($aliasData, JSON_PRETTY_PRINT));

        if ($result === false) {
            // Clean up directory if file creation failed
            @rmdir($aliasPath);
            throw new \RuntimeException("Failed to create alias file: {$aliasFile}");
        }

        // Add to metadata
        if (!isset($this->meta['aliases'])) {
            $this->meta['aliases'] = [];
        }
        $this->meta['aliases'][] = $aliasId;
        $this->meta['updatedAt'] = date('c');
        $this->saveMeta();

        return $aliasId;
    }

    /**
     * Remove an alias from this paste
     * @throws \RuntimeException if alias removal fails
     */
    public function removeAlias(string $aliasId): void
    {
        // Check if this is actually an alias of this paste
        if (!in_array($aliasId, $this->meta['aliases'] ?? [])) {
            throw new \RuntimeException("Alias '$aliasId' is not associated with this paste");
        }

        // Remove alias directory and its contents
        $aliasPath = self::getNotesDir() . '/' . $aliasId;
        $aliasFile = $aliasPath . '/_alias.json';

        if (file_exists($aliasFile)) {
            @unlink($aliasFile);
        }

        if (is_dir($aliasPath)) {
            @rmdir($aliasPath);
        }

        // Remove from metadata
        $this->meta['aliases'] = array_values(array_filter(
            $this->meta['aliases'] ?? [],
            fn($id) => $id !== $aliasId
        ));
        $this->meta['updatedAt'] = date('c');
        $this->saveMeta();
    }

    /**
     * Make an alias the primary ID by swapping it with the current ID
     * @param string $aliasId The alias to promote to primary
     * @throws \RuntimeException if operation fails
     */
    public function makePrimary(string $aliasId): void
    {
        // Verify the alias exists and belongs to this paste
        if (!in_array($aliasId, $this->meta['aliases'] ?? [])) {
            throw new \RuntimeException("Alias '{$aliasId}' is not associated with this paste");
        }

        $oldId = $this->id;
        $newId = $aliasId;
        $notesDir = self::getNotesDir();

        // Get all aliases (excluding the one we're promoting)
        $otherAliases = array_values(array_filter(
            $this->meta['aliases'] ?? [],
            fn($id) => $id !== $aliasId
        ));

        // Step 1: Delete the alias directory (it's just a pointer)
        $aliasPath = $notesDir . '/' . $aliasId;
        $aliasFile = $aliasPath . '/_alias.json';
        if (file_exists($aliasFile)) {
            @unlink($aliasFile);
        }
        if (is_dir($aliasPath)) {
            @rmdir($aliasPath);
        }

        // Step 2: Rename current paste directory to the new ID
        $oldPath = $notesDir . '/' . $oldId;
        $newPath = $notesDir . '/' . $newId;
        if (!rename($oldPath, $newPath)) {
            throw new \RuntimeException("Failed to rename paste directory from '{$oldId}' to '{$newId}'");
        }

        // Step 3: Update internal state
        $this->id = $newId;
        $this->basePath = $newPath;

        // Step 4: Update metadata with new alias list (old ID becomes alias)
        $this->meta['aliases'] = array_merge([$oldId], $otherAliases);
        $this->meta['updatedAt'] = date('c');
        $this->saveMeta();

        // Step 5: Create alias directory for old ID pointing to new ID
        $oldAliasPath = $notesDir . '/' . $oldId;
        if (!@mkdir($oldAliasPath, 0755, true)) {
            throw new \RuntimeException("Failed to create alias directory for old ID: {$oldAliasPath}");
        }

        $oldAliasFile = $oldAliasPath . '/_alias.json';
        $aliasData = ['parent' => $newId];
        $result = @file_put_contents($oldAliasFile, json_encode($aliasData, JSON_PRETTY_PRINT));

        if ($result === false) {
            @rmdir($oldAliasPath);
            throw new \RuntimeException("Failed to create alias file for old ID: {$oldAliasFile}");
        }

        // Step 6: Update all other alias directories to point to new ID
        foreach ($otherAliases as $otherAlias) {
            $otherAliasPath = $notesDir . '/' . $otherAlias;
            $otherAliasFile = $otherAliasPath . '/_alias.json';

            if (file_exists($otherAliasFile)) {
                $aliasData = ['parent' => $newId];
                @file_put_contents($otherAliasFile, json_encode($aliasData, JSON_PRETTY_PRINT));
            }
        }
    }

    /**
     * Delete this paste and all its aliases
     * @throws \RuntimeException if deletion fails
     */
    public function delete(): void
    {
        $notesDir = self::getNotesDir();

        // Delete all alias directories first
        foreach ($this->getAliases() as $aliasId) {
            $aliasPath = $notesDir . '/' . $aliasId;
            $this->deleteDirectory($aliasPath);
        }

        // Delete the main paste directory
        $this->deleteDirectory($this->basePath);
    }

    /**
     * Recursively delete a directory and all its contents
     */
    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath)) {
                $this->deleteDirectory($itemPath);
            } else {
                @unlink($itemPath);
            }
        }

        @rmdir($path);
    }
}
