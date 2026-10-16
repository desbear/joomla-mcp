CREATE TABLE IF NOT EXISTS "#__mcpask_agents" (
  "id" serial NOT NULL,
  "type" varchar(50) DEFAULT '' NOT NULL,
  "title" varchar(255) DEFAULT '' NOT NULL,
  "published" smallint DEFAULT 0 NOT NULL,
  "ordering" integer DEFAULT 0 NOT NULL,
  "params" text NOT NULL,
  "phrases" text NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "modified" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__mcpask_agents_idx_published" ON "#__mcpask_agents" ("published");

CREATE TABLE IF NOT EXISTS "#__mcpask_agent_sessions" (
  "id" char(64) NOT NULL,
  "agent_id" integer DEFAULT 0 NOT NULL,
  "visitor" char(64) DEFAULT '' NOT NULL,
  "status" varchar(16) DEFAULT 'active' NOT NULL,
  "steps" smallint DEFAULT 0 NOT NULL,
  "state" text NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "modified" timestamp without time zone NOT NULL,
  "expires" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__mcpask_agent_sessions_idx_agent" ON "#__mcpask_agent_sessions" ("agent_id");
CREATE INDEX IF NOT EXISTS "#__mcpask_agent_sessions_idx_visitor" ON "#__mcpask_agent_sessions" ("visitor", "created");
CREATE INDEX IF NOT EXISTS "#__mcpask_agent_sessions_idx_expires" ON "#__mcpask_agent_sessions" ("expires");

CREATE TABLE IF NOT EXISTS "#__mcpask_agent_events" (
  "id" serial NOT NULL,
  "agent_id" integer DEFAULT 0 NOT NULL,
  "event" varchar(16) DEFAULT '' NOT NULL,
  "created" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__mcpask_agent_events_idx_agent_event" ON "#__mcpask_agent_events" ("agent_id", "event", "created");
