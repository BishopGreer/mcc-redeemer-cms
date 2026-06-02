-- Migration 0018: Drop newsletter and subscriber tables (feature removed)

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `newsletter_sends`;
DROP TABLE IF EXISTS `subscriber_list_pivot`;
DROP TABLE IF EXISTS `subscriber_list_members`;
DROP TABLE IF EXISTS `subscribers`;
DROP TABLE IF EXISTS `subscriber_lists`;
DROP TABLE IF EXISTS `newsletters`;
SET FOREIGN_KEY_CHECKS = 1;

DELETE FROM `settings` WHERE `key` IN ('newsletter_from_name', 'newsletter_from_email');
