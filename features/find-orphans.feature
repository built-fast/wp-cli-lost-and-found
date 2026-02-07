Feature: Find orphaned files in WordPress uploads

  Background:
    Given a WP install

  Scenario: Reports no orphans on a clean install
    When I run `wp media find-orphans --format=json`
    Then STDOUT should be JSON containing:
      """
      {"status":"completed","orphan_count":0}
      """
    And the return code should be 0

  Scenario: Reports no orphans on a clean install with table format
    When I run `wp media find-orphans --format=table`
    Then STDOUT should contain:
      """
      No orphaned files found.
      """
    And the return code should be 0

  Scenario: Detects orphaned files not tracked in the database
    Given a WP install

    # Create a real attachment via WP-CLI so the DB has records.
    When I run `wp media import 'https://via.placeholder.com/300.png' --title="Known Image" --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    # Plant orphan files directly in uploads.
    Given a wp-content/uploads/2024/03/orphan-image.jpg file:
      """
      fake image content
      """
    And a wp-content/uploads/2024/03/orphan-thumb-150x150.jpg file:
      """
      fake thumb content
      """

    When I run `wp media find-orphans --format=json`
    Then STDOUT should be JSON containing:
      """
      {"status":"completed"}
      """
    And STDOUT should contain:
      """
      orphan-image.jpg
      """
    And STDOUT should contain:
      """
      orphan-thumb-150x150.jpg
      """
    And the return code should be 0

  Scenario: Excludes cache directory by default
    Given a wp-content/uploads/cache/object-cache.tmp file:
      """
      cached data
      """
    And a wp-content/uploads/2024/03/orphan.jpg file:
      """
      fake image
      """

    When I run `wp media find-orphans --format=json`
    Then STDOUT should contain:
      """
      "skipped_dirs":["cache"]
      """
    And STDOUT should contain:
      """
      orphan.jpg
      """
    And STDOUT should not contain:
      """
      object-cache.tmp
      """

  Scenario: Custom exclude directories
    Given a wp-content/uploads/woocommerce_uploads/product.zip file:
      """
      product data
      """
    And a wp-content/uploads/2024/03/orphan.jpg file:
      """
      fake image
      """

    When I run `wp media find-orphans --exclude-dirs=cache,woocommerce_uploads --format=json`
    Then STDOUT should not contain:
      """
      product.zip
      """
    And STDOUT should contain:
      """
      orphan.jpg
      """

  Scenario: Excludes index.php and .htaccess files
    Given a wp-content/uploads/index.php file:
      """
      <?php // Silence is golden.
      """
    And a wp-content/uploads/2024/03/index.php file:
      """
      <?php // Silence is golden.
      """
    And a wp-content/uploads/.htaccess file:
      """
      deny from all
      """
    And a wp-content/uploads/2024/03/orphan.jpg file:
      """
      fake image
      """

    When I run `wp media find-orphans --format=json`
    Then STDOUT should contain:
      """
      orphan.jpg
      """
    And STDOUT should not contain:
      """
      index.php
      """

  Scenario: Finds orphans from a file list (S3 mode)
    # Import an image so DB has a known attachment.
    When I run `wp media import 'https://via.placeholder.com/300.png' --title="Known" --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    # Get the attached file path so we can build the file list.
    When I run `wp post meta get {ATTACHMENT_ID} _wp_attached_file`
    Then save STDOUT as {ATTACHED_FILE}

    # Create a file list with the known file + an orphan.
    Given a /tmp/test-file-list.txt file:
      """
      {ATTACHED_FILE}
      2024/03/s3-orphan.jpg
      2024/03/s3-orphan-150x150.jpg
      """

    When I run `wp media find-orphans --file-list=/tmp/test-file-list.txt --format=json`
    Then STDOUT should be JSON containing:
      """
      {"status":"completed","source":"s3"}
      """
    And STDOUT should contain:
      """
      s3-orphan.jpg
      """
    And the return code should be 0

  Scenario: Reports known file count correctly
    When I run `wp media import 'https://via.placeholder.com/300.png' --title="Image 1" --porcelain`
    And I run `wp media import 'https://via.placeholder.com/600.png' --title="Image 2" --porcelain`

    When I run `wp media find-orphans --format=json`
    Then STDOUT should be JSON containing:
      """
      {"status":"completed"}
      """
    # known_files should be > 0 (exact count depends on WP thumbnail config).
    And STDOUT should not contain:
      """
      "known_files":0
      """
    And the return code should be 0
