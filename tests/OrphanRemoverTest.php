<?php

declare(strict_types=1);

namespace LostAndFound\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use LostAndFound\OrphanRemover;

class OrphanRemoverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/wp-cli-lost-and-found-test-' . uniqid();
        mkdir($this->tempDir . '/uploads', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function deletes_files_and_verifies_gone(): void
    {
        $basedir = $this->tempDir . '/uploads';
        $this->createFile($basedir . '/orphan.jpg', 'orphan content');
        $this->createFile($basedir . '/another.png', 'another orphan');

        $remover = new OrphanRemover($basedir);
        $result = $remover->remove([
            ['path' => 'orphan.jpg', 'size' => 14, 'mtime' => 0],
            ['path' => 'another.png', 'size' => 15, 'mtime' => 0],
        ]);

        $this->assertSame(2, $result->getRemovedCount());
        $this->assertSame(0, $result->getFailedCount());
        $this->assertSame(0, $result->getSkippedCount());
        $this->assertFalse(file_exists($basedir . '/orphan.jpg'));
        $this->assertFalse(file_exists($basedir . '/another.png'));
        $this->assertSame('deleted', $remover->getAction());
    }

    #[Test]
    public function quarantines_files_with_directory_structure(): void
    {
        $basedir = $this->tempDir . '/uploads';
        $quarantineDir = $this->tempDir . '/quarantine';

        mkdir($basedir . '/2024/03', 0755, true);
        $this->createFile($basedir . '/2024/03/orphan.jpg', 'orphan');

        $remover = new OrphanRemover($basedir, $quarantineDir);
        $result = $remover->remove([
            ['path' => '2024/03/orphan.jpg', 'size' => 6, 'mtime' => 0],
        ]);

        $this->assertSame(1, $result->getRemovedCount());
        $this->assertFalse(file_exists($basedir . '/2024/03/orphan.jpg'));
        $this->assertTrue(file_exists($quarantineDir . '/2024/03/orphan.jpg'));
        $this->assertSame('quarantined', $result->getRemoved()[0]['action']);
        $this->assertSame('quarantined', $remover->getAction());
    }

    #[Test]
    public function file_vanished_between_scan_and_removal_is_skipped(): void
    {
        $basedir = $this->tempDir . '/uploads';

        $remover = new OrphanRemover($basedir);
        $result = $remover->remove([
            ['path' => 'vanished.jpg', 'size' => 100, 'mtime' => 0],
        ]);

        $this->assertSame(0, $result->getRemovedCount());
        $this->assertSame(0, $result->getFailedCount());
        $this->assertSame(1, $result->getSkippedCount());
        $this->assertSame('File no longer exists', $result->getSkipped()[0]['reason']);
    }

    #[Test]
    public function permission_denied_recorded_as_failure_continues(): void
    {
        $basedir = $this->tempDir . '/uploads';
        mkdir($basedir . '/locked', 0755, true);
        $this->createFile($basedir . '/locked/file.jpg', 'content');
        $this->createFile($basedir . '/deletable.jpg', 'deletable');

        // Make directory unwritable so unlink fails.
        chmod($basedir . '/locked', 0555);

        $remover = new OrphanRemover($basedir);
        $result = $remover->remove([
            ['path' => 'locked/file.jpg', 'size' => 7, 'mtime' => 0],
            ['path' => 'deletable.jpg', 'size' => 9, 'mtime' => 0],
        ]);

        // Restore permissions for cleanup.
        chmod($basedir . '/locked', 0755);

        $this->assertSame(1, $result->getRemovedCount());
        $this->assertSame(1, $result->getFailedCount());
        $this->assertTrue($result->hasFailures());
        $this->assertSame('locked/file.jpg', $result->getFailed()[0]['path']);
        $this->assertSame('deletable.jpg', $result->getRemoved()[0]['path']);
    }

    #[Test]
    public function unwritable_quarantine_dir_throws_runtime_exception(): void
    {
        $basedir = $this->tempDir . '/uploads';
        $quarantineDir = $this->tempDir . '/nope';

        // Create quarantine dir as unwritable.
        mkdir($quarantineDir, 0555, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not writable');

        try {
            new OrphanRemover($basedir, $quarantineDir);
        } finally {
            chmod($quarantineDir, 0755);
        }
    }

    #[Test]
    public function empty_orphan_list_returns_zero_count_result(): void
    {
        $basedir = $this->tempDir . '/uploads';

        $remover = new OrphanRemover($basedir);
        $result = $remover->remove([]);

        $this->assertSame(0, $result->getRemovedCount());
        $this->assertSame(0, $result->getFailedCount());
        $this->assertSame(0, $result->getSkippedCount());
        $this->assertFalse($result->hasFailures());
    }

    #[Test]
    public function quarantine_dir_created_if_missing(): void
    {
        $basedir = $this->tempDir . '/uploads';
        $quarantineDir = $this->tempDir . '/new-quarantine/subdir';

        $this->createFile($basedir . '/orphan.jpg', 'orphan');

        $remover = new OrphanRemover($basedir, $quarantineDir);
        $result = $remover->remove([
            ['path' => 'orphan.jpg', 'size' => 6, 'mtime' => 0],
        ]);

        $this->assertTrue(is_dir($quarantineDir));
        $this->assertSame(1, $result->getRemovedCount());
        $this->assertTrue(file_exists($quarantineDir . '/orphan.jpg'));
    }

    #[Test]
    public function records_actual_file_size_on_disk(): void
    {
        $basedir = $this->tempDir . '/uploads';
        $content = str_repeat('x', 1024);
        $this->createFile($basedir . '/orphan.jpg', $content);

        $remover = new OrphanRemover($basedir);
        $result = $remover->remove([
            ['path' => 'orphan.jpg', 'size' => 999, 'mtime' => 0],
        ]);

        // Should use actual disk size, not the size from the orphan list.
        $this->assertSame(1024, $result->getRemovedBytes());
    }

    private function createFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                chmod($file->getPathname(), 0755);
                rmdir($file->getPathname());
            } else {
                chmod(dirname($file->getPathname()), 0755);
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
