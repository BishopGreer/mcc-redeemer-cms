-- Migration 0037: Event Calendar + Custom Roles

-- ── Events ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `events` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`           INT UNSIGNED NOT NULL DEFAULT 1,
  `title`             VARCHAR(300) NOT NULL,
  `slug`              VARCHAR(300) NOT NULL,
  `description`       LONGTEXT DEFAULT NULL,
  `location`          VARCHAR(300) DEFAULT NULL,
  `address`           TEXT DEFAULT NULL,
  `start_dt`          DATETIME NOT NULL,
  `end_dt`            DATETIME DEFAULT NULL,
  `all_day`           TINYINT(1) NOT NULL DEFAULT 0,
  `status`            ENUM('published','draft') NOT NULL DEFAULT 'draft',
  `featured_image_id` INT UNSIGNED DEFAULT NULL,
  -- Recurrence
  `recur_type`        ENUM('none','daily','weekly','monthly','yearly') NOT NULL DEFAULT 'none',
  `recur_interval`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `recur_days`        VARCHAR(20) DEFAULT NULL COMMENT 'comma-separated day-of-week ints 0=Sun',
  `recur_month_type`  ENUM('date','day') NOT NULL DEFAULT 'date',
  `recur_until`       DATE DEFAULT NULL,
  `recur_count`       SMALLINT UNSIGNED DEFAULT NULL,
  `created_by`        INT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_site_slug`         (`site_id`, `slug`),
  KEY       `idx_site_status_start` (`site_id`, `status`, `start_dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Custom Roles ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `custom_roles` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`     INT UNSIGNED NOT NULL DEFAULT 1,
  `name`        VARCHAR(100) NOT NULL,
  `slug`        VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `base_role`   ENUM('parishioner','author','editor','admin') NOT NULL DEFAULT 'editor',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_site_slug` (`site_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `custom_role_permissions` (
  `role_id`    INT UNSIGNED NOT NULL,
  `permission` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`role_id`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Add custom_role_id to users ───────────────────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `custom_role_id` INT UNSIGNED DEFAULT NULL AFTER `permissions`;

-- ── New settings ──────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`site_id`, `key`, `value`) VALUES
  (1, 'events_enabled',   '1'),
  (1, 'events_nav_label', 'Events');
