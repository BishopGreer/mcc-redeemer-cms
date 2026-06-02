-- Migration 0010: Social media sharing

CREATE TABLE IF NOT EXISTS `social_shares` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `post_id`          INT UNSIGNED NOT NULL,
  `platform`         VARCHAR(20)  NOT NULL,   -- facebook, bluesky, threads, mastodon
  `status`           VARCHAR(20)  NOT NULL DEFAULT 'success',  -- success, failed
  `platform_post_id` VARCHAR(500) DEFAULT NULL,
  `message`          TEXT         DEFAULT NULL,
  `error_message`    TEXT         DEFAULT NULL,
  `shared_by`        INT UNSIGNED DEFAULT NULL,
  `shared_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`post_id`),
  INDEX (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Social media credential settings
INSERT IGNORE INTO settings (`site_id`, `key`, `value`) VALUES
  (1, 'social_fb_page_id',          ''),
  (1, 'social_fb_access_token',     ''),
  (1, 'social_bsky_handle',         ''),
  (1, 'social_bsky_app_password',   ''),
  (1, 'social_threads_user_id',     ''),
  (1, 'social_threads_access_token',''),
  (1, 'social_mastodon_instance',   ''),
  (1, 'social_mastodon_token',      '');
