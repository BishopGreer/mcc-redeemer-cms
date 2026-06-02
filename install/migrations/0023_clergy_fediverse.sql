-- Migration 0023: Add Mastodon and Pixelfed to clergy social media

ALTER TABLE `clergy`
  ADD COLUMN `social_mastodon`  VARCHAR(500) DEFAULT NULL AFTER `social_snapchat`,
  ADD COLUMN `social_pixelfed`  VARCHAR(500) DEFAULT NULL AFTER `social_mastodon`;
