-- MySQL schema for the autocomplete POC.
--
-- This file is the ONLY copy of the DDL. MysqlIndexer reads it, substitutes the
-- table name and executes the sections below, so there is nothing here that can
-- drift away from what the importer actually creates.
--
-- `{{table}}` is replaced with the configured table name (MYSQL_TABLE, default
-- `location_suggestions`). To run the file by hand:
--
--     sed 's/{{table}}/location_suggestions/g' sql/schema.sql | mysql -uautocomplete -p autocomplete
--
-- Sections are delimited by `-- @section <name>` markers and are executed at
-- different moments of the import:
--
--   drop      only with --recreate
--   create    before the first document is written
--   fulltext  after the last one (see below)
--
-- Why the FULLTEXT index is not part of CREATE TABLE: with the index in place
-- every INSERT feeds the InnoDB fulltext cache, which is flushed into the
-- auxiliary FTS tables over and over while 4.2M rows stream in. Adding the
-- index once at the end builds it in a single sorted pass instead, which is
-- roughly an order of magnitude cheaper. The table is simply not searchable
-- until the import finishes, which is exactly what MysqlSuggester::health()
-- reports on.

-- @section drop

DROP TABLE IF EXISTS `{{table}}`;

-- @section create

CREATE TABLE IF NOT EXISTS `{{table}}` (
    -- Ids are machine-made ("street:<id>|<postcode>", "municipality:<nis>"),
    -- so ASCII is enough. ascii_bin also means the primary key compares
    -- byte-exact: under the accent-insensitive server collation "Straat:1" and
    -- "straat:1" would be the same key, which is not what an id means.
    `id`                VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

    `doc_type`          ENUM('address', 'street', 'municipality', 'postcode', 'place') NOT NULL,

    -- Display strings keep their diacritics and their original casing.
    --
    -- The widths are set by the UiTdatabank place export, not by the address
    -- register: its text is hand-entered, so a `street_name` is occasionally a
    -- whole sentence of directions (248 characters at the worst) and a place
    -- label reaches 331. Under a strict sql_mode a too-narrow column is an
    -- error mid-import, and under a lax one it is a silently truncated label.
    `label`             VARCHAR(512) NOT NULL,

    -- The venue name, on `place` rows only; NULL for everything that comes out
    -- of the address register.
    `place_name`        VARCHAR(255) NULL,

    `street_name`       VARCHAR(255) NULL,
    `house_number`      VARCHAR(16)  NULL,
    `box_number`        VARCHAR(16)  NULL,
    `postcode`          CHAR(4)      NULL,
    `post_name`         VARCHAR(128) NULL,
    `municipality_name` VARCHAR(128) NOT NULL,

    -- NULL on `place` rows: the export names a municipality but never
    -- identifies it, and there is no reliable name-to-NIS mapping to invent one
    -- from (9,557 of its localities are written "Onkerzele (Geraardsbergen)").
    `nis_code`          CHAR(5)      NULL,

    `lat`               DECIMAL(9,6) NULL,
    `lon`               DECIMAL(9,6) NULL,

    -- Number of addresses behind this document. Used as a damped ranking
    -- prior, never as a hard sort key.
    `popularity`        INT UNSIGNED NOT NULL DEFAULT 0,

    -- Both search columns are written by App\Support\Normalizer, which emits
    -- [a-z0-9 ] and nothing else. Declaring them ASCII therefore loses no
    -- information while making the FULLTEXT index and the prefix index a
    -- quarter of the size they would be under utf8mb4, and a binary collation
    -- keeps LIKE 'x%' a plain byte comparison.
    `search_text`       TEXT         CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `primary_name_norm` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

    PRIMARY KEY (`id`),

    -- Range scan for `primary_name_norm LIKE 'goorb%'`: the type-ahead path
    -- that must stay fast even when the fulltext term expansion would not.
    KEY `idx_primary_name_norm` (`primary_name_norm`),

    KEY `idx_doc_type` (`doc_type`),
    KEY `idx_postcode` (`postcode`),

    -- "the most important things of this kind", used when a query degenerates
    -- into a type filter with almost no selectivity.
    KEY `idx_doc_type_popularity` (`doc_type`, `popularity` DESC)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

-- @section fulltext

ALTER TABLE `{{table}}` ADD FULLTEXT INDEX `ft_search_text` (`search_text`);
