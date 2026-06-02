-- Migration 0017: Drop podcast and parish registration tables (feature removed)

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `podcast_episodes`;
DROP TABLE IF EXISTS `podcasts`;
DROP TABLE IF EXISTS `registration_members`;
DROP TABLE IF EXISTS `parish_registrations`;
SET FOREIGN_KEY_CHECKS = 1;

DELETE FROM `settings` WHERE `key` = 'form_intro_register';
