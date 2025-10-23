<?php

return [
    'htpasswd_path' => getenv('HTPASSWD_PATH') ?: __DIR__ . '/.htpasswd',
    'notes_dir' => getenv('NOTES_DIR') ?: __DIR__ . '/../public/notes',
];
