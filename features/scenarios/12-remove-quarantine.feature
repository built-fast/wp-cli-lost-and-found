Feature: Quarantine removal mode

  Tests quarantine directory creation and JSON output for scripting.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Quarantine dir created if missing with JSON output
    Given a wp-content/uploads/orphan.jpg file:
      """
      orphan content
      """

    When I run `wp media remove-orphans --yes --quarantine-dir={RUN_DIR}/new-quarantine --format=json`
    Then the return code should be 0
    And STDOUT should be JSON containing:
      """
      {"status":"completed","action":"quarantined"}
      """
    And STDOUT should contain:
      """
      orphan.jpg
      """

  Scenario: Quarantine with multiple files preserves structure
    Given a wp-content/uploads/2024/01/jan-orphan.jpg file:
      """
      jan
      """
    And a wp-content/uploads/2024/06/jun-orphan.jpg file:
      """
      jun
      """
    And a wp-content/uploads/root-orphan.png file:
      """
      root
      """

    When I run `wp media remove-orphans --yes --quarantine-dir={RUN_DIR}/quarantine --format=json`
    Then the return code should be 0
    And STDOUT should be JSON containing:
      """
      {"status":"completed","action":"quarantined"}
      """
    And STDOUT should contain:
      """
      jan-orphan.jpg
      """
    And STDOUT should contain:
      """
      jun-orphan.jpg
      """
    And STDOUT should contain:
      """
      root-orphan.png
      """
    # Files removed from uploads.
    And the wp-content/uploads/2024/01/jan-orphan.jpg file should not exist
    And the wp-content/uploads/2024/06/jun-orphan.jpg file should not exist
    And the wp-content/uploads/root-orphan.png file should not exist
