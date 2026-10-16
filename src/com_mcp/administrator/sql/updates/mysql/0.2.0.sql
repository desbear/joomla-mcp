-- 0.2.0: OAuth 2.1
CREATE TABLE IF NOT EXISTS `#__mcp_oauth_clients` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(255) NOT NULL,
  `client_secret_hash` varchar(64) NOT NULL DEFAULT '',
  `client_name` varchar(255) NOT NULL DEFAULT '',
  `redirect_uris` text NOT NULL,
  `metadata` text NOT NULL,
  `is_metadata_document` tinyint NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  `last_used` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_client_id` (`client_id`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcp_oauth_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code_hash` char(64) NOT NULL,
  `client_id` varchar(255) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `redirect_uri` text NOT NULL,
  `code_challenge` varchar(128) NOT NULL,
  `scopes` varchar(255) NOT NULL DEFAULT '',
  `resource` varchar(512) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `expires` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_code_hash` (`code_hash`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcp_oauth_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `type` varchar(16) NOT NULL,
  `family` char(32) NOT NULL,
  `client_id` varchar(255) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `scopes` varchar(255) NOT NULL DEFAULT '',
  `resource` varchar(512) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `expires` datetime NOT NULL,
  `last_used` datetime NULL DEFAULT NULL,
  `revoked` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_family` (`family`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
