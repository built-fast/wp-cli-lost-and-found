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
    Given I run `wp option update uploads_use_yearmonth_folders 0`

    # Create a real attachment via wp eval so the DB has records.
    When I run `wp eval '$id = wp_insert_attachment(["post_title" => "Known Image", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "known-image.jpg"); update_post_meta($id, "_wp_attached_file", "known-image.jpg"); update_post_meta($id, "_wp_attachment_metadata", ["file" => "known-image.jpg", "sizes" => ["thumbnail" => ["file" => "known-image-150x150.jpg"]]]); echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    Given a wp-content/uploads/known-image.jpg file:
      """
      real image
      """
    And a wp-content/uploads/known-image-150x150.jpg file:
      """
      real thumb
      """

    # Plant orphan files directly in uploads.
    And a wp-content/uploads/orphan-image.jpg file:
      """
      fake image content
      """
    And a wp-content/uploads/orphan-thumb-150x150.jpg file:
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
    And STDOUT should not contain:
      """
      known-image.jpg
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
    Then STDOUT should be JSON containing:
      """
      {"skipped_count":1}
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
    Given I run `wp option update uploads_use_yearmonth_folders 0`

    # Create a known attachment in the DB.
    When I run `wp eval '$id = wp_insert_attachment(["post_title" => "Known", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "s3-known.jpg"); update_post_meta($id, "_wp_attached_file", "s3-known.jpg"); update_post_meta($id, "_wp_attachment_metadata", ["file" => "s3-known.jpg", "sizes" => ["thumbnail" => ["file" => "s3-known-150x150.jpg"]]]); echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    # Create a file list with the known file + an orphan.
    Given a wp-content/uploads/test-file-list.txt file:
      """
      s3-known.jpg
      s3-known-150x150.jpg
      2024/03/s3-orphan.jpg
      2024/03/s3-orphan-150x150.jpg
      """

    When I run `wp eval 'echo rtrim(wp_upload_dir()["basedir"], "/") . "/test-file-list.txt";'`
    Then save STDOUT as {FILE_LIST_PATH}

    When I run `wp media find-orphans --file-list={FILE_LIST_PATH} --format=json`
    Then STDOUT should be JSON containing:
      """
      {"status":"completed","source":"s3"}
      """
    And STDOUT should contain:
      """
      s3-orphan.jpg
      """
    And STDOUT should not contain:
      """
      s3-known.jpg
      """
    And the return code should be 0

  Scenario: Reports known file count correctly
    Given I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp eval '$id1 = wp_insert_attachment(["post_title" => "Image 1", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "img1.jpg"); update_post_meta($id1, "_wp_attached_file", "img1.jpg"); update_post_meta($id1, "_wp_attachment_metadata", ["file" => "img1.jpg", "sizes" => ["thumbnail" => ["file" => "img1-150x150.jpg"]]]); $id2 = wp_insert_attachment(["post_title" => "Image 2", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "img2.jpg"); update_post_meta($id2, "_wp_attached_file", "img2.jpg"); update_post_meta($id2, "_wp_attachment_metadata", ["file" => "img2.jpg", "sizes" => ["thumbnail" => ["file" => "img2-150x150.jpg"]]]); echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    When I run `wp media find-orphans --format=json`
    Then STDOUT should be JSON containing:
      """
      {"status":"completed"}
      """
    # known_files should be > 0 (4 files: 2 originals + 2 thumbnails).
    And STDOUT should be JSON containing:
      """
      {"known_files":4}
      """
    And the return code should be 0
