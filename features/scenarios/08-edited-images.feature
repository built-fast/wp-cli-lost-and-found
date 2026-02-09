Feature: WordPress image editor files

  WordPress creates -e{timestamp} files when images are edited in the
  media library. These files are tracked in attachment metadata and must
  not be reported as orphans.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Tracks edited image variants correctly
    # Simulate an edited image: WP creates -e{timestamp} versions and
    # updates the sizes metadata to point to the edited thumbnails.
    When I run `wp eval '$id = wp_insert_attachment(["post_title" => "Edited Photo", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "photo-e1700000000.jpg"); update_post_meta($id, "_wp_attached_file", "photo-e1700000000.jpg"); update_post_meta($id, "_wp_attachment_metadata", ["file" => "photo-e1700000000.jpg", "original_image" => "photo.jpg", "sizes" => ["thumbnail" => ["file" => "photo-e1700000000-150x150.jpg"], "medium" => ["file" => "photo-e1700000000-300x225.jpg"]]]); echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    # Plant all files on disk.
    Given a wp-content/uploads/photo-e1700000000.jpg file:
      """
      edited main
      """
    And a wp-content/uploads/photo.jpg file:
      """
      original
      """
    And a wp-content/uploads/photo-e1700000000-150x150.jpg file:
      """
      edited thumb
      """
    And a wp-content/uploads/photo-e1700000000-300x225.jpg file:
      """
      edited medium
      """

    # Plant an orphan that looks like it could be an old edit.
    And a wp-content/uploads/photo-e1699999999.jpg file:
      """
      old edit orphan
      """

    When I run `wp media find-orphans --format=json`
    Then the return code should be 0
    # All tracked edited files should NOT be orphans.
    And STDOUT should not contain:
      """
      photo-e1700000000.jpg
      """
    And STDOUT should not contain:
      """
      photo-e1700000000-150x150.jpg
      """
    And STDOUT should not contain:
      """
      photo-e1700000000-300x225.jpg
      """
    # original_image is tracked.
    And STDOUT should not contain:
      """
      "path": "photo.jpg"
      """
    # Old untracked edit IS an orphan.
    And STDOUT should contain:
      """
      photo-e1699999999.jpg
      """
