-- Migration 0031: Make categories site-specific and add tags system

-- Add site_id to categories (default 1 preserves existing data)
ALTER TABLE `categories`
  ADD COLUMN `site_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;

-- Replace global slug unique with per-site unique
ALTER TABLE `categories` DROP INDEX `slug`;
ALTER TABLE `categories` ADD UNIQUE KEY `idx_site_slug` (`site_id`, `slug`);

-- Tags table (per-site)
CREATE TABLE IF NOT EXISTS `tags` (
  `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `name`    VARCHAR(100) NOT NULL,
  `slug`    VARCHAR(100) NOT NULL,
  UNIQUE KEY `idx_site_slug` (`site_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Post → Tags pivot
CREATE TABLE IF NOT EXISTS `post_tags` (
  `post_id` INT UNSIGNED NOT NULL,
  `tag_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`post_id`, `tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
