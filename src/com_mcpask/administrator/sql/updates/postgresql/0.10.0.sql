CREATE TABLE IF NOT EXISTS "#__mcpask_handoffs" (
  "id" serial NOT NULL,
  "token" char(64) NOT NULL,
  "agent_id" integer DEFAULT 0 NOT NULL,
  "visitor" char(64) DEFAULT '' NOT NULL,
  "source" varchar(16) DEFAULT 'list' NOT NULL,
  "hours" smallint DEFAULT 0 NOT NULL,
  "availability" varchar(16) DEFAULT '' NOT NULL,
  "outcome" varchar(16) DEFAULT 'requested' NOT NULL,
  "question" varchar(300) DEFAULT '' NOT NULL,
  "page" varchar(500) DEFAULT '' NOT NULL,
  "report" smallint DEFAULT 0 NOT NULL,
  "language" char(7) DEFAULT '*' NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "modified" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__mcpask_handoffs_idx_token" UNIQUE ("token")
);
CREATE INDEX IF NOT EXISTS "#__mcpask_handoffs_idx_agent" ON "#__mcpask_handoffs" ("agent_id");
CREATE INDEX IF NOT EXISTS "#__mcpask_handoffs_idx_created" ON "#__mcpask_handoffs" ("created");
