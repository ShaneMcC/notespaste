<?php

namespace App;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

class PasteRenderer
{
    private Paste $paste;
    private \Twig\Environment $twig;

    public function __construct(Paste $paste)
    {
        $this->paste = $paste;

        $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
        $this->twig = new \Twig\Environment($loader);

        // Add BASE_PATH as a global variable in Twig
        $basePath = defined('BASE_PATH') ? BASE_PATH : '';
        $this->twig->addGlobal('basePath', $basePath);
    }

    public function render(): string
    {
        $meta = $this->paste->getMeta();
        $files = $this->paste->getFiles();
        $displayMode = $meta['displayMode'] ?? 'multi-normal';
        $selectedFile = $meta['selectedFile'] ?? '';

        $renderedFiles = [];

        // For single-file mode, only render the selected file
        if (str_starts_with($displayMode, 'single-') && $selectedFile && isset($files[$selectedFile])) {
            $fileMeta = $files[$selectedFile];
            $content = $this->paste->getFile($selectedFile);
            $renderedContent = $this->renderFileContent($selectedFile, $content, $fileMeta);

            $renderedFiles[] = [
                'filename' => $selectedFile,
                'meta' => $fileMeta,
                'content' => $content,
                'renderedContent' => $renderedContent,
            ];
        } else {
            // Multi-file mode: render all non-hidden files
            foreach ($files as $filename => $fileMeta) {
                // Skip hidden files in multi-file mode
                if (!empty($fileMeta['hidden'])) {
                    continue;
                }

                $content = $this->paste->getFile($filename);
                $renderedContent = $this->renderFileContent($filename, $content, $fileMeta);

                $renderedFiles[] = [
                    'filename' => $filename,
                    'meta' => $fileMeta,
                    'content' => $content,
                    'renderedContent' => $renderedContent,
                ];
            }
        }

        return $this->twig->render('paste.html.twig', [
            'paste' => $this->paste,
            'meta' => $meta,
            'files' => $renderedFiles,
            'displayMode' => $displayMode,
            'isLoggedIn' => Auth::isLoggedIn(),
        ]);
    }

    private function renderFileContent(string $filename, ?string $content, array $fileMeta): string
    {
        $renderMode = $fileMeta['render'] ?? 'plain';

        if ($content === null) {
            return '<p class="error">File not found</p>';
        }

        switch ($renderMode) {
            case 'plain':
                return '<pre>' . htmlspecialchars($content) . '</pre>';

            case 'highlighted':
                $type = $fileMeta['type'] ?? 'text';
                return '<pre><code class="language-' . htmlspecialchars($type) . '">' .
                    htmlspecialchars($content) . '</code></pre>';

            case 'image':
                $url = './files/' . urlencode($filename);
                return '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($filename) . '" />';

            case 'file':
                $url = './files/' . urlencode($filename);
                return '<div class="file-download">
                    <a href="' . htmlspecialchars($url) . '" download>
                        <span class="icon">📁</span> Download ' . htmlspecialchars($filename) . '
                    </a>
                </div>';

            case 'file-link':
                $url = './files/' . urlencode($filename);
                return '<div class="file-link">
                    <a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">
                        <span class="icon">📎</span> ' . htmlspecialchars($filename) . '
                    </a>
                </div>';

            case 'link':
                $links = array_filter(array_map('trim', explode("\n", $content)));
                $html = '<ul class="link-list">';
                foreach ($links as $link) {
                    $html .= '<li><a href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener">' .
                        htmlspecialchars($link) . '</a></li>';
                }
                $html .= '</ul>';
                return $html;

            case 'rendered':
                if (($fileMeta['type'] ?? '') === 'markdown') {
                    return $this->renderMarkdown($content);
                }
                return '<pre>' . htmlspecialchars($content) . '</pre>';

            default:
                return '<pre>' . htmlspecialchars($content) . '</pre>';
        }
    }

    private function renderMarkdown(string $content): string
    {
        $environment = new Environment([]);
        $environment->addExtension(new CommonMarkCoreExtension());

        $converter = new MarkdownConverter($environment);
        return $converter->convert($content)->getContent();
    }
}
