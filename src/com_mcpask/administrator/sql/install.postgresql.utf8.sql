CREATE TABLE IF NOT EXISTS "#__mcpask_documents" (
  "id" serial NOT NULL,
  "source" varchar(32) NOT NULL,
  "item_id" integer NOT NULL,
  "title" varchar(255) NOT NULL DEFAULT '',
  "link" varchar(1024) NOT NULL DEFAULT '',
  "language" varchar(7) NOT NULL DEFAULT '*',
  "access" integer NOT NULL DEFAULT 1,
  "hash" varchar(40) NOT NULL DEFAULT '',
  "status" varchar(16) NOT NULL DEFAULT 'ok',
  "message" varchar(255) NOT NULL DEFAULT '',
  "chunks" integer NOT NULL DEFAULT 0,
  "checked" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__mcpask_documents_idx_item" UNIQUE ("source", "item_id")
);
CREATE INDEX IF NOT EXISTS "#__mcpask_documents_idx_status" ON "#__mcpask_documents" ("status");
CREATE INDEX IF NOT EXISTS "#__mcpask_documents_idx_checked" ON "#__mcpask_documents" ("checked");

CREATE TABLE IF NOT EXISTS "#__mcpask_chunks" (
  "id" serial NOT NULL,
  "document_id" integer NOT NULL,
  "position" smallint NOT NULL DEFAULT 0,
  "heading" varchar(255) NOT NULL DEFAULT '',
  "text" text NOT NULL,
  "embedding" text NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__mcpask_chunks_idx_document" ON "#__mcpask_chunks" ("document_id");

CREATE TABLE IF NOT EXISTS "#__mcpask_log" (
  "id" serial NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "ip_hash" varchar(64) NOT NULL DEFAULT '',
  "user_id" integer NOT NULL DEFAULT 0,
  "ip" varchar(45) NOT NULL DEFAULT '',
  "question" text,
  "status" varchar(16) NOT NULL DEFAULT '',
  "message" varchar(255) NOT NULL DEFAULT '',
  "sources" smallint NOT NULL DEFAULT 0,
  "tokens_in" integer NOT NULL DEFAULT 0,
  "tokens_out" integer NOT NULL DEFAULT 0,
  "duration_ms" integer NOT NULL DEFAULT 0,
  "rating" smallint NOT NULL DEFAULT 0,
  "feedback" varchar(255) NOT NULL DEFAULT '',
  "hint_id" integer NOT NULL DEFAULT 0,
  "flags" varchar(255) NOT NULL DEFAULT '',
  "answer" text,
  "answer_raw" text,
  "embed_id" integer NOT NULL DEFAULT 0,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__mcpask_log_idx_rating" ON "#__mcpask_log" ("rating");
CREATE INDEX IF NOT EXISTS "#__mcpask_log_idx_created" ON "#__mcpask_log" ("created");
CREATE INDEX IF NOT EXISTS "#__mcpask_log_idx_ip_created" ON "#__mcpask_log" ("ip_hash", "created");
CREATE INDEX IF NOT EXISTS "#__mcpask_log_idx_embed_created" ON "#__mcpask_log" ("embed_id", "created");

CREATE TABLE IF NOT EXISTS "#__mcpask_state" (
  "name" varchar(64) NOT NULL,
  "value" text NOT NULL,
  PRIMARY KEY ("name")
);

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

CREATE TABLE IF NOT EXISTS "#__mcpask_embeds" (
  "id" serial NOT NULL,
  "title" varchar(255) DEFAULT '' NOT NULL,
  "public_key" varchar(40) NOT NULL,
  "secret" varchar(64) DEFAULT '' NOT NULL,
  "domains" text NOT NULL,
  "published" smallint DEFAULT 1 NOT NULL,
  "params" text NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "modified" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__mcpask_embeds_idx_public_key" UNIQUE ("public_key")
);

CREATE TABLE IF NOT EXISTS "#__mcpask_pets" (
  "id" serial NOT NULL,
  "title" varchar(255) DEFAULT '' NOT NULL,
  "params" text NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "modified" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
