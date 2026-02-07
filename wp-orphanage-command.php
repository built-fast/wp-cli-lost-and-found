<?php

declare(strict_types=1);

/**
 * Registers the `wp media find-orphans` command.
 *
 * Usage:
 *   wp --require=wp-orphanage-command.php media find-orphans
 *
 * Or install as a WP-CLI package:
 *   wp package install /path/to/this/directory
 */

namespace WpOrphanage;

if (! class_exists('WP_CLI')) {
    return;
}

$autoloader = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // When loaded via --require without Composer install, autoload manually.
    require_once __DIR__ . '/src/OrphanDetector.php';
    require_once __DIR__ . '/src/FindOrphansCommand.php';
}

\WP_CLI::add_command('media find-orphans', FindOrphansCommand::class);
