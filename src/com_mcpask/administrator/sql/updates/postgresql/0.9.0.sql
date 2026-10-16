CREATE TABLE IF NOT EXISTS "#__mcpask_reports" (
  "id" serial NOT NULL,
  "token" char(64) NOT NULL,
  "agent_id" integer DEFAULT 0 NOT NULL,
  "title" varchar(255) DEFAULT '' NOT NULL,
  "status" varchar(16) DEFAULT 'pending' NOT NULL,
  "language" char(7) DEFAULT '*' NOT NULL,
  "state" text NOT NULL,
  "content" text NOT NULL,
  "emailed" smallint DEFAULT 0 NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "modified" timestamp without time zone NOT NULL,
  "expires" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__mcpask_reports_idx_token" UNIQUE ("token")
);
CREATE INDEX IF NOT EXISTS "#__mcpask_reports_idx_agent" ON "#__mcpask_reports" ("agent_id");
CREATE INDEX IF NOT EXISTS "#__mcpask_reports_idx_expires" ON "#__mcpask_reports" ("expires");
