-- Migration 0004: Prayer requests table and form intro settings

CREATE TABLE IF NOT EXISTS `prayer_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `intention_type` VARCHAR(100) DEFAULT NULL,
  `intention` TEXT NOT NULL,
  `is_anonymous` TINYINT(1) NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO `settings` (`site_id`, `key`, `value`) VALUES
  (1, 'form_intro_forms',   'Find all of our parish forms below. We welcome your messages and prayer intentions.'),
  (1, 'form_intro_contact', 'We would love to hear from you. Fill out the form below and a member of our parish staff will be in touch soon.'),
  (1, 'form_intro_prayer',  'We are honored to pray with you. Please share your intention below and our parish community will hold you in prayer.');
