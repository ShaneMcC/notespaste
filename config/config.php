<?php

$config = [
    'htpasswd_path' => getenv('HTPASSWD_PATH') ?: __DIR__ . '/.htpasswd',
    'notes_dir' => getenv('NOTES_DIR') ?: __DIR__ . '/../public/notes',

    // OpenID Connect login. Disabled unless issuer, client_id, client_secret and
    // name are all set. redirect_uri is optional - it is worked out from the
    // request when left empty.
    'oidc' => [
        'issuer' => getenv('OIDC_ISSUER') ?: '',
        'client_id' => getenv('OIDC_CLIENTID') ?: '',
        'client_secret' => getenv('OIDC_SECRET') ?: '',
        'name' => getenv('OIDC_NAME') ?: '',
        'redirect_uri' => getenv('OIDC_REDIRECT_URI') ?: '',
    ],
];

// Local overrides, mainly for running outside Docker - see config.local.example.php.
// The file is gitignored and excluded from the Docker image, and only needs to hold
// the settings it wants to change; nested arrays are merged rather than replaced.
$localConfig = __DIR__ . '/config.local.php';

if (is_file($localConfig)) {
    $overrides = require $localConfig;

    if (!is_array($overrides)) {
        throw new RuntimeException(
            'config.local.php must return an array, got ' . get_debug_type($overrides)
        );
    }

    $config = array_replace_recursive($config, $overrides);
}

return $config;
