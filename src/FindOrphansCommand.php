<?php

declare(strict_types=1);

namespace WpOrphanage;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_CLI;
use WP_CLI_Command;

/**
 * Find orphaned files in WordPress uploads.
 *
 * Scans the uploads directory (or a provided file list) and cross-references
 * against the WordPress attachment database to identify files that are not
 * tracked as media library items.
 */
class FindOrphansCommand extends WP_CLI_Command
{
    /**
     * Find orphaned files in the uploads directory.
     *
     * Queries all attachments from the WordPress database, builds a set of
     * known file paths (originals, thumbnails, scaled images), then compares
     * against the actual files on disk (or an S3 object listing passed via
     * --file-list). Files not tracked as attachments are reported as orphans.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: json
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
     * [--file-list=<path>]
     * : Path to a file listing for S3 mode. Supports TSV (path\tsize\tmtime)
     *   or plain text (one path per line). When provided, scans these paths
     *   instead of the local filesystem.
     *
     * ## EXAMPLES
     *
     *     # Scan local uploads directory
     *     $ wp media find-orphans
     *
     *     # Scan with table output
     *     $ wp media find-orphans --format=table
     *
     *     # Scan S3 object listing
     *     $ wp media find-orphans --file-list=/tmp/s3-objects.txt
     *
     *     # Custom exclude directories
     *     $ wp media find-orphans --exclude-dirs=cache,woocommerce_uploads
     *
     * @when after_wp_load
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        global $wpdb;

        $format = $assocArgs['format'] ?? 'json';
        $excludeDirsStr = $assocArgs['exclude-dirs'] ?? 'cache';
        $fileList = $assocArgs['file-list'] ?? null;

        $excludeDirs = array_map('trim', explode(',', $excludeDirsStr));

        $startTime = microtime(true);
        $uploadDir = wp_upload_dir();
        $basedir = $uploadDir['basedir'];

        // Step 1: Build known files set from attachment database.
        $detector = new OrphanDetector($excludeDirs);
        $this->loadAttachments($wpdb, $detector);

        WP_CLI::debug(sprintf('Built known files set: %d entries', $detector->getKnownCount()));

        // Step 2: Get actual file list and detect orphans.
        $source = $fileList !== null ? 's3' : 'filesystem';
        $actualFiles = $fileList !== null
            ? $this->readFileList($fileList)
            : $this->scanFilesystem($basedir);

        $result = $detector->detect($actualFiles);

        // Step 3: Build output.
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        $orphanBytes = array_sum(array_column($result['orphans'], 'size'));
        $skippedBytes = 0;

        // Calculate skipped bytes for filesystem mode.
        if ($source === 'filesystem') {
            foreach ($result['skipped'] as $skippedPath) {
                $fullPath = $basedir . '/' . $skippedPath;
                if (file_exists($fullPath)) {
                    $skippedBytes += filesize($fullPath);
                }
            }
        }

        $output = [
            'status' => 'completed',
            'duration_ms' => $durationMs,
            'uploads_basedir' => $basedir,
            'source' => $source,
            'total_files_scanned' => $result['total_scanned'],
            'known_files' => $detector->getKnownCount(),
            'orphan_count' => count($result['orphans']),
            'orphan_bytes' => $orphanBytes,
            'skipped_count' => count($result['skipped']),
            'skipped_bytes' => $skippedBytes,
            'skipped_dirs' => $detector->getExcludeDirs(),
            'orphans' => $result['orphans'],
        ];

        if ($format === 'json') {
            WP_CLI::line((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTable($output);
        }
    }

    /**
     * Query all attachments from the database and register them with the detector.
     *
     * @param \wpdb $wpdb WordPress database instance.
     */
    private function loadAttachments(object $wpdb, OrphanDetector $detector): void
    {
        $perPage = 100;
        $page = 1;

        do {
            $offset = ($page - 1) * $perPage;

            /** @var list<object{ID: int}> $attachments */
            $attachments = $wpdb->get_results($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' LIMIT %d OFFSET %d",
                $perPage,
                $offset
            ));

            foreach ($attachments as $attachment) {
                $file = get_post_meta((int) $attachment->ID, '_wp_attached_file', true);
                $meta = wp_get_attachment_metadata((int) $attachment->ID);

                if (is_string($file) && $file !== '') {
                    $detector->addAttachment($file, is_array($meta) ? $meta : []);
                }
            }

            $page++;
        } while (count($attachments) === $perPage);
    }

    /**
     * Iterate files on the local filesystem under the uploads basedir.
     *
     * @return \Generator<int, array{path: string, size: int, mtime: int}>
     */
    private function scanFilesystem(string $basedir): \Generator
    {
        if (! is_dir($basedir)) {
            WP_CLI::warning("Uploads directory does not exist: {$basedir}");

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basedir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relativePath = ltrim(str_replace($basedir, '', $file->getPathname()), '/');

            yield [
                'path' => $relativePath,
                'size' => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
            ];
        }
    }

    /**
     * Read file entries from a text file.
     *
     * Supports two formats:
     *   - TSV: path\tsize\tmtime (from bash wrapper's S3 listing)
     *   - Plain text: one path per line (size and mtime will be 0)
     *
     * @return \Generator<int, array{path: string, size: int, mtime: int}>
     */
    private function readFileList(string $filePath): \Generator
    {
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            WP_CLI::error("Cannot open file list: {$filePath}");
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);
            $relativePath = $parts[0];

            yield [
                'path' => $relativePath,
                'size' => isset($parts[1]) ? (int) $parts[1] : 0,
                'mtime' => isset($parts[2]) ? (int) $parts[2] : 0,
            ];
        }

        fclose($handle);
    }

    /**
     * Render results as a human-readable table.
     *
     * @param array<string, mixed> $output
     */
    private function renderTable(array $output): void
    {
        WP_CLI::line('');
        WP_CLI::line(sprintf('Uploads:      %s', $output['uploads_basedir']));
        WP_CLI::line(sprintf('Source:       %s', $output['source']));
        WP_CLI::line(sprintf('Known files:  %s', number_format($output['known_files'])));
        WP_CLI::line(sprintf('Scanned:      %s', number_format($output['total_files_scanned'])));
        WP_CLI::line(sprintf('Orphans:      %s (%s)', number_format($output['orphan_count']), size_format($output['orphan_bytes'])));
        WP_CLI::line(sprintf('Skipped:      %s (%s)', number_format($output['skipped_count']), size_format($output['skipped_bytes'])));
        WP_CLI::line(sprintf('Duration:     %d ms', $output['duration_ms']));
        WP_CLI::line('');

        if (! empty($output['orphans'])) {
            $tableData = array_map(function (array $orphan): array {
                return [
                    'path' => $orphan['path'],
                    'size' => size_format($orphan['size']),
                ];
            }, $output['orphans']);

            WP_CLI\Utils\format_items('table', $tableData, ['path', 'size']);
        } else {
            WP_CLI::success('No orphaned files found.');
        }
    }
}
