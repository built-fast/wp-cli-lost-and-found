Feature: WP 5.3+ scaled images

  WordPress 5.3+ creates -scaled versions of large images and stores
  the original filename in original_image metadata. Both the scaled
  file and the original must be tracked as known.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Tracks scaled image and original correctly
    # Simulate a WP 5.3+ scaled attachment:
    #   - "big-photo-scaled.jpg" is the _wp_attached_file (what WP uses)
    #   - "big-photo.jpg" is the original_image (full-res original)
    When I run `wp eval '$id = wp_insert_attachment(["post_title" => "Big Photo", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "big-photo-scaled.jpg"); update_post_meta($id, "_wp_attached_file", "big-photo-scaled.jpg"); update_post_meta($id, "_wp_attachment_metadata", ["file" => "big-photo-scaled.jpg", "original_image" => "big-photo.jpg", "sizes" => ["thumbnail" => ["file" => "big-photo-150x150.jpg"], "medium" => ["file" => "big-photo-300x225.jpg"]]]); echo "id:$id";'`
    Then STDOUT should contain:
      """
      id:
      """

    # Plant all the files on disk.
    Given a wp-content/uploads/big-photo-scaled.jpg file:
      """
      scaled version
      """
    And a wp-content/uploads/big-photo.jpg file:
      """
      original full-res
      """
    And a wp-content/uploads/big-photo-150x150.jpg file:
      """
      thumbnail
      """
    And a wp-content/uploads/big-photo-300x225.jpg file:
      """
      medium
      """

    # Plant orphans that look like a deleted scaled attachment.
    And a wp-content/uploads/deleted-photo-scaled.jpg file:
      """
      orphan scaled
      """
    And a wp-content/uploads/deleted-photo.jpg file:
      """
      orphan original
      """

    When I run `wp media find-orphans --format=json`
    Then the return code should be 0
    # Real scaled set should NOT be orphans.
    And STDOUT should not contain:
      """
      big-photo
      """
    # Orphan scaled set should be detected.
    And STDOUT should contain:
      """
      deleted-photo-scaled.jpg
      """
    And STDOUT should contain:
      """
      deleted-photo.jpg
      """
