-- Migration 0027: Add manual text columns to lectionary_readings
-- Allows pasting passage text directly without relying on a Bible API

ALTER TABLE `lectionary_readings`
  ADD COLUMN `reading1_text` MEDIUMTEXT DEFAULT NULL AFTER `reading1_api`,
  ADD COLUMN `psalm_text`    MEDIUMTEXT DEFAULT NULL AFTER `psalm_api`,
  ADD COLUMN `reading2_text` MEDIUMTEXT DEFAULT NULL AFTER `reading2_api`,
  ADD COLUMN `gospel_text`   MEDIUMTEXT DEFAULT NULL AFTER `gospel_api`;
