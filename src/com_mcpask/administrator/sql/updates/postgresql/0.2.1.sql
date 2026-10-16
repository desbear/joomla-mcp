-- 0.2.1: who asked
ALTER TABLE "#__mcpask_log" ADD COLUMN "user_id" integer NOT NULL DEFAULT 0;
ALTER TABLE "#__mcpask_log" ADD COLUMN "ip" varchar(45) NOT NULL DEFAULT '';
