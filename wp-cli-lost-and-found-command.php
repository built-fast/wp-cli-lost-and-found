<?php

declare(strict_types=1);

/**
 * Registers the `wp media find-orphans` and `wp media remove-orphans` commands.
 *
 * Usage:
 *   wp --require=wp-cli-lost-and-found-command.php media find-orphans
 *
 * Or install as a WP-CLI package:
 *   wp package install /path/to/this/directory
 */

namespace LostAndFound;

if (! class_exists('WP_CLI')) {
    return;
}

$autoloader = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // When loaded via --require without Composer install, autoload manually.
    require_once __DIR__ . '/src/OrphanDetector.php';
    require_once __DIR__ . '/src/OrphanScanner.php';
    require_once __DIR__ . '/src/RemovalResult.php';
    require_once __DIR__ . '/src/OrphanRemover.php';
    require_once __DIR__ . '/src/FindOrphansCommand.php';
    require_once __DIR__ . '/src/RemoveOrphansCommand.php';
}

\WP_CLI::add_command('media find-orphans', FindOrphansCommand::class);
\WP_CLI::add_command('media remove-orphans', RemoveOrphansCommand::class);
