CREATE TABLE IF NOT EXISTS "#__guides_guides" (
  "id" serial NOT NULL,
  "title" varchar(255) NOT NULL DEFAULT '',
  "summary" text NOT NULL,
  "prerequisites" text NOT NULL,
  "verify" text NOT NULL,
  "language" varchar(7) NOT NULL DEFAULT '*',
  "product" varchar(100) NOT NULL DEFAULT '',
  "state" varchar(16) NOT NULL DEFAULT 'draft',
  "target_type" varchar(20) NOT NULL DEFAULT 'content',
  "target_id" integer NOT NULL DEFAULT 0,
  "fc_type" integer NOT NULL DEFAULT 0,
  "catid" integer NOT NULL DEFAULT 0,
  "tags" text NOT NULL,
  "next_guide_id" integer NOT NULL DEFAULT 0,
  "gaps" text NOT NULL,
  "review_note" text NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "created_by" integer NOT NULL DEFAULT 0,
  "modified" timestamp without time zone NOT NULL,
  "modified_by" integer NOT NULL DEFAULT 0,
  "submitted" timestamp without time zone NULL DEFAULT NULL,
  "submitted_by" integer NOT NULL DEFAULT 0,
  "reviewed" timestamp without time zone NULL DEFAULT NULL,
  "reviewed_by" integer NOT NULL DEFAULT 0,
  PRIMARY KEY ("id")
);

CREATE TABLE IF NOT EXISTS "#__guides_steps" (
  "id" serial NOT NULL,
  "title" varchar(255) NOT NULL DEFAULT '',
  "body" text NOT NULL,
  "image" varchar(1024) NOT NULL DEFAULT '',
  "note" text NOT NULL,
  "language" varchar(7) NOT NULL DEFAULT '*',
  "product" varchar(100) NOT NULL DEFAULT '',
  "created" timestamp without time zone NOT NULL,
  "created_by" integer NOT NULL DEFAULT 0,
  "modified" timestamp without time zone NOT NULL,
  "modified_by" integer NOT NULL DEFAULT 0,
  PRIMARY KEY ("id")
);

CREATE TABLE IF NOT EXISTS "#__guides_guide_steps" (
  "guide_id" integer NOT NULL,
  "step_id" integer NOT NULL,
  "ordering" integer NOT NULL DEFAULT 0,
  PRIMARY KEY ("guide_id", "step_id")
);

CREATE TABLE IF NOT EXISTS "#__guides_sources" (
  "id" serial NOT NULL,
  "step_id" integer NOT NULL DEFAULT 0,
  "guide_id" integer NOT NULL DEFAULT 0,
  "source_type" varchar(20) NOT NULL DEFAULT 'content',
  "item_id" integer NOT NULL,
  "section" varchar(255) NOT NULL DEFAULT '',
  "title" varchar(255) NOT NULL DEFAULT '',
  "hash" varchar(40) NOT NULL DEFAULT '',
  "checked" timestamp without time zone NULL DEFAULT NULL,
  "changed" timestamp without time zone NULL DEFAULT NULL,
  PRIMARY KEY ("id")
);

CREATE INDEX IF NOT EXISTS "#__guides_guides_idx_state" ON "#__guides_guides" ("state");
CREATE INDEX IF NOT EXISTS "#__guides_guide_steps_idx_step" ON "#__guides_guide_steps" ("step_id");
CREATE INDEX IF NOT EXISTS "#__guides_sources_idx_step" ON "#__guides_sources" ("step_id");
CREATE INDEX IF NOT EXISTS "#__guides_sources_idx_guide" ON "#__guides_sources" ("guide_id");
CREATE INDEX IF NOT EXISTS "#__guides_sources_idx_item" ON "#__guides_sources" ("source_type", "item_id");
