# wp-orphanage

Detect and remove orphaned files in WordPress uploads directories.

Scans the uploads directory and cross-references against the WordPress attachment database to identify files not tracked as media library items. Supports local filesystem scanning and S3 object listings.

## Requirements

- PHP 8.1+
- WP-CLI 2.12+

## Installation

```bash
wp package install built-fast/wp-orphanage
```

Or load directly:

```bash
wp --require=wp-orphanage-command.php media find-orphans
```

## Commands

### `wp media find-orphans`

Scan for orphaned files without modifying anything.

```bash
# JSON output (default)
wp media find-orphans

# Human-readable table
wp media find-orphans --format=table

# Scan S3 object listing
wp media find-orphans --file-list=/tmp/s3-objects.txt

# Custom exclude directories
wp media find-orphans --exclude-dirs=cache,woocommerce_uploads,gravity_forms
```

| Option | Default | Description |
|--------|---------|-------------|
| `--format=<format>` | `json` | Output format: `json` or `table` |
| `--exclude-dirs=<dirs>` | `cache` | Comma-separated directory names to skip |
| `--file-list=<path>` | — | Path to file listing (enables S3 mode) |

### `wp media remove-orphans`

Remove orphaned files from the uploads directory.

```bash
# Preview what would be removed
wp media remove-orphans --dry-run

# Remove orphans (with confirmation prompt)
wp media remove-orphans

# Remove without confirmation
wp media remove-orphans --yes

# Move to quarantine directory instead of deleting
wp media remove-orphans --yes --quarantine-dir=/tmp/quarantine

# JSON output for scripting
wp media remove-orphans --yes --format=json
```

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | `false` | Show what would be removed, touch nothing |
| `--yes` | `false` | Skip interactive confirmation |
| `--quarantine-dir=<path>` | — | Move files here instead of deleting; preserves directory structure |
| `--format=<format>` | `table` | Output format: `json` or `table` |
| `--exclude-dirs=<dirs>` | `cache` | Comma-separated directory names to skip |

## What gets detected

- Files left behind after attachments are deleted from the database
- Files uploaded via FTP/SFTP never registered in the media library
- Plugin artifacts in uploads (Gravity Forms, Elementor, WooCommerce, etc.)

## What is always tracked (never reported as orphans)

- Original attachment files
- All thumbnail/size variants from attachment metadata
- WP 5.3+ scaled originals (`original_image`)
- WordPress image editor variants (`-e{timestamp}` files)
- PDF-generated preview thumbnails

## What is always excluded

- `index.php` and `.htaccess` files
- Files inside directories specified by `--exclude-dirs`

## Development

```bash
composer install

# Unit tests
composer phpunit

# Integration tests (requires MySQL)
composer prepare-tests
composer behat

# All tests
composer test
```

## License

MIT
