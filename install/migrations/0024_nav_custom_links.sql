-- Migration 0024: Custom navigation links support

ALTER TABLE `pages`
  ADD COLUMN `page_type` ENUM('page','link') NOT NULL DEFAULT 'page' AFTER `id`,
  ADD COLUMN `link_url`  VARCHAR(500) DEFAULT NULL AFTER `page_type`;
