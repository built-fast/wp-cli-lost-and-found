<?php

declare(strict_types=1);

namespace LostAndFound;

/**
 * Pure filesystem removal logic for orphaned files.
 *
 * No WP-CLI dependencies — handles unlink or quarantine (rename).
 */
class OrphanRemover
{
    private string $basedir;

    private ?string $quarantineDir;

    /**
     * @param string      $basedir       Absolute path to uploads basedir.
     * @param string|null $quarantineDir Absolute path to quarantine dir, or null for deletion.
     *
     * @throws \RuntimeException If quarantine dir cannot be created or is not writable.
     */
    public function __construct(string $basedir, ?string $quarantineDir = null)
    {
        $this->basedir = rtrim($basedir, '/');
        $this->quarantineDir = null;

        if ($quarantineDir !== null) {
            $quarantineDir = rtrim($quarantineDir, '/');

            if (! is_dir($quarantineDir) && ! mkdir($quarantineDir, 0755, true)) {
                throw new \RuntimeException("Cannot create quarantine directory: {$quarantineDir}");
            }

            if (! is_writable($quarantineDir)) {
                throw new \RuntimeException("Quarantine directory is not writable: {$quarantineDir}");
            }

            $this->quarantineDir = $quarantineDir;
        }
    }

    /**
     * Remove a list of orphaned files.
     *
     * @param list<array{path: string, size: int, mtime: int}> $orphans Relative paths from basedir.
     */
    public function remove(array $orphans): RemovalResult
    {
        $result = new RemovalResult();

        foreach ($orphans as $orphan) {
            $relativePath = $orphan['path'];
            $fullPath = $this->basedir . '/' . $relativePath;

            if (! file_exists($fullPath)) {
                $result->addSkipped($relativePath, 'File no longer exists');
                continue;
            }

            $size = (int) filesize($fullPath);

            if ($this->quarantineDir !== null) {
                $this->quarantine($fullPath, $relativePath, $size, $result);
            } else {
                $this->delete($fullPath, $relativePath, $size, $result);
            }
        }

        return $result;
    }

    public function getAction(): string
    {
        return $this->quarantineDir !== null ? 'quarantined' : 'deleted';
    }

    private function delete(string $fullPath, string $relativePath, int $size, RemovalResult $result): void
    {
        if (@unlink($fullPath)) {
            $result->addRemoved($relativePath, $size, 'deleted');
        } else {
            $result->addFailed($relativePath, 'Permission denied');
        }
    }

    private function quarantine(string $fullPath, string $relativePath, int $size, RemovalResult $result): void
    {
        $destPath = $this->quarantineDir . '/' . $relativePath;
        $destDir = dirname($destPath);

        if (! is_dir($destDir) && ! mkdir($destDir, 0755, true)) {
            $result->addFailed($relativePath, "Cannot create quarantine subdirectory: {$destDir}");

            return;
        }

        if (@rename($fullPath, $destPath)) {
            $result->addRemoved($relativePath, $size, 'quarantined');
        } else {
            $result->addFailed($relativePath, 'Permission denied');
        }
    }
}
