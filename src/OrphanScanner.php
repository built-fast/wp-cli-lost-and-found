<?php

declare(strict_types=1);

namespace LostAndFound;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Shared scan orchestration for orphan detection.
 *
 * Owns attachment loading, filesystem scanning, and file list reading.
 * Used by both FindOrphansCommand and RemoveOrphansCommand.
 */
class OrphanScanner
{
    private OrphanDetector $detector;

    public function __construct(OrphanDetector $detector)
    {
        $this->detector = $detector;
    }

    public function getDetector(): OrphanDetector
    {
        return $this->detector;
    }

    /**
     * Query all attachments from the database and register them with the detector.
     *
     * @param \wpdb $wpdb WordPress database instance.
     */
    public function loadAttachments(object $wpdb): void
    {
        $perPage = 100;
        $page = 1;

        do {
            $offset = ($page - 1) * $perPage;

            /** @var list<\stdClass> $attachments */
            $attachments = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table prefix is safe
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' LIMIT %d OFFSET %d",
                    $perPage,
                    $offset
                )
            );

            foreach ($attachments as $attachment) {
                $file = get_post_meta((int) $attachment->ID, '_wp_attached_file', true);
                $meta = wp_get_attachment_metadata((int) $attachment->ID);

                if (is_string($file) && $file !== '') {
                    $this->detector->addAttachment($file, is_array($meta) ? $meta : []);
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
    public function scanFilesystem(string $basedir): \Generator
    {
        if (! is_dir($basedir)) {
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
    public function readFileList(string $filePath): \Generator
    {
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open file list: {$filePath}");
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
}
