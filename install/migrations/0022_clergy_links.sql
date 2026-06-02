-- Migration 0022: Add optional URL fields to clergy directory

ALTER TABLE `clergy`
  ADD COLUMN `parish_url` VARCHAR(500) DEFAULT NULL AFTER `parish`,
  ADD COLUMN `diocese_url` VARCHAR(500) DEFAULT NULL AFTER `diocese`,
  ADD COLUMN `office_url` VARCHAR(500) DEFAULT NULL AFTER `office`;
