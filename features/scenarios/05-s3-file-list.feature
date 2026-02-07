Feature: S3 file list mode

  The --file-list flag accepts a text file of paths instead of scanning
  the local filesystem. Supports TSV (path\tsize\tmtime) and plain text.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: TSV file list with sizes
    # Create a known attachment.
    When I run `wp eval '$id = wp_insert_attachment(["post_title" => "S3 Photo", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "s3-photo.jpg"); update_post_meta($id, "_wp_attached_file", "s3-photo.jpg"); update_post_meta($id, "_wp_attachment_metadata", ["file" => "s3-photo.jpg", "sizes" => ["thumbnail" => ["file" => "s3-photo-150x150.jpg"]]]); echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    # TSV file list: known files + orphan + cache entry.
    Given a /tmp/s3-tsv-list.txt file:
      """
      s3-photo.jpg	5000	1710000000
      s3-photo-150x150.jpg	1000	1710000000
      s3-orphan.jpg	9999	1710000001
      cache/cached-file.tmp	500	1710000002
      """

    When I run `wp media find-orphans --file-list=/tmp/s3-tsv-list.txt --format=json`
    Then the return code should be 0
    And STDOUT should be JSON containing:
      """
      {"status":"completed","source":"s3"}
      """
    And STDOUT should contain:
      """
      s3-orphan.jpg
      """
    And STDOUT should not contain:
      """
      cached-file.tmp
      """

  Scenario: Plain text file list
    When I run `wp eval '$id = wp_insert_attachment(["post_title" => "Plain Photo", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "plain-photo.jpg"); update_post_meta($id, "_wp_attached_file", "plain-photo.jpg"); update_post_meta($id, "_wp_attachment_metadata", ["file" => "plain-photo.jpg", "sizes" => []]); echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    Given a /tmp/s3-plain-list.txt file:
      """
      plain-photo.jpg
      plain-orphan.jpg
      plain-orphan-300x200.jpg
      """

    When I run `wp media find-orphans --file-list=/tmp/s3-plain-list.txt --format=json`
    Then the return code should be 0
    And STDOUT should be JSON containing:
      """
      {"status":"completed","source":"s3"}
      """
    And STDOUT should contain:
      """
      plain-orphan.jpg
      """
    And STDOUT should contain:
      """
      plain-orphan-300x200.jpg
      """
