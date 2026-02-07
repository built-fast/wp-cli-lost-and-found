Feature: Plugin artifacts in uploads directory

  Various plugins write files to wp-content/uploads outside the media
  library. Tests detecting them as orphans and excluding with --exclude-dirs.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Detects plugin artifacts as orphans with default excludes
    Given a wp-content/uploads/gravity_forms/1-abc/upload.pdf file:
      """
      form submission
      """
    And a wp-content/uploads/elementor/css/post-42.css file:
      """
      .elementor-42 { color: red; }
      """
    And a wp-content/uploads/woocommerce_uploads/download.zip file:
      """
      protected file
      """
    And a wp-content/uploads/wc-logs/fatal-2024-03-15.log file:
      """
      error log
      """
    And a wp-content/uploads/backwpup-abc-logs/backup.log file:
      """
      backup log
      """
    And a wp-content/uploads/cache/object.tmp file:
      """
      cached data
      """
    And a wp-content/uploads/debug.log file:
      """
      PHP Warning
      """
    And a wp-content/uploads/orphan.jpg file:
      """
      real orphan
      """

    When I run `wp media find-orphans --format=json`
    Then the return code should be 0
    # All plugin artifacts are orphans (only cache/ excluded by default).
    And STDOUT should contain:
      """
      upload.pdf
      """
    And STDOUT should contain:
      """
      post-42.css
      """
    And STDOUT should contain:
      """
      download.zip
      """
    And STDOUT should contain:
      """
      debug.log
      """
    And STDOUT should contain:
      """
      orphan.jpg
      """
    # cache/ is excluded by default.
    And STDOUT should not contain:
      """
      object.tmp
      """

  Scenario: Excludes multiple plugin directories
    Given a wp-content/uploads/gravity_forms/1-abc/upload.pdf file:
      """
      form submission
      """
    And a wp-content/uploads/elementor/css/post-42.css file:
      """
      styles
      """
    And a wp-content/uploads/woocommerce_uploads/download.zip file:
      """
      protected file
      """
    And a wp-content/uploads/orphan.jpg file:
      """
      real orphan
      """

    When I run `wp media find-orphans --exclude-dirs=cache,gravity_forms,elementor,woocommerce_uploads --format=json`
    Then the return code should be 0
    # Plugin dirs excluded.
    And STDOUT should not contain:
      """
      upload.pdf
      """
    And STDOUT should not contain:
      """
      post-42.css
      """
    And STDOUT should not contain:
      """
      download.zip
      """
    # Real orphan still found.
    And STDOUT should contain:
      """
      orphan.jpg
      """
