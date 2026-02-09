<?php

declare(strict_types=1);

namespace LostAndFound;

/**
 * Pure detection logic for finding orphaned files in WordPress uploads.
 *
 * Builds a hashset of known attachment file paths (originals, thumbnails,
 * scaled images) and diffs against an actual file list. No WordPress
 * dependencies — all WP-specific querying happens in the command class.
 */
class OrphanDetector
{
    /** @var array<string, true> Known file paths (relative to uploads basedir). */
    private array $knownFiles = [];

    /** @var list<string> Directory names to skip. */
    private array $excludeDirs = [];

    /** @var list<string> Filenames to skip (exact basename match). */
    private array $excludeFiles = ['index.php', '.htaccess'];

    /**
     * @param list<string> $excludeDirs Directory names to exclude (e.g. ['cache']).
     */
    public function __construct(array $excludeDirs = ['cache'])
    {
        $this->excludeDirs = $excludeDirs;
    }

    /**
     * Register a known attachment and all its derived files.
     *
     * @param string               $attachedFile Relative path from uploads (e.g. '2024/03/photo.jpg').
     * @param array<string, mixed> $metadata     Unserialized _wp_attachment_metadata.
     */
    public function addAttachment(string $attachedFile, array $metadata): void
    {
        $this->knownFiles[$attachedFile] = true;

        $dir = dirname($attachedFile);
        if ($dir === '.') {
            $dir = '';
        }

        // Thumbnails from sizes array.
        if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (! empty($size['file'])) {
                    $path = $dir !== '' ? $dir . '/' . $size['file'] : $size['file'];
                    $this->knownFiles[$path] = true;
                }
            }
        }

        // WP 5.3+ scaled originals — the original full-res file before scaling.
        if (! empty($metadata['original_image'])) {
            $path = $dir !== '' ? $dir . '/' . $metadata['original_image'] : $metadata['original_image'];
            $this->knownFiles[$path] = true;
        }
    }

    /**
     * Check if a relative path belongs to a known attachment.
     */
    public function isKnown(string $relativePath): bool
    {
        return isset($this->knownFiles[$relativePath]);
    }

    /**
     * Check if a relative path should be excluded from orphan detection.
     */
    public function isExcluded(string $relativePath): bool
    {
        foreach ($this->excludeDirs as $dir) {
            if (str_starts_with($relativePath, $dir . '/')) {
                return true;
            }
        }

        $basename = basename($relativePath);

        return in_array($basename, $this->excludeFiles, true);
    }

    /**
     * Scan a list of actual file paths and return orphans + skipped.
     *
     * @param iterable<array{path: string, size: int, mtime: int}> $actualFiles
     * @return array{orphans: list<array{path: string, size: int, mtime: int}>, skipped: list<string>, total_scanned: int}
     */
    public function detect(iterable $actualFiles): array
    {
        $orphans = [];
        $skipped = [];
        $totalScanned = 0;

        foreach ($actualFiles as $file) {
            $totalScanned++;

            if ($this->isExcluded($file['path'])) {
                $skipped[] = $file['path'];
                continue;
            }

            if (! $this->isKnown($file['path'])) {
                $orphans[] = $file;
            }
        }

        return [
            'orphans' => $orphans,
            'skipped' => $skipped,
            'total_scanned' => $totalScanned,
        ];
    }

    public function getKnownCount(): int
    {
        return count($this->knownFiles);
    }

    /**
     * @return list<string>
     */
    public function getExcludeDirs(): array
    {
        return $this->excludeDirs;
    }
}
