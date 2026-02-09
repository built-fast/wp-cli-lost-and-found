<?php

declare(strict_types=1);

$autoloader = dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // Fallback for running without Composer.
    // Only load classes that don't require WP-CLI — command classes
    // are tested via Behat, not PHPUnit.
    require_once dirname(__DIR__) . '/src/OrphanDetector.php';
    require_once dirname(__DIR__) . '/src/RemovalResult.php';
    require_once dirname(__DIR__) . '/src/OrphanRemover.php';
}
