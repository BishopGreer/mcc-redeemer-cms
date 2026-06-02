-- Migration 0026: Daily Readings (Lectionary + Bible API cache)

-- Stores reading references keyed by liturgical position or specific date
CREATE TABLE IF NOT EXISTS `lectionary_readings` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `lookup_key`       VARCHAR(80)   NOT NULL,         -- e.g. "S-ordinary-3-A" or "W-advent-2-3-I"
  `date_override`    DATE          DEFAULT NULL,      -- exact date (highest priority)
  `liturgical_title` VARCHAR(255)  NOT NULL DEFAULT '',
  `reading1_ref`     VARCHAR(200)  DEFAULT NULL,     -- Human-readable: "Is 42:1-4, 6-7"
  `reading1_api`     VARCHAR(300)  DEFAULT NULL,     -- api.bible format (auto-filled or manual)
  `psalm_ref`        VARCHAR(200)  DEFAULT NULL,
  `psalm_api`        VARCHAR(300)  DEFAULT NULL,
  `reading2_ref`     VARCHAR(200)  DEFAULT NULL,     -- NULL on weekdays
  `reading2_api`     VARCHAR(300)  DEFAULT NULL,
  `gospel_ref`       VARCHAR(200)  DEFAULT NULL,
  `gospel_api`       VARCHAR(300)  DEFAULT NULL,
  `notes`            TEXT          DEFAULT NULL,      -- optional commentary / antiphon
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_lookup` (`lookup_key`),
  KEY `idx_date` (`date_override`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache for fetched Bible passage text (keyed by api.bible passage + bible ID)
CREATE TABLE IF NOT EXISTS `readings_cache` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cache_key`  VARCHAR(180) NOT NULL,
  `passage_text` MEDIUMTEXT NOT NULL,
  `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_key` (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
