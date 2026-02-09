Feature: Multisite orphan detection

  Scans subsite uploads without cross-site contamination. Each subsite's
  uploads live under wp-content/uploads/sites/<id>/ and should be scanned
  independently.

  Scenario: Scans subsite uploads without cross-site contamination
    Given a WP multisite subdirectory install

    # Create a subsite.
    When I run `wp site create --slug=child --porcelain`
    Then save STDOUT as {CHILD_SITE_ID}

    # Get the child site URL.
    When I run `wp site url {CHILD_SITE_ID}`
    Then save STDOUT as {CHILD_URL}

    # Create an attachment on the child site.
    When I run `wp eval 'switch_to_blog({CHILD_SITE_ID}); $id = wp_insert_attachment(["post_title" => "Child Photo", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "child-photo.jpg"); update_post_meta($id, "_wp_attached_file", "child-photo.jpg"); update_post_meta($id, "_wp_attachment_metadata", ["file" => "child-photo.jpg", "sizes" => ["thumbnail" => ["file" => "child-photo-150x150.jpg"]]]); restore_current_blog(); echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    # Plant files in the child site's uploads dir.
    Given a wp-content/uploads/sites/{CHILD_SITE_ID}/child-photo.jpg file:
      """
      child image
      """
    And a wp-content/uploads/sites/{CHILD_SITE_ID}/child-photo-150x150.jpg file:
      """
      child thumb
      """
    And a wp-content/uploads/sites/{CHILD_SITE_ID}/child-orphan.jpg file:
      """
      child orphan
      """

    # Scan the child site — only child-orphan.jpg is unknown.
    When I run `wp media find-orphans --url={CHILD_URL} --format=json`
    Then the return code should be 0
    And STDOUT should contain:
      """
      child-orphan.jpg
      """
    And STDOUT should not contain:
      """
      child-photo.jpg
      """
