-- 0.6.0: how visitors rated answers, and sources the administrator points questions to
ALTER TABLE `#__mcpask_log`
  ADD COLUMN `rating` tinyint NOT NULL DEFAULT 0 AFTER `duration_ms`,
  ADD COLUMN `feedback` varchar(255) NOT NULL DEFAULT '' AFTER `rating`,
  ADD COLUMN `hint_id` int unsigned NOT NULL DEFAULT 0 AFTER `feedback`,
  ADD KEY `idx_rating` (`rating`);

CREATE TABLE IF NOT EXISTS `#__mcpask_hints` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `question` varchar(500) NOT NULL DEFAULT '',
  `variants` text NOT NULL,
  `embedding` mediumtext NOT NULL,
  `signature` varchar(255) NOT NULL DEFAULT '',
  `documents` text NOT NULL,
  `note` text NOT NULL,
  `language` char(7) NOT NULL DEFAULT '*',
  `state` tinyint NOT NULL DEFAULT 1,
  `hits` int unsigned NOT NULL DEFAULT 0,
  `last_hit` datetime NULL DEFAULT NULL,
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
