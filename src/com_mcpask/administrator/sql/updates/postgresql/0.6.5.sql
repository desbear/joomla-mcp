-- 0.6.5: what was answered (shown and as written), and marks for attempts to steer the answer
ALTER TABLE "#__mcpask_log" ADD COLUMN "flags" varchar(255) NOT NULL DEFAULT '';
ALTER TABLE "#__mcpask_log" ADD COLUMN "answer" text;
ALTER TABLE "#__mcpask_log" ADD COLUMN "answer_raw" text;
