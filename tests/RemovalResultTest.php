<?php

declare(strict_types=1);

namespace LostAndFound\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use LostAndFound\RemovalResult;

class RemovalResultTest extends TestCase
{
    #[Test]
    public function empty_result_has_zero_counts(): void
    {
        $result = new RemovalResult();

        $this->assertSame(0, $result->getRemovedCount());
        $this->assertSame(0, $result->getFailedCount());
        $this->assertSame(0, $result->getSkippedCount());
        $this->assertSame(0, $result->getRemovedBytes());
        $this->assertFalse($result->hasFailures());
        $this->assertSame([], $result->getRemoved());
        $this->assertSame([], $result->getFailed());
        $this->assertSame([], $result->getSkipped());
    }

    #[Test]
    public function tracks_removed_files(): void
    {
        $result = new RemovalResult();
        $result->addRemoved('2024/03/orphan.jpg', 500000, 'deleted');
        $result->addRemoved('2024/03/another.jpg', 300000, 'deleted');

        $this->assertSame(2, $result->getRemovedCount());
        $this->assertSame(800000, $result->getRemovedBytes());
        $this->assertFalse($result->hasFailures());

        $removed = $result->getRemoved();
        $this->assertSame('2024/03/orphan.jpg', $removed[0]['path']);
        $this->assertSame(500000, $removed[0]['size']);
        $this->assertSame('deleted', $removed[0]['action']);
    }

    #[Test]
    public function tracks_failed_files(): void
    {
        $result = new RemovalResult();
        $result->addFailed('2024/03/locked.jpg', 'Permission denied');

        $this->assertSame(1, $result->getFailedCount());
        $this->assertTrue($result->hasFailures());

        $failed = $result->getFailed();
        $this->assertSame('2024/03/locked.jpg', $failed[0]['path']);
        $this->assertSame('Permission denied', $failed[0]['reason']);
    }

    #[Test]
    public function tracks_skipped_files(): void
    {
        $result = new RemovalResult();
        $result->addSkipped('2024/03/vanished.jpg', 'File no longer exists');

        $this->assertSame(1, $result->getSkippedCount());
        $this->assertFalse($result->hasFailures());

        $skipped = $result->getSkipped();
        $this->assertSame('2024/03/vanished.jpg', $skipped[0]['path']);
        $this->assertSame('File no longer exists', $skipped[0]['reason']);
    }

    #[Test]
    public function mixed_results_reflect_all_categories(): void
    {
        $result = new RemovalResult();
        $result->addRemoved('a.jpg', 100, 'deleted');
        $result->addRemoved('b.jpg', 200, 'deleted');
        $result->addFailed('c.jpg', 'Permission denied');
        $result->addSkipped('d.jpg', 'File no longer exists');

        $this->assertSame(2, $result->getRemovedCount());
        $this->assertSame(1, $result->getFailedCount());
        $this->assertSame(1, $result->getSkippedCount());
        $this->assertSame(300, $result->getRemovedBytes());
        $this->assertTrue($result->hasFailures());
    }

    #[Test]
    public function tracks_quarantined_action(): void
    {
        $result = new RemovalResult();
        $result->addRemoved('orphan.jpg', 1000, 'quarantined');

        $removed = $result->getRemoved();
        $this->assertSame('quarantined', $removed[0]['action']);
    }
}
