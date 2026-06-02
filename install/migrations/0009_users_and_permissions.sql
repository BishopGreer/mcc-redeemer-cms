-- Migration 0009: User roles, granular permissions, and Parish Register settings

-- Add parishioner role and permissions column to users
ALTER TABLE users
  MODIFY COLUMN role ENUM('super_admin','admin','editor','author','parishioner') NOT NULL DEFAULT 'author';

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS permissions JSON DEFAULT NULL AFTER role;

-- (Parish Register settings removed — not used in this CMS)
