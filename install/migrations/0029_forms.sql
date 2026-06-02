-- Migration 0029: Custom Forms System
-- Adds custom_forms, form_submissions, and form_files tables.

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
