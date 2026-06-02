-- Migration 0012: Facebook App ID/Secret for OAuth flow

INSERT IGNORE INTO settings (`site_id`, `key`, `value`) VALUES
  (1, 'social_fb_app_id',      ''),
  (1, 'social_fb_app_secret',  ''),
  (1, 'social_fb_page_name',   '');
