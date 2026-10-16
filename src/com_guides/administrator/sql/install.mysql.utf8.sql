CREATE TABLE IF NOT EXISTS `#__guides_guides` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `summary` text NOT NULL,
  `prerequisites` text NOT NULL,
  `verify` text NOT NULL,
  `language` char(7) NOT NULL DEFAULT '*',
  `product` varchar(100) NOT NULL DEFAULT '',
  `state` varchar(16) NOT NULL DEFAULT 'draft',
  `target_type` varchar(20) NOT NULL DEFAULT 'content',
  `target_id` int unsigned NOT NULL DEFAULT 0,
  `fc_type` int unsigned NOT NULL DEFAULT 0,
  `catid` int unsigned NOT NULL DEFAULT 0,
  `tags` text NOT NULL,
  `next_guide_id` int unsigned NOT NULL DEFAULT 0,
  `gaps` text NOT NULL,
  `review_note` text NOT NULL,
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int unsigned NOT NULL DEFAULT 0,
  `submitted` datetime NULL DEFAULT NULL,
  `submitted_by` int unsigned NOT NULL DEFAULT 0,
  `reviewed` datetime NULL DEFAULT NULL,
  `reviewed_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__guides_steps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `body` mediumtext NOT NULL,
  `image` varchar(1024) NOT NULL DEFAULT '',
  `note` text NOT NULL,
  `language` char(7) NOT NULL DEFAULT '*',
  `product` varchar(100) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__guides_guide_steps` (
  `guide_id` int unsigned NOT NULL,
  `step_id` int unsigned NOT NULL,
  `ordering` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`guide_id`, `step_id`),
  KEY `idx_step` (`step_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__guides_sources` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `step_id` int unsigned NOT NULL DEFAULT 0,
  `guide_id` int unsigned NOT NULL DEFAULT 0,
  `source_type` varchar(20) NOT NULL DEFAULT 'content',
  `item_id` int unsigned NOT NULL,
  `section` varchar(255) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `hash` char(40) NOT NULL DEFAULT '',
  `checked` datetime NULL DEFAULT NULL,
  `changed` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_step` (`step_id`),
  KEY `idx_guide` (`guide_id`),
  KEY `idx_item` (`source_type`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
