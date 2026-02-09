Feature: Large media library with zero false positives

  Verifies detection accuracy at scale: 500+ attachments with thumbnails,
  no false positives among known files, and orphans still detected.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: 500 attachments with zero false positives
    # Bulk-create 500 attachment records, each with 3 thumbnail sizes.
    When I run `wp eval 'for ($i = 1; $i <= 500; $i++) { $f = sprintf("img-%04d.jpg", $i); $id = wp_insert_attachment(["post_title" => "Image $i", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], $f); update_post_meta($id, "_wp_attached_file", $f); $base = sprintf("img-%04d", $i); update_post_meta($id, "_wp_attachment_metadata", ["file" => $f, "sizes" => ["thumbnail" => ["file" => "$base-150x150.jpg"], "medium" => ["file" => "$base-300x225.jpg"], "large" => ["file" => "$base-1024x768.jpg"]]]); } echo "created 500";'`
    Then STDOUT should contain:
      """
      created 500
      """

    # Plant a handful of known files on disk.
    Given a wp-content/uploads/img-0001.jpg file:
      """
      img
      """
    And a wp-content/uploads/img-0001-150x150.jpg file:
      """
      thumb
      """
    And a wp-content/uploads/img-0250.jpg file:
      """
      img
      """
    And a wp-content/uploads/img-0250-300x225.jpg file:
      """
      medium
      """
    And a wp-content/uploads/img-0500.jpg file:
      """
      img
      """
    And a wp-content/uploads/img-0500-1024x768.jpg file:
      """
      large
      """

    # Plant orphans.
    And a wp-content/uploads/not-tracked-a.jpg file:
      """
      orphan a
      """
    And a wp-content/uploads/not-tracked-b.png file:
      """
      orphan b
      """

    When I run `wp media find-orphans --format=json`
    Then the return code should be 0
    And STDOUT should be JSON containing:
      """
      {"status":"completed","orphan_count":2}
      """
    # Only the true orphans should appear.
    And STDOUT should contain:
      """
      not-tracked-a.jpg
      """
    And STDOUT should contain:
      """
      not-tracked-b.png
      """
    # Known files must NOT appear as orphans.
    And STDOUT should not contain:
      """
      img-0001.jpg
      """
    And STDOUT should not contain:
      """
      img-0250.jpg
      """
    And STDOUT should not contain:
      """
      img-0500.jpg
      """
