-- Migration 0030: Track nav link page for custom forms
-- Stores the pages.id of the auto-created nav link entry so the form
-- admin can detect and manage it without a separate nav management UI.

ALTER TABLE `custom_forms`
  ADD COLUMN `nav_page_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'pages.id of the auto-created nav link entry for this form'
  AFTER `imported_from`;
