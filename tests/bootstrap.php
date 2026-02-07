<?php

declare(strict_types=1);

$autoloader = dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // Fallback for running without Composer.
    // Only load OrphanDetector — FindOrphansCommand requires WP-CLI
    // and is tested via Behat, not PHPUnit.
    require_once dirname(__DIR__) . '/src/OrphanDetector.php';
}
