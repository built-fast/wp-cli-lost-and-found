<?php

declare(strict_types=1);

namespace LostAndFound;

use WP_CLI;
use WP_CLI_Command;

/**
 * Remove orphaned files from WordPress uploads.
 *
 * Scans the uploads directory, identifies files not tracked in the media
 * library, and deletes or quarantines them.
 */
class RemoveOrphansCommand extends WP_CLI_Command
{
    /**
     * Remove orphaned files from the uploads directory.
     *
     * Scans the local filesystem for orphaned files and removes them.
     * By default, files are deleted permanently. Use --quarantine-dir to
     * move files instead of deleting.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be removed without touching any files.
     *
     * [--yes]
     * : Skip interactive confirmation prompt.
     *
     * [--quarantine-dir=<path>]
     * : Move files here instead of deleting. Preserves directory structure.
     *   Created if missing.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - json
     *   - table
     * ---
     *
     * [--exclude-dirs=<dirs>]
     * : Comma-separated directory names to skip.
     * ---
     * default: cache
     * ---
     *
     * ## EXAMPLES
     *
     *     # Preview what would be removed
     *     $ wp media remove-orphans --dry-run
     *
     *     # Remove orphans without confirmation
     *     $ wp media remove-orphans --yes
     *
     *     # Quarantine instead of deleting
     *     $ wp media remove-orphans --yes --quarantine-dir=/tmp/quarantine
     *
     *     # JSON output for scripting
     *     $ wp media remove-orphans --yes --format=json
     *
     * @when after_wp_load
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        global $wpdb;

        $format = $assocArgs['format'] ?? 'table';
        $excludeDirsStr = $assocArgs['exclude-dirs'] ?? 'cache';
        $dryRun = (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'dry-run', false);
        $yes = (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'yes', false);
        $quarantineDir = $assocArgs['quarantine-dir'] ?? null;

        $excludeDirs = array_map('trim', explode(',', $excludeDirsStr));

        $startTime = microtime(true);
        $uploadDir = wp_upload_dir();
        $basedir = $uploadDir['basedir'];

        // Step 1: Scan for orphans.
        $scanner = new OrphanScanner(new OrphanDetector($excludeDirs));
        $scanner->loadAttachments($wpdb);
        $detector = $scanner->getDetector();

        $actualFiles = $scanner->scanFilesystem($basedir);
        $scanResult = $detector->detect($actualFiles);

        // Step 2: No orphans found.
        if ($scanResult['orphans'] === []) {
            if ($format === 'json') {
                $output = [
                    'status' => $dryRun ? 'dry_run' : 'completed',
                    'action' => $quarantineDir !== null ? 'quarantined' : 'deleted',
                    'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                    'uploads_basedir' => $basedir,
                    'quarantine_dir' => $quarantineDir,
                    'removed_count' => 0,
                    'removed_bytes' => 0,
                    'failed_count' => 0,
                    'skipped_count' => 0,
                    'removed' => [],
                    'failed' => [],
                ];
                WP_CLI::line((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                WP_CLI::success('No orphaned files found.');
            }

            return;
        }

        // Step 3: Dry run — show orphans and exit.
        if ($dryRun) {
            $this->outputDryRun($scanResult['orphans'], $basedir, $quarantineDir, $startTime, $format);

            return;
        }

        // Step 4: Confirm with user.
        $orphanCount = count($scanResult['orphans']);
        $orphanBytes = array_sum(array_column($scanResult['orphans'], 'size'));
        $action = $quarantineDir !== null ? 'quarantine' : 'delete';

        if (! $yes) {
            WP_CLI::line('');
            WP_CLI::line(sprintf('Found %d orphaned file(s) totaling %s.', $orphanCount, size_format($orphanBytes)));
            WP_CLI::confirm(sprintf('Are you sure you want to %s these files?', $action));
        }

        // Step 5: Remove files.
        try {
            $remover = new OrphanRemover($basedir, $quarantineDir);
        } catch (\RuntimeException $e) {
            WP_CLI::error($e->getMessage());

            return;
        }

        $removalResult = $remover->remove($scanResult['orphans']);

        // Step 6: Output results.
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($format === 'json') {
            $this->outputJsonResult($removalResult, $remover, $basedir, $quarantineDir, $durationMs);
        } else {
            $this->outputTableResult($removalResult, $remover);
        }

        // Exit 1 if all files failed.
        if ($removalResult->getRemovedCount() === 0 && $removalResult->hasFailures()) {
            WP_CLI::error('All files failed to be removed.');
        }

        if ($removalResult->hasFailures()) {
            WP_CLI::warning(sprintf('%d file(s) could not be removed.', $removalResult->getFailedCount()));
        }
    }

    /**
     * @param list<array{path: string, size: int, mtime: int}> $orphans
     */
    private function outputDryRun(array $orphans, string $basedir, ?string $quarantineDir, float $startTime, string $format): void
    {
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($format === 'json') {
            $output = [
                'status' => 'dry_run',
                'action' => $quarantineDir !== null ? 'quarantined' : 'deleted',
                'duration_ms' => $durationMs,
                'uploads_basedir' => $basedir,
                'quarantine_dir' => $quarantineDir,
                'removed_count' => count($orphans),
                'removed_bytes' => array_sum(array_column($orphans, 'size')),
                'failed_count' => 0,
                'skipped_count' => 0,
                'removed' => array_map(function (array $orphan) use ($quarantineDir): array {
                    return [
                        'path' => $orphan['path'],
                        'size' => $orphan['size'],
                        'action' => $quarantineDir !== null ? 'quarantined' : 'deleted',
                    ];
                }, $orphans),
                'failed' => [],
            ];
            WP_CLI::line((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $action = $quarantineDir !== null ? 'quarantined' : 'deleted';
            WP_CLI::line('');
            WP_CLI::line(sprintf('Dry run: %d file(s) would be %s.', count($orphans), $action));
            WP_CLI::line('');

            $tableData = array_map(function (array $orphan): array {
                return [
                    'path' => $orphan['path'],
                    'size' => size_format($orphan['size']),
                ];
            }, $orphans);

            WP_CLI\Utils\format_items('table', $tableData, ['path', 'size']);
        }
    }

    private function outputJsonResult(RemovalResult $result, OrphanRemover $remover, string $basedir, ?string $quarantineDir, int $durationMs): void
    {
        $output = [
            'status' => 'completed',
            'action' => $remover->getAction(),
            'duration_ms' => $durationMs,
            'uploads_basedir' => $basedir,
            'quarantine_dir' => $quarantineDir,
            'removed_count' => $result->getRemovedCount(),
            'removed_bytes' => $result->getRemovedBytes(),
            'failed_count' => $result->getFailedCount(),
            'skipped_count' => $result->getSkippedCount(),
            'removed' => $result->getRemoved(),
            'failed' => $result->getFailed(),
        ];

        WP_CLI::line((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function outputTableResult(RemovalResult $result, OrphanRemover $remover): void
    {
        $action = $remover->getAction();

        WP_CLI::line('');

        if ($result->getRemovedCount() > 0) {
            WP_CLI::success(sprintf(
                '%d file(s) %s (%s).',
                $result->getRemovedCount(),
                $action,
                size_format($result->getRemovedBytes())
            ));
        }

        if ($result->getSkippedCount() > 0) {
            WP_CLI::line(sprintf('Skipped: %d file(s) (no longer on disk).', $result->getSkippedCount()));
        }

        if ($result->hasFailures()) {
            WP_CLI::line('');
            WP_CLI::line('Failed:');

            $tableData = array_map(function (array $failed): array {
                return [
                    'path' => $failed['path'],
                    'reason' => $failed['reason'],
                ];
            }, $result->getFailed());

            WP_CLI\Utils\format_items('table', $tableData, ['path', 'reason']);
        }
    }
}
