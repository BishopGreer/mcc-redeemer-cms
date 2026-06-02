-- Network/Multisite support
-- Adds network_sites table and site_id scoping to all content tables.
-- The primary site (site_id = 1) is created automatically.

-- -------------------------------------------------------
-- Network sites registry
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_sites` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `subdomain`    VARCHAR(100) NOT NULL UNIQUE COMMENT 'e.g. osfoc — empty string = main domain',
  `name`         VARCHAR(255) NOT NULL,
  `status`       ENUM('active','suspended') NOT NULL DEFAULT 'active',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_subdomain` (`subdomain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert the primary site if it doesn't exist yet
INSERT IGNORE INTO `network_sites` (`id`, `subdomain`, `name`, `status`)
VALUES (1, '', 'Primary Site', 'active');

-- -------------------------------------------------------
-- Add site_id to content tables
-- -------------------------------------------------------
ALTER TABLE `settings`
  ADD COLUMN IF NOT EXISTS `site_id` INT UNSIGNED NOT NULL DEFAULT 1 FIRST;

ALTER TABLE `pages`
  ADD COLUMN IF NOT EXISTS `site_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;

ALTER TABLE `posts`
  ADD COLUMN IF NOT EXISTS `site_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;


CREATE TABLE IF NOT EXISTS `contact_submissions` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `site_id`    INT UNSIGNED NOT NULL DEFAULT 1,
  `name`       VARCHAR(100) NOT NULL,
  `email`      VARCHAR(150) NOT NULL,
  `phone`      VARCHAR(30) DEFAULT NULL,
  `subject`    VARCHAR(200) DEFAULT NULL,
  `message`    TEXT NOT NULL,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_site_id` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `contact_submissions`
  ADD COLUMN IF NOT EXISTS `site_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;

CREATE TABLE IF NOT EXISTS `prayer_requests` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `site_id`        INT UNSIGNED NOT NULL DEFAULT 1,
  `name`           VARCHAR(100) NOT NULL,
  `email`          VARCHAR(150) DEFAULT NULL,
  `phone`          VARCHAR(30) DEFAULT NULL,
  `intention_type` VARCHAR(100) DEFAULT NULL,
  `intention`      TEXT NOT NULL,
  `is_anonymous`   TINYINT(1) NOT NULL DEFAULT 0,
  `ip`             VARCHAR(45) DEFAULT NULL,
  `is_read`        TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_site_id` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `prayer_requests`
  ADD COLUMN IF NOT EXISTS `site_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;

ALTER TABLE `analytics_views`
  ADD COLUMN IF NOT EXISTS `site_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;

ALTER TABLE `media`
  ADD COLUMN IF NOT EXISTS `site_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;

CREATE TABLE IF NOT EXISTS `social_shares` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`          INT UNSIGNED NOT NULL DEFAULT 1,
  `post_id`          INT UNSIGNED NOT NULL,
  `platform`         VARCHAR(20)  NOT NULL,
  `status`           VARCHAR(20)  NOT NULL DEFAULT 'success',
  `platform_post_id` VARCHAR(500) DEFAULT NULL,
  `message`          TEXT         DEFAULT NULL,
  `error_message`    TEXT         DEFAULT NULL,
  `shared_by`        INT UNSIGNED DEFAULT NULL,
  `shared_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`post_id`),
  INDEX (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `social_shares`
  ADD COLUMN IF NOT EXISTS `site_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `site_id` INT UNSIGNED DEFAULT NULL AFTER `id`;

-- -------------------------------------------------------
-- settings: change PK from (key) to (site_id, key)
-- -------------------------------------------------------
ALTER TABLE `settings` DROP PRIMARY KEY;
ALTER TABLE `settings` ADD PRIMARY KEY (`site_id`, `key`);

-- -------------------------------------------------------
-- Slug uniqueness: per-site instead of global
-- -------------------------------------------------------
ALTER TABLE `pages` DROP INDEX IF EXISTS `idx_slug`;
ALTER TABLE `pages` DROP INDEX IF EXISTS `slug`;
ALTER TABLE `pages` DROP INDEX IF EXISTS `idx_site_slug`;
ALTER TABLE `pages` DROP INDEX IF EXISTS `idx_site_status`;
ALTER TABLE `pages` ADD UNIQUE KEY `idx_site_slug` (`site_id`, `slug`);
ALTER TABLE `pages` ADD INDEX `idx_site_status` (`site_id`, `status`);

ALTER TABLE `posts` DROP INDEX IF EXISTS `idx_slug`;
ALTER TABLE `posts` DROP INDEX IF EXISTS `slug`;
ALTER TABLE `posts` DROP INDEX IF EXISTS `idx_site_slug`;
ALTER TABLE `posts` DROP INDEX IF EXISTS `idx_site_status`;
ALTER TABLE `posts` ADD UNIQUE KEY `idx_site_slug` (`site_id`, `slug`);
ALTER TABLE `posts` ADD INDEX `idx_site_status` (`site_id`, `status`);

-- -------------------------------------------------------
-- Add super_admin role to users
-- -------------------------------------------------------
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('super_admin','admin','editor','author','parishioner')
  NOT NULL DEFAULT 'author';
