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

ALTER TABLE "#__mcpask_log" ADD COLUMN "embed_id" integer DEFAULT 0 NOT NULL;
CREATE INDEX "#__mcpask_log_idx_embed_created" ON "#__mcpask_log" ("embed_id", "created");
