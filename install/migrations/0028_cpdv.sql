-- Migration 0028: CPDV (Catholic Public Domain Version) local Bible text
-- Stores all 73 books of the Catholic canon locally so passage text can be
-- served without any external Bible API dependency.

CREATE TABLE IF NOT EXISTS `cpdv_verses` (
  `book`    VARCHAR(10)       NOT NULL  COMMENT 'OSIS code (GEN, PSA, 1CO, etc.)',
  `chapter` SMALLINT UNSIGNED NOT NULL,
  `verse`   SMALLINT UNSIGNED NOT NULL,
  `text`    TEXT              NOT NULL,
  PRIMARY KEY (`book`, `chapter`, `verse`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
