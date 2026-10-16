CREATE TABLE IF NOT EXISTS "#__mcpask_pets" (
  "id" serial NOT NULL,
  "title" varchar(255) DEFAULT '' NOT NULL,
  "params" text NOT NULL,
  "created" timestamp without time zone NOT NULL,
  "modified" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
