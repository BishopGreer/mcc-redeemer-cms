-- Migration 0011: Auto-share settings per platform

INSERT IGNORE INTO settings (`site_id`, `key`, `value`) VALUES
  (1, 'social_auto_facebook', '0'),
  (1, 'social_auto_bluesky',  '0'),
  (1, 'social_auto_threads',  '0'),
  (1, 'social_auto_mastodon', '0');
