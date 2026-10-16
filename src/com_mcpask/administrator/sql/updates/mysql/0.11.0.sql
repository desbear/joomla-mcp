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

ALTER TABLE `#__mcpask_log` ADD COLUMN `embed_id` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `#__mcpask_log` ADD INDEX `idx_embed_created` (`embed_id`, `created`);
