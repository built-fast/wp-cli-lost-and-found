<?php

declare(strict_types=1);

namespace WpOrphanage\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WpOrphanage\OrphanDetector;

class OrphanDetectorTest extends TestCase
{
    #[Test]
    public function it_registers_the_main_attached_file(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('2024/03/photo.jpg', []);

        $this->assertTrue($detector->isKnown('2024/03/photo.jpg'));
        $this->assertFalse($detector->isKnown('2024/03/other.jpg'));
    }

    #[Test]
    public function it_registers_all_thumbnail_sizes(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('2024/03/photo.jpg', [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150],
                'medium' => ['file' => 'photo-300x300.jpg', 'width' => 300, 'height' => 300],
                'large' => ['file' => 'photo-1024x768.jpg', 'width' => 1024, 'height' => 768],
            ],
        ]);

        $this->assertTrue($detector->isKnown('2024/03/photo.jpg'));
        $this->assertTrue($detector->isKnown('2024/03/photo-150x150.jpg'));
        $this->assertTrue($detector->isKnown('2024/03/photo-300x300.jpg'));
        $this->assertTrue($detector->isKnown('2024/03/photo-1024x768.jpg'));
    }

    #[Test]
    public function it_registers_wp53_scaled_original(): void
    {
        $detector = new OrphanDetector();

        // WP 5.3+ scales large images. The _wp_attached_file points to the
        // scaled version, and original_image has the original filename.
        $detector->addAttachment('2024/03/photo-scaled.jpg', [
            'original_image' => 'photo.jpg',
            'sizes' => [
                'thumbnail' => ['file' => 'photo-scaled-150x150.jpg', 'width' => 150, 'height' => 150],
            ],
        ]);

        $this->assertTrue($detector->isKnown('2024/03/photo-scaled.jpg'));
        $this->assertTrue($detector->isKnown('2024/03/photo.jpg'));
        $this->assertTrue($detector->isKnown('2024/03/photo-scaled-150x150.jpg'));
    }

    #[Test]
    public function it_handles_attachments_without_metadata(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('2024/03/document.pdf', []);

        $this->assertTrue($detector->isKnown('2024/03/document.pdf'));
        $this->assertSame(1, $detector->getKnownCount());
    }

    #[Test]
    public function it_handles_root_level_files_without_year_month_dir(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('photo.jpg', [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150],
            ],
            'original_image' => 'photo-original.jpg',
        ]);

        $this->assertTrue($detector->isKnown('photo.jpg'));
        $this->assertTrue($detector->isKnown('photo-150x150.jpg'));
        $this->assertTrue($detector->isKnown('photo-original.jpg'));
    }

    #[Test]
    public function it_excludes_configured_directories(): void
    {
        $detector = new OrphanDetector(['cache', 'woocommerce_uploads']);

        $this->assertTrue($detector->isExcluded('cache/advanced-cache.php'));
        $this->assertTrue($detector->isExcluded('woocommerce_uploads/file.zip'));
        $this->assertFalse($detector->isExcluded('2024/03/photo.jpg'));
    }

    #[Test]
    public function it_excludes_index_php_and_htaccess(): void
    {
        $detector = new OrphanDetector();

        $this->assertTrue($detector->isExcluded('index.php'));
        $this->assertTrue($detector->isExcluded('2024/03/index.php'));
        $this->assertTrue($detector->isExcluded('.htaccess'));
        $this->assertTrue($detector->isExcluded('2024/.htaccess'));
    }

    #[Test]
    public function it_does_not_exclude_files_that_merely_contain_excluded_names(): void
    {
        $detector = new OrphanDetector(['cache']);

        // "cache" as part of a filename should not be excluded.
        $this->assertFalse($detector->isExcluded('2024/03/cached-image.jpg'));

        // But files inside the cache directory should be.
        $this->assertTrue($detector->isExcluded('cache/object-cache.tmp'));
    }

    #[Test]
    public function detect_returns_orphans_and_skipped(): void
    {
        $detector = new OrphanDetector(['cache']);
        $detector->addAttachment('2024/03/photo.jpg', [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150],
            ],
        ]);

        $actualFiles = [
            ['path' => '2024/03/photo.jpg', 'size' => 500000, 'mtime' => 1709251200],
            ['path' => '2024/03/photo-150x150.jpg', 'size' => 8000, 'mtime' => 1709251200],
            ['path' => '2024/03/orphan.jpg', 'size' => 100000, 'mtime' => 1709251200],
            ['path' => '2024/03/another-orphan.png', 'size' => 200000, 'mtime' => 1709251200],
            ['path' => 'cache/advanced-cache.php', 'size' => 1000, 'mtime' => 1709251200],
            ['path' => '2024/03/index.php', 'size' => 28, 'mtime' => 1709251200],
        ];

        $result = $detector->detect($actualFiles);

        $this->assertSame(6, $result['total_scanned']);
        $this->assertCount(2, $result['orphans']);
        $this->assertCount(2, $result['skipped']);

        $orphanPaths = array_column($result['orphans'], 'path');
        $this->assertContains('2024/03/orphan.jpg', $orphanPaths);
        $this->assertContains('2024/03/another-orphan.png', $orphanPaths);
    }

    #[Test]
    public function detect_works_with_generator_input(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('photo.jpg', []);

        $generator = (function () {
            yield ['path' => 'photo.jpg', 'size' => 500000, 'mtime' => 1709251200];
            yield ['path' => 'orphan.jpg', 'size' => 100000, 'mtime' => 1709251200];
        })();

        $result = $detector->detect($generator);

        $this->assertSame(2, $result['total_scanned']);
        $this->assertCount(1, $result['orphans']);
        $this->assertSame('orphan.jpg', $result['orphans'][0]['path']);
    }

    #[Test]
    public function detect_with_no_attachments_reports_all_files_as_orphans(): void
    {
        $detector = new OrphanDetector();

        $actualFiles = [
            ['path' => '2024/03/photo.jpg', 'size' => 500000, 'mtime' => 1709251200],
            ['path' => '2024/03/photo-150x150.jpg', 'size' => 8000, 'mtime' => 1709251200],
        ];

        $result = $detector->detect($actualFiles);

        $this->assertSame(0, $detector->getKnownCount());
        $this->assertCount(2, $result['orphans']);
    }

    #[Test]
    public function detect_with_empty_file_list_returns_zero_counts(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('2024/03/photo.jpg', []);

        $result = $detector->detect([]);

        $this->assertSame(0, $result['total_scanned']);
        $this->assertCount(0, $result['orphans']);
        $this->assertCount(0, $result['skipped']);
    }

    #[Test]
    public function it_registers_many_thumbnail_sizes(): void
    {
        $detector = new OrphanDetector();
        $sizes = [];
        $expected = ['2024/03/photo.jpg'];

        for ($i = 1; $i <= 12; $i++) {
            $w = $i * 100;
            $h = $i * 75;
            $file = "photo-{$w}x{$h}.jpg";
            $sizes["custom_{$i}"] = ['file' => $file, 'width' => $w, 'height' => $h];
            $expected[] = "2024/03/{$file}";
        }

        $detector->addAttachment('2024/03/photo.jpg', ['sizes' => $sizes]);

        foreach ($expected as $path) {
            $this->assertTrue($detector->isKnown($path), "Expected {$path} to be known");
        }

        $this->assertSame(13, $detector->getKnownCount());
    }

    #[Test]
    public function it_handles_malformed_sizes_entries(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('2024/03/photo.jpg', [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150],
                'broken_no_file' => ['width' => 300, 'height' => 200],
                'broken_empty_file' => ['file' => '', 'width' => 400, 'height' => 300],
                'broken_not_array' => 'invalid',
            ],
        ]);

        $this->assertTrue($detector->isKnown('2024/03/photo.jpg'));
        $this->assertTrue($detector->isKnown('2024/03/photo-150x150.jpg'));
        // Malformed entries should not add anything.
        $this->assertSame(2, $detector->getKnownCount());
    }

    #[Test]
    public function it_handles_unicode_filenames(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('2024/03/café-photo.jpg', [
            'sizes' => [
                'thumbnail' => ['file' => 'café-photo-150x150.jpg', 'width' => 150, 'height' => 150],
            ],
        ]);

        $this->assertTrue($detector->isKnown('2024/03/café-photo.jpg'));
        $this->assertTrue($detector->isKnown('2024/03/café-photo-150x150.jpg'));
        $this->assertFalse($detector->isKnown('2024/03/cafe-photo.jpg'));
    }

    #[Test]
    public function it_handles_deeply_nested_paths(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('2024/03/subdir/deep/photo.jpg', [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150],
            ],
        ]);

        $this->assertTrue($detector->isKnown('2024/03/subdir/deep/photo.jpg'));
        $this->assertTrue($detector->isKnown('2024/03/subdir/deep/photo-150x150.jpg'));
    }

    #[Test]
    public function it_handles_spaces_in_filenames(): void
    {
        $detector = new OrphanDetector();
        $detector->addAttachment('2024/03/my photo (1).jpg', [
            'sizes' => [
                'thumbnail' => ['file' => 'my photo (1)-150x150.jpg', 'width' => 150, 'height' => 150],
            ],
        ]);

        $this->assertTrue($detector->isKnown('2024/03/my photo (1).jpg'));
        $this->assertTrue($detector->isKnown('2024/03/my photo (1)-150x150.jpg'));
    }
}
