-- Migration 0036: Ensure page_type and link_url columns exist on pages table
-- Migration 0024 may have been recorded as applied without the ALTER TABLE running.
-- IF NOT EXISTS makes this safe to run even if columns already exist.

ALTER TABLE `pages`
  ADD COLUMN IF NOT EXISTS `page_type` ENUM('page','link') NOT NULL DEFAULT 'page' AFTER `id`,
  ADD COLUMN IF NOT EXISTS `link_url`  VARCHAR(500) DEFAULT NULL AFTER `page_type`;

-- Also ensure mailing_address setting row exists
INSERT IGNORE INTO `settings` (`site_id`, `key`, `value`) VALUES (1, 'mailing_address', '');
