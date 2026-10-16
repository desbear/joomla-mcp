-- 0.2.0: OAuth 2.1
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
