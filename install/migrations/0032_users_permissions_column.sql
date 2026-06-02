-- Migration 0032: Add permissions column to users and support network-wide users

ALTER TABLE `users`
  ADD COLUMN `permissions` TEXT DEFAULT NULL AFTER `role`;
