<?php

declare(strict_types=1);

namespace WpOrphanage;

/**
 * Value object for orphan removal outcomes.
 */
class RemovalResult
{
    /** @var list<array{path: string, size: int, action: string}> */
    private array $removed = [];

    /** @var list<array{path: string, reason: string}> */
    private array $failed = [];

    /** @var list<array{path: string, reason: string}> */
    private array $skipped = [];

    public function addRemoved(string $path, int $size, string $action): void
    {
        $this->removed[] = ['path' => $path, 'size' => $size, 'action' => $action];
    }

    public function addFailed(string $path, string $reason): void
    {
        $this->failed[] = ['path' => $path, 'reason' => $reason];
    }

    public function addSkipped(string $path, string $reason): void
    {
        $this->skipped[] = ['path' => $path, 'reason' => $reason];
    }

    public function getRemovedCount(): int
    {
        return count($this->removed);
    }

    public function getFailedCount(): int
    {
        return count($this->failed);
    }

    public function getSkippedCount(): int
    {
        return count($this->skipped);
    }

    public function getRemovedBytes(): int
    {
        return array_sum(array_column($this->removed, 'size'));
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    /**
     * @return list<array{path: string, size: int, action: string}>
     */
    public function getRemoved(): array
    {
        return $this->removed;
    }

    /**
     * @return list<array{path: string, reason: string}>
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * @return list<array{path: string, reason: string}>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }
}
