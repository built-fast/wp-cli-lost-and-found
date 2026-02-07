Feature: Table format output

  The --format=table flag produces human-readable output for support
  teams reviewing orphaned files.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Table output lists orphaned files
    Given a wp-content/uploads/orphan-a.jpg file:
      """
      orphan image
      """
    And a wp-content/uploads/orphan-b.png file:
      """
      another orphan
      """

    When I run `wp media find-orphans --format=table`
    Then the return code should be 0
    And STDOUT should contain:
      """
      Orphans:
      """
    And STDOUT should contain:
      """
      orphan-a.jpg
      """
    And STDOUT should contain:
      """
      orphan-b.png
      """
    And STDOUT should contain:
      """
      path
      """
    And STDOUT should contain:
      """
      size
      """
