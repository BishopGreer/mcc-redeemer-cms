-- Migration 0002: Add TinyMCE API key setting
INSERT IGNORE INTO `settings` (`site_id`, `key`, `value`, `autoload`)
VALUES (1, 'tinymce_api_key', '', 1);
