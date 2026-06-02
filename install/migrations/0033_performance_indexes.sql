-- Migration 0033: Add performance indexes for common queries

-- pages: slug+site_id lookups (already has UNIQUE KEY idx_site_slug but ensure status index)
ALTER TABLE `pages`
  ADD INDEX IF NOT EXISTS `idx_site_status` (`site_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_parent_site` (`parent_id`, `site_id`);

-- posts: published feed queries filter on site_id + status + published_at
ALTER TABLE `posts`
  ADD INDEX IF NOT EXISTS `idx_site_status_pub` (`site_id`, `status`, `published_at`),
  ADD INDEX IF NOT EXISTS `idx_site_cat` (`site_id`, `category_id`);

-- analytics: viewed_at queries are the hottest (dashboard aggregates)
ALTER TABLE `analytics_views`
  ADD INDEX IF NOT EXISTS `idx_site_viewed` (`site_id`, `viewed_at`);

-- settings: site_id + key lookups (autoload)
ALTER TABLE `settings`
  ADD INDEX IF NOT EXISTS `idx_site_autoload` (`site_id`, `autoload`);

-- lectionary_readings: date_override and lookup_key are queried on every reading display
ALTER TABLE `lectionary_readings`
  ADD INDEX IF NOT EXISTS `idx_date_override` (`date_override`),
  ADD INDEX IF NOT EXISTS `idx_lookup_key`    (`lookup_key`);

-- contact & prayer submissions: site_id filter for inbox views
ALTER TABLE `contact_submissions`
  ADD INDEX IF NOT EXISTS `idx_site_read` (`site_id`, `is_read`);

ALTER TABLE `prayer_requests`
  ADD INDEX IF NOT EXISTS `idx_site_read` (`site_id`, `is_read`);
