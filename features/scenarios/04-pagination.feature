Feature: Pagination across 100-per-page boundary

  The command loads attachments 100 at a time. Verifies all attachments
  are tracked when there are more than 100 records in the database.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Tracks all attachments across pagination boundary
    # Bulk-create 120 attachment records (no image processing needed).
    When I run `wp eval 'for ($i = 1; $i <= 120; $i++) { $f = sprintf("bulk-%03d.jpg", $i); $id = wp_insert_attachment(["post_title" => "Bulk $i", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], $f); update_post_meta($id, "_wp_attached_file", $f); update_post_meta($id, "_wp_attachment_metadata", ["file" => $f, "sizes" => []]); } echo "created 120";'`
    Then STDOUT should contain:
      """
      created 120
      """

    # Plant files for a few of them + an orphan.
    Given a wp-content/uploads/bulk-001.jpg file:
      """
      img
      """
    And a wp-content/uploads/bulk-060.jpg file:
      """
      img
      """
    And a wp-content/uploads/bulk-120.jpg file:
      """
      img
      """
    And a wp-content/uploads/not-in-database.jpg file:
      """
      orphan
      """

    When I run `wp media find-orphans --format=json`
    Then the return code should be 0
    And STDOUT should be JSON containing:
      """
      {"status":"completed"}
      """
    # The orphan should be found.
    And STDOUT should contain:
      """
      not-in-database.jpg
      """
    # Known bulk files should NOT be orphans.
    And STDOUT should not contain:
      """
      bulk-001.jpg
      """
    And STDOUT should not contain:
      """
      bulk-060.jpg
      """
    And STDOUT should not contain:
      """
      bulk-120.jpg
      """
