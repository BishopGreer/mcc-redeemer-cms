-- Migration 0035: MCC Redeemer CMS — new features
-- Board of Directors, analytics expansion, Constant Contact settings, blog toggle, donations

-- ── Board of Directors ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `board_members` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `site_id`       INT UNSIGNED NOT NULL DEFAULT 1,
  `name`          VARCHAR(150) NOT NULL,
  `title`         VARCHAR(150) DEFAULT NULL,
  `bio`           LONGTEXT DEFAULT NULL,
  `photo_id`      INT UNSIGNED DEFAULT NULL COMMENT 'references media.id',
  `email`         VARCHAR(255) DEFAULT NULL,
  `display_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_site_id` (`site_id`),
  KEY `idx_order`   (`site_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Analytics: add browser, OS, session tracking ────────────────────────────
ALTER TABLE `analytics_views`
  ADD COLUMN IF NOT EXISTS `browser`       VARCHAR(40)  DEFAULT NULL AFTER `device`,
  ADD COLUMN IF NOT EXISTS `os`            VARCHAR(40)  DEFAULT NULL AFTER `browser`,
  ADD COLUMN IF NOT EXISTS `session_id`    VARCHAR(64)  DEFAULT NULL AFTER `os`,
  ADD COLUMN IF NOT EXISTS `is_entry`      TINYINT(1)   NOT NULL DEFAULT 0 AFTER `session_id`,
  ADD COLUMN IF NOT EXISTS `duration_sec`  SMALLINT UNSIGNED DEFAULT NULL AFTER `is_entry`,
  ADD INDEX IF NOT EXISTS `idx_session`    (`session_id`),
  ADD INDEX IF NOT EXISTS `idx_site_date`  (`site_id`, `viewed_at`);

-- ── New settings: Constant Contact, blog toggle, donations ───────────────────
INSERT IGNORE INTO `settings` (`site_id`, `key`, `value`) VALUES
  (1, 'blog_enabled',              '0'),
  (1, 'blog_nav_label',            'Blog'),
  (1, 'constant_contact_api_key',  ''),
  (1, 'constant_contact_list_id',  ''),
  (1, 'paypal_link',               ''),
  (1, 'venmo_link',                ''),
  (1, 'donate_page_title',         'Support Our Church'),
  (1, 'donate_description',        'Your generosity helps us continue our ministry and serve our community.'),
  (1, 'newsletter_signup_enabled', '1'),
  (1, 'newsletter_signup_label',   'Stay Connected — Join Our Newsletter'),
  (1, 'site_name',                 'MCC Our Redeemer'),
  (1, 'site_tagline',              'Open Hearts, Open Doors, Open Minds'),
  (1, 'parish_city',               'Augusta'),
  (1, 'parish_state',              'GA'),
  (1, 'analytics_track_browser',   '1'),
  (1, 'analytics_track_os',        '1'),
  (1, 'analytics_session_minutes', '30');
