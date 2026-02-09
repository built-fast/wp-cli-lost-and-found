Feature: Remove orphans with plugin artifact directories

  Plugin artifact directories excluded during removal — only actual
  orphans are removed while plugin dirs remain untouched.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Excludes plugin artifact dirs during removal
    Given a wp-content/uploads/gravity_forms/1-abc/upload.pdf file:
      """
      form submission
      """
    And a wp-content/uploads/elementor/css/post-42.css file:
      """
      .elementor { color: red; }
      """
    And a wp-content/uploads/woocommerce_uploads/download.zip file:
      """
      protected file
      """
    And a wp-content/uploads/orphan-a.jpg file:
      """
      real orphan
      """
    And a wp-content/uploads/orphan-b.png file:
      """
      another orphan
      """

    When I run `wp media remove-orphans --yes --exclude-dirs=cache,gravity_forms,elementor,woocommerce_uploads`
    Then the return code should be 0
    # Plugin dirs must remain.
    And the wp-content/uploads/gravity_forms/1-abc/upload.pdf file should exist
    And the wp-content/uploads/elementor/css/post-42.css file should exist
    And the wp-content/uploads/woocommerce_uploads/download.zip file should exist
    # Only real orphans removed.
    And the wp-content/uploads/orphan-a.jpg file should not exist
    And the wp-content/uploads/orphan-b.png file should not exist
