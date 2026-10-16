-- 0.6.5: what was answered (shown and as written), and marks for attempts to steer the answer
ALTER TABLE `#__mcpask_log`
  ADD COLUMN `flags` varchar(255) NOT NULL DEFAULT '' AFTER `hint_id`,
  ADD COLUMN `answer` mediumtext AFTER `flags`,
  ADD COLUMN `answer_raw` mediumtext AFTER `answer`;
