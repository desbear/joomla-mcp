CREATE TABLE IF NOT EXISTS "#__mcp_logs" (
  "id" serial NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "user_id" integer DEFAULT 0 NOT NULL,
  "tool" varchar(100) DEFAULT '' NOT NULL,
  "arguments" text,
  "status" varchar(16) DEFAULT '' NOT NULL,
  "message" text,
  "duration_ms" integer DEFAULT 0 NOT NULL,
  "ip" varchar(64) DEFAULT '' NOT NULL,
  "user_agent" varchar(255) DEFAULT '' NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__mcp_logs_idx_created" ON "#__mcp_logs" ("created");
CREATE INDEX IF NOT EXISTS "#__mcp_logs_idx_user" ON "#__mcp_logs" ("user_id");
CREATE INDEX IF NOT EXISTS "#__mcp_logs_idx_tool" ON "#__mcp_logs" ("tool");
CREATE INDEX IF NOT EXISTS "#__mcp_logs_idx_tool_created" ON "#__mcp_logs" ("tool", "created");
CREATE INDEX IF NOT EXISTS "#__mcp_logs_idx_ip_created" ON "#__mcp_logs" ("ip", "created");

CREATE TABLE IF NOT EXISTS "#__mcp_oauth_clients" (
  "id" serial NOT NULL,
  "client_id" varchar(255) NOT NULL,
  "client_secret_hash" varchar(64) DEFAULT '' NOT NULL,
  "client_name" varchar(255) DEFAULT '' NOT NULL,
  "redirect_uris" text NOT NULL,
  "metadata" text NOT NULL,
  "is_metadata_document" smallint DEFAULT 0 NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "last_used" timestamp without time zone,
  PRIMARY KEY ("id")
);
CREATE UNIQUE INDEX IF NOT EXISTS "#__mcp_oauth_clients_idx_client_id" ON "#__mcp_oauth_clients" ("client_id");

CREATE TABLE IF NOT EXISTS "#__mcp_oauth_codes" (
  "id" serial NOT NULL,
  "code_hash" char(64) NOT NULL,
  "client_id" varchar(255) NOT NULL,
  "user_id" integer NOT NULL,
  "redirect_uri" text NOT NULL,
  "code_challenge" varchar(128) NOT NULL,
  "scopes" varchar(255) DEFAULT '' NOT NULL,
  "resource" varchar(512) DEFAULT '' NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "expires" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
CREATE UNIQUE INDEX IF NOT EXISTS "#__mcp_oauth_codes_idx_code_hash" ON "#__mcp_oauth_codes" ("code_hash");

CREATE TABLE IF NOT EXISTS "#__mcp_oauth_tokens" (
  "id" serial NOT NULL,
  "token_hash" char(64) NOT NULL,
  "type" varchar(16) NOT NULL,
  "family" char(32) NOT NULL,
  "client_id" varchar(255) NOT NULL,
  "user_id" integer NOT NULL,
  "scopes" varchar(255) DEFAULT '' NOT NULL,
  "resource" varchar(512) DEFAULT '' NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "expires" timestamp without time zone NOT NULL,
  "last_used" timestamp without time zone,
  "revoked" smallint DEFAULT 0 NOT NULL,
  PRIMARY KEY ("id")
);
CREATE UNIQUE INDEX IF NOT EXISTS "#__mcp_oauth_tokens_idx_token_hash" ON "#__mcp_oauth_tokens" ("token_hash");
CREATE INDEX IF NOT EXISTS "#__mcp_oauth_tokens_idx_family" ON "#__mcp_oauth_tokens" ("family");
CREATE INDEX IF NOT EXISTS "#__mcp_oauth_tokens_idx_user" ON "#__mcp_oauth_tokens" ("user_id");
