Feature: PDF thumbnail generation

  WordPress generates thumbnail images for uploaded PDFs. The generated
  image files are tracked in the sizes metadata and must not be reported
  as orphans.

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Tracks PDF-generated thumbnail images
    # Simulate a PDF upload with generated thumbnails.
    # WP creates image files named like "document-pdf.jpg" for the preview.
    When I run `wp eval '$id = wp_insert_attachment(["post_title" => "Document", "post_mime_type" => "application/pdf", "post_status" => "inherit"], "document.pdf"); update_post_meta($id, "_wp_attached_file", "document.pdf"); update_post_meta($id, "_wp_attachment_metadata", ["file" => "document.pdf", "sizes" => ["thumbnail" => ["file" => "document-pdf-150x150.jpg"], "medium" => ["file" => "document-pdf-300x388.jpg"], "full" => ["file" => "document-pdf.jpg"]]]); echo "ok";'`
    Then STDOUT should contain:
      """
      ok
      """

    # Plant files on disk.
    Given a wp-content/uploads/document.pdf file:
      """
      fake pdf content
      """
    And a wp-content/uploads/document-pdf.jpg file:
      """
      pdf preview
      """
    And a wp-content/uploads/document-pdf-150x150.jpg file:
      """
      pdf thumb
      """
    And a wp-content/uploads/document-pdf-300x388.jpg file:
      """
      pdf medium
      """

    # Plant an orphan PDF.
    And a wp-content/uploads/deleted-doc.pdf file:
      """
      orphan pdf
      """
    And a wp-content/uploads/deleted-doc-pdf.jpg file:
      """
      orphan pdf preview
      """

    When I run `wp media find-orphans --format=json`
    Then the return code should be 0
    # Tracked PDF and its thumbnails should NOT be orphans.
    And STDOUT should not contain:
      """
      document.pdf
      """
    And STDOUT should not contain:
      """
      document-pdf.jpg
      """
    And STDOUT should not contain:
      """
      document-pdf-150x150.jpg
      """
    # Orphan PDF and its preview should be detected.
    And STDOUT should contain:
      """
      deleted-doc.pdf
      """
    And STDOUT should contain:
      """
      deleted-doc-pdf.jpg
      """
