-- Migration 0020: Parish Locator directory table

CREATE TABLE IF NOT EXISTS `parish_locator` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(255) NOT NULL,
  `pastor_name`    VARCHAR(255) DEFAULT NULL,
  `address_line1`  VARCHAR(255) DEFAULT NULL,
  `address_line2`  VARCHAR(255) DEFAULT NULL,
  `city`           VARCHAR(100) DEFAULT NULL,
  `state_province` VARCHAR(100) DEFAULT NULL,
  `postal_code`    VARCHAR(20)  DEFAULT NULL,
  `country`        VARCHAR(100) NOT NULL DEFAULT 'United States',
  `phone`          VARCHAR(50)  DEFAULT NULL,
  `email`          VARCHAR(255) DEFAULT NULL,
  `website`        VARCHAR(500) DEFAULT NULL,
  `description`    TEXT         DEFAULT NULL,
  `latitude`       DECIMAL(10,7) DEFAULT NULL,
  `longitude`      DECIMAL(10,7) DEFAULT NULL,
  `map_embed_url`  VARCHAR(1000) DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `menu_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status`     (`status`),
  KEY `idx_country`    (`country`),
  KEY `idx_menu_order` (`menu_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
