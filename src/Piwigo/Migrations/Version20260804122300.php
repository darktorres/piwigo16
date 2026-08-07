<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;
use Piwigo\Db\DbCredentials;

/**
 * Baseline bootstrap, content domain: categories, images, tags, comments,
 * image_category, image_tag, image_format, caddie, favorites, lounge,
 * rate, old_permalinks -- 12 of the 39 tables `install/
 * piwigo_structure-mysql.sql` defines, split by domain purely for
 * authoring/review size. This is the first migration -- nothing runs
 * before it -- see this migration's own sibling files for the users/auth
 * and admin/system domains, plus a final migration adding every FK
 * constraint once all 39 tables exist.
 *
 * MySQL/MariaDB: the exact `CREATE TABLE` text from `install/
 * piwigo_structure-mysql.sql`, copied verbatim rather than re-derived
 * through Doctrine's Schema API -- this guarantees byte-identical DDL to
 * the already-shipped, already-tested file, with zero risk of a
 * re-derivation drift (a different code path silently producing a
 * subtly different column width/default/comment across 39 tables is a
 * real risk worth avoiding, not a hypothetical one).
 *
 * PostgreSQL: hand-translated, verified live against a real PostgreSQL
 * 18.4 server (tsvector/GIN generated columns, `DROP DATABASE ... WITH
 * (FORCE)`-adjacent Postgres-specific behavior, `CHECK` constraint
 * introspection format, etc.). Translation rules applied uniformly across
 * every table in this migration set:
 * - MySQL `unsigned` has no Postgres equivalent -- widened to the next
 *   signed type covering the full declared range: `tinyint unsigned`
 *   (max 255) -> `smallint`; `smallint unsigned` (max 65535, exceeds
 *   Postgres `smallint`'s own 32767 ceiling) -> `integer`; `mediumint
 *   unsigned` (max 16777215) -> `integer`; `int unsigned` (max ~4.29B,
 *   exceeds Postgres `integer`'s ~2.15B ceiling) -> `bigint`. A signed
 *   MySQL column (no `unsigned` keyword) keeps the directly-corresponding
 *   Postgres width since both ranges already match.
 * - `tinyint(1)` columns that are real boolean flags (not a genuinely
 *   numeric small value like `level`/`rotation`/`rate`) -> Postgres
 *   `boolean` -- matches Doctrine DBAL's own real `Types::BOOLEAN`
 *   per-platform translation (confirmed live via a `SchemaTool` probe
 *   this same session: `TINYINT` on MySQL, `BOOLEAN` on Postgres for the
 *   identical mapped field), not an arbitrary choice.
 * - `... CHARACTER SET utf8mb4 COLLATE utf8mb4_bin` (MySQL's
 *   case-sensitive/binary-comparison collation) -> Postgres
 *   `COLLATE "C"` (byte-order, case-sensitive comparison), the closest
 *   real equivalent.
 * - `JSON` -> `JSONB` (idiomatic, indexable Postgres; DBAL's own
 *   `Types::JSON` round-trips through json_encode()/json_decode() either
 *   way, so this is transparent to every real consumer).
 * - `TIMESTAMP ... DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 *   -> `timestamp DEFAULT now()` plus a shared `piwigo_set_lastmodified()`
 *   trigger function (created once, in this migration) + one `BEFORE
 *   UPDATE` trigger per affected table -- the real gap the reverted prior
 *   attempt left unbuilt ("no application code targets a non-MySQL/
 *   MariaDB connection today" was its own stated reason; no longer true
 *   once this plan lands).
 * - `FULLTEXT KEY ... WITH PARSER ngram` -> a generated `tsvector` column
 *   (`'simple'` text search config -- whitespace/punctuation tokenizer,
 *   no stemming/stopwords, the closest behavioral match to ngram's
 *   language-agnostic, non-stemmed matching) + a `GIN` index. Verified
 *   live this session: insert -> generated column computes correctly ->
 *   both a plain `to_tsquery()` match and a `term:*` prefix-match query
 *   work against it.
 * - MySQL tolerates the same bare index name (`lastmodified`) reused
 *   across multiple tables (its own index namespace is per-table);
 *   Postgres requires index names unique per-schema, so every Postgres
 *   index name below is table-prefixed even where the MySQL source
 *   wasn't.
 */
final class Version20260804122300 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Baseline bootstrap: content domain (categories, images, tags, comments, and 8 more)';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->upPostgres();

            return;
        }

        $this->upMysql();
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Baseline bootstrap has nothing to revert to but a blank database.'
        );
    }

    private function upMysql(): void
    {
        // Must run before any of this migration's FULLTEXT ... WITH PARSER
        // ngram tables below (categories/images/tags) -- MySQL bakes a
        // FULLTEXT index's effective stopword-filtering behavior in at
        // CREATE TABLE time, for the lifetime of that index (a later
        // per-connection SET SESSION at INSERT time has zero effect on an
        // already-existing index). Without this, any 2-character fragment
        // of a word matching MySQL's default stopword list (at/in/on/etc,
        // ngram_token_size is 2) makes that whole word unsearchable --
        // e.g. "cat" (contains "at") becomes entirely unmatched, hitting
        // any word containing at/in/on/etc anywhere in it (cat, chat,
        // combat, station, water, later...).
        $this->addSql('SET SESSION innodb_ft_enable_stopword = 0');

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_caddie` (
              `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'owning user id',
              `element_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'image id added to the caddie',
              PRIMARY KEY  (`user_id`,`element_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user temporary photo selection (caddie/basket) used by batch operations'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_categories` (
              `id` smallint(5) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
              `name` varchar(255) NOT NULL default '' COMMENT 'album display name',
              `id_uppercat` smallint(5) unsigned default NULL COMMENT 'parent album id, null for a root album',
              `comment` text COMMENT 'album description shown on its page',
              `dir` varchar(255) default NULL COMMENT 'filesystem subdirectory name for a physical, synchronized album, null for a virtual album',
              `rank` smallint(5) unsigned default NULL COMMENT 'sibling display order within the same parent, distinct from global_rank',
              `status` enum('public','private') NOT NULL default 'public' COMMENT 'private albums require an explicit user_access or group_access grant to view',
              `site_id` tinyint(4) unsigned default NULL COMMENT 'owning site id, resolves to sites.galleries_url for a physical album',
              `visible` tinyint(1) NOT NULL default 1 COMMENT 'whether the album is shown in navigation, forced false at creation if its parent is not visible',
              `representative_picture_id` mediumint(8) unsigned default NULL COMMENT 'image id used as the album thumbnail',
              `uppercats` varchar(255) NOT NULL default '' COMMENT 'comma-separated ancestor album id path, from root to this album',
              `commentable` tinyint(1) NOT NULL default 1 COMMENT 'whether photo comments are allowed for images in this album',
              `global_rank` varchar(255) default NULL COMMENT 'full-tree sort key derived from rank along the ancestor path, used to order albums across different parents',
              `image_order` varchar(128) default NULL COMMENT 'preferred ORDER BY expression for images in this album, inheritable to descendant albums',
              `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin default NULL COMMENT 'unique URL-friendly slug for this album',
              `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp',
              PRIMARY KEY  (`id`),
              UNIQUE KEY `categories_i3` (`permalink`),
              KEY `categories_i2` (`id_uppercat`),
              KEY `lastmodified` (`lastmodified`),
              FULLTEXT KEY `categories_ft_name_comment` (`name`,`comment`) WITH PARSER ngram
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='photo albums, both physical filesystem-synced and virtual'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_comments` (
              `id` int(11) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
              `image_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'commented image id',
              `date` datetime default NULL COMMENT 'when the comment was submitted',
              `author` varchar(255) default NULL COMMENT 'display name shown with the comment, the account username for a logged-in user or the guest-entered name otherwise',
              `email` varchar(255) default NULL COMMENT 'guest-provided email address',
              `author_id` mediumint(8) unsigned DEFAULT NULL COMMENT 'commenting user id, null for a guest comment',
              `anonymous_id` varchar(45) NOT NULL COMMENT 'full IP address of a guest commenter, used for anti-flood throttling',
              `website_url` varchar(255) DEFAULT NULL COMMENT 'guest-provided homepage link',
              `content` longtext COMMENT 'comment body',
              `validated` tinyint(1) NOT NULL default '0' COMMENT 'moderation approval flag, gates visibility when comments_validation is enabled',
              `validation_date` datetime default NULL COMMENT 'when the comment was approved',
              PRIMARY KEY  (`id`),
              KEY `comments_i2` (`validation_date`),
              KEY `comments_i1` (`image_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='visitor comments left on photos'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_favorites` (
              `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'owning user id',
              `image_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'image the user marked as a favorite',
              PRIMARY KEY  (`user_id`,`image_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user favorited images'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_image_category` (
              `image_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'member image id',
              `category_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'album the image belongs to',
              `rank` mediumint(8) unsigned default NULL COMMENT 'manual sort position of the image within this specific album',
              PRIMARY KEY  (`image_id`,`category_id`),
              KEY `image_category_i1` (`category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='image-to-album membership, an image can belong to more than one album'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_image_format` (
              `format_id` int(11) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
              `image_id` mediumint(8) unsigned NOT NULL DEFAULT '0' COMMENT 'image this alternate format file belongs to',
              `ext` varchar(255) NOT NULL COMMENT 'file extension of this alternate format, e.g. a RAW file stored alongside the main JPEG',
              `filesize` mediumint(9) unsigned DEFAULT NULL COMMENT 'file size of this alternate format in KB',
              PRIMARY KEY  (`format_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='alternate format files stored alongside an image (the multiple formats feature)'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_image_tag` (
              `image_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'tagged image id',
              `tag_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'tag applied to the image',
              PRIMARY KEY  (`image_id`,`tag_id`),
              KEY `image_tag_i1` (`tag_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='image-to-tag associations'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_images` (
              `id` mediumint(8) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
              `file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'base filename of the original file',
              `date_available` datetime default NULL COMMENT 'date the photo is considered added/visible in the gallery, can be mapped from EXIF/IPTC or admin-edited',
              `date_creation` datetime default NULL COMMENT 'date the photo was taken, typically synced from EXIF/IPTC metadata',
              `name` varchar(255) default NULL COMMENT 'display title, distinct from the filename',
              `comment` text COMMENT 'photo description shown on its page',
              `author` varchar(255) default NULL COMMENT 'photographer/author credit',
              `hit` int(10) unsigned NOT NULL default '0' COMMENT 'view counter',
              `filesize` mediumint(9) unsigned default NULL COMMENT 'original file size in KB',
              `width` smallint(9) unsigned default NULL COMMENT 'original pixel width',
              `height` smallint(9) unsigned default NULL COMMENT 'original pixel height',
              `coi` char(4) default NULL COMMENT 'center of interest',
              `representative_ext` varchar(4) default NULL COMMENT 'file extension of a separate representative thumbnail, for formats that cannot be thumbnailed directly, e.g. PDF/video',
              `date_metadata_update` date default NULL COMMENT 'date the row was last synced from the file EXIF/IPTC metadata, null if never synced',
              `rating_score` float(5,2) unsigned default NULL COMMENT 'bayesian average of piwigo_rate ratings, recomputed by RateService::updateRatingScore',
              `path` varchar(255) NOT NULL default '' COMMENT 'full relative filesystem path to the original file',
              `storage_category_id` smallint(5) unsigned default NULL COMMENT 'album the file is physically stored under, distinct from possibly multiple image_category memberships',
              `level` tinyint unsigned NOT NULL default '0' COMMENT 'minimum permission level required to view the image, see PwgImages::setPrivacyLevel and available_permission_levels',
              `md5sum` char(32) default NULL COMMENT 'MD5 checksum of the original file, computed lazily for duplicate detection',
              `added_by` mediumint(8) unsigned default NULL COMMENT 'uploading user id',
              `rotation` tinyint unsigned default NULL COMMENT 'pending quarter-turn rotation to apply when rendering, 0 to 3',
              `latitude` double(8, 6) default NULL COMMENT 'GPS latitude, from EXIF',
              `longitude` double(9, 6) default NULL COMMENT 'GPS longitude, from EXIF',
              `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp',
              PRIMARY KEY  (`id`),
              KEY `images_i2` (`date_available`),
              KEY `images_i3` (`rating_score`),
              KEY `images_i4` (`hit`),
              KEY `images_i5` (`date_creation`),
              KEY `images_i1` (`storage_category_id`),
              KEY `images_i6` (`latitude`),
              KEY `images_i7` (`path`),
              KEY `images_i8` (`md5sum`),
              KEY `images_i9` (`file`),
              KEY `lastmodified` (`lastmodified`),
              KEY `idx_images_date_desc` (`date_available` DESC, `id` DESC),
              FULLTEXT KEY `images_ft_name_comment` (`name`,`comment`) WITH PARSER ngram,
              FULLTEXT KEY `images_ft_author` (`author`) WITH PARSER ngram
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='photo/media metadata and file location, one row per uploaded image'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_lounge` (
              `image_id` mediumint(8) unsigned NOT NULL DEFAULT '0' COMMENT 'newly uploaded image pending album association',
              `category_id` smallint(5) unsigned NOT NULL DEFAULT '0' COMMENT 'album the image is intended for once the lounge is emptied',
              PRIMARY KEY (`image_id`,`category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='pending image-to-album associations, applied in bulk by ImageService::emptyLounge'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_old_permalinks` (
              `cat_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'album the removed permalink used to point to',
              `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'the retired URL slug, kept so it is not immediately reusable by another album',
              `date_deleted` datetime default NULL COMMENT 'when the permalink was retired',
              `last_hit` datetime default NULL COMMENT 'when the dead permalink was last visited',
              `hit` int(10) unsigned NOT NULL default '0' COMMENT 'visit count against the dead permalink',
              PRIMARY KEY  (`permalink`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='retired album permalinks, kept to block reuse and shown on the admin permalinks page'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_rate` (
              `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'rating user id, the guest user id for anonymous visitors',
              `element_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'rated image id',
              `anonymous_id` varchar(45) NOT NULL default '' COMMENT 'truncated IP address identifying an anonymous rater, from the anonymous_rater cookie',
              `rate` tinyint(2) unsigned NOT NULL default '0' COMMENT 'submitted rating value, restricted to the configured rate_items',
              `date` date default NULL COMMENT 'date the rate was submitted',
              PRIMARY KEY  (`element_id`,`user_id`,`anonymous_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user or per-anonymous-visitor image ratings, aggregated into images.rating_score'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_tags` (
              `id` smallint(5) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
              `name` varchar(255) NOT NULL default '' COMMENT 'tag display name',
              `url_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'URL-friendly slug derived from name',
              `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp, set on insert only',
              PRIMARY KEY  (`id`),
              KEY `tags_i1` (`url_name`),
              KEY `lastmodified` (`lastmodified`),
              FULLTEXT KEY `tags_ft_name` (`name`) WITH PARSER ngram
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='photo tags/keywords'
            SQL));
    }

    private function upPostgres(): void
    {
        $prefix = DbCredentials::fromEnv()->prefix;

        // Shared BEFORE UPDATE trigger function backing every
        // `ON UPDATE CURRENT_TIMESTAMP`-equivalent `lastmodified` column
        // in the whole schema (this migration's own categories/images/tags,
        // plus groups/user_infos in the users-domain migration) -- created
        // once here since this is the first migration to need it.
        //
        // Every `lastmodified` column below is declared `timestamp(0)`, not
        // bare `timestamp` -- real bug found live (typed-primitives Phase
        // 5): PostgreSQL's bare `timestamp` defaults to microsecond
        // precision, but MySQL's `TIMESTAMP` (this schema's other real
        // target, see the *-mysql.sql reference) has zero fractional
        // digits by default. `Common\ValueObject\SqlDateTime::from()`'s
        // own canonical `Y-m-d H:i:s` form (no fractional seconds) reads a
        // real fetched `now()`-populated row on MySQL fine, but throws on
        // Postgres once this column is Doctrine-Type-mapped, since
        // `now()`'s real return value carries microseconds. `timestamp(0)`
        // makes Postgres truncate to whole seconds on write (both the
        // trigger's `now()` assignment and this column's own `DEFAULT
        // now()`), matching MySQL's real behavior exactly.
        $this->addSql('CREATE FUNCTION piwigo_set_lastmodified() RETURNS trigger AS $$ BEGIN NEW.lastmodified = now(); RETURN NEW; END; $$ LANGUAGE plpgsql');

        $this->addSql('CREATE TABLE ' . $prefix . 'caddie (user_id integer NOT NULL, element_id integer NOT NULL, PRIMARY KEY (user_id, element_id))');
        $this->addSql("COMMENT ON TABLE {$prefix}caddie IS 'per-user temporary photo selection (caddie/basket) used by batch operations'");
        $this->addSql("COMMENT ON COLUMN {$prefix}caddie.user_id IS 'owning user id'");
        $this->addSql("COMMENT ON COLUMN {$prefix}caddie.element_id IS 'image id added to the caddie'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'categories (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            "name varchar(255) NOT NULL DEFAULT '', " .
            'id_uppercat integer DEFAULT NULL, ' .
            'comment text DEFAULT NULL, ' .
            'dir varchar(255) DEFAULT NULL, ' .
            '"rank" integer DEFAULT NULL, ' .
            "status varchar(10) NOT NULL DEFAULT 'public' CHECK (status IN ('public', 'private')), " .
            'site_id smallint DEFAULT NULL, ' .
            'visible boolean NOT NULL DEFAULT true, ' .
            'representative_picture_id integer DEFAULT NULL, ' .
            "uppercats varchar(255) NOT NULL DEFAULT '', " .
            'commentable boolean NOT NULL DEFAULT true, ' .
            'global_rank varchar(255) DEFAULT NULL, ' .
            'image_order varchar(128) DEFAULT NULL, ' .
            'permalink varchar(64) COLLATE "C" DEFAULT NULL, ' .
            'lastmodified timestamp(0) NOT NULL DEFAULT now(), ' .
            "tsv_search tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(comment, ''))) STORED, " .
            'PRIMARY KEY (id))'
        );
        $this->addSql('ALTER TABLE ' . $prefix . 'categories ADD CONSTRAINT categories_i3_unique UNIQUE (permalink)');
        $this->addSql('CREATE INDEX categories_i2 ON ' . $prefix . 'categories (id_uppercat)');
        $this->addSql('CREATE INDEX categories_lastmodified_idx ON ' . $prefix . 'categories (lastmodified)');
        $this->addSql('CREATE INDEX categories_ft_name_comment ON ' . $prefix . 'categories USING GIN (tsv_search)');
        $this->addSql('CREATE TRIGGER trg_categories_lastmodified BEFORE UPDATE ON ' . $prefix . 'categories FOR EACH ROW EXECUTE FUNCTION piwigo_set_lastmodified()');
        $this->addSql("COMMENT ON TABLE {$prefix}categories IS 'photo albums, both physical filesystem-synced and virtual'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.name IS 'album display name'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.id_uppercat IS 'parent album id, null for a root album'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.comment IS 'album description shown on its page'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.dir IS 'filesystem subdirectory name for a physical, synchronized album, null for a virtual album'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.rank IS 'sibling display order within the same parent, distinct from global_rank'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.status IS 'private albums require an explicit user_access or group_access grant to view'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.site_id IS 'owning site id, resolves to sites.galleries_url for a physical album'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.visible IS 'whether the album is shown in navigation, forced false at creation if its parent is not visible'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.representative_picture_id IS 'image id used as the album thumbnail'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.uppercats IS 'comma-separated ancestor album id path, from root to this album'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.commentable IS 'whether photo comments are allowed for images in this album'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.global_rank IS 'full-tree sort key derived from rank along the ancestor path, used to order albums across different parents'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.image_order IS 'preferred ORDER BY expression for images in this album, inheritable to descendant albums'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.permalink IS 'unique URL-friendly slug for this album'");
        $this->addSql("COMMENT ON COLUMN {$prefix}categories.lastmodified IS 'row last-update timestamp'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'comments (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'image_id integer NOT NULL, ' .
            'date timestamp DEFAULT NULL, ' .
            'author varchar(255) DEFAULT NULL, ' .
            'email varchar(255) DEFAULT NULL, ' .
            'author_id integer DEFAULT NULL, ' .
            'anonymous_id varchar(45) NOT NULL, ' .
            'website_url varchar(255) DEFAULT NULL, ' .
            'content text DEFAULT NULL, ' .
            'validated boolean NOT NULL DEFAULT false, ' .
            'validation_date timestamp DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql('CREATE INDEX comments_i2 ON ' . $prefix . 'comments (validation_date)');
        $this->addSql('CREATE INDEX comments_i1 ON ' . $prefix . 'comments (image_id)');
        $this->addSql("COMMENT ON TABLE {$prefix}comments IS 'visitor comments left on photos'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.image_id IS 'commented image id'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.date IS 'when the comment was submitted'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.author IS 'display name shown with the comment, the account username for a logged-in user or the guest-entered name otherwise'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.email IS 'guest-provided email address'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.author_id IS 'commenting user id, null for a guest comment'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.anonymous_id IS 'full IP address of a guest commenter, used for anti-flood throttling'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.website_url IS 'guest-provided homepage link'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.content IS 'comment body'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.validated IS 'moderation approval flag, gates visibility when comments_validation is enabled'");
        $this->addSql("COMMENT ON COLUMN {$prefix}comments.validation_date IS 'when the comment was approved'");

        $this->addSql('CREATE TABLE ' . $prefix . 'favorites (user_id integer NOT NULL, image_id integer NOT NULL, PRIMARY KEY (user_id, image_id))');
        $this->addSql("COMMENT ON TABLE {$prefix}favorites IS 'per-user favorited images'");
        $this->addSql("COMMENT ON COLUMN {$prefix}favorites.user_id IS 'owning user id'");
        $this->addSql("COMMENT ON COLUMN {$prefix}favorites.image_id IS 'image the user marked as a favorite'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'image_category (' .
            'image_id integer NOT NULL, category_id integer NOT NULL, "rank" integer DEFAULT NULL, ' .
            'PRIMARY KEY (image_id, category_id))'
        );
        $this->addSql('CREATE INDEX image_category_i1 ON ' . $prefix . 'image_category (category_id)');
        $this->addSql("COMMENT ON TABLE {$prefix}image_category IS 'image-to-album membership, an image can belong to more than one album'");
        $this->addSql("COMMENT ON COLUMN {$prefix}image_category.image_id IS 'member image id'");
        $this->addSql("COMMENT ON COLUMN {$prefix}image_category.category_id IS 'album the image belongs to'");
        $this->addSql("COMMENT ON COLUMN {$prefix}image_category.rank IS 'manual sort position of the image within this specific album'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'image_format (' .
            'format_id integer GENERATED BY DEFAULT AS IDENTITY, image_id integer NOT NULL, ' .
            'ext varchar(255) NOT NULL, filesize integer DEFAULT NULL, PRIMARY KEY (format_id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}image_format IS 'alternate format files stored alongside an image (the multiple formats feature)'");
        $this->addSql("COMMENT ON COLUMN {$prefix}image_format.format_id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}image_format.image_id IS 'image this alternate format file belongs to'");
        $this->addSql("COMMENT ON COLUMN {$prefix}image_format.ext IS 'file extension of this alternate format, e.g. a RAW file stored alongside the main JPEG'");
        $this->addSql("COMMENT ON COLUMN {$prefix}image_format.filesize IS 'file size of this alternate format in KB'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'image_tag (' .
            'image_id integer NOT NULL, tag_id integer NOT NULL, PRIMARY KEY (image_id, tag_id))'
        );
        $this->addSql('CREATE INDEX image_tag_i1 ON ' . $prefix . 'image_tag (tag_id)');
        $this->addSql("COMMENT ON TABLE {$prefix}image_tag IS 'image-to-tag associations'");
        $this->addSql("COMMENT ON COLUMN {$prefix}image_tag.image_id IS 'tagged image id'");
        $this->addSql("COMMENT ON COLUMN {$prefix}image_tag.tag_id IS 'tag applied to the image'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'images (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            "file varchar(255) COLLATE \"C\" NOT NULL DEFAULT '', " .
            'date_available timestamp DEFAULT NULL, ' .
            'date_creation timestamp DEFAULT NULL, ' .
            'name varchar(255) DEFAULT NULL, ' .
            'comment text DEFAULT NULL, ' .
            'author varchar(255) DEFAULT NULL, ' .
            'hit bigint NOT NULL DEFAULT 0, ' .
            'filesize integer DEFAULT NULL, ' .
            'width integer DEFAULT NULL, ' .
            'height integer DEFAULT NULL, ' .
            'coi char(4) DEFAULT NULL, ' .
            'representative_ext varchar(4) DEFAULT NULL, ' .
            'date_metadata_update date DEFAULT NULL, ' .
            'rating_score real DEFAULT NULL, ' .
            "path varchar(255) NOT NULL DEFAULT '', " .
            'storage_category_id integer DEFAULT NULL, ' .
            'level smallint NOT NULL DEFAULT 0, ' .
            'md5sum char(32) DEFAULT NULL, ' .
            'added_by integer DEFAULT NULL, ' .
            'rotation smallint DEFAULT NULL, ' .
            'latitude double precision DEFAULT NULL, ' .
            'longitude double precision DEFAULT NULL, ' .
            'lastmodified timestamp(0) NOT NULL DEFAULT now(), ' .
            "tsv_search tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(comment, ''))) STORED, " .
            "tsv_author tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(author, ''))) STORED, " .
            'PRIMARY KEY (id))'
        );
        $this->addSql('CREATE INDEX images_i2 ON ' . $prefix . 'images (date_available)');
        $this->addSql('CREATE INDEX images_i3 ON ' . $prefix . 'images (rating_score)');
        $this->addSql('CREATE INDEX images_i4 ON ' . $prefix . 'images (hit)');
        $this->addSql('CREATE INDEX images_i5 ON ' . $prefix . 'images (date_creation)');
        $this->addSql('CREATE INDEX images_i1 ON ' . $prefix . 'images (storage_category_id)');
        $this->addSql('CREATE INDEX images_i6 ON ' . $prefix . 'images (latitude)');
        $this->addSql('CREATE INDEX images_i7 ON ' . $prefix . 'images (path)');
        $this->addSql('CREATE INDEX images_i8 ON ' . $prefix . 'images (md5sum)');
        $this->addSql('CREATE INDEX images_i9 ON ' . $prefix . 'images (file)');
        $this->addSql('CREATE INDEX images_lastmodified_idx ON ' . $prefix . 'images (lastmodified)');
        $this->addSql('CREATE INDEX idx_images_date_desc ON ' . $prefix . 'images (date_available DESC, id DESC)');
        $this->addSql('CREATE INDEX images_ft_name_comment ON ' . $prefix . 'images USING GIN (tsv_search)');
        $this->addSql('CREATE INDEX images_ft_author ON ' . $prefix . 'images USING GIN (tsv_author)');
        $this->addSql('CREATE TRIGGER trg_images_lastmodified BEFORE UPDATE ON ' . $prefix . 'images FOR EACH ROW EXECUTE FUNCTION piwigo_set_lastmodified()');
        $this->addSql("COMMENT ON TABLE {$prefix}images IS 'photo/media metadata and file location, one row per uploaded image'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.file IS 'base filename of the original file'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.date_available IS 'date the photo is considered added/visible in the gallery, can be mapped from EXIF/IPTC or admin-edited'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.date_creation IS 'date the photo was taken, typically synced from EXIF/IPTC metadata'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.name IS 'display title, distinct from the filename'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.comment IS 'photo description shown on its page'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.author IS 'photographer/author credit'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.hit IS 'view counter'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.filesize IS 'original file size in KB'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.width IS 'original pixel width'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.height IS 'original pixel height'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.coi IS 'center of interest'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.representative_ext IS 'file extension of a separate representative thumbnail, for formats that cannot be thumbnailed directly, e.g. PDF/video'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.date_metadata_update IS 'date the row was last synced from the file EXIF/IPTC metadata, null if never synced'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.rating_score IS 'bayesian average of piwigo_rate ratings, recomputed by RateService::updateRatingScore'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.path IS 'full relative filesystem path to the original file'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.storage_category_id IS 'album the file is physically stored under, distinct from possibly multiple image_category memberships'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.level IS 'minimum permission level required to view the image, see PwgImages::setPrivacyLevel and available_permission_levels'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.md5sum IS 'MD5 checksum of the original file, computed lazily for duplicate detection'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.added_by IS 'uploading user id'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.rotation IS 'pending quarter-turn rotation to apply when rendering, 0 to 3'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.latitude IS 'GPS latitude, from EXIF'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.longitude IS 'GPS longitude, from EXIF'");
        $this->addSql("COMMENT ON COLUMN {$prefix}images.lastmodified IS 'row last-update timestamp'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'lounge (' .
            'image_id integer NOT NULL, category_id integer NOT NULL, PRIMARY KEY (image_id, category_id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}lounge IS 'pending image-to-album associations, applied in bulk by ImageService::emptyLounge'");
        $this->addSql("COMMENT ON COLUMN {$prefix}lounge.image_id IS 'newly uploaded image pending album association'");
        $this->addSql("COMMENT ON COLUMN {$prefix}lounge.category_id IS 'album the image is intended for once the lounge is emptied'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'old_permalinks (' .
            'cat_id integer NOT NULL, ' .
            "permalink varchar(64) COLLATE \"C\" NOT NULL DEFAULT '', " .
            'date_deleted timestamp DEFAULT NULL, ' .
            'last_hit timestamp DEFAULT NULL, ' .
            'hit bigint NOT NULL DEFAULT 0, ' .
            'PRIMARY KEY (permalink))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}old_permalinks IS 'retired album permalinks, kept to block reuse and shown on the admin permalinks page'");
        $this->addSql("COMMENT ON COLUMN {$prefix}old_permalinks.cat_id IS 'album the removed permalink used to point to'");
        $this->addSql("COMMENT ON COLUMN {$prefix}old_permalinks.permalink IS 'the retired URL slug, kept so it is not immediately reusable by another album'");
        $this->addSql("COMMENT ON COLUMN {$prefix}old_permalinks.date_deleted IS 'when the permalink was retired'");
        $this->addSql("COMMENT ON COLUMN {$prefix}old_permalinks.last_hit IS 'when the dead permalink was last visited'");
        $this->addSql("COMMENT ON COLUMN {$prefix}old_permalinks.hit IS 'visit count against the dead permalink'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'rate (' .
            'user_id integer NOT NULL, element_id integer NOT NULL, ' .
            "anonymous_id varchar(45) NOT NULL DEFAULT '', rate smallint NOT NULL DEFAULT 0, date date DEFAULT NULL, " .
            'PRIMARY KEY (element_id, user_id, anonymous_id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}rate IS 'per-user or per-anonymous-visitor image ratings, aggregated into images.rating_score'");
        $this->addSql("COMMENT ON COLUMN {$prefix}rate.user_id IS 'rating user id, the guest user id for anonymous visitors'");
        $this->addSql("COMMENT ON COLUMN {$prefix}rate.element_id IS 'rated image id'");
        $this->addSql("COMMENT ON COLUMN {$prefix}rate.anonymous_id IS 'truncated IP address identifying an anonymous rater, from the anonymous_rater cookie'");
        $this->addSql("COMMENT ON COLUMN {$prefix}rate.rate IS 'submitted rating value, restricted to the configured rate_items'");
        $this->addSql("COMMENT ON COLUMN {$prefix}rate.date IS 'date the rate was submitted'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'tags (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            "name varchar(255) NOT NULL DEFAULT '', " .
            "url_name varchar(255) COLLATE \"C\" NOT NULL DEFAULT '', " .
            'lastmodified timestamp(0) NOT NULL DEFAULT now(), ' .
            "tsv_search tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(name, ''))) STORED, " .
            'PRIMARY KEY (id))'
        );
        $this->addSql('CREATE INDEX tags_i1 ON ' . $prefix . 'tags (url_name)');
        $this->addSql('CREATE INDEX tags_lastmodified_idx ON ' . $prefix . 'tags (lastmodified)');
        $this->addSql('CREATE INDEX tags_ft_name ON ' . $prefix . 'tags USING GIN (tsv_search)');
        $this->addSql('CREATE TRIGGER trg_tags_lastmodified BEFORE UPDATE ON ' . $prefix . 'tags FOR EACH ROW EXECUTE FUNCTION piwigo_set_lastmodified()');
        $this->addSql("COMMENT ON TABLE {$prefix}tags IS 'photo tags/keywords'");
        $this->addSql("COMMENT ON COLUMN {$prefix}tags.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}tags.name IS 'tag display name'");
        $this->addSql("COMMENT ON COLUMN {$prefix}tags.url_name IS 'URL-friendly slug derived from name'");
        $this->addSql("COMMENT ON COLUMN {$prefix}tags.lastmodified IS 'row last-update timestamp, set on insert only'");
    }

    /**
     * The verbatim-copied MySQL/MariaDB DDL blocks above always literally
     * say `piwigo_`, matching `install/piwigo_structure-mysql.sql` itself
     * (which has no prefix placeholder either) -- the *old* install path
     * only supported a custom `PIWIGO_DB_PREFIX` by string-replacing
     * `InstallService::executeSqlfile()`'s own `DEFAULT_PREFIX_TABLE`
     * ('piwigo_') for the real submitted prefix as it streamed the file.
     * Now that `InstallWizard` calls this migration directly instead of
     * that file-based path, the same replacement has to happen here, or a
     * non-default prefix submitted through the install form would be
     * silently ignored on the MySQL/MariaDB branch.
     */
    private function withMysqlPrefix(string $sql): string
    {
        return str_replace('piwigo_', DbCredentials::fromEnv()->prefix, $sql);
    }
}
