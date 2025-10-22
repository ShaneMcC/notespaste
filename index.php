<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Auth;
use App\Paste;
use App\Helpers;

// Initialize authentication
Auth::init();

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
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates');
$twig = new \Twig\Environment($loader);

// Add BASE_PATH as a global variable in Twig
$twig->addGlobal('basePath', $basePath);

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
            'title' => $_POST['title'] ?? 'Untitled',
            'description' => $_POST['description'] ?? '',
            'summary' => $_POST['summary'] ?? '',
            'author' => $_POST['author'] ?? ucfirst(Auth::getCurrentUser()),
            'public' => isset($_POST['public']) && $_POST['public'] === '1',
        ];

        $paste = Paste::create($data);

        // Handle file uploads
        $usedFilenames = [];
        if (isset($_POST['files'])) {
            foreach ($_POST['files'] as $index => $fileData) {
                $filename = Helpers::sanitizeFilename($fileData['filename'] ?? 'untitled.txt');

                // Check for duplicate filenames and add prefix if needed
                if (in_array($filename, $usedFilenames)) {
                    $filename = "file{$index}_{$filename}";
                }
                $usedFilenames[] = $filename;

                $render = $fileData['render'] ?? 'plain';
                $type = $fileData['type'] ?? 'text';

                // Override type for image/file/file-link render modes
                if (in_array($render, ['image', 'file', 'file-link'])) {
                    $type = $render;
                }

                $fileMeta = [
                    'description' => $fileData['description'] ?? '',
                    'type' => $type,
                    'render' => $render,
                ];

                // Check if file was uploaded
                if (isset($_FILES['file_uploads']['tmp_name'][$index]) &&
                    $_FILES['file_uploads']['error'][$index] === UPLOAD_ERR_OK) {
                    $content = file_get_contents($_FILES['file_uploads']['tmp_name'][$index]);
                } else {
                    $content = $fileData['content'] ?? '';
                }

                $paste->addFile($filename, $content, $fileMeta);
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
$router->get('/notes/([a-zA-Z0-9]+)/edit', function($id) use ($twig) {
    Auth::requireLogin();

    if (!Paste::exists($id)) {
        Helpers::show404();
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
$router->post('/notes/([a-zA-Z0-9]+)/edit', function($id) {
    Auth::requireLogin();

    if (!Paste::exists($id)) {
        Helpers::show404();
    }

    try {
        $paste = new Paste($id);

        $data = [
            'title' => $_POST['title'] ?? 'Untitled',
            'description' => $_POST['description'] ?? '',
            'summary' => $_POST['summary'] ?? '',
            'author' => $_POST['author'] ?? ucfirst(Auth::getCurrentUser()),
            'public' => isset($_POST['public']) && $_POST['public'] === '1',
        ];

        $paste->update($data);

        // Track existing files to determine what to remove
        $existingFiles = array_keys($paste->getFiles());
        $keptFiles = [];

        // Handle file updates
        if (isset($_POST['files'])) {
            foreach ($_POST['files'] as $index => $fileData) {
                $filename = Helpers::sanitizeFilename($fileData['filename'] ?? 'untitled.txt');

                // Check for duplicate filenames and add prefix if needed
                if (in_array($filename, $keptFiles)) {
                    $filename = "file{$index}_{$filename}";
                }
                $keptFiles[] = $filename;

                $render = $fileData['render'] ?? 'plain';
                $type = $fileData['type'] ?? 'text';

                // Override type for image/file/file-link render modes
                if (in_array($render, ['image', 'file', 'file-link'])) {
                    $type = $render;
                }

                $fileMeta = [
                    'description' => $fileData['description'] ?? '',
                    'type' => $type,
                    'render' => $render,
                ];

                // Check if file was uploaded
                if (isset($_FILES['file_uploads']['tmp_name'][$index]) &&
                    $_FILES['file_uploads']['error'][$index] === UPLOAD_ERR_OK) {
                    $content = file_get_contents($_FILES['file_uploads']['tmp_name'][$index]);
                } elseif (isset($fileData['content']) && $fileData['content'] !== '') {
                    $content = $fileData['content'];
                } else {
                    $content = null; // Keep existing content
                }

                if (in_array($filename, $existingFiles)) {
                    $paste->updateFile($filename, $content, $fileMeta);
                } else {
                    $paste->addFile($filename, $content ?? '', $fileMeta);
                }
            }
        }

        // Remove files that are no longer present
        foreach ($existingFiles as $existingFile) {
            if (!in_array($existingFile, $keptFiles)) {
                $paste->removeFile($existingFile);
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

// Rerender single paste
$router->post('/notes/([a-zA-Z0-9]+)/rerender', function($id) {
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

// View rendered paste - catch-all for paste URLs
$router->get('/notes/([a-zA-Z0-9]+)(/.*)?', function($id, $path = '') {
    if (!Paste::exists($id)) {
        Helpers::show404();
    }

    $paste = new Paste($id);
    $slug = $paste->getSlug();
    $expectedPath = '/' . $slug . '.html';

    // If not accessing the correct slug URL, redirect
    if ($path !== $expectedPath) {
        // Only redirect if we can render the file OR it already exists
        // This prevents redirect loops when rendering fails
        if (!file_exists($paste->getHtmlPath())) {
            try {
                $paste->render();
            } catch (\Exception $e) {
                // If we can't render, show 500 instead of redirecting
                error_log("Failed to render paste {$id}: " . $e->getMessage());
                Helpers::show500();
            }
        }

        Helpers::redirect($paste->getHtmlUrl(), 301);
    }

    // We're on the correct path now - serve or render the HTML file
    if (file_exists($paste->getHtmlPath())) {
        readfile($paste->getHtmlPath());
        exit;
    }

    // If HTML doesn't exist, try to render it
    try {
        $html = $paste->render();
        echo $html;
        exit;
    } catch (\Exception $e) {
        error_log("Failed to render paste {$id}: " . $e->getMessage());
        Helpers::show500();
    }
});

// 404 for everything else
$router->set404(function() {
    Helpers::show404();
});

// Run the router
$router->run();
