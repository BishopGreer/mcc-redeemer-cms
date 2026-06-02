-- Migration 0014: Threads App credentials for OAuth

INSERT IGNORE INTO settings (`site_id`, `key`, `value`) VALUES
  (1, 'social_threads_app_id',     ''),
  (1, 'social_threads_app_secret', '')
