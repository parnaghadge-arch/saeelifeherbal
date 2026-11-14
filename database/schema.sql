-- Saee Life Herbal - MySQL 8 schema
-- Generated 2026-11-14 13:19:19

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE IF NOT EXISTS `settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` LONGTEXT NULL,
  `setting_type` VARCHAR(20) NOT NULL DEFAULT 'string',
  `setting_group` VARCHAR(60) NOT NULL DEFAULT 'general',
  `updated_at` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uq_settings_setting_key` (`setting_key`),
  KEY `idx_settings_setting_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sequences`;
CREATE TABLE IF NOT EXISTS `sequences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL,
  `value` BIGINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uq_sequences_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `scope` VARCHAR(40) NOT NULL,
  `identifier` VARCHAR(191) NOT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME NULL,
  `locked_until` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_login_attempts_scope_identifier` (`scope`, `identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `idempotency_keys`;
CREATE TABLE IF NOT EXISTS `idempotency_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `idempotency_key` VARCHAR(191) NOT NULL,
  `scope` VARCHAR(60) NOT NULL,
  `reference_id` BIGINT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_idempotency_keys_idempotency_key` (`idempotency_key`),
  KEY `idx_idempotency_keys_scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `website_pages`;
CREATE TABLE IF NOT EXISTS `website_pages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(191) NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `meta_title` VARCHAR(191) NULL,
  `meta_description` TEXT NULL,
  `meta_keywords` TEXT NULL,
  `featured_image` VARCHAR(255) NULL,
  `banner_image` VARCHAR(255) NULL,
  `og_image` VARCHAR(255) NULL,
  `canonical_url` VARCHAR(255) NULL,
  `content` LONGTEXT NULL,
  `template` VARCHAR(40) NOT NULL DEFAULT 'default',
  `status` ENUM('draft','published') NOT NULL DEFAULT 'published',
  `visibility` ENUM('public','private','members') NOT NULL DEFAULT 'public',
  `is_system` TINYINT(1) NULL DEFAULT 0,
  `show_in_nav` TINYINT(1) NULL DEFAULT 0,
  `show_in_footer` TINYINT(1) NULL DEFAULT 0,
  `custom_css` TEXT NULL,
  `robots` VARCHAR(60) NOT NULL DEFAULT 'index,follow',
  `sort_order` INT NOT NULL DEFAULT 0,
  `published_at` DATETIME NULL,
  `author_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_website_pages_author_id FOREIGN KEY (`author_id`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY `uq_website_pages_slug` (`slug`),
  KEY `idx_website_pages_status` (`status`),
  KEY `idx_website_pages_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `page_sections`;
CREATE TABLE IF NOT EXISTS `page_sections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `page_id` BIGINT UNSIGNED NOT NULL,
  `section_key` VARCHAR(80) NOT NULL,
  `title` VARCHAR(191) NULL,
  `subtitle` VARCHAR(255) NULL,
  `content` LONGTEXT NULL,
  `image` VARCHAR(255) NULL,
  `settings` JSON NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_page_sections_page_id FOREIGN KEY (`page_id`) REFERENCES `website_pages`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_page_sections_page_id` (`page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `navigation_menus`;
CREATE TABLE IF NOT EXISTS `navigation_menus` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `parent_id` BIGINT UNSIGNED NULL,
  `label` VARCHAR(120) NOT NULL,
  `url` VARCHAR(255) NOT NULL,
  `icon` VARCHAR(80) NULL,
  `target` VARCHAR(10) NOT NULL DEFAULT '_self',
  `position` VARCHAR(20) NOT NULL DEFAULT 'header',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_navigation_menus_parent_id FOREIGN KEY (`parent_id`) REFERENCES `navigation_menus`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_navigation_menus_parent_id` (`parent_id`),
  KEY `idx_navigation_menus_position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `homepage_sliders`;
CREATE TABLE IF NOT EXISTS `homepage_sliders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(191) NULL,
  `subtitle` VARCHAR(255) NULL,
  `description` TEXT NULL,
  `image_desktop` VARCHAR(255) NULL,
  `image_mobile` VARCHAR(255) NULL,
  `button_text` VARCHAR(80) NULL,
  `button_url` VARCHAR(255) NULL,
  `button2_text` VARCHAR(80) NULL,
  `button2_url` VARCHAR(255) NULL,
  `text_align` ENUM('left','center','right') NOT NULL DEFAULT 'left',
  `show_heading` TINYINT(1) NULL DEFAULT 1,
  `show_description` TINYINT(1) NULL DEFAULT 1,
  `show_buttons` TINYINT(1) NULL DEFAULT 1,
  `overlay_color` VARCHAR(20) NOT NULL DEFAULT '#14532D',
  `overlay_opacity` DECIMAL(4,2) NOT NULL DEFAULT 0.45,
  `transition` ENUM('fade','slide','zoom','kenburns','parallax','soft') NOT NULL DEFAULT 'fade',
  `text_animation` VARCHAR(30) NOT NULL DEFAULT 'fade-up',
  `image_animation` VARCHAR(30) NOT NULL DEFAULT 'none',
  `duration` INT NOT NULL DEFAULT 6,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `publish_from` DATETIME NULL,
  `publish_to` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_homepage_sliders_sort_order` (`sort_order`),
  KEY `idx_homepage_sliders_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `banners`;
CREATE TABLE IF NOT EXISTS `banners` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(191) NULL,
  `image` VARCHAR(255) NULL,
  `link` VARCHAR(255) NULL,
  `position` VARCHAR(40) NOT NULL DEFAULT 'home-middle',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `starts_at` DATETIME NULL,
  `ends_at` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_banners_position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `social_media`;
CREATE TABLE IF NOT EXISTS `social_media` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `platform` VARCHAR(60) NOT NULL,
  `display_name` VARCHAR(80) NULL,
  `url` VARCHAR(255) NOT NULL,
  `icon` VARCHAR(80) NULL,
  `icon_image` VARCHAR(255) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `open_new_tab` TINYINT(1) NULL DEFAULT 1,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_social_media_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `media_library`;
CREATE TABLE IF NOT EXISTS `media_library` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `file_name` VARCHAR(191) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(40) NOT NULL DEFAULT 'image',
  `mime_type` VARCHAR(120) NULL,
  `file_size` BIGINT NOT NULL DEFAULT 0,
  `width` INT NULL,
  `height` INT NULL,
  `alt_text` VARCHAR(191) NULL,
  `folder` VARCHAR(60) NOT NULL DEFAULT 'media',
  `uploaded_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_media_library_uploaded_by FOREIGN KEY (`uploaded_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_media_library_file_type` (`file_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `blog_categories`;
CREATE TABLE IF NOT EXISTS `blog_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `description` TEXT NULL,
  `image` VARCHAR(255) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uq_blog_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `blogs`;
CREATE TABLE IF NOT EXISTS `blogs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `category_id` BIGINT UNSIGNED NULL,
  `title` VARCHAR(191) NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `excerpt` TEXT NULL,
  `content` LONGTEXT NULL,
  `featured_image` VARCHAR(255) NULL,
  `author_name` VARCHAR(120) NULL,
  `tags` VARCHAR(255) NULL,
  `meta_title` VARCHAR(191) NULL,
  `meta_description` TEXT NULL,
  `views` INT NOT NULL DEFAULT 0,
  `status` ENUM('draft','published') NOT NULL DEFAULT 'published',
  `published_at` DATETIME NULL,
  `author_id` BIGINT UNSIGNED NULL,
  `is_featured` TINYINT(1) NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_blogs_category_id FOREIGN KEY (`category_id`) REFERENCES `blog_categories`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_blogs_author_id FOREIGN KEY (`author_id`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_blogs_category_id` (`category_id`),
  UNIQUE KEY `uq_blogs_slug` (`slug`),
  KEY `idx_blogs_status` (`status`),
  KEY `idx_blogs_published_at` (`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `gallery_items`;
CREATE TABLE IF NOT EXISTS `gallery_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(191) NULL,
  `image` VARCHAR(255) NOT NULL,
  `category` VARCHAR(60) NOT NULL DEFAULT 'General',
  `description` TEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_gallery_items_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `videos`;
CREATE TABLE IF NOT EXISTS `videos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(191) NOT NULL,
  `description` TEXT NULL,
  `source_type` ENUM('youtube','local','vimeo') NOT NULL DEFAULT 'youtube',
  `video_url` VARCHAR(255) NOT NULL,
  `video_file` VARCHAR(255) NULL,
  `thumbnail` VARCHAR(255) NULL,
  `category` VARCHAR(60) NOT NULL DEFAULT 'General',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `testimonials`;
CREATE TABLE IF NOT EXISTS `testimonials` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `designation` VARCHAR(120) NULL,
  `location` VARCHAR(120) NULL,
  `message` TEXT NOT NULL,
  `photo` VARCHAR(255) NULL,
  `rating` TINYINT NOT NULL DEFAULT 5,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `team_members`;
CREATE TABLE IF NOT EXISTS `team_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `designation` VARCHAR(120) NULL,
  `role_type` VARCHAR(40) NOT NULL DEFAULT 'team',
  `bio` TEXT NULL,
  `photo` VARCHAR(255) NULL,
  `email` VARCHAR(191) NULL,
  `phone` VARCHAR(20) NULL,
  `linkedin_url` VARCHAR(255) NULL,
  `facebook_url` VARCHAR(255) NULL,
  `instagram_url` VARCHAR(255) NULL,
  `x_url` VARCHAR(255) NULL,
  `highlight` VARCHAR(60) NULL,
  `quote` TEXT NULL,
  `show_on_home` TINYINT(1) NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_team_members_role_type` (`role_type`),
  KEY `idx_team_members_show_on_home` (`show_on_home`),
  KEY `idx_team_members_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `faqs`;
CREATE TABLE IF NOT EXISTS `faqs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `category` VARCHAR(80) NOT NULL DEFAULT 'General',
  `question` VARCHAR(255) NOT NULL,
  `answer` TEXT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_faqs_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `contact_enquiries`;
CREATE TABLE IF NOT EXISTS `contact_enquiries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(191) NULL,
  `phone` VARCHAR(20) NULL,
  `subject` VARCHAR(191) NULL,
  `message` TEXT NOT NULL,
  `source_page` VARCHAR(120) NULL,
  `status` ENUM('new','read','replied','spam') NOT NULL DEFAULT 'new',
  `admin_reply` TEXT NULL,
  `replied_at` DATETIME NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_contact_enquiries_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `newsletter_subscribers`;
CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(191) NOT NULL,
  `name` VARCHAR(120) NULL,
  `status` TINYINT(1) NULL DEFAULT 1,
  `token` VARCHAR(64) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uq_newsletter_subscribers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `b2b_leads`;
CREATE TABLE IF NOT EXISTS `b2b_leads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `lead_no` VARCHAR(30) NULL,
  `company_name` VARCHAR(191) NULL,
  `contact_name` VARCHAR(120) NOT NULL,
  `designation` VARCHAR(120) NULL,
  `email` VARCHAR(191) NULL,
  `phone` VARCHAR(20) NOT NULL,
  `whatsapp` VARCHAR(20) NULL,
  `business_type` ENUM('retailer','distributor','dealer','fpo','cooperative','exporter','other') NULL DEFAULT 'other',
  `city` VARCHAR(120) NULL,
  `state` VARCHAR(120) NULL,
  `pincode` VARCHAR(10) NULL,
  `gstin` VARCHAR(20) NULL,
  `annual_turnover` VARCHAR(60) NULL,
  `product_interest` VARCHAR(255) NULL,
  `monthly_volume` VARCHAR(120) NULL,
  `message` TEXT NULL,
  `source_page` VARCHAR(120) NULL,
  `assigned_to` BIGINT UNSIGNED NULL,
  `status` ENUM('new','contacted','qualified','proposal','negotiation','won','lost','spam') NOT NULL DEFAULT 'new',
  `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `follow_up_at` DATETIME NULL,
  `admin_note` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_b2b_leads_assigned_to FOREIGN KEY (`assigned_to`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY `uq_b2b_leads_lead_no` (`lead_no`),
  KEY `idx_b2b_leads_email` (`email`),
  KEY `idx_b2b_leads_phone` (`phone`),
  KEY `idx_b2b_leads_assigned_to` (`assigned_to`),
  KEY `idx_b2b_leads_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `b2b_lead_activities`;
CREATE TABLE IF NOT EXISTS `b2b_lead_activities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `lead_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NULL,
  `type` ENUM('note','call','email','whatsapp','meeting','status') NOT NULL DEFAULT 'note',
  `body` TEXT NOT NULL,
  `old_status` VARCHAR(20) NULL,
  `new_status` VARCHAR(20) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_b2b_lead_activities_lead_id FOREIGN KEY (`lead_id`) REFERENCES `b2b_leads`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_b2b_lead_activities_admin_id FOREIGN KEY (`admin_id`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_b2b_lead_activities_lead_id` (`lead_id`),
  KEY `idx_b2b_lead_activities_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_categories`;
CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `parent_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `short_description` TEXT NULL,
  `description` LONGTEXT NULL,
  `image` VARCHAR(255) NULL,
  `banner_image` VARCHAR(255) NULL,
  `icon` VARCHAR(80) NULL,
  `meta_title` VARCHAR(191) NULL,
  `meta_description` TEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `show_on_home` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_product_categories_parent_id FOREIGN KEY (`parent_id`) REFERENCES `product_categories`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_product_categories_parent_id` (`parent_id`),
  UNIQUE KEY `uq_product_categories_slug` (`slug`),
  KEY `idx_product_categories_sort_order` (`sort_order`),
  KEY `idx_product_categories_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `category_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(191) NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `sku` VARCHAR(80) NULL,
  `short_description` TEXT NULL,
  `description` LONGTEXT NULL,
  `technical_specifications` LONGTEXT NULL,
  `benefits` LONGTEXT NULL,
  `application_instructions` LONGTEXT NULL,
  `dosage` TEXT NULL,
  `suitable_crops` VARCHAR(255) NULL,
  `target_pests` VARCHAR(255) NULL,
  `precautions` TEXT NULL,
  `packaging_sizes` VARCHAR(255) NULL,
  `mrp` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `stock_quantity` INT NOT NULL DEFAULT 0,
  `min_order_qty` INT NOT NULL DEFAULT 1,
  `unit` VARCHAR(30) NOT NULL DEFAULT 'unit',
  `featured_image` VARCHAR(255) NULL,
  `is_featured` TINYINT(1) NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `meta_title` VARCHAR(191) NULL,
  `meta_description` TEXT NULL,
  `meta_keywords` VARCHAR(255) NULL,
  `views` INT NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `mlm_enabled` TINYINT(1) NULL DEFAULT 0,
  `direct_commission_type` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  `direct_commission_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `grand_direct_commission_type` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  `grand_direct_commission_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `commission_basis` ENUM('selling_price','mrp','net_amount') NOT NULL DEFAULT 'selling_price',
  `royalty_eligible` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_products_category_id FOREIGN KEY (`category_id`) REFERENCES `product_categories`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_products_category_id` (`category_id`),
  UNIQUE KEY `uq_products_slug` (`slug`),
  KEY `idx_products_sku` (`sku`),
  KEY `idx_products_is_featured` (`is_featured`),
  KEY `idx_products_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_images`;
CREATE TABLE IF NOT EXISTS `product_images` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `image_path` VARCHAR(255) NOT NULL,
  `alt_text` VARCHAR(191) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1) NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_product_images_product_id FOREIGN KEY (`product_id`) REFERENCES `products`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_product_images_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_videos`;
CREATE TABLE IF NOT EXISTS `product_videos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(191) NULL,
  `source_type` ENUM('youtube','local','vimeo') NOT NULL DEFAULT 'youtube',
  `video_url` VARCHAR(255) NULL,
  `video_file` VARCHAR(255) NULL,
  `thumbnail` VARCHAR(255) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_product_videos_product_id FOREIGN KEY (`product_id`) REFERENCES `products`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_product_videos_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_variants`;
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `sku` VARCHAR(80) NULL,
  `pack_size` VARCHAR(60) NULL,
  `mrp` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `stock_quantity` INT NOT NULL DEFAULT 0,
  `is_default` TINYINT(1) NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_product_variants_product_id FOREIGN KEY (`product_id`) REFERENCES `products`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_product_variants_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `customers`;
CREATE TABLE IF NOT EXISTS `customers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `member_id` BIGINT UNSIGNED NULL,
  `referred_by_member_id` BIGINT UNSIGNED NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `email_verified_at` DATETIME NULL,
  `remember_token` VARCHAR(100) NULL,
  `reset_token` VARCHAR(100) NULL,
  `reset_expires_at` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_customers_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_customers_referred_by_member_id FOREIGN KEY (`referred_by_member_id`) REFERENCES `members`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY `uq_customers_email` (`email`),
  UNIQUE KEY `uq_customers_mobile` (`mobile`),
  KEY `idx_customers_member_id` (`member_id`),
  KEY `idx_customers_referred_by_member_id` (`referred_by_member_id`),
  KEY `idx_customers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `customer_addresses`;
CREATE TABLE IF NOT EXISTS `customer_addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `address_type` ENUM('shipping','billing') NOT NULL DEFAULT 'shipping',
  `full_name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(15) NOT NULL,
  `address_line1` VARCHAR(255) NOT NULL,
  `address_line2` VARCHAR(255) NULL,
  `city` VARCHAR(100) NOT NULL,
  `district` VARCHAR(100) NULL,
  `state` VARCHAR(100) NOT NULL,
  `pincode` VARCHAR(10) NOT NULL,
  `landmark` VARCHAR(150) NULL,
  `is_default` TINYINT(1) NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_customer_addresses_customer_id FOREIGN KEY (`customer_id`) REFERENCES `customers`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_customer_addresses_customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `carts`;
CREATE TABLE IF NOT EXISTS `carts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id` BIGINT UNSIGNED NULL,
  `session_token` VARCHAR(64) NULL,
  `status` ENUM('open','converted','abandoned') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_carts_customer_id FOREIGN KEY (`customer_id`) REFERENCES `customers`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_carts_customer_id` (`customer_id`),
  KEY `idx_carts_session_token` (`session_token`),
  KEY `idx_carts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cart_items`;
CREATE TABLE IF NOT EXISTS `cart_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `cart_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_cart_items_cart_id FOREIGN KEY (`cart_id`) REFERENCES `carts`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cart_items_product_id FOREIGN KEY (`product_id`) REFERENCES `products`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cart_items_variant_id FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_cart_items_cart_id` (`cart_id`),
  KEY `idx_cart_items_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `wishlists`;
CREATE TABLE IF NOT EXISTS `wishlists` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id` BIGINT UNSIGNED NULL,
  `session_token` VARCHAR(64) NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_wishlists_customer_id FOREIGN KEY (`customer_id`) REFERENCES `customers`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_wishlists_product_id FOREIGN KEY (`product_id`) REFERENCES `products`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_wishlists_customer_id` (`customer_id`),
  KEY `idx_wishlists_session_token` (`session_token`),
  KEY `idx_wishlists_product_id` (`product_id`),
  KEY `idx_wishlists_customer_id_product_id` (`customer_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `orders`;
CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `order_number` VARCHAR(40) NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `member_id` BIGINT UNSIGNED NULL,
  `referral_member_id` BIGINT UNSIGNED NULL,
  `customer_name` VARCHAR(150) NOT NULL,
  `customer_email` VARCHAR(191) NULL,
  `customer_mobile` VARCHAR(15) NOT NULL,
  `shipping_address` JSON NOT NULL,
  `billing_address` JSON NULL,
  `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `discount_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `shipping_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `grand_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `payment_method` VARCHAR(30) NOT NULL DEFAULT 'cod',
  `payment_status` ENUM('unpaid','pending','paid','failed','refunded') NOT NULL DEFAULT 'unpaid',
  `status` ENUM('new','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'new',
  `notes` TEXT NULL,
  `admin_notes` TEXT NULL,
  `coupon_code` VARCHAR(40) NULL,
  `tracking_number` VARCHAR(80) NULL,
  `carrier` VARCHAR(80) NULL,
  `invoice_number` VARCHAR(40) NULL,
  `invoice_date` DATE NULL,
  `commission_processed_at` DATETIME NULL,
  `commission_status` ENUM('pending','credited','reversed','none') NOT NULL DEFAULT 'none',
  `paid_at` DATETIME NULL,
  `shipped_at` DATETIME NULL,
  `delivered_at` DATETIME NULL,
  `cancelled_at` DATETIME NULL,
  `refunded_at` DATETIME NULL,
  `cancel_reason` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_orders_customer_id FOREIGN KEY (`customer_id`) REFERENCES `customers`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_orders_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_orders_referral_member_id FOREIGN KEY (`referral_member_id`) REFERENCES `members`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY `uq_orders_order_number` (`order_number`),
  KEY `idx_orders_customer_id` (`customer_id`),
  KEY `idx_orders_member_id` (`member_id`),
  KEY `idx_orders_referral_member_id` (`referral_member_id`),
  KEY `idx_orders_payment_method` (`payment_method`),
  KEY `idx_orders_payment_status` (`payment_status`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_invoice_number` (`invoice_number`),
  KEY `idx_orders_commission_status` (`commission_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `order_items`;
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NULL,
  `variant_id` BIGINT UNSIGNED NULL,
  `product_name` VARCHAR(191) NOT NULL,
  `product_sku` VARCHAR(80) NULL,
  `pack_size` VARCHAR(60) NULL,
  `unit_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `quantity` INT NOT NULL DEFAULT 1,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `commissionable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_order_items_order_id FOREIGN KEY (`order_id`) REFERENCES `orders`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_order_items_product_id FOREIGN KEY (`product_id`) REFERENCES `products`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_order_items_variant_id FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_order_items_order_id` (`order_id`),
  KEY `idx_order_items_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `order_payments`;
CREATE TABLE IF NOT EXISTS `order_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `gateway` VARCHAR(30) NOT NULL DEFAULT 'cod',
  `gateway_order_id` VARCHAR(120) NULL,
  `gateway_payment_id` VARCHAR(120) NULL,
  `gateway_signature` VARCHAR(255) NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` ENUM('pending','success','failed','refunded') NOT NULL DEFAULT 'pending',
  `idempotency_key` VARCHAR(191) NULL,
  `payload` JSON NULL,
  `failure_reason` TEXT NULL,
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_order_payments_order_id FOREIGN KEY (`order_id`) REFERENCES `orders`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_order_payments_order_id` (`order_id`),
  KEY `idx_order_payments_gateway` (`gateway`),
  KEY `idx_order_payments_gateway_order_id` (`gateway_order_id`),
  UNIQUE KEY `uq_order_payments_gateway_payment_id` (`gateway_payment_id`),
  KEY `idx_order_payments_status` (`status`),
  UNIQUE KEY `uq_order_payments_idempotency_key` (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `order_status_history`;
CREATE TABLE IF NOT EXISTS `order_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(30) NOT NULL,
  `note` TEXT NULL,
  `changed_by_type` VARCHAR(20) NOT NULL DEFAULT 'system',
  `changed_by_id` BIGINT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_order_status_history_order_id FOREIGN KEY (`order_id`) REFERENCES `orders`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_order_status_history_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `gateway_webhook_events`;
CREATE TABLE IF NOT EXISTS `gateway_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `gateway` VARCHAR(30) NOT NULL,
  `event_id` VARCHAR(191) NOT NULL,
  `event_type` VARCHAR(80) NULL,
  `payload` LONGTEXT NULL,
  `processed` TINYINT(1) NULL DEFAULT 0,
  `processed_at` DATETIME NULL,
  `error` TEXT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_gateway_webhook_events_gateway` (`gateway`),
  UNIQUE KEY `uq_gateway_webhook_events_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `members`;
CREATE TABLE IF NOT EXISTS `members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_code` VARCHAR(20) NOT NULL,
  `sponsor_id` BIGINT UNSIGNED NULL,
  `original_sponsor_id` BIGINT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('farmer','distributor','retailer') NOT NULL DEFAULT 'farmer',
  `status` ENUM('pending','active','inactive','suspended') NOT NULL DEFAULT 'active',
  `is_company` TINYINT(1) NULL DEFAULT 0,
  `referral_code` VARCHAR(24) NOT NULL,
  `kyc_status` ENUM('not_submitted','submitted','under_verification','approved','rejected','resubmission_required') NOT NULL DEFAULT 'not_submitted',
  `is_payout_eligible` TINYINT(1) NULL DEFAULT 0,
  `joined_at` DATETIME NOT NULL,
  `activated_at` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `remember_token` VARCHAR(100) NULL,
  `reset_token` VARCHAR(100) NULL,
  `reset_expires_at` DATETIME NULL,
  `terms_accepted_at` DATETIME NULL,
  `direct_referral_count` INT NOT NULL DEFAULT 0,
  `downline_count` INT NOT NULL DEFAULT 0,
  `active_downline_count` INT NOT NULL DEFAULT 0,
  `total_purchase_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_earned` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `admin_note` TEXT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_members_sponsor_id FOREIGN KEY (`sponsor_id`) REFERENCES `members`(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  UNIQUE KEY `uq_members_member_code` (`member_code`),
  KEY `idx_members_member_code` (`member_code`),
  KEY `idx_members_sponsor_id` (`sponsor_id`),
  KEY `idx_members_name` (`name`),
  UNIQUE KEY `uq_members_email` (`email`),
  UNIQUE KEY `uq_members_mobile` (`mobile`),
  KEY `idx_members_mobile` (`mobile`),
  KEY `idx_members_role` (`role`),
  KEY `idx_members_status` (`status`),
  UNIQUE KEY `uq_members_referral_code` (`referral_code`),
  KEY `idx_members_kyc_status` (`kyc_status`),
  KEY `idx_members_joined_at` (`joined_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `member_profiles`;
CREATE TABLE IF NOT EXISTS `member_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `date_of_birth` DATE NULL,
  `gender` ENUM('male','female','other') NULL,
  `address` VARCHAR(255) NULL,
  `state` VARCHAR(100) NULL,
  `district` VARCHAR(100) NULL,
  `taluka` VARCHAR(100) NULL,
  `village` VARCHAR(100) NULL,
  `pincode` VARCHAR(10) NULL,
  `photo` VARCHAR(255) NULL,
  `occupation` VARCHAR(100) NULL,
  `land_area` VARCHAR(60) NULL,
  `primary_crops` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_member_profiles_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY `uq_member_profiles_member_id` (`member_id`),
  KEY `idx_member_profiles_state` (`state`),
  KEY `idx_member_profiles_district` (`district`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `member_sponsors`;
CREATE TABLE IF NOT EXISTS `member_sponsors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `sponsor_id` BIGINT UNSIGNED NULL,
  `original_sponsor_id` BIGINT NULL,
  `relationship` VARCHAR(20) NOT NULL DEFAULT 'direct',
  `assigned_at` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_member_sponsors_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_member_sponsors_sponsor_id FOREIGN KEY (`sponsor_id`) REFERENCES `members`(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  UNIQUE KEY `uq_member_sponsors_member_id` (`member_id`),
  KEY `idx_member_sponsors_sponsor_id` (`sponsor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `member_sponsor_history`;
CREATE TABLE IF NOT EXISTS `member_sponsor_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `old_sponsor_id` BIGINT NULL,
  `new_sponsor_id` BIGINT NULL,
  `changed_by_admin_id` BIGINT UNSIGNED NULL,
  `reason` TEXT NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_member_sponsor_history_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_member_sponsor_history_changed_by_admin_id FOREIGN KEY (`changed_by_admin_id`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_member_sponsor_history_member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `member_referrals`;
CREATE TABLE IF NOT EXISTS `member_referrals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sponsor_id` BIGINT UNSIGNED NOT NULL,
  `referred_member_id` BIGINT UNSIGNED NOT NULL,
  `referral_code` VARCHAR(24) NULL,
  `source` VARCHAR(30) NOT NULL DEFAULT 'link',
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_member_referrals_sponsor_id FOREIGN KEY (`sponsor_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_member_referrals_referred_member_id FOREIGN KEY (`referred_member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_member_referrals_sponsor_id` (`sponsor_id`),
  UNIQUE KEY `uq_member_referrals_referred_member_id` (`referred_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `member_referral_clicks`;
CREATE TABLE IF NOT EXISTS `member_referral_clicks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `referral_code` VARCHAR(24) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_member_referral_clicks_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_member_referral_clicks_member_id` (`member_id`),
  KEY `idx_member_referral_clicks_referral_code` (`referral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `member_kyc`;
CREATE TABLE IF NOT EXISTS `member_kyc` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `pan_number` VARCHAR(255) NULL,
  `pan_name` VARCHAR(150) NULL,
  `aadhaar_number` VARCHAR(255) NULL,
  `date_of_birth` DATE NULL,
  `address` VARCHAR(255) NULL,
  `pan_document` VARCHAR(255) NULL,
  `identity_document` VARCHAR(255) NULL,
  `address_document` VARCHAR(255) NULL,
  `photo` VARCHAR(255) NULL,
  `status` ENUM('not_submitted','submitted','under_verification','approved','rejected','resubmission_required') NOT NULL DEFAULT 'not_submitted',
  `rejection_reason` TEXT NULL,
  `admin_note` TEXT NULL,
  `submitted_at` DATETIME NULL,
  `verified_at` DATETIME NULL,
  `verified_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_member_kyc_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_member_kyc_verified_by FOREIGN KEY (`verified_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY `uq_member_kyc_member_id` (`member_id`),
  KEY `idx_member_kyc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `member_bank_accounts`;
CREATE TABLE IF NOT EXISTS `member_bank_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `account_holder_name` VARCHAR(150) NOT NULL,
  `bank_name` VARCHAR(150) NOT NULL,
  `account_number` VARCHAR(255) NOT NULL,
  `ifsc_code` VARCHAR(20) NOT NULL,
  `branch_name` VARCHAR(150) NULL,
  `account_type` VARCHAR(20) NOT NULL DEFAULT 'savings',
  `cheque_image` VARCHAR(255) NULL,
  `status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `is_primary` TINYINT(1) NULL DEFAULT 1,
  `verified_at` DATETIME NULL,
  `verified_by` BIGINT UNSIGNED NULL,
  `rejection_reason` TEXT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_member_bank_accounts_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_member_bank_accounts_verified_by FOREIGN KEY (`verified_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_member_bank_accounts_member_id` (`member_id`),
  KEY `idx_member_bank_accounts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `commission_transactions`;
CREATE TABLE IF NOT EXISTS `commission_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `transaction_code` VARCHAR(30) NOT NULL,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `beneficiary_type` ENUM('direct','grand_direct') NOT NULL,
  `commission_type` ENUM('direct','grand_direct','royalty','incentive') NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `order_item_id` BIGINT UNSIGNED NULL,
  `product_id` BIGINT UNSIGNED NULL,
  `product_name` VARCHAR(191) NULL,
  `buyer_member_id` BIGINT UNSIGNED NULL,
  `buyer_name` VARCHAR(150) NULL,
  `level` INT NOT NULL DEFAULT 1,
  `basis_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `commission_type_rate` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  `rate_value` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` ENUM('pending','approved','available','paid','rejected','reversed') NOT NULL DEFAULT 'pending',
  `reversed_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `idempotency_key` VARCHAR(191) NOT NULL,
  `payment_reference` VARCHAR(80) NULL,
  `remittance_reference` VARCHAR(80) NULL,
  `remarks` TEXT NULL,
  `available_at` DATETIME NULL,
  `credited_at` DATETIME NULL,
  `paid_at` DATETIME NULL,
  `reversed_at` DATETIME NULL,
  `reversal_reason` TEXT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_commission_transactions_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_commission_transactions_order_id FOREIGN KEY (`order_id`) REFERENCES `orders`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_commission_transactions_order_item_id FOREIGN KEY (`order_item_id`) REFERENCES `order_items`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_commission_transactions_product_id FOREIGN KEY (`product_id`) REFERENCES `products`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_commission_transactions_buyer_member_id FOREIGN KEY (`buyer_member_id`) REFERENCES `members`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY `uq_commission_transactions_transaction_code` (`transaction_code`),
  KEY `idx_commission_transactions_member_id` (`member_id`),
  KEY `idx_commission_transactions_commission_type` (`commission_type`),
  KEY `idx_commission_transactions_order_id` (`order_id`),
  KEY `idx_commission_transactions_order_item_id` (`order_item_id`),
  KEY `idx_commission_transactions_product_id` (`product_id`),
  KEY `idx_commission_transactions_buyer_member_id` (`buyer_member_id`),
  KEY `idx_commission_transactions_status` (`status`),
  UNIQUE KEY `uq_commission_transactions_idempotency_key` (`idempotency_key`),
  KEY `idx_commission_transactions_member_id_status` (`member_id`, `status`),
  KEY `idx_commission_transactions_order_id_commission_type` (`order_id`, `commission_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `royalty_rules`;
CREATE TABLE IF NOT EXISTS `royalty_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `level_no` INT NOT NULL DEFAULT 1,
  `required_direct_referrals` INT NOT NULL DEFAULT 0,
  `required_downline` INT NOT NULL DEFAULT 0,
  `required_active_members` INT NOT NULL DEFAULT 0,
  `required_purchase_count` INT NOT NULL DEFAULT 0,
  `min_qualifying_sales` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `qualification_period_days` INT NOT NULL DEFAULT 0,
  `reward_type` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  `reward_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `reward_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `percent_basis` ENUM('downline_sales','team_sales','payout_pool') NOT NULL DEFAULT 'downline_sales',
  `frequency` ENUM('one_time','recurring') NOT NULL DEFAULT 'one_time',
  `recurring_months` INT NOT NULL DEFAULT 1,
  `requires_admin_approval` TINYINT(1) NULL DEFAULT 1,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `description` TEXT NULL,
  `status` TINYINT(1) NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_royalty_rules_level_no` (`level_no`),
  KEY `idx_royalty_rules_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `royalty_achievements`;
CREATE TABLE IF NOT EXISTS `royalty_achievements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `royalty_rule_id` BIGINT UNSIGNED NOT NULL,
  `cycle_key` VARCHAR(30) NOT NULL DEFAULT 'once',
  `direct_referrals_snapshot` INT NOT NULL DEFAULT 0,
  `downline_snapshot` INT NOT NULL DEFAULT 0,
  `active_snapshot` INT NOT NULL DEFAULT 0,
  `qualifying_sales_snapshot` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `reward_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
  `commission_transaction_id` BIGINT UNSIGNED NULL,
  `achieved_at` DATETIME NOT NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `remarks` TEXT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_royalty_achievements_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_royalty_achievements_royalty_rule_id FOREIGN KEY (`royalty_rule_id`) REFERENCES `royalty_rules`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_royalty_achievements_commission_transaction_id FOREIGN KEY (`commission_transaction_id`) REFERENCES `commission_transactions`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_royalty_achievements_approved_by FOREIGN KEY (`approved_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_royalty_achievements_member_id` (`member_id`),
  KEY `idx_royalty_achievements_royalty_rule_id` (`royalty_rule_id`),
  KEY `idx_royalty_achievements_status` (`status`),
  KEY `idx_royalty_achievements_achieved_at` (`achieved_at`),
  UNIQUE KEY `uq_royalty_achievements_member_id_royalty_rule_id_cycle_key` (`member_id`, `royalty_rule_id`, `cycle_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `wallet_accounts`;
CREATE TABLE IF NOT EXISTS `wallet_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `direct_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `grand_direct_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `royalty_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `incentive_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `pending_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `approved_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `available_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_earned` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_withdrawn` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_reversed` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `locked_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `last_activity_at` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_wallet_accounts_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY `uq_wallet_accounts_member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `wallet_transactions`;
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `transaction_code` VARCHAR(30) NOT NULL,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `wallet_type` ENUM('direct','grand_direct','royalty','incentive') NOT NULL,
  `direction` ENUM('credit','debit') NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `balance_after` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `reference_type` VARCHAR(40) NOT NULL DEFAULT 'manual',
  `reference_id` BIGINT NULL,
  `status` ENUM('pending','available','paid','reversed') NOT NULL DEFAULT 'available',
  `description` VARCHAR(255) NULL,
  `created_by_admin_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_wallet_transactions_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_wallet_transactions_created_by_admin_id FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY `uq_wallet_transactions_transaction_code` (`transaction_code`),
  KEY `idx_wallet_transactions_member_id` (`member_id`),
  KEY `idx_wallet_transactions_wallet_type` (`wallet_type`),
  KEY `idx_wallet_transactions_direction` (`direction`),
  KEY `idx_wallet_transactions_reference_type` (`reference_type`),
  KEY `idx_wallet_transactions_reference_id` (`reference_id`),
  KEY `idx_wallet_transactions_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payout_requests`;
CREATE TABLE IF NOT EXISTS `payout_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `request_code` VARCHAR(30) NOT NULL,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `deduction_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tds_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `admin_charges` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` ENUM('pending','approved','processing','rejected','paid','cancelled') NOT NULL DEFAULT 'pending',
  `source` ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  `cycle_key` VARCHAR(20) NULL,
  `bank_account_id` BIGINT UNSIGNED NULL,
  `bank_snapshot` JSON NULL,
  `kyc_snapshot` JSON NULL,
  `member_note` TEXT NULL,
  `admin_note` TEXT NULL,
  `rejection_reason` TEXT NULL,
  `utr_number` VARCHAR(80) NULL,
  `payment_proof` VARCHAR(255) NULL,
  `payment_mode` VARCHAR(30) NOT NULL DEFAULT 'bank_transfer',
  `requested_at` DATETIME NOT NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `rejected_by` BIGINT UNSIGNED NULL,
  `rejected_at` DATETIME NULL,
  `paid_by` BIGINT UNSIGNED NULL,
  `paid_at` DATETIME NULL,
  `report_downloaded_at` DATETIME NULL,
  `report_downloaded_by` BIGINT UNSIGNED NULL,
  `report_format` VARCHAR(20) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_payout_requests_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_payout_requests_bank_account_id FOREIGN KEY (`bank_account_id`) REFERENCES `member_bank_accounts`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_payout_requests_approved_by FOREIGN KEY (`approved_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_payout_requests_rejected_by FOREIGN KEY (`rejected_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_payout_requests_paid_by FOREIGN KEY (`paid_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_payout_requests_report_downloaded_by FOREIGN KEY (`report_downloaded_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY `uq_payout_requests_request_code` (`request_code`),
  KEY `idx_payout_requests_member_id` (`member_id`),
  KEY `idx_payout_requests_status` (`status`),
  KEY `idx_payout_requests_source` (`source`),
  KEY `idx_payout_requests_cycle_key` (`cycle_key`),
  KEY `idx_payout_requests_requested_at` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payout_transactions`;
CREATE TABLE IF NOT EXISTS `payout_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `payout_request_id` BIGINT UNSIGNED NOT NULL,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `mode` VARCHAR(30) NOT NULL DEFAULT 'bank_transfer',
  `reference_number` VARCHAR(80) NULL,
  `proof_file` VARCHAR(255) NULL,
  `status` ENUM('pending','success','failed') NOT NULL DEFAULT 'success',
  `remarks` TEXT NULL,
  `processed_by` BIGINT UNSIGNED NULL,
  `processed_at` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_payout_transactions_payout_request_id FOREIGN KEY (`payout_request_id`) REFERENCES `payout_requests`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_payout_transactions_member_id FOREIGN KEY (`member_id`) REFERENCES `members`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_payout_transactions_processed_by FOREIGN KEY (`processed_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_payout_transactions_payout_request_id` (`payout_request_id`),
  KEY `idx_payout_transactions_member_id` (`member_id`),
  KEY `idx_payout_transactions_reference_number` (`reference_number`),
  KEY `idx_payout_transactions_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payout_settings`;
CREATE TABLE IF NOT EXISTS `payout_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `min_withdrawal_amount` DECIMAL(14,2) NOT NULL DEFAULT 500,
  `max_withdrawal_amount` DECIMAL(14,2) NOT NULL DEFAULT 100000,
  `withdrawal_fee_type` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  `withdrawal_fee_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tds_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `processing_days` INT NOT NULL DEFAULT 3,
  `require_kyc` TINYINT(1) NULL DEFAULT 1,
  `require_verified_bank` TINYINT(1) NULL DEFAULT 1,
  `auto_approve` TINYINT(1) NULL DEFAULT 0,
  `auto_payout_enabled` TINYINT(1) NULL DEFAULT 1,
  `payouts_enabled` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `mlm_settings`;
CREATE TABLE IF NOT EXISTS `mlm_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `company_sponsor_code` VARCHAR(20) NOT NULL DEFAULT 'SAEE00003',
  `plan_enabled` TINYINT(1) NULL DEFAULT 0,
  `commission_hold_days` INT NOT NULL DEFAULT 0,
  `direct_levels` INT NOT NULL DEFAULT 1,
  `grand_direct_levels` INT NOT NULL DEFAULT 1,
  `commission_on_paid_only` TINYINT(1) NULL DEFAULT 1,
  `commission_on_cod` TINYINT(1) NULL DEFAULT 0,
  `auto_credit_status` ENUM('pending','available') NOT NULL DEFAULT 'available',
  `royalty_auto_process` TINYINT(1) NULL DEFAULT 1,
  `royalty_requires_approval` TINYINT(1) NULL DEFAULT 1,
  `member_id_prefix` VARCHAR(10) NOT NULL DEFAULT 'SLH',
  `member_id_padding` INT NOT NULL DEFAULT 6,
  `registration_enabled` TINYINT(1) NULL DEFAULT 1,
  `max_direct_referrals` INT NOT NULL DEFAULT 0,
  `compliance_notice` TEXT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_roles`;
CREATE TABLE IF NOT EXISTS `admin_roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(80) NOT NULL,
  `slug` VARCHAR(80) NOT NULL,
  `description` TEXT NULL,
  `is_super` TINYINT(1) NULL DEFAULT 0,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uq_admin_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_permissions`;
CREATE TABLE IF NOT EXISTS `admin_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `group_name` VARCHAR(60) NOT NULL DEFAULT 'general',
  `description` TEXT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uq_admin_permissions_slug` (`slug`),
  KEY `idx_admin_permissions_group_name` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_role_permissions`;
CREATE TABLE IF NOT EXISTS `admin_role_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_admin_role_permissions_role_id FOREIGN KEY (`role_id`) REFERENCES `admin_roles`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_admin_role_permissions_permission_id FOREIGN KEY (`permission_id`) REFERENCES `admin_permissions`(id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_admin_role_permissions_role_id` (`role_id`),
  KEY `idx_admin_role_permissions_permission_id` (`permission_id`),
  UNIQUE KEY `uq_admin_role_permissions_role_id_permission_id` (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `username` VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role_id` BIGINT UNSIGNED NULL,
  `mobile` VARCHAR(15) NULL,
  `avatar` VARCHAR(255) NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `must_change_password` TINYINT(1) NULL DEFAULT 0,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `reset_token` VARCHAR(100) NULL,
  `reset_expires_at` DATETIME NULL,
  `remember_token` VARCHAR(100) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_admin_users_role_id FOREIGN KEY (`role_id`) REFERENCES `admin_roles`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY `uq_admin_users_email` (`email`),
  UNIQUE KEY `uq_admin_users_username` (`username`),
  KEY `idx_admin_users_role_id` (`role_id`),
  KEY `idx_admin_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_activity_logs`;
CREATE TABLE IF NOT EXISTS `admin_activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `admin_id` BIGINT UNSIGNED NULL,
  `admin_name` VARCHAR(120) NULL,
  `action` VARCHAR(80) NOT NULL,
  `module` VARCHAR(60) NOT NULL DEFAULT 'general',
  `entity_type` VARCHAR(60) NULL,
  `entity_id` BIGINT NULL,
  `description` TEXT NULL,
  `changes` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_admin_activity_logs_admin_id FOREIGN KEY (`admin_id`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_admin_activity_logs_admin_id` (`admin_id`),
  KEY `idx_admin_activity_logs_action` (`action`),
  KEY `idx_admin_activity_logs_module` (`module`),
  KEY `idx_admin_activity_logs_entity_type_entity_id` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `recipient_type` ENUM('member','customer','admin') NOT NULL DEFAULT 'member',
  `recipient_id` BIGINT NOT NULL,
  `template_code` VARCHAR(80) NULL,
  `title` VARCHAR(191) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(30) NOT NULL DEFAULT 'info',
  `link` VARCHAR(255) NULL,
  `icon` VARCHAR(60) NULL,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'in_system',
  `is_read` TINYINT(1) NULL DEFAULT 0,
  `read_at` DATETIME NULL,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_notifications_recipient_type` (`recipient_type`),
  KEY `idx_notifications_recipient_id` (`recipient_id`),
  KEY `idx_notifications_is_read` (`is_read`),
  KEY `idx_notifications_recipient_type_recipient_id_is_read` (`recipient_type`, `recipient_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notification_templates`;
CREATE TABLE IF NOT EXISTS `notification_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(80) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'in_system',
  `subject` VARCHAR(191) NULL,
  `body` TEXT NOT NULL,
  `variables` VARCHAR(255) NULL,
  `status` TINYINT(1) NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uq_notification_templates_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` TEXT NULL,
  `description` VARCHAR(255) NULL,
  `is_locked` TINYINT(1) NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uq_system_settings_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `database_backups`;
CREATE TABLE IF NOT EXISTS `database_backups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(191) NOT NULL,
  `file_size` BIGINT NOT NULL DEFAULT 0,
  `checksum` VARCHAR(64) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'completed',
  `notes` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_database_backups_created_by FOREIGN KEY (`created_by`) REFERENCES `admin_users`(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `page_views`;
CREATE TABLE IF NOT EXISTS `page_views` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `page_url` VARCHAR(255) NOT NULL,
  `visitor_hash` VARCHAR(64) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `referrer` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  KEY `idx_page_views_page_url` (`page_url`),
  KEY `idx_page_views_visitor_hash` (`visitor_hash`),
  KEY `idx_page_views_page_url_created_at` (`page_url`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
