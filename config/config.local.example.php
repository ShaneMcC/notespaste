<?php

/**
 * Local development configuration.
 *
 * Copy this to config.local.php and uncomment whatever you want to change.
 * That file is gitignored and excluded from the Docker image, so it is only
 * ever picked up when you run the app directly, e.g.:
 *
 *     php -S localhost:8000 -t public/
 *
 * Whatever you return here is merged over the defaults in config.php, so you
 * only need the settings you actually care about. Nested arrays like 'oidc'
 * are merged too, so overriding one key leaves the rest alone.
 *
 * These values take priority over the equivalent environment variables.
 */

return [
    // 'htpasswd_path' => __DIR__ . '/.htpasswd',
    // 'notes_dir' => __DIR__ . '/../public/notes',

    // 'oidc' => [
    //     'issuer' => 'https://auth.example.com',
    //     'client_id' => 'notespaste',
    //     'client_secret' => 'your-client-secret',
    //     'name' => 'Company SSO',
    //
    //     // Worth setting locally: the callback is otherwise derived from the
    //     // request, which will not match what you registered with the provider.
    //     'redirect_uri' => 'http://localhost:8000/login/oidc/callback',
    // ],
];
