<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * Copy this file to config.local.php and override values there for local
 * development. config.local.php is gitignored and never committed.
 */
return [
    // Base URL is derived dynamically from the request by default.
    // Set this to a string (e.g. 'https://link.velynox.de') to force a fixed domain.
    'base_url' => null,

    // Absolute path to the SQLite database file.
    'db_path' => __DIR__ . '/db/links.sqlite',

    // Short code length for links created via the web UI.
    'code_length' => 6,

    // Short code length for links created via the API (one longer than UI).
    'api_code_length' => 7,

    // GitHub profile/repo URL shown in the footer and API docs.
    'github_url' => 'https://github.com/velynox',

    // Timezone for display purposes (created_at timestamps, footer clock).
    'timezone' => 'Europe/Berlin',
];
