-- 0.2.1: who asked
ALTER TABLE `#__mcpask_log`
  ADD COLUMN `user_id` int unsigned NOT NULL DEFAULT 0 AFTER `ip_hash`,
  ADD COLUMN `ip` varchar(45) NOT NULL DEFAULT '' AFTER `user_id`;
