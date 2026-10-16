CREATE TABLE IF NOT EXISTS `#__mcpask_documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(32) NOT NULL,
  `item_id` int unsigned NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `link` varchar(1024) NOT NULL DEFAULT '',
  `language` char(7) NOT NULL DEFAULT '*',
  `access` int unsigned NOT NULL DEFAULT 1,
  `hash` char(40) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'ok',
  `message` varchar(255) NOT NULL DEFAULT '',
  `chunks` int unsigned NOT NULL DEFAULT 0,
  `checked` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_item` (`source`, `item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_checked` (`checked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpask_chunks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int unsigned NOT NULL,
  `position` smallint unsigned NOT NULL DEFAULT 0,
  `heading` varchar(255) NOT NULL DEFAULT '',
  `text` mediumtext NOT NULL,
  `embedding` mediumtext NOT NULL,
  `b0` bigint unsigned NOT NULL DEFAULT 0,
  `b1` bigint unsigned NOT NULL DEFAULT 0,
  `b2` bigint unsigned NOT NULL DEFAULT 0,
  `b3` bigint unsigned NOT NULL DEFAULT 0,
  `b4` bigint unsigned NOT NULL DEFAULT 0,
  `b5` bigint unsigned NOT NULL DEFAULT 0,
  `b6` bigint unsigned NOT NULL DEFAULT 0,
  `b7` bigint unsigned NOT NULL DEFAULT 0,
  `coded` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_coded` (`coded`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpask_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime NOT NULL,
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `question` text,
  `status` varchar(16) NOT NULL DEFAULT '',
  `message` varchar(255) NOT NULL DEFAULT '',
  `sources` tinyint unsigned NOT NULL DEFAULT 0,
  `tokens_in` int unsigned NOT NULL DEFAULT 0,
  `tokens_out` int unsigned NOT NULL DEFAULT 0,
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `rating` tinyint NOT NULL DEFAULT 0,
  `feedback` varchar(255) NOT NULL DEFAULT '',
  `hint_id` int unsigned NOT NULL DEFAULT 0,
  `flags` varchar(255) NOT NULL DEFAULT '',
  `answer` mediumtext,
  `answer_raw` mediumtext,
  `embed_id` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created`),
  KEY `idx_rating` (`rating`),
  KEY `idx_ip_created` (`ip_hash`, `created`),
  KEY `idx_embed_created` (`embed_id`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpask_state` (
  `name` varchar(64) NOT NULL,
  `value` mediumtext NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `#__mcpask_handoffs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token` char(64) NOT NULL,
  `agent_id` int unsigned NOT NULL DEFAULT 0,
  `visitor` char(64) NOT NULL DEFAULT '',
  `source` varchar(16) NOT NULL DEFAULT 'list',
  `hours` tinyint NOT NULL DEFAULT 0,
  `availability` varchar(16) NOT NULL DEFAULT '',
  `outcome` varchar(16) NOT NULL DEFAULT 'requested',
  `question` varchar(300) NOT NULL DEFAULT '',
  `page` varchar(500) NOT NULL DEFAULT '',
  `report` tinyint NOT NULL DEFAULT 0,
  `language` char(7) NOT NULL DEFAULT '*',
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token` (`token`),
  KEY `idx_agent` (`agent_id`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpask_embeds` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `public_key` varchar(40) NOT NULL,
  `secret` varchar(64) NOT NULL DEFAULT '',
  `domains` text NOT NULL,
  `published` tinyint NOT NULL DEFAULT 1,
  `params` mediumtext NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_public_key` (`public_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpask_pets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `params` mediumtext NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
