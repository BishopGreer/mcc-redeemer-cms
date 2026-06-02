-- MCC Our Redeemer CMS - Database Schema
-- MariaDB / MySQL compatible
-- Run this once on a fresh database

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Drop all tables in reverse dependency order so re-running the installer
-- on a partially-initialized database always starts from a clean slate.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `migrations`;
DROP TABLE IF EXISTS `board_members`;
DROP TABLE IF EXISTS `form_files`;
DROP TABLE IF EXISTS `form_submissions`;
DROP TABLE IF EXISTS `custom_forms`;
DROP TABLE IF EXISTS `social_shares`;
DROP TABLE IF EXISTS `analytics_views`;
DROP TABLE IF EXISTS `prayer_requests`;
DROP TABLE IF EXISTS `contact_submissions`;
DROP TABLE IF EXISTS `media`;
DROP TABLE IF EXISTS `post_tags`;
DROP TABLE IF EXISTS `tags`;
DROP TABLE IF EXISTS `post_categories`;
DROP TABLE IF EXISTS `posts`;
DROP TABLE IF EXISTS `pages`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `user_permissions`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `network_sites`;
SET FOREIGN_KEY_CHECKS = 1;

-- -------------------------------------------------------
-- Migrations (update tracking)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `migrations` (
  `version`    VARCHAR(80) NOT NULL PRIMARY KEY,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Network sites registry
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_sites` (
  `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `subdomain` VARCHAR(100) NOT NULL UNIQUE COMMENT 'empty string = main domain',
  `name`      VARCHAR(255) NOT NULL,
  `status`    ENUM('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_subdomain` (`subdomain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `network_sites` (`id`, `subdomain`, `name`, `status`)
VALUES (1, '', 'Primary Site', 'active');

-- -------------------------------------------------------
-- Users
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`      INT UNSIGNED DEFAULT NULL COMMENT 'NULL = network-wide user',
  `name`         VARCHAR(120) NOT NULL,
  `email`        VARCHAR(255) NOT NULL UNIQUE,
  `password`     VARCHAR(255) NOT NULL,
  `role`         ENUM('super_admin','admin','editor','author','parishioner') NOT NULL DEFAULT 'author',
  `permissions`  TEXT DEFAULT NULL,
  `avatar`       VARCHAR(500) DEFAULT NULL,
  `remember_token` VARCHAR(100) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login`   DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Pages
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pages` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`      INT UNSIGNED NOT NULL DEFAULT 1,
  `title`        VARCHAR(255) NOT NULL,
  `slug`         VARCHAR(255) NOT NULL,
  UNIQUE KEY `idx_site_slug` (`site_id`, `slug`),
  `content`      LONGTEXT DEFAULT NULL,
  `excerpt`      TEXT DEFAULT NULL,
  `status`       ENUM('published','draft','private') NOT NULL DEFAULT 'draft',
  `template`     VARCHAR(80) NOT NULL DEFAULT 'default',
  `featured_image` INT UNSIGNED DEFAULT NULL,
  `meta_title`   VARCHAR(255) DEFAULT NULL,
  `meta_desc`    VARCHAR(500) DEFAULT NULL,
  `menu_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `show_in_nav`  TINYINT(1) NOT NULL DEFAULT 0,
  `nav_label`    VARCHAR(100) DEFAULT NULL,
  `parent_id`    INT UNSIGNED DEFAULT NULL,
  `author_id`    INT UNSIGNED NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_slug` (`slug`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Posts (Blog)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `posts` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`      INT UNSIGNED NOT NULL DEFAULT 1,
  `title`        VARCHAR(255) NOT NULL,
  `slug`         VARCHAR(255) NOT NULL,
  UNIQUE KEY `idx_site_slug` (`site_id`, `slug`),
  `content`      LONGTEXT DEFAULT NULL,
  `excerpt`      TEXT DEFAULT NULL,
  `status`       ENUM('published','draft','private') NOT NULL DEFAULT 'draft',
  `featured_image` INT UNSIGNED DEFAULT NULL,
  `category_id`  INT UNSIGNED DEFAULT NULL,
  `meta_title`   VARCHAR(255) DEFAULT NULL,
  `meta_desc`    VARCHAR(500) DEFAULT NULL,
  `author_id`    INT UNSIGNED NOT NULL,
  `published_at` DATETIME DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_slug` (`slug`),
  INDEX `idx_status` (`status`),
  INDEX `idx_published` (`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Post Categories
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`      INT UNSIGNED NOT NULL DEFAULT 1,
  `name`         VARCHAR(120) NOT NULL,
  `slug`         VARCHAR(120) NOT NULL,
  `description`  TEXT DEFAULT NULL,
  UNIQUE KEY `idx_site_slug` (`site_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
  `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `name`    VARCHAR(100) NOT NULL,
  `slug`    VARCHAR(100) NOT NULL,
  UNIQUE KEY `idx_site_slug` (`site_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_tags` (
  `post_id` INT UNSIGNED NOT NULL,
  `tag_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`post_id`, `tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Media Library
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `media` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`      INT UNSIGNED NOT NULL DEFAULT 1,
  `filename`     VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type`    VARCHAR(100) NOT NULL,
  `file_size`    INT UNSIGNED NOT NULL DEFAULT 0,
  `width`        SMALLINT UNSIGNED DEFAULT NULL,
  `height`       SMALLINT UNSIGNED DEFAULT NULL,
  `path`         VARCHAR(500) NOT NULL,
  `thumb_path`   VARCHAR(500) DEFAULT NULL,
  `alt_text`     VARCHAR(255) DEFAULT NULL,
  `caption`      TEXT DEFAULT NULL,
  `uploader_id`  INT UNSIGNED NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Analytics — Page Views
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `analytics_views` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`      INT UNSIGNED NOT NULL DEFAULT 1,
  `url`          VARCHAR(500) NOT NULL,
  `page_title`   VARCHAR(255) DEFAULT NULL,
  `referrer`     VARCHAR(500) DEFAULT NULL,
  `ip_hash`      VARCHAR(64) NOT NULL,
  `user_agent`   VARCHAR(500) DEFAULT NULL,
  `country`      VARCHAR(80) DEFAULT NULL,
  `device`       ENUM('desktop','tablet','mobile','bot') NOT NULL DEFAULT 'desktop',
  `browser`      VARCHAR(40) DEFAULT NULL,
  `os`           VARCHAR(40) DEFAULT NULL,
  `session_id`   VARCHAR(64) DEFAULT NULL,
  `is_entry`     TINYINT(1) NOT NULL DEFAULT 0,
  `duration_sec` SMALLINT UNSIGNED DEFAULT NULL,
  `viewed_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_url`      (`url`(191)),
  INDEX `idx_viewed_at` (`viewed_at`),
  INDEX `idx_session`  (`session_id`),
  INDEX `idx_site_date` (`site_id`, `viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Board of Directors
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `board_members` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`       INT UNSIGNED NOT NULL DEFAULT 1,
  `name`          VARCHAR(150) NOT NULL,
  `title`         VARCHAR(150) DEFAULT NULL,
  `bio`           LONGTEXT DEFAULT NULL,
  `photo_id`      INT UNSIGNED DEFAULT NULL,
  `email`         VARCHAR(255) DEFAULT NULL,
  `display_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_site_id` (`site_id`),
  KEY `idx_order`   (`site_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Site Settings (key-value)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `site_id`      INT UNSIGNED NOT NULL DEFAULT 1,
  `key`          VARCHAR(100) NOT NULL,
  `value`        TEXT DEFAULT NULL,
  `autoload`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`site_id`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Social sharing log
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
  KEY `idx_site_id` (`site_id`),
  INDEX (`post_id`),
  INDEX (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contact form submissions
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

-- Prayer requests
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

-- Custom Forms
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `custom_forms` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`        INT UNSIGNED NOT NULL,
  `title`          VARCHAR(255) NOT NULL,
  `slug`           VARCHAR(255) NOT NULL,
  `description`    TEXT DEFAULT NULL,
  `status`         ENUM('published','draft','archived') NOT NULL DEFAULT 'draft',
  `requires_login` TINYINT(1) NOT NULL DEFAULT 0,
  `use_hcaptcha`   TINYINT(1) NOT NULL DEFAULT 1,
  `notify_email`   VARCHAR(500) DEFAULT NULL,
  `success_msg`    TEXT DEFAULT NULL,
  `fields_json`    LONGTEXT NOT NULL,
  `imported_from`  VARCHAR(50) DEFAULT NULL,
  `nav_page_id`    INT UNSIGNED DEFAULT NULL
                   COMMENT 'pages.id of the auto-created nav link entry for this form',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_slug` (`site_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_submissions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id`      INT UNSIGNED NOT NULL,
  `site_id`      INT UNSIGNED NOT NULL,
  `data_json`    LONGTEXT NOT NULL,
  `ip_address`   VARCHAR(45) DEFAULT NULL,
  `user_agent`   VARCHAR(500) DEFAULT NULL,
  `is_read`      TINYINT(1) NOT NULL DEFAULT 0,
  `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_form_id` (`form_id`),
  KEY `idx_site_id` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_files` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id` INT UNSIGNED NOT NULL,
  `field_id`      VARCHAR(100) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name`   VARCHAR(255) NOT NULL,
  `mime_type`     VARCHAR(100) DEFAULT NULL,
  `file_size`     INT UNSIGNED DEFAULT NULL,
  `uploaded_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submission_id` (`submission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Navigation Menu Items
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nav_items` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `label`        VARCHAR(100) NOT NULL,
  `url`          VARCHAR(500) NOT NULL,
  `target`       VARCHAR(20) NOT NULL DEFAULT '_self',
  `menu_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `parent_id`    INT UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Default Data
-- -------------------------------------------------------

-- Default settings (site 1 = primary site)
INSERT IGNORE INTO `settings` (`site_id`, `key`, `value`) VALUES
  (1, 'site_name',                  'MCC Our Redeemer'),
  (1, 'site_tagline',               'Open Hearts, Open Doors, Open Minds'),
  (1, 'site_url',                   'https://your-site.org'),
  (1, 'admin_email',                'admin@your-site.org'),
  (1, 'smtp_host',                  ''),
  (1, 'smtp_port',                  '587'),
  (1, 'smtp_user',                  ''),
  (1, 'smtp_pass',                  ''),
  (1, 'smtp_encryption',            'tls'),
  (1, 'posts_per_page',             '10'),
  (1, 'date_format',                'F j, Y'),
  (1, 'timezone',                   'America/New_York'),
  (1, 'analytics_enabled',          '1'),
  (1, 'analytics_exclude_admins',   '1'),
  (1, 'analytics_track_browser',    '1'),
  (1, 'analytics_track_os',         '1'),
  (1, 'analytics_session_minutes',  '30'),
  (1, 'home_page_id',               ''),
  (1, 'blog_page_id',               ''),
  (1, 'blog_enabled',               '0'),
  (1, 'blog_nav_label',             'Blog'),
  (1, 'contact_page_enabled',       '1'),
  (1, 'constant_contact_api_key',   ''),
  (1, 'constant_contact_list_id',   ''),
  (1, 'paypal_link',                ''),
  (1, 'venmo_link',                 ''),
  (1, 'donate_page_title',          'Support Our Church'),
  (1, 'donate_description',         'Your generosity helps us continue our ministry and serve our community.'),
  (1, 'newsletter_signup_enabled',  '1'),
  (1, 'newsletter_signup_label',    'Stay Connected — Join Our Newsletter'),
  (1, 'parish_address',             ''),
  (1, 'parish_phone',               ''),
  (1, 'parish_city',                'Augusta'),
  (1, 'parish_state',               'GA');

-- Default admin user (site_id=NULL = network-wide; password: ChangeMe123! — CHANGE IMMEDIATELY)
INSERT IGNORE INTO `users` (`site_id`, `name`, `email`, `password`, `role`) VALUES
  (NULL, 'Administrator', 'admin@your-site.org',
   '$2y$12$5P3GWa.UMxRxw3fYmpFmfuujjyaQZVFN6LG3Ive8Yqvaj4vGmgjLy',
   'super_admin');

-- Default home page
INSERT IGNORE INTO `pages` (`site_id`, `title`, `slug`, `content`, `status`, `show_in_nav`, `nav_label`, `menu_order`, `author_id`) VALUES
  (1, 'Home', 'home',
   '<h2>Welcome to MCC Our Redeemer</h2><p>We are a Metropolitan Community Church where everyone is welcome. All are beloved children of God.</p>',
   'published', 0, 'Home', 0, 1),
  (1, 'About Us', 'about',
   '<h2>About MCC Our Redeemer</h2><p>Metropolitan Community Church of Our Redeemer is an affirming, inclusive Christian community in Augusta, Georgia.</p>',
   'published', 1, 'About', 1, 1),
  (1, 'Worship', 'worship',
   '<h2>Worship With Us</h2><p>Our worship schedule and service information will appear here.</p>',
   'published', 1, 'Worship', 2, 1),
  (1, 'Ministries', 'ministries',
   '<h2>Ministries</h2><p>Information about our church ministries will appear here.</p>',
   'published', 1, 'Ministries', 3, 1),
  (1, 'Leadership', 'leadership',
   '<h2>Our Leadership</h2><p>Meet our pastoral team and board of directors.</p>',
   'published', 1, 'Leadership', 4, 1),
  (1, 'Give', 'give',
   '<h2>Support Our Church</h2><p>Your generosity helps us continue our ministry and serve our community.</p>',
   'published', 1, 'Give', 5, 1);

-- Update home_page_id for site 1
UPDATE `settings` SET `value` = (SELECT `id` FROM `pages` WHERE `slug` = 'home' AND site_id = 1 LIMIT 1)
WHERE site_id = 1 AND `key` = 'home_page_id';

-- Default blog category
INSERT IGNORE INTO `categories` (`name`, `slug`, `description`) VALUES
  ('Church News', 'church-news', 'News and announcements from MCC Our Redeemer');
