<?php

return [
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
