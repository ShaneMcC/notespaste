<?php

// Load configuration
$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../vendor/autoload.php';

use App\Auth;
use App\Paste;
use App\Helpers;

// Initialize authentication
Auth::init($config['htpasswd_path']);

// Initialize Paste with notes directory
Paste::setNotesDir($config['notes_dir']);

// Detect base path from the request URI
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = $scriptPath === '/' ? '' : $scriptPath;

// Store base path globally for use in templates
define('BASE_PATH', $basePath);

// Initialize router
$router = new \Bramus\Router\Router();

// Set base path
$router->setBasePath($basePath);

// Initialize Twig
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
$twig = new \Twig\Environment($loader);

// Add BASE_PATH as a global variable in Twig
$twig->addGlobal('basePath', $basePath);

// Helper function to process file data from form submission
function processFileData(int $index, array $fileData): array {
    $filename = Helpers::sanitizeFilename($fileData['filename'] ?? 'untitled.txt');
    $render = $fileData['render'] ?? 'plain';
    $type = $fileData['type'] ?? 'text';

    // Check if file was uploaded
    $content = null;
    if (isset($_FILES['file_uploads']['tmp_name'][$index]) &&
        $_FILES['file_uploads']['error'][$index] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['file_uploads']['tmp_name'][$index]);

        // Auto-detect render mode for uploaded files if not already set to file/image modes
        if (!in_array($render, ['image', 'file', 'file-link'])) {
            $mimeType = mime_content_type($_FILES['file_uploads']['tmp_name'][$index]);

            // Check if it's an image
            if (strpos($mimeType, 'image/') === 0) {
                $render = 'image';
                $type = 'image';
            }
            // Check if it's binary (not text)
            elseif (strpos($mimeType, 'text/') !== 0 &&
                    !in_array($mimeType, ['application/json', 'application/xml', 'application/javascript'])) {
                $render = 'file';
                $type = 'file';
            }
        }
    } elseif (isset($fileData['content']) && $fileData['content'] !== '') {
        $content = $fileData['content'];
    }

    // Override type for image/file/file-link/link/rendered render modes
    if (in_array($render, ['image', 'file', 'file-link', 'link'])) {
        $type = $render;
    } elseif ($render === 'rendered') {
        $type = 'markdown';
    }

    $fileMeta = [
        'displayName' => $fileData['displayName'] ?? '',
        'description' => $fileData['description'] ?? '',
        'type' => $type,
        'render' => $render,
        'hidden' => isset($fileData['hidden']) && $fileData['hidden'] === '1',
        'unwrapped' => isset($fileData['unwrapped']) && $fileData['unwrapped'] === '1',
        'collapsed' => isset($fileData['collapsed']) && $fileData['collapsed'] === '1',
        'collapsedDescription' => $fileData['collapsedDescription'] ?? '',
    ];

    return [
        'filename' => $filename,
        'content' => $content,
        'fileMeta' => $fileMeta,
        'originalFilename' => $fileData['originalFilename'] ?? null,
    ];
}

// Home page - show public pastes for everyone, all pastes for logged in users
$router->get('/', function() use ($twig) {
    $isLoggedIn = Auth::isLoggedIn();

    // Show only public pastes if not logged in, all pastes if logged in
    $pastes = Paste::listAll(!$isLoggedIn);

    echo $twig->render('home.html.twig', [
        'pastes' => $pastes,
        'currentUser' => Auth::getCurrentUser(),
        'isLoggedIn' => $isLoggedIn
    ]);
});

// Login page
$router->get('/login', function() use ($twig) {
    if (Auth::isLoggedIn()) {
        Helpers::redirect(BASE_PATH . '/');
    }

    echo $twig->render('login.html.twig', [
        'error' => $_SESSION['login_error'] ?? null
    ]);
    unset($_SESSION['login_error']);
});

// Login handler
$router->post('/login', function() {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (Auth::authenticate($username, $password)) {
        Helpers::redirect(BASE_PATH . '/');
    } else {
        $_SESSION['login_error'] = 'Invalid username or password';
        Helpers::redirect(BASE_PATH . '/login');
    }
});

// Logout
$router->get('/logout', function() {
    Auth::logout();
    Helpers::redirect(BASE_PATH . '/');
});

// New paste
$router->get('/notes/new', function() use ($twig) {
    Auth::requireLogin();

    echo $twig->render('edit.html.twig', [
        'paste' => null,
        'meta' => [
            'title' => '',
            'description' => '',
            'author' => ucfirst(Auth::getCurrentUser()),
            'files' => []
        ],
        'isNew' => true,
        'currentUser' => Auth::getCurrentUser()
    ]);
});

// Create paste handler
$router->post('/notes/new', function() {
    Auth::requireLogin();

    try {
        $data = [
            'id' => $_POST['paste_id'] ?? '', // Custom paste ID (optional)
            'title' => $_POST['title'] ?? 'Untitled',
            'description' => $_POST['description'] ?? '',
            'summary' => $_POST['summary'] ?? '',
            'author' => $_POST['author'] ?? ucfirst(Auth::getCurrentUser()),
            'public' => isset($_POST['public']) && $_POST['public'] === '1',
            'displayMode' => $_POST['displayMode'] ?? 'multi-normal',
            'selectedFile' => $_POST['selectedFile'] ?? '',
        ];

        $paste = Paste::create($data);

        // Handle file uploads
        $usedFilenames = [];
        if (isset($_POST['files'])) {
            foreach ($_POST['files'] as $index => $fileData) {
                $processed = processFileData($index, $fileData);
                $filename = $processed['filename'];

                // Check for duplicate filenames and add prefix if needed
                if (in_array($filename, $usedFilenames)) {
                    $filename = "file{$index}_{$filename}";
                }
                $usedFilenames[] = $filename;

                $paste->addFile($filename, $processed['content'] ?? '', $processed['fileMeta']);
            }
        }

        // Render the paste
        $paste->render();

        Helpers::redirect($paste->getHtmlUrl());
    } catch (\Exception $e) {
        error_log("Failed to create paste: " . $e->getMessage());
        Helpers::show500();
    }
});

// Edit paste
$router->get('/notes/([a-zA-Z0-9_-]+)/edit', function($id) use ($twig) {
    Auth::requireLogin();

    // Resolve alias to real ID if needed
    $realId = Paste::getRealId($id);

    if (!Paste::exists($realId)) {
        Helpers::show404();
    }

    // If accessing via alias, redirect to real ID for editing
    if ($realId !== $id) {
        Helpers::redirect(BASE_PATH . '/notes/' . $realId . '/edit', 301);
    }

    $paste = new Paste($id);

    echo $twig->render('edit.html.twig', [
        'paste' => $paste,
        'meta' => $paste->getMeta(),
        'isNew' => false,
        'currentUser' => Auth::getCurrentUser()
    ]);
});

// Update paste handler
$router->post('/notes/([a-zA-Z0-9_-]+)/edit', function($id) {
    Auth::requireLogin();

    // Resolve alias to real ID if needed
    $realId = Paste::getRealId($id);

    if (!Paste::exists($realId)) {
        Helpers::show404();
    }

    // If submitting via alias, redirect to real ID
    if ($realId !== $id) {
        Helpers::redirect(BASE_PATH . '/notes/' . $realId . '/edit', 301);
    }

    try {
        $paste = new Paste($id);

        $data = [
            'title' => $_POST['title'] ?? 'Untitled',
            'description' => $_POST['description'] ?? '',
            'summary' => $_POST['summary'] ?? '',
            'author' => $_POST['author'] ?? ucfirst(Auth::getCurrentUser()),
            'public' => isset($_POST['public']) && $_POST['public'] === '1',
            'displayMode' => $_POST['displayMode'] ?? 'multi-normal',
            'selectedFile' => $_POST['selectedFile'] ?? '',
        ];

        $paste->update($data);

        // Track existing files to determine what to remove
        $existingFiles = array_keys($paste->getFiles());
        $filesToRemove = [];
        $filesToRename = []; // [originalFilename => newFilename]
        $filesToUpdate = []; // [filename => [content, fileMeta]]
        $filesToAdd = []; // [filename => [content, fileMeta]]
        $newFilesOrder = [];

        // First pass: collect all operations
        if (isset($_POST['files'])) {
            $usedFilenames = [];

            foreach ($_POST['files'] as $index => $fileData) {
                $processed = processFileData($index, $fileData);
                $originalFilename = $processed['originalFilename'];
                $newFilename = $processed['filename'];

                // Check for duplicate filenames and add prefix if needed
                if (in_array($newFilename, $usedFilenames)) {
                    $newFilename = "file{$index}_{$newFilename}";
                }
                $usedFilenames[] = $newFilename;

                // Determine operation type
                if ($originalFilename && in_array($originalFilename, $existingFiles)) {
                    // Existing file
                    if ($originalFilename !== $newFilename) {
                        // Rename operation
                        $filesToRename[$originalFilename] = $newFilename;
                    }
                    // Update/add to update list
                    $filesToUpdate[$newFilename] = ['content' => $processed['content'], 'fileMeta' => $processed['fileMeta'], 'originalFilename' => $originalFilename];
                } else {
                    // New file
                    $filesToAdd[$newFilename] = ['content' => $processed['content'] ?? '', 'fileMeta' => $processed['fileMeta']];
                }

                $newFilesOrder[] = $newFilename;
            }
        }

        // Determine files to remove (existed before but not in form submission)
        foreach ($existingFiles as $existingFile) {
            $stillExists = false;
            if (isset($_POST['files'])) {
                foreach ($_POST['files'] as $fileData) {
                    if (($fileData['originalFilename'] ?? null) === $existingFile) {
                        $stillExists = true;
                        break;
                    }
                }
            }
            if (!$stillExists) {
                $filesToRemove[] = $existingFile;
            }
        }

        // Stage 1: Rename files to temporary names to avoid conflicts
        $tempRenames = [];
        foreach ($filesToRename as $oldName => $newName) {
            $tempName = '__temp_' . uniqid() . '_' . $oldName;
            $paste->renameFile($oldName, $tempName);
            $tempRenames[$tempName] = $newName;
        }

        // Stage 2: Rename from temp names to final names
        foreach ($tempRenames as $tempName => $finalName) {
            $paste->renameFile($tempName, $finalName);
        }

        // Update file metadata and content
        foreach ($filesToUpdate as $filename => $data) {
            $paste->updateFile($filename, $data['content'], $data['fileMeta']);
        }

        // Add new files
        foreach ($filesToAdd as $filename => $data) {
            $paste->addFile($filename, $data['content'], $data['fileMeta']);
        }

        // Remove deleted files
        foreach ($filesToRemove as $filename) {
            $paste->removeFile($filename);
        }

        // Reorder files in metadata to match form submission order
        $paste->reorderFiles($newFilesOrder);

        // Check if we need to make an alias the primary ID
        $makePrimaryId = isset($_POST['make_primary_id']) && $_POST['make_primary_id'] !== '' ? $_POST['make_primary_id'] : null;

        if ($makePrimaryId) {
            // Make the alias primary (this swaps IDs and updates all aliases)
            try {
                $paste->makePrimary($makePrimaryId);
                // After making primary, the paste object's ID has changed
                // The submitted aliases should already include the old ID as an alias
            } catch (\Exception $e) {
                error_log("Failed to make alias primary {$makePrimaryId}: " . $e->getMessage());
                // Continue with normal save if makePrimary fails
            }
        }

        // Handle alias changes (only if we didn't already handle them in makePrimary)
        $submittedAliases = isset($_POST['aliases']) ? array_filter($_POST['aliases']) : [];
        $existingAliases = $paste->getAliases();

        // Remove aliases that are no longer in the list
        foreach ($existingAliases as $existingAlias) {
            if (!in_array($existingAlias, $submittedAliases)) {
                try {
                    $paste->removeAlias($existingAlias);
                } catch (\Exception $e) {
                    error_log("Failed to remove alias {$existingAlias}: " . $e->getMessage());
                }
            }
        }

        // Add new aliases
        foreach ($submittedAliases as $newAlias) {
            if (!in_array($newAlias, $existingAliases)) {
                try {
                    $paste->addAlias($newAlias);
                } catch (\Exception $e) {
                    error_log("Failed to add alias {$newAlias}: " . $e->getMessage());
                    // Continue with other aliases even if one fails
                }
            }
        }

        // Re-render the paste
        $paste->render();

        Helpers::redirect($paste->getHtmlUrl());
    } catch (\Exception $e) {
        error_log("Failed to update paste {$id}: " . $e->getMessage());
        Helpers::show500();
    }
});

// Redirect /notes directory access to home
$router->get('/notes/?', function() {
    Helpers::redirect(BASE_PATH . '/');
});

// Generate a random alias ID (doesn't create it)
$router->post('/notes/([a-zA-Z0-9_-]+)/alias/generate', function($id) {
    Auth::requireLogin();
    header('Content-Type: application/json');

    // Resolve alias to real ID if needed
    $realId = Paste::getRealId($id);

    if (!Paste::exists($realId)) {
        echo json_encode(['success' => false, 'error' => 'Paste not found']);
        return;
    }

    try {
        // Generate a unique ID without creating the alias
        $aliasId = Paste::generateId();
        echo json_encode(['success' => true, 'aliasId' => $aliasId]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
});

// Validate an alias ID (doesn't create it)
$router->post('/notes/([a-zA-Z0-9_-]+)/alias/validate', function($id) {
    Auth::requireLogin();
    header('Content-Type: application/json');

    // Resolve alias to real ID if needed
    $realId = Paste::getRealId($id);

    if (!Paste::exists($realId)) {
        echo json_encode(['success' => false, 'error' => 'Paste not found']);
        return;
    }

    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['aliasId'])) {
            echo json_encode(['success' => false, 'error' => 'Alias ID required']);
            return;
        }

        $aliasId = $data['aliasId'];

        // Validate format
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $aliasId)) {
            echo json_encode(['success' => false, 'error' => 'Alias ID must contain only alphanumeric characters, hyphens, and underscores']);
            return;
        }

        // Check if ID already exists
        if (Paste::idExists($aliasId)) {
            echo json_encode(['success' => false, 'error' => 'Alias ID already exists']);
            return;
        }

        echo json_encode(['success' => true, 'aliasId' => $aliasId]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
});

// Rerender single paste
$router->post('/notes/([a-zA-Z0-9_-]+)/rerender', function($id) {
    Auth::requireLogin();

    if (!Paste::exists($id)) {
        Helpers::show404();
    }

    try {
        $paste = new Paste($id);
        $paste->render();
        Helpers::redirect($paste->getHtmlUrl());
    } catch (\Exception $e) {
        error_log("Failed to rerender paste {$id}: " . $e->getMessage());
        Helpers::show500();
    }
});

// Rerender all pastes
$router->post('/notes/rerender-all', function() use ($twig) {
    Auth::requireLogin();

    try {
        $pastes = Paste::listAll();
        $results = [];

        foreach ($pastes as $pasteData) {
            try {
                $pasteData['paste']->render();
                $results[] = [
                    'id' => $pasteData['id'],
                    'title' => $pasteData['title'],
                    'success' => true
                ];
            } catch (\Exception $e) {
                error_log("Failed to rerender paste {$pasteData['id']}: " . $e->getMessage());
                $results[] = [
                    'id' => $pasteData['id'],
                    'title' => $pasteData['title'],
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        echo $twig->render('rerender-results.html.twig', [
            'results' => $results,
            'currentUser' => Auth::getCurrentUser()
        ]);
    } catch (\Exception $e) {
        error_log("Failed to rerender all pastes: " . $e->getMessage());
        Helpers::show500();
    }
});

// Proxy individual files through PHP
$router->get('/notes/([a-zA-Z0-9_-]+)/files/(.+)', function($id, $filename) {
    // Check if this is an alias and redirect to real ID
    if (Paste::isAlias($id)) {
        $realId = Paste::resolveAlias($id);
        if ($realId) {
            Helpers::redirect(BASE_PATH . '/notes/' . $realId . '/files/' . $filename, 301);
        }
    }

    if (!Paste::exists($id)) {
        Helpers::show404();
    }

    $paste = new Paste($id);
    $filePath = $paste->getFilePath($filename);

    if (!$filePath) {
        Helpers::show404();
    }

    // Get MIME type and serve the file
    $mimeType = Helpers::getMimeType($filePath);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));

    // Add Content-Disposition for non-text/non-image files when render mode is 'file'
    $files = $paste->getFiles();
    $fileMeta = $files[$filename] ?? [];

    $isTextOrImage = str_starts_with($mimeType, 'text/') || str_starts_with($mimeType, 'image/');

    if (($fileMeta['render'] ?? '') === 'file' && !$isTextOrImage) {
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    }

    readfile($filePath);
    exit;
});

// Proxy rendered HTML through PHP
$router->get('/notes/([a-zA-Z0-9_-]+)/([^/]+\.html)', function($id, $htmlFile) {
    // Check if this is an alias and redirect to real ID
    if (Paste::isAlias($id)) {
        $realId = Paste::resolveAlias($id);
        if ($realId) {
            Helpers::redirect(BASE_PATH . '/notes/' . $realId . '/' . $htmlFile, 301);
        }
    }

    if (!Paste::exists($id)) {
        Helpers::show404();
    }

    $paste = new Paste($id);
    $htmlPath = $paste->getHtmlPath();
    $slug = $paste->getSlug();
    $expectedFile = $slug . '.html';

    // Check if requesting the correct HTML file
    if ($htmlFile !== $expectedFile) {
        Helpers::redirect($paste->getHtmlUrl(), 301);
    }

    // Render if doesn't exist
    if (!file_exists($htmlPath)) {
        try {
            $paste->render();
        } catch (\Exception $e) {
            error_log("Failed to render paste {$id}: " . $e->getMessage());
            Helpers::show500();
        }
    }

    // Serve the HTML file
    header('Content-Type: text/html; charset=utf-8');
    readfile($htmlPath);
    exit;
});

// View rendered paste - catch-all for paste URLs
$router->get('/notes/([a-zA-Z0-9_-]+)(/.*)?', function($id, $path = '') {
    // Check if this is an alias and redirect to real ID
    if (Paste::isAlias($id)) {
        $realId = Paste::resolveAlias($id);
        if ($realId) {
            // Preserve the path in the redirect
            Helpers::redirect(BASE_PATH . '/notes/' . $realId . $path, 301);
        }
    }

    if (!Paste::exists($id)) {
        Helpers::show404();
    }

    $paste = new Paste($id);
    $slug = $paste->getSlug();
    $expectedPath = '/' . $slug . '.html';

    // If not accessing the correct slug URL, redirect to it
    // The HTML proxy route will handle rendering if needed
    if ($path !== $expectedPath) {
        Helpers::redirect($paste->getHtmlUrl(), 301);
    }

    // Redirect to the HTML file (which will be served by Apache or the HTML proxy route)
    Helpers::redirect($paste->getHtmlUrl(), 301);
});

// 404 for everything else
$router->set404(function() {
    Helpers::show404();
});

// Run the router
$router->run();
