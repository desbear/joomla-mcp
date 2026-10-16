CREATE TABLE IF NOT EXISTS `#__mcpask_agents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `published` tinyint NOT NULL DEFAULT 0,
  `ordering` int NOT NULL DEFAULT 0,
  `params` mediumtext NOT NULL,
  `phrases` mediumtext NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_published` (`published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpask_agent_sessions` (
  `id` char(64) NOT NULL,
  `agent_id` int unsigned NOT NULL DEFAULT 0,
  `visitor` char(64) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `steps` smallint unsigned NOT NULL DEFAULT 0,
  `state` mediumtext NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  `expires` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_agent` (`agent_id`),
  KEY `idx_visitor` (`visitor`, `created`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpask_agent_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` int unsigned NOT NULL DEFAULT 0,
  `event` varchar(16) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_agent_event` (`agent_id`, `event`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
