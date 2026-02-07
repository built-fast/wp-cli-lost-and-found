Feature: Standard blog orphan detection

  Detects the most common orphan sources: deleted posts leaving media
  files behind, and files uploaded via FTP never attached to any post.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Detects orphans from deleted attachments and FTP uploads
    # Create attachment records directly in the DB (no network/GD needed).
    When I run `wp eval 'foreach (["photo1.jpg", "photo2.jpg"] as $f) { $id = wp_insert_attachment(["post_title" => $f, "post_mime_type" => "image/jpeg", "post_status" => "inherit"], $f); update_post_meta($id, "_wp_attached_file", $f); update_post_meta($id, "_wp_attachment_metadata", ["file" => $f, "sizes" => ["thumbnail" => ["file" => str_replace(".jpg", "-150x150.jpg", $f)]]]); } echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    # Plant files on disk for both attachments.
    Given a wp-content/uploads/photo1.jpg file:
      """
      img1
      """
    And a wp-content/uploads/photo1-150x150.jpg file:
      """
      thumb1
      """
    And a wp-content/uploads/photo2.jpg file:
      """
      img2
      """
    And a wp-content/uploads/photo2-150x150.jpg file:
      """
      thumb2
      """

    # Delete photo2 from DB only — files stay on disk as orphans.
    When I run `wp eval 'global $wpdb; $id = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key=\"_wp_attached_file\" AND meta_value=\"photo2.jpg\""); $wpdb->delete($wpdb->posts, ["ID" => $id]); $wpdb->delete($wpdb->postmeta, ["post_id" => $id]); echo "deleted $id";'`
    Then STDOUT should contain:
      """
      deleted
      """

    # Plant FTP-uploaded files (never in media library).
    Given a wp-content/uploads/client-logo.png file:
      """
      fake logo
      """
    And a wp-content/uploads/ftp-upload.jpg file:
      """
      ftp image
      """

    When I run `wp media find-orphans --format=json`
    Then the return code should be 0
    And STDOUT should be JSON containing:
      """
      {"status":"completed"}
      """
    # Deleted attachment files are orphans.
    And STDOUT should contain:
      """
      photo2.jpg
      """
    And STDOUT should contain:
      """
      photo2-150x150.jpg
      """
    # FTP uploads are orphans.
    And STDOUT should contain:
      """
      ftp-upload.jpg
      """
    And STDOUT should contain:
      """
      client-logo.png
      """
    # Kept attachment is NOT an orphan.
    And STDOUT should not contain:
      """
      photo1.jpg
      """
