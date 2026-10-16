-- 0.2.0: content of other sites, and a first pass over bit codes when searching
ALTER TABLE `#__mcpask_chunks`
  ADD COLUMN `b0` bigint unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `b1` bigint unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `b2` bigint unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `b3` bigint unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `b4` bigint unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `b5` bigint unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `b6` bigint unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `b7` bigint unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `coded` tinyint NOT NULL DEFAULT 0,
  ADD KEY `idx_coded` (`coded`);

CREATE TABLE IF NOT EXISTS `#__mcpask_state` (
  `name` varchar(64) NOT NULL,
  `value` mediumtext NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
