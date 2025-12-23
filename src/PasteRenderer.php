<?php

namespace App;

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
        // Don't set basePath - rendered pastes use relative paths
        $this->twig = TwigFactory::create();
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
            // Force unwrapped mode for single-file display
            $fileMeta['unwrapped'] = true;

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
        $renderModeValue = $fileMeta['render'] ?? RenderMode::PLAIN->value;
        $renderMode = RenderMode::tryFrom($renderModeValue) ?? RenderMode::PLAIN;

        if ($content === null) {
            return '<p class="error">File not found</p>';
        }

        return match ($renderMode) {
            RenderMode::PLAIN => '<pre>' . htmlspecialchars($content) . '</pre>',

            RenderMode::HIGHLIGHTED => $this->renderHighlighted($content, $fileMeta),

            RenderMode::IMAGE => '<img src="' . htmlspecialchars('./files/' . urlencode($filename)) . '" alt="' . htmlspecialchars($filename) . '" />',

            RenderMode::FILE => '<div class="file-download">
                    <a href="' . htmlspecialchars('./files/' . urlencode($filename)) . '" download>
                        <span class="icon">📁</span> Download ' . htmlspecialchars($filename) . '
                    </a>
                </div>',

            RenderMode::FILE_LINK => '<div class="file-link">
                    <a href="' . htmlspecialchars('./files/' . urlencode($filename)) . '" target="_blank" rel="noopener">
                        <span class="icon">📎</span> ' . htmlspecialchars($filename) . '
                    </a>
                </div>',

            RenderMode::LINK => $this->renderLinks($content),

            RenderMode::RENDERED => ($fileMeta['type'] ?? '') === 'markdown'
                ? $this->renderMarkdown($content)
                : '<pre>' . htmlspecialchars($content) . '</pre>',
        };
    }

    private function renderHighlighted(string $content, array $fileMeta): string
    {
        $type = $fileMeta['type'] ?? 'text';
        $lineNumbersClass = !empty($fileMeta['lineNumbers']) ? ' line-numbers' : '';
        $lineNumberStart = (int)($fileMeta['lineNumberStart'] ?? 1) ?: 1;
        $dataAttr = $lineNumberStart !== 1 ? ' data-ln-start-from="' . $lineNumberStart . '"' : '';

        return '<pre class="hljs-pre' . $lineNumbersClass . '"' . $dataAttr . '><code class="language-' . htmlspecialchars($type) . '">' .
            htmlspecialchars($content) . '</code></pre>';
    }

    private function renderLinks(string $content): string
    {
        $links = array_filter(array_map('trim', explode("\n", $content)));
        $html = '<ul class="link-list">';
        foreach ($links as $link) {
            $html .= '<li><a href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener">' .
                htmlspecialchars($link) . '</a></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    private function renderMarkdown(string $content): string
    {
        $environment = new Environment([]);
        $environment->addExtension(new CommonMarkCoreExtension());

        $converter = new MarkdownConverter($environment);
        return $converter->convert($content)->getContent();
    }
}
