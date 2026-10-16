CREATE TABLE IF NOT EXISTS `#__mcpask_reports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token` char(64) NOT NULL,
  `agent_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `language` char(7) NOT NULL DEFAULT '*',
  `state` mediumtext NOT NULL,
  `content` mediumtext NOT NULL,
  `emailed` tinyint NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  `expires` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token` (`token`),
  KEY `idx_agent` (`agent_id`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
