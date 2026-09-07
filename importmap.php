<?php

declare(strict_types=1);

/**
 * Application import map.
 *
 * Keep application entry points here; third-party packages can be added with
 * `php bin/console importmap:require ...` when the UI needs them.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
];
