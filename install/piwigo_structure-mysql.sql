-- Final v17 schema (InnoDB + utf8mb4, all FK constraints, all indexes).
-- Hand-maintained -- there is no Doctrine Migrations layer between "what
-- the schema should be" and what a fresh install creates.

-- Item 22: MySQL bakes a FULLTEXT index's effective stopword-filtering
-- behavior in at CREATE TABLE time, for the lifetime of that index --
-- confirmed live that a later per-connection SET SESSION at INSERT time
-- has zero effect on an already-existing index, while an index created
-- under this setting correctly indexes every future row regardless of
-- the writing connection's own later setting. The ngram FULLTEXT parser
-- (categories/images/tags below) has a severe, real interaction with the
-- default INNODB stopword list otherwise: if *any* 2-character fragment
-- of a word matches a stopword (ngram_token_size is 2; MySQL's own
-- default stopword list includes short, common fragments like
-- at/in/on), MySQL drops every fragment of that *whole* word from the
-- index, not just the matching fragment -- e.g. "cat" (contains "at")
-- becomes entirely unsearchable, hitting any word containing at/in/on/
-- etc. anywhere in it (cat, chat, combat, station, water, later...), not
-- a rare edge case.
SET SESSION innodb_ft_enable_stopword = 0;

--
-- Table structure for table `piwigo_activity`
--

DROP TABLE IF EXISTS `piwigo_activity`;
CREATE TABLE `piwigo_activity` (
  `activity_id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `object` varchar(255) NOT NULL COMMENT 'entity type the action applies to, e.g. user, photo, album, tag, plugin',
  `object_id` int(11) unsigned NOT NULL COMMENT 'id of the affected object, or the target user id on a logout action',
  `action` varchar(255) NOT NULL COMMENT 'action verb, e.g. add, delete, login, logout, autoupdate',
  `performed_by` mediumint(8) unsigned DEFAULT NULL COMMENT 'acting user id, null for an unresolved or system actor',
  `session_idx` varchar(255) NOT NULL COMMENT 'PHP session id active during the request, or none if there was no session',
  `ip_address` varchar(50) DEFAULT NULL COMMENT 'REMOTE_ADDR of the request that triggered the action',
  `occured_on` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'when the action was recorded',
  `details` JSON DEFAULT NULL COMMENT 'per-action heterogeneous payload, e.g. config diffs, batch-edit fields, install metadata',
  `user_agent` varchar(255) DEFAULT NULL COMMENT 'browser user agent string, only captured on login actions',
  PRIMARY KEY (`activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='general activity log of user and system actions, distinct from the tamper-evident audit_log';

--
-- Table structure for table `piwigo_caddie`
--

DROP TABLE IF EXISTS `piwigo_caddie`;
CREATE TABLE `piwigo_caddie` (
  `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'owning user id',
  `element_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'image id added to the caddie',
  PRIMARY KEY  (`user_id`,`element_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user temporary photo selection (caddie/basket) used by batch operations';

--
-- Table structure for table `piwigo_categories`
--

DROP TABLE IF EXISTS `piwigo_categories`;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='photo albums, both physical filesystem-synced and virtual';

--
-- Table structure for table `piwigo_comments`
--

DROP TABLE IF EXISTS `piwigo_comments`;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='visitor comments left on photos';

--
-- Table structure for table `piwigo_config`
--

DROP TABLE IF EXISTS `piwigo_config`;
CREATE TABLE `piwigo_config` (
  `param` varchar(40) NOT NULL default '' COMMENT 'configuration key',
  `value` JSON DEFAULT NULL COMMENT 'JSON-encoded configuration value, see ConfigService::encode()/hydrate()',
  `comment` varchar(255) default NULL COMMENT 'human-readable description of the param, seeded for built-in settings by install/config.sql',
  PRIMARY KEY  (`param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='configuration table';

--
-- Table structure for table `piwigo_favorites`
--

DROP TABLE IF EXISTS `piwigo_favorites`;
CREATE TABLE `piwigo_favorites` (
  `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'owning user id',
  `image_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'image the user marked as a favorite',
  PRIMARY KEY  (`user_id`,`image_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user favorited images';

--
-- Table structure for table `piwigo_group_access`
--

DROP TABLE IF EXISTS `piwigo_group_access`;
CREATE TABLE `piwigo_group_access` (
  `group_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'granted group id',
  `cat_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'private album the group is granted access to',
  PRIMARY KEY  (`group_id`,`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-group private album permission grants';

--
-- Table structure for table `piwigo_groups`
--

DROP TABLE IF EXISTS `piwigo_groups`;
CREATE TABLE `piwigo_groups` (
  `id` smallint(5) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
  `name` varchar(255) NOT NULL default '' COMMENT 'group display name, unique',
  `is_default` tinyint(1) NOT NULL default '0' COMMENT 'every newly registered user is automatically added to groups marked default',
  `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp, set on insert only',
  PRIMARY KEY  (`id`),
  UNIQUE KEY `groups_ui1` (`name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='user groups for bulk permission and membership management';

--
-- Table structure for table `piwigo_history`
--

DROP TABLE IF EXISTS `piwigo_history`;
CREATE TABLE `piwigo_history` (
  `id` int(10) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
  `date` date default NULL COMMENT 'calendar date of the visit',
  `time` time NOT NULL default '00:00:00' COMMENT 'time of day of the visit',
  `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'visiting user id, the guest user id for anonymous visitors',
  `IP` char(39) NOT NULL default '' COMMENT 'REMOTE_ADDR of the request, truncated to fit an IPv6 address',
  `section` enum('categories','tags','search','list','favorites','most_visited','best_rated','recent_pics','recent_cats') default NULL COMMENT 'gallery navigation view the visit occurred in, plugin-defined sections are appended to this enum automatically',
  `category_id` smallint(5) unsigned default NULL COMMENT 'album being viewed, set when section is a category-based view',
  `search_id` int(10) unsigned default NULL COMMENT 'search being viewed, set when section is search',
  `tag_ids` varchar(50) default NULL COMMENT 'comma-separated tag ids being viewed, set when section is tags, truncated to fit',
  `image_id` mediumint(8) unsigned default NULL COMMENT 'viewed image id, null for a listing/section page-view',
  `image_type` enum('picture','high','other') default NULL COMMENT 'size the image was viewed at',
  `format_id` int(11) unsigned default NULL COMMENT 'image_format row downloaded or viewed, when applicable',
  `auth_key_id` int(11) unsigned DEFAULT NULL COMMENT 'API auth key the request was authenticated with, if any',
  PRIMARY KEY  (`id`),
  KEY `idx_history_date_desc` (`date` DESC, `id` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-visit page-view log, periodically rolled up into history_summary';

--
-- Table structure for table `piwigo_history_summary`
--

DROP TABLE IF EXISTS `piwigo_history_summary`;
CREATE TABLE `piwigo_history_summary` (
  `summary_id` int(10) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
  `year` smallint(4) NOT NULL default '0' COMMENT 'rollup year',
  `month` tinyint(2) default NULL COMMENT 'rollup month, null for a year-level summary row',
  `day` tinyint(2) default NULL COMMENT 'rollup day, null for a year- or month-level summary row',
  `hour` tinyint(2) default NULL COMMENT 'rollup hour, null for a year-, month-, or day-level summary row',
  `nb_pages` int(11) default NULL COMMENT 'number of history page-views folded into this summary row',
  `history_id_from` int(10) unsigned default NULL COMMENT 'lowest history.id folded into this summary row',
  `history_id_to` int(10) unsigned default NULL COMMENT 'highest history.id folded into this summary row, the next run resumes past this id',
  PRIMARY KEY (`summary_id`),
  UNIQUE KEY history_summary_ymdh (`year`,`month`,`day`,`hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='year/month/day/hour rollup of piwigo_history, one row per granularity level, letting old detail rows be purged';

--
-- Table structure for table `piwigo_image_category`
--

DROP TABLE IF EXISTS `piwigo_image_category`;
CREATE TABLE `piwigo_image_category` (
  `image_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'member image id',
  `category_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'album the image belongs to',
  `rank` mediumint(8) unsigned default NULL COMMENT 'manual sort position of the image within this specific album',
  PRIMARY KEY  (`image_id`,`category_id`),
  KEY `image_category_i1` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='image-to-album membership, an image can belong to more than one album';

--
-- Table structure for table `piwigo_image_format`
--

DROP TABLE IF EXISTS `piwigo_image_format`;
CREATE TABLE `piwigo_image_format` (
  `format_id` int(11) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT '0' COMMENT 'image this alternate format file belongs to',
  `ext` varchar(255) NOT NULL COMMENT 'file extension of this alternate format, e.g. a RAW file stored alongside the main JPEG',
  `filesize` mediumint(9) unsigned DEFAULT NULL COMMENT 'file size of this alternate format in KB',
  PRIMARY KEY  (`format_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='alternate format files stored alongside an image (the multiple formats feature)';

--
-- Table structure for table `piwigo_image_tag`
--

DROP TABLE IF EXISTS `piwigo_image_tag`;
CREATE TABLE `piwigo_image_tag` (
  `image_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'tagged image id',
  `tag_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'tag applied to the image',
  PRIMARY KEY  (`image_id`,`tag_id`),
  KEY `image_tag_i1` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='image-to-tag associations';

--
-- Table structure for table `piwigo_images`
--

DROP TABLE IF EXISTS `piwigo_images`;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='photo/media metadata and file location, one row per uploaded image';

--
-- Table structure for table `piwigo_languages`
--

DROP TABLE IF EXISTS `piwigo_languages`;
CREATE TABLE `piwigo_languages` (
  `id` varchar(64) NOT NULL default '' COMMENT 'language directory-name identifier, e.g. en_UK, row existence alone means installed and active',
  `version` varchar(64) NOT NULL default '0' COMMENT 'installed language pack version string',
  `name` varchar(64) default NULL COMMENT 'human-readable language display name',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed/active language packs, row deleted outright on deactivation';

--
-- Table structure for table `piwigo_lounge`
--

DROP TABLE IF EXISTS `piwigo_lounge`;
CREATE TABLE `piwigo_lounge` (
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT '0' COMMENT 'newly uploaded image pending album association',
  `category_id` smallint(5) unsigned NOT NULL DEFAULT '0' COMMENT 'album the image is intended for once the lounge is emptied',
  PRIMARY KEY (`image_id`,`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='pending image-to-album associations, applied in bulk by ImageService::emptyLounge';

--
-- Table structure for table `piwigo_old_permalinks`
--

DROP TABLE IF EXISTS `piwigo_old_permalinks`;
CREATE TABLE `piwigo_old_permalinks` (
  `cat_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'album the removed permalink used to point to',
  `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'the retired URL slug, kept so it is not immediately reusable by another album',
  `date_deleted` datetime default NULL COMMENT 'when the permalink was retired',
  `last_hit` datetime default NULL COMMENT 'when the dead permalink was last visited',
  `hit` int(10) unsigned NOT NULL default '0' COMMENT 'visit count against the dead permalink',
  PRIMARY KEY  (`permalink`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='retired album permalinks, kept to block reuse and shown on the admin permalinks page';

--
-- Table structure for table `piwigo_plugins`
--

DROP TABLE IF EXISTS `piwigo_plugins`;
CREATE TABLE `piwigo_plugins` (
  `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'plugin directory-name identifier, row existence alone means installed, active or not',
  `state` enum('inactive','active') NOT NULL default 'inactive' COMMENT 'whether the installed plugin is currently active',
  `version` varchar(64) NOT NULL default '0' COMMENT 'installed plugin version string',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed plugins and their active/inactive state';

--
-- Table structure for table `piwigo_rate`
--

DROP TABLE IF EXISTS `piwigo_rate`;
CREATE TABLE `piwigo_rate` (
  `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'rating user id, the guest user id for anonymous visitors',
  `element_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'rated image id',
  `anonymous_id` varchar(45) NOT NULL default '' COMMENT 'truncated IP address identifying an anonymous rater, from the anonymous_rater cookie',
  `rate` tinyint(2) unsigned NOT NULL default '0' COMMENT 'submitted rating value, restricted to the configured rate_items',
  `date` date default NULL COMMENT 'date the rate was submitted',
  PRIMARY KEY  (`element_id`,`user_id`,`anonymous_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user or per-anonymous-visitor image ratings, aggregated into images.rating_score';

--
-- Table structure for table `piwigo_search`
--

DROP TABLE IF EXISTS `piwigo_search`;
CREATE TABLE `piwigo_search` (
  `id` int(10) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
  `search_uuid` CHAR(23) DEFAULT NULL COMMENT 'public, shareable identifier for this saved search, used in URLs instead of id',
  `created_on` DATETIME DEFAULT NULL COMMENT 'when the search was saved',
  `created_by` MEDIUMINT(8) UNSIGNED COMMENT 'user id who saved the search, null for an anonymous search',
  `forked_from` INT(10) UNSIGNED COMMENT 'search this one was refined/derived from, null for an original search',
  `rules` JSON COMMENT 'encoded search criteria (query terms, filters) evaluated by SearchService',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='saved/shareable search queries';

--
-- Table structure for table `piwigo_sessions`
--

DROP TABLE IF EXISTS `piwigo_sessions`;
CREATE TABLE `piwigo_sessions` (
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'composite PHP session id, IP-hash-prefixed by SessionService',
  `data` mediumtext NOT NULL COMMENT 'serialized PHP session payload',
  `expiration` datetime default NULL COMMENT 'when this session becomes invalid',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP session storage backend';

--
-- Table structure for table `piwigo_sites`
--

DROP TABLE IF EXISTS `piwigo_sites`;
CREATE TABLE `piwigo_sites` (
  `id` tinyint(4) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key, referenced by categories.site_id',
  `galleries_url` varchar(255) NOT NULL default '' COMMENT 'base path or URL this site synchronizes photos from, local or remote (see UrlService::urlIsRemote)',
  PRIMARY KEY  (`id`),
  UNIQUE KEY `sites_ui1` (`galleries_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='multi-site photo sources synchronized into albums';

--
-- Table structure for table `piwigo_tags`
--

DROP TABLE IF EXISTS `piwigo_tags`;
CREATE TABLE `piwigo_tags` (
  `id` smallint(5) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
  `name` varchar(255) NOT NULL default '' COMMENT 'tag display name',
  `url_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'URL-friendly slug derived from name',
  `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp, set on insert only',
  PRIMARY KEY  (`id`),
  KEY `tags_i1` (`url_name`),
  KEY `lastmodified` (`lastmodified`),
  FULLTEXT KEY `tags_ft_name` (`name`) WITH PARSER ngram
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='photo tags/keywords';

--
-- Table structure for table `piwigo_themes`
--

DROP TABLE IF EXISTS `piwigo_themes`;
CREATE TABLE `piwigo_themes` (
  `id` varchar(64) NOT NULL default '' COMMENT 'theme directory-name identifier, referenced by user_infos.theme, row existence alone means installed and active',
  `version` varchar(64) NOT NULL default '0' COMMENT 'installed theme version string',
  `name` varchar(64) default NULL COMMENT 'human-readable theme display name',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed/active themes, row deleted outright on deactivation';

--
-- Table structure for table `piwigo_user_access`
--

DROP TABLE IF EXISTS `piwigo_user_access`;
CREATE TABLE `piwigo_user_access` (
  `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'granted user id',
  `cat_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'private album the user is granted access to',
  PRIMARY KEY  (`user_id`,`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user private album permission grants';

--
-- Table structure for table `piwigo_user_auth_keys`
--

DROP TABLE IF EXISTS `piwigo_user_auth_keys`;
CREATE TABLE `piwigo_user_auth_keys` (
  `auth_key_id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `auth_key` varchar(255) NOT NULL COMMENT 'the token value: a random persistent-login token for key_type=auth_key, or the public pkid-... identifier for key_type=api_key',
  `apikey_secret` VARCHAR(255) DEFAULT NULL COMMENT 'hashed secret half of a key_type=api_key pair, null for auth_key rows',
  `user_id` mediumint(8) unsigned NOT NULL COMMENT 'owning user id',
  `created_on` datetime NOT NULL COMMENT 'when the key was issued',
  `duration` int(11) unsigned DEFAULT NULL COMMENT 'requested key lifetime, seconds for auth_key rows or days for api_key rows, see expired_on for the actual cutoff',
  `expired_on` datetime NOT NULL COMMENT 'when the key stops being valid',
  `apikey_name` VARCHAR(100) DEFAULT NULL COMMENT 'user-given label for a key_type=api_key row, null for auth_key rows',
  `key_type` VARCHAR(40) DEFAULT NULL COMMENT 'auth_key for a persistent-login/URL-login token, api_key for a personal API key',
  `revoked_on`  datetime DEFAULT NULL COMMENT 'when the key was manually revoked, null if still live',
  `last_used_on` datetime DEFAULT NULL COMMENT 'when the key last authenticated a request',
  `last_notified_on` datetime DEFAULT NULL COMMENT 'when the owner was last emailed an expiration notice',
  PRIMARY KEY (`auth_key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='persistent-login tokens and personal API keys, two row shapes sharing one table';

--
-- Table structure for table `piwigo_user_feed`
--

DROP TABLE IF EXISTS `piwigo_user_feed`;
CREATE TABLE `piwigo_user_feed` (
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'private feed token, passed as ?feed= to authenticate as the owning user without a login',
  `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'user this feed token authenticates as',
  `last_check` datetime default NULL COMMENT 'when this feed URL was last polled',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user private RSS feed tokens';

--
-- Table structure for table `piwigo_user_group`
--

DROP TABLE IF EXISTS `piwigo_user_group`;
CREATE TABLE `piwigo_user_group` (
  `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'member user id',
  `group_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'group the user belongs to',
  PRIMARY KEY  (`group_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='user to group membership';

--
-- Table structure for table `piwigo_user_infos`
--

DROP TABLE IF EXISTS `piwigo_user_infos`;
CREATE TABLE `piwigo_user_infos` (
  `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'the owning users.id row, application-assigned, never auto-generated here',
  `nb_image_page` smallint(3) unsigned NOT NULL default '15' COMMENT 'photos per page preference',
  `status` enum('webmaster','admin','normal','generic','guest') NOT NULL default 'guest' COMMENT 'account role, gates admin access and permission checks',
  `language` varchar(50) NOT NULL default 'en_UK' COMMENT 'interface language, references languages.id',
  `expand` tinyint(1) NOT NULL default '0' COMMENT 'whether the album tree auto-expands in the menu',
  `show_nb_comments` tinyint(1) NOT NULL default '0' COMMENT 'whether comment counts are shown alongside thumbnails',
  `show_nb_hits` tinyint(1) NOT NULL default '0' COMMENT 'whether view counts are shown alongside thumbnails',
  `recent_period` tinyint(3) unsigned NOT NULL default '7' COMMENT 'number of days considered recent for the recent photos/albums views',
  `theme` varchar(255) NOT NULL default 'modus' COMMENT 'interface theme, references themes.id',
  `registration_date` datetime default NULL COMMENT 'account creation date',
  `enabled_high` tinyint(1) NOT NULL default '1' COMMENT 'whether the user may view/download the original, high-definition photo',
  `level` tinyint unsigned NOT NULL default '0' COMMENT 'effective permission level, gates access to images.level-restricted photos',
  `activation_key` varchar(255) default NULL COMMENT 'hashed password-reset token, see AuthService::setActivationKey and password.php',
  `activation_key_expire` datetime default NULL COMMENT 'when activation_key stops being valid',
  `last_visit` datetime default NULL COMMENT 'when the user was last seen, refreshed once per session length',
  `last_visit_from_history` tinyint(1) NOT NULL default '0' COMMENT 'whether last_visit was already backfilled from the history table, avoids repeating that lookup',
  `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp',
  `preferences` JSON default NULL COMMENT 'generic per-user key-value bag for preferences with no dedicated column',
  PRIMARY KEY (`user_id`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user profile and preferences, one row per users.id';

--
-- Table structure for table `piwigo_user_mail_notification`
--

DROP TABLE IF EXISTS `piwigo_user_mail_notification`;
CREATE TABLE `piwigo_user_mail_notification` (
  `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'subscribing user id',
  `check_key` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'private token used in subscribe/unsubscribe confirmation email links',
  `enabled` tinyint(1) NOT NULL default '0' COMMENT 'whether the user currently receives new-photo notification emails',
  `last_send` datetime default NULL COMMENT 'when a notification email was last sent to this user',
  PRIMARY KEY  (`user_id`),
  UNIQUE KEY `user_mail_notification_ui1` (`check_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user new-photo email notification subscriptions';

--
-- Table structure for table `piwigo_users`
--

DROP TABLE IF EXISTS `piwigo_users`;
CREATE TABLE `piwigo_users` (
  `id` mediumint(8) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key, referenced by user_id everywhere else',
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'login name, unique',
  `password` varchar(255) default NULL COMMENT 'hashed login password',
  `mail_address` varchar(255) default NULL COMMENT 'account email address',
  PRIMARY KEY  (`id`),
  UNIQUE KEY `users_ui1` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='core login accounts, column names configurable via CurrentConfig::userFields for multi-auth integrations';

--
-- The 7 new tables
--

DROP TABLE IF EXISTS `piwigo_derivative_settings`;
CREATE TABLE `piwigo_derivative_settings` (
  `id` smallint NOT NULL COMMENT 'settings row identifier',
  `default_quality` int NOT NULL DEFAULT 95 COMMENT 'default JPEG compression quality, 0 to 100, for generated derivative images',
  `watermark_json` JSON NOT NULL COMMENT 'encoded watermark configuration',
  `custom_json` JSON NOT NULL COMMENT 'encoded custom derivative-generation parameters',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='global derivative-image generation settings, read and written by ImageStdParams via DerivativeSettingsRepository';

DROP TABLE IF EXISTS `piwigo_derivative_size`;
CREATE TABLE `piwigo_derivative_size` (
  `name` varchar(32) NOT NULL COMMENT 'derivative size name, e.g. thumb, medium, xxlarge',
  `enabled` smallint NOT NULL DEFAULT 1 COMMENT 'whether this derivative size is generated',
  `max_width` int NOT NULL DEFAULT 0 COMMENT 'maximum output width in pixels, see SizingParams',
  `max_height` int NOT NULL DEFAULT 0 COMMENT 'maximum output height in pixels, see SizingParams',
  `max_crop` decimal(5,4) NOT NULL DEFAULT 0 COMMENT 'cropping ratio from 0, no cropping, to 1, max cropping, see SizingParams::max_crop',
  `min_width` int DEFAULT NULL COMMENT 'minimum output width required to allow cropping, see SizingParams::min_size',
  `min_height` int DEFAULT NULL COMMENT 'minimum output height required to allow cropping, see SizingParams::min_size',
  `sharpen` decimal(5,4) NOT NULL DEFAULT 0 COMMENT 'sharpening amount from 0, none, to 1, max, see DerivativeParams::sharpen',
  `last_mod_time` int NOT NULL DEFAULT 0 COMMENT 'unix timestamp of the last parameter change, used to invalidate cached derivatives, see DerivativeParams::last_mod_time',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-named derivative size definitions, read and written by ImageStdParams via DerivativeSizeRepository';

DROP TABLE IF EXISTS `piwigo_extension_ignored_updates`;
CREATE TABLE `piwigo_extension_ignored_updates` (
  `extension_type` varchar(16) NOT NULL COMMENT 'plugin, theme, or language, see ExtensionType',
  `extension_id` varchar(64) NOT NULL COMMENT 'directory-name identifier of the extension whose update is being ignored',
  `ignored_at` DATETIME NOT NULL COMMENT 'when the update was dismissed',
  PRIMARY KEY (`extension_type`,`extension_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='extension updates an admin dismissed, read and written by ExtensionUpdateChecker via ExtensionIgnoredUpdateRepository';

DROP TABLE IF EXISTS `piwigo_integrity_ignored_anomalies`;
CREATE TABLE `piwigo_integrity_ignored_anomalies` (
  `anomaly_id` varchar(64) NOT NULL COMMENT 'add_anomaly()-generated md5 id, see CheckIntegrity',
  `piwigo_version` varchar(16) NOT NULL COMMENT 'Piwigo version the anomaly was ignored under',
  `ignored_at` DATETIME NOT NULL COMMENT 'when the anomaly was dismissed',
  PRIMARY KEY (`anomaly_id`,`piwigo_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='integrity-check anomalies an admin dismissed, read and written by CheckIntegrity via IntegrityIgnoredAnomalyRepository';

DROP TABLE IF EXISTS `piwigo_plugin_migrations`;
CREATE TABLE `piwigo_plugin_migrations` (
  `plugin_id` varchar(64) NOT NULL COMMENT 'directory-name identifier of the plugin that ran this migration',
  `version` varchar(191) NOT NULL COMMENT 'plugin-internal migration version identifier',
  `executed_at` DATETIME NOT NULL COMMENT 'when this plugin migration ran',
  PRIMARY KEY (`plugin_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-plugin install/update history, read and written by ExtensionLifecycle via PluginMigrationRepository, not a real migration runner';

DROP TABLE IF EXISTS `piwigo_search_filter_view`;
CREATE TABLE `piwigo_search_filter_view` (
  `name` varchar(64) NOT NULL COMMENT 'saved filter view name',
  `config_json` JSON NOT NULL COMMENT 'encoded search filter configuration',
  `created_at` DATETIME NOT NULL COMMENT 'when the filter view was saved',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='named, reusable saved search-filter presets, unused: not read or written by any repository or service in this codebase';

DROP TABLE IF EXISTS `piwigo_user_failed_logins`;
CREATE TABLE `piwigo_user_failed_logins` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `user_id` mediumint(8) unsigned DEFAULT NULL COMMENT 'targeted user id, if the attempted username resolved to a real account',
  `ip` varchar(45) NOT NULL COMMENT 'REMOTE_ADDR the failed login attempt came from',
  `attempted_at` DATETIME NOT NULL COMMENT 'when the failed attempt occurred',
  PRIMARY KEY (`id`),
  KEY `idx_user_failed_logins_user_time` (`user_id`, `attempted_at`),
  KEY `idx_user_failed_logins_ip_time` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='failed login attempts, read and written by AuthService::pwgLogin() via UserFailedLoginRepository to back its dual-scope (username + IP) lockout';

--
-- SEC-57: append-only, hash-chained audit trail
--

DROP TABLE IF EXISTS `piwigo_audit_log`;
CREATE TABLE `piwigo_audit_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `actor_id` mediumint(8) unsigned DEFAULT NULL COMMENT 'acting user id, null for an unattributed or system action',
  `action` varchar(64) NOT NULL COMMENT 'action verb, e.g. delete, grant, revoke',
  `entity_type` varchar(64) NOT NULL COMMENT 'audited entity type, e.g. group, permission',
  `entity_id` int(11) unsigned DEFAULT NULL COMMENT 'id of the audited entity, null when not applicable',
  `before_json` JSON DEFAULT NULL COMMENT 'entity-agnostic snapshot before the change, null for a creation, folded into row_hash so must stay exactly what was recorded',
  `after_json` JSON DEFAULT NULL COMMENT 'entity-agnostic snapshot after the change, null for a deletion, folded into row_hash so must stay exactly what was recorded',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'REMOTE_ADDR of the request that performed the action',
  `created_at` DATETIME NOT NULL COMMENT 'when the action was recorded',
  `prev_hash` varchar(64) DEFAULT NULL COMMENT 'row_hash of the previous row, null for the first row, forms the hash chain',
  `row_hash` varchar(64) NOT NULL COMMENT 'sha256 of this row content plus prev_hash, tamper-evidence for the chain, see AuditService::computeHash',
  PRIMARY KEY (`id`),
  KEY `idx_audit_log_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_log_actor` (`actor_id`),
  KEY `idx_audit_log_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SEC-57 append-only, hash-chained audit trail of admin actions and permission changes';

--
-- Foreign key constraints (added last, once every table above exists)
--

ALTER TABLE `piwigo_image_category` ADD CONSTRAINT `fk_image_category_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_image_category` ADD CONSTRAINT `fk_image_category_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_image_tag` ADD CONSTRAINT `fk_image_tag_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_image_tag` ADD CONSTRAINT `fk_image_tag_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `piwigo_tags` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_image_format` ADD CONSTRAINT `fk_image_format_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_comments` ADD CONSTRAINT `fk_comments_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_comments` ADD CONSTRAINT `fk_comments_author_id` FOREIGN KEY (`author_id`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_favorites` ADD CONSTRAINT `fk_favorites_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_favorites` ADD CONSTRAINT `fk_favorites_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_user_access` ADD CONSTRAINT `fk_user_access_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_user_access` ADD CONSTRAINT `fk_user_access_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_user_group` ADD CONSTRAINT `fk_user_group_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_user_group` ADD CONSTRAINT `fk_user_group_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_group_access` ADD CONSTRAINT `fk_group_access_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_group_access` ADD CONSTRAINT `fk_group_access_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_user_infos` ADD CONSTRAINT `fk_user_infos_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_user_feed` ADD CONSTRAINT `fk_user_feed_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_user_mail_notification` ADD CONSTRAINT `fk_user_mail_notification_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_user_auth_keys` ADD CONSTRAINT `fk_user_auth_keys_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_user_failed_logins` ADD CONSTRAINT `fk_user_failed_logins_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_caddie` ADD CONSTRAINT `fk_caddie_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_caddie` ADD CONSTRAINT `fk_caddie_element_id` FOREIGN KEY (`element_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_lounge` ADD CONSTRAINT `fk_lounge_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_lounge` ADD CONSTRAINT `fk_lounge_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_rate` ADD CONSTRAINT `fk_rate_element_id` FOREIGN KEY (`element_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_rate` ADD CONSTRAINT `fk_rate_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_categories` ADD CONSTRAINT `fk_categories_id_uppercat` FOREIGN KEY (`id_uppercat`) REFERENCES `piwigo_categories` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_categories` ADD CONSTRAINT `fk_categories_representative_picture_id` FOREIGN KEY (`representative_picture_id`) REFERENCES `piwigo_images` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_images` ADD CONSTRAINT `fk_images_storage_category_id` FOREIGN KEY (`storage_category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_images` ADD CONSTRAINT `fk_images_added_by` FOREIGN KEY (`added_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_history` ADD CONSTRAINT `fk_history_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_history` ADD CONSTRAINT `fk_history_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_history` ADD CONSTRAINT `fk_history_search_id` FOREIGN KEY (`search_id`) REFERENCES `piwigo_search` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_history` ADD CONSTRAINT `fk_history_format_id` FOREIGN KEY (`format_id`) REFERENCES `piwigo_image_format` (`format_id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_history` ADD CONSTRAINT `fk_history_auth_key_id` FOREIGN KEY (`auth_key_id`) REFERENCES `piwigo_user_auth_keys` (`auth_key_id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_history` ADD CONSTRAINT `fk_history_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE;
ALTER TABLE `piwigo_search` ADD CONSTRAINT `fk_search_created_by` FOREIGN KEY (`created_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_search` ADD CONSTRAINT `fk_search_forked_from` FOREIGN KEY (`forked_from`) REFERENCES `piwigo_search` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_activity` ADD CONSTRAINT `fk_activity_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL;
ALTER TABLE `piwigo_audit_log` ADD CONSTRAINT `fk_audit_log_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL;
