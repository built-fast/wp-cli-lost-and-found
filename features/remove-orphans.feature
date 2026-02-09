Feature: Remove orphaned files from WordPress uploads

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Dry-run shows orphans but does not remove files
    Given a wp-content/uploads/orphan.jpg file:
      """
      orphan content
      """

    When I run `wp media remove-orphans --dry-run`
    Then the return code should be 0
    And STDOUT should contain:
      """
      orphan.jpg
      """
    And STDOUT should contain:
      """
      Dry run
      """
    And the wp-content/uploads/orphan.jpg file should exist

  Scenario: Deletes orphan files with --yes
    Given a wp-content/uploads/orphan-a.jpg file:
      """
      orphan a
      """
    And a wp-content/uploads/orphan-b.png file:
      """
      orphan b
      """

    When I run `wp media remove-orphans --yes`
    Then the return code should be 0
    And STDOUT should contain:
      """
      deleted
      """
    And the wp-content/uploads/orphan-a.jpg file should not exist
    And the wp-content/uploads/orphan-b.png file should not exist

  Scenario: Does NOT delete known attachment files
    # Create a real attachment in the DB.
    When I run `wp eval '$id = wp_insert_attachment(["post_title" => "Keep Me", "post_mime_type" => "image/jpeg", "post_status" => "inherit"], "keep-me.jpg"); update_post_meta($id, "_wp_attached_file", "keep-me.jpg"); update_post_meta($id, "_wp_attachment_metadata", ["file" => "keep-me.jpg", "sizes" => ["thumbnail" => ["file" => "keep-me-150x150.jpg"]]]); echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    Given a wp-content/uploads/keep-me.jpg file:
      """
      real attachment
      """
    And a wp-content/uploads/keep-me-150x150.jpg file:
      """
      real thumb
      """
    And a wp-content/uploads/orphan.jpg file:
      """
      orphan
      """

    When I run `wp media remove-orphans --yes`
    Then the return code should be 0
    # Known files must still exist.
    And the wp-content/uploads/keep-me.jpg file should exist
    And the wp-content/uploads/keep-me-150x150.jpg file should exist
    # Orphan is removed.
    And the wp-content/uploads/orphan.jpg file should not exist

  Scenario: Quarantines to specified directory
    Given a wp-content/uploads/2024/03/orphan.jpg file:
      """
      orphan content
      """

    When I run `wp media remove-orphans --yes --quarantine-dir={RUN_DIR}/quarantine`
    Then the return code should be 0
    And STDOUT should contain:
      """
      quarantined
      """
    # File removed from uploads.
    And the wp-content/uploads/2024/03/orphan.jpg file should not exist

  Scenario: Quarantine preserves directory structure
    Given a wp-content/uploads/2024/03/deep-orphan.jpg file:
      """
      deep orphan
      """

    When I run `wp media remove-orphans --yes --quarantine-dir={RUN_DIR}/quarantine`
    Then the return code should be 0
    And STDOUT should contain:
      """
      quarantined
      """

  Scenario: No orphans reports success
    When I run `wp media remove-orphans --yes`
    Then the return code should be 0
    And STDOUT should contain:
      """
      No orphaned files found.
      """

  Scenario: JSON output for dry-run
    Given a wp-content/uploads/orphan.jpg file:
      """
      orphan
      """

    When I run `wp media remove-orphans --dry-run --format=json`
    Then the return code should be 0
    And STDOUT should be JSON containing:
      """
      {"status":"dry_run"}
      """
    And STDOUT should contain:
      """
      orphan.jpg
      """

  Scenario: JSON output for completed removal
    Given a wp-content/uploads/orphan.jpg file:
      """
      orphan
      """

    When I run `wp media remove-orphans --yes --format=json`
    Then the return code should be 0
    And STDOUT should be JSON containing:
      """
      {"status":"completed","action":"deleted"}
      """
    And STDOUT should contain:
      """
      orphan.jpg
      """

  Scenario: Respects --exclude-dirs during removal
    Given a wp-content/uploads/gravity_forms/1-abc/upload.pdf file:
      """
      form submission
      """
    And a wp-content/uploads/orphan.jpg file:
      """
      orphan
      """

    When I run `wp media remove-orphans --yes --exclude-dirs=cache,gravity_forms`
    Then the return code should be 0
    # Excluded dir file must still exist.
    And the wp-content/uploads/gravity_forms/1-abc/upload.pdf file should exist
    # Orphan is removed.
    And the wp-content/uploads/orphan.jpg file should not exist

  Scenario: Errors if --file-list is passed
    When I try `wp media remove-orphans --file-list=/tmp/list.txt`
    Then the return code should be 1
    And STDERR should contain:
      """
      unknown --file-list parameter
      """
