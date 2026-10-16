-- 0.6.0: how visitors rated answers, and sources the administrator points questions to
ALTER TABLE "#__mcpask_log" ADD COLUMN "rating" smallint NOT NULL DEFAULT 0;
ALTER TABLE "#__mcpask_log" ADD COLUMN "feedback" varchar(255) NOT NULL DEFAULT '';
ALTER TABLE "#__mcpask_log" ADD COLUMN "hint_id" integer NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS "#__mcpask_log_idx_rating" ON "#__mcpask_log" ("rating");

CREATE TABLE IF NOT EXISTS "#__mcpask_hints" (
  "id" serial NOT NULL,
  "question" varchar(500) NOT NULL DEFAULT '',
  "variants" text NOT NULL,
  "embedding" text NOT NULL,
  "signature" varchar(255) NOT NULL DEFAULT '',
  "documents" text NOT NULL,
  "note" text NOT NULL,
  "language" varchar(7) NOT NULL DEFAULT '*',
  "state" smallint NOT NULL DEFAULT 1,
  "hits" integer NOT NULL DEFAULT 0,
  "last_hit" timestamp without time zone NULL DEFAULT NULL,
  "created" timestamp without time zone NOT NULL,
  "created_by" integer NOT NULL DEFAULT 0,
  "modified" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__mcpask_hints_idx_state" ON "#__mcpask_hints" ("state");
