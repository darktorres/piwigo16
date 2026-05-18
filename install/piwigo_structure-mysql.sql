-- Piwigo v17 canonical database schema (MySQL 8.0+ / MariaDB 10.5+).

--
-- Table structure for table `piwigo_activity`
--

DROP TABLE IF EXISTS `piwigo_activity`;
CREATE TABLE `piwigo_activity` (
  `activity_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `object` varchar(255) NOT NULL,
  `object_id` int(11) unsigned NOT NULL,
  `action` varchar(255) NOT NULL,
  `performed_by` mediumint(8) unsigned NOT NULL,
  `session_idx` varchar(255) NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `occured_on` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `details` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_caddie`
--

DROP TABLE IF EXISTS `piwigo_caddie`;
CREATE TABLE `piwigo_caddie` (
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `element_id` mediumint(8) unsigned NOT NULL default '0',
  PRIMARY KEY  (`user_id`,`element_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_categories`
--

DROP TABLE IF EXISTS `piwigo_categories`;
CREATE TABLE `piwigo_categories` (
  `id` smallint(5) unsigned NOT NULL auto_increment,
  `name` varchar(255) NOT NULL default '',
  `id_uppercat` smallint(5) unsigned default NULL,
  `comment` text,
  `dir` varchar(255) default NULL,
  `rank` smallint(5) unsigned default NULL,
  `status` enum('public','private') NOT NULL default 'public',
  `site_id` tinyint(4) unsigned default NULL,
  `visible` tinyint(1) unsigned NOT NULL default 1,
  `representative_picture_id` mediumint(8) unsigned default NULL,
  `uppercats` varchar(255) NOT NULL default '',
  `commentable` tinyint(1) unsigned NOT NULL default 1,
  `global_rank` varchar(255) default NULL,
  `image_order` varchar(128) default NULL,
  `permalink` varchar(64) COLLATE utf8mb4_bin default NULL,
  `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `categories_i3` (`permalink`),
  KEY `categories_i2` (`id_uppercat`),
  KEY `categories_status_idx` (`status`),
  KEY `lastmodified` (`lastmodified`),
  FULLTEXT KEY `categories_ft_name_comment` (`name`, `comment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_comments`
--

DROP TABLE IF EXISTS `piwigo_comments`;
CREATE TABLE `piwigo_comments` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `image_id` mediumint(8) unsigned NOT NULL default '0',
  `date` datetime DEFAULT NULL,
  `author` varchar(255) default NULL,
  `email` varchar(255) default NULL,
  `author_id` mediumint(8) unsigned DEFAULT NULL,
  `anonymous_id` varchar(45) NOT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `content` longtext,
  `validated` tinyint(1) unsigned NOT NULL default 0,
  `validation_date` datetime default NULL,
  PRIMARY KEY  (`id`),
  KEY `comments_i2` (`validation_date`),
  KEY `comments_image_validated_idx` (`image_id`,`validated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_config`
--

DROP TABLE IF EXISTS `piwigo_config`;
CREATE TABLE `piwigo_config` (
  `param` varchar(40) NOT NULL default '',
  `value` json DEFAULT NULL,
  `comment` varchar(255) default NULL,
  PRIMARY KEY  (`param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='configuration table';

--
-- Table structure for table `piwigo_derivative_settings`
--

DROP TABLE IF EXISTS `piwigo_derivative_settings`;
CREATE TABLE `piwigo_derivative_settings` (
  `id` tinyint(4) NOT NULL,
  `default_quality` int(11) NOT NULL default '95',
  `watermark_json` json NOT NULL,
  `custom_json` json NOT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='singleton-row: JPEG quality + watermark + custom-size recency map';

--
-- Table structure for table `piwigo_derivative_size`
--

DROP TABLE IF EXISTS `piwigo_derivative_size`;
CREATE TABLE `piwigo_derivative_size` (
  `name` varchar(32) CHARACTER SET ascii NOT NULL,
  `enabled` tinyint(1) NOT NULL default '1',
  `max_width` int(11) NOT NULL default '0',
  `max_height` int(11) NOT NULL default '0',
  `max_crop` decimal(5,4) NOT NULL default '0.0000',
  `min_width` int(11) default NULL,
  `min_height` int(11) default NULL,
  `sharpen` decimal(5,4) NOT NULL default '0.0000',
  `last_mod_time` int(11) NOT NULL default '0',
  PRIMARY KEY  (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-size derivative params (replaces the derivatives serialize() blob)';

--
-- Table structure for table `piwigo_extension_ignored_updates`
--

DROP TABLE IF EXISTS `piwigo_extension_ignored_updates`;
CREATE TABLE `piwigo_extension_ignored_updates` (
  `extension_type` enum('plugins','themes','languages') CHARACTER SET ascii NOT NULL,
  `extension_id` varchar(64) CHARACTER SET ascii NOT NULL,
  `ignored_at` datetime NOT NULL,
  PRIMARY KEY  (`extension_type`,`extension_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='extensions the admin has asked not to notify about (replaces updates_ignored serialize() blob)';

--
-- Table structure for table `piwigo_favorites`
--

DROP TABLE IF EXISTS `piwigo_favorites`;
CREATE TABLE `piwigo_favorites` (
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `image_id` mediumint(8) unsigned NOT NULL default '0',
  PRIMARY KEY  (`user_id`,`image_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_group_access`
--

DROP TABLE IF EXISTS `piwigo_group_access`;
CREATE TABLE `piwigo_group_access` (
  `group_id` smallint(5) unsigned NOT NULL default '0',
  `cat_id` smallint(5) unsigned NOT NULL default '0',
  PRIMARY KEY  (`group_id`,`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_groups`
--

DROP TABLE IF EXISTS `piwigo_groups`;
CREATE TABLE `piwigo_groups` (
  `id` smallint(5) unsigned NOT NULL auto_increment,
  `name` varchar(255) NOT NULL default '',
  `is_default` tinyint(1) unsigned NOT NULL default 0,
  `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `groups_ui1` (`name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_history`
--

DROP TABLE IF EXISTS `piwigo_history`;
CREATE TABLE `piwigo_history` (
  `id` int(10) unsigned NOT NULL auto_increment,
  `date` date DEFAULT NULL,
  `time` time NOT NULL default '00:00:00',
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `IP` char(39) NOT NULL default '',
  `section` enum('categories','tags','search','list','favorites','most_visited','best_rated','recent_pics','recent_cats') default NULL,
  `category_id` smallint(5) unsigned default NULL,
  `search_id` int(10) unsigned default NULL,
  `tag_ids` varchar(50) default NULL,
  `image_id` mediumint(8) unsigned default NULL,
  `image_type` enum('picture','high','other') default NULL,
  `format_id` int(11) unsigned default NULL,
  `auth_key_id` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_history_summary`
--

DROP TABLE IF EXISTS `piwigo_history_summary`;
CREATE TABLE `piwigo_history_summary` (
  `summary_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `year` smallint(4) NOT NULL default '0',
  `month` tinyint(2) default NULL,
  `day` tinyint(2) default NULL,
  `hour` tinyint(2) default NULL,
  `nb_pages` int(11) default NULL,
  `history_id_from` int(10) unsigned default NULL,
  `history_id_to` int(10) unsigned default NULL,
  PRIMARY KEY (`summary_id`),
  UNIQUE KEY `history_summary_ymdh` (`year`,`month`,`day`,`hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_image_category`
--

DROP TABLE IF EXISTS `piwigo_image_category`;
CREATE TABLE `piwigo_image_category` (
  `image_id` mediumint(8) unsigned NOT NULL default '0',
  `category_id` smallint(5) unsigned NOT NULL default '0',
  `rank` mediumint(8) unsigned default NULL,
  PRIMARY KEY  (`image_id`,`category_id`),
  KEY `image_category_i1` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_image_format`
--

DROP TABLE IF EXISTS `piwigo_image_format`;
CREATE TABLE `piwigo_image_format` (
  `format_id` int(11) unsigned NOT NULL auto_increment,
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `ext` varchar(255) NOT NULL,
  `filesize` mediumint(9) unsigned DEFAULT NULL,
  PRIMARY KEY  (`format_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_image_tag`
--

DROP TABLE IF EXISTS `piwigo_image_tag`;
CREATE TABLE `piwigo_image_tag` (
  `image_id` mediumint(8) unsigned NOT NULL default '0',
  `tag_id` smallint(5) unsigned NOT NULL default '0',
  PRIMARY KEY  (`image_id`,`tag_id`),
  KEY `image_tag_i1` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_images`
--

DROP TABLE IF EXISTS `piwigo_images`;
CREATE TABLE `piwigo_images` (
  `id` mediumint(8) unsigned NOT NULL auto_increment,
  `file` varchar(255) COLLATE utf8mb4_bin NOT NULL default '',
  `date_available` datetime DEFAULT NULL,
  `date_creation` datetime default NULL,
  `name` varchar(255) default NULL,
  `comment` text,
  `author` varchar(255) default NULL,
  `hit` int(10) unsigned NOT NULL default '0',
  `filesize` mediumint(9) unsigned default NULL,
  `width` smallint(9) unsigned default NULL,
  `height` smallint(9) unsigned default NULL,
  `coi` char(4) default NULL COMMENT 'center of interest',
  `representative_ext` varchar(4) default NULL,
  `date_metadata_update` date default NULL,
  `rating_score` float(5,2) unsigned default NULL,
  `path` varchar(255) NOT NULL default '',
  `storage_category_id` smallint(5) unsigned default NULL,
  `level` tinyint unsigned NOT NULL default '0',
  `md5sum` char(32) default NULL,
  `added_by` mediumint(8) unsigned default NULL,
  `rotation` tinyint unsigned default NULL,
  `latitude` double(8, 6) default NULL,
  `longitude` double(9, 6) default NULL,
  `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (`id`),
  KEY `images_i2` (`date_available`),
  KEY `images_i3` (`rating_score`),
  KEY `images_i4` (`hit`),
  KEY `images_i5` (`date_creation`),
  KEY `images_i1` (`storage_category_id`),
  KEY `images_i6` (`latitude`),
  KEY `images_i7` (`path`),
  KEY `images_added_by_idx` (`added_by`),
  KEY `lastmodified` (`lastmodified`),
  FULLTEXT KEY `images_ft_name_comment` (`name`, `comment`),
  FULLTEXT KEY `images_ft_author` (`author`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_integrity_ignored_anomalies`
--

DROP TABLE IF EXISTS `piwigo_integrity_ignored_anomalies`;
CREATE TABLE `piwigo_integrity_ignored_anomalies` (
  `anomaly_id` varchar(64) CHARACTER SET ascii NOT NULL,
  `piwigo_version` varchar(16) CHARACTER SET ascii NOT NULL,
  `ignored_at` datetime NOT NULL,
  PRIMARY KEY  (`anomaly_id`,`piwigo_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='integrity-check anomalies the admin has acknowledged (replaces c13y_ignore serialize() blob)';

--
-- Table structure for table `piwigo_languages`
--

DROP TABLE IF EXISTS `piwigo_languages`;
CREATE TABLE `piwigo_languages` (
  `id` varchar(64) NOT NULL default '',
  `version` varchar(64) NOT NULL default '0',
  `name` varchar(64) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_lounge`
--

DROP TABLE IF EXISTS `piwigo_lounge`;
CREATE TABLE `piwigo_lounge` (
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `category_id` smallint(5) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`image_id`,`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_old_permalinks`
--

DROP TABLE IF EXISTS `piwigo_old_permalinks`;
CREATE TABLE `piwigo_old_permalinks` (
  `cat_id` smallint(5) unsigned NOT NULL default '0',
  `permalink` varchar(64) COLLATE utf8mb4_bin NOT NULL default '',
  `date_deleted` datetime DEFAULT NULL,
  `last_hit` datetime default NULL,
  `hit` int(10) unsigned NOT NULL default '0',
  PRIMARY KEY  (`permalink`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_plugins`
--

DROP TABLE IF EXISTS `piwigo_plugins`;
CREATE TABLE `piwigo_plugins` (
  `id` varchar(64) COLLATE utf8mb4_bin NOT NULL default '',
  `state` enum('inactive','active') NOT NULL default 'inactive',
  `version` varchar(64) NOT NULL default '0',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_plugin_migrations`
--

DROP TABLE IF EXISTS `piwigo_plugin_migrations`;
CREATE TABLE `piwigo_plugin_migrations` (
  `plugin_id` varchar(64) CHARACTER SET ascii NOT NULL,
  `version` varchar(191) CHARACTER SET ascii NOT NULL,
  `executed_at` datetime NOT NULL,
  PRIMARY KEY (`plugin_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_rate`
--

DROP TABLE IF EXISTS `piwigo_rate`;
CREATE TABLE `piwigo_rate` (
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `element_id` mediumint(8) unsigned NOT NULL default '0',
  `anonymous_id` varchar(45) NOT NULL default '',
  `rate` tinyint(2) unsigned NOT NULL default '0',
  `date` date DEFAULT NULL,
  PRIMARY KEY  (`element_id`,`user_id`,`anonymous_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_search`
--

DROP TABLE IF EXISTS `piwigo_search`;
CREATE TABLE `piwigo_search` (
  `id` int(10) unsigned NOT NULL auto_increment,
  `search_uuid` CHAR(23) DEFAULT NULL,
  `created_on` DATETIME DEFAULT NULL,
  `created_by` MEDIUMINT(8) UNSIGNED,
  `forked_from` INT(10) UNSIGNED,
  `rules` json DEFAULT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_search_filter_view`
--

DROP TABLE IF EXISTS `piwigo_search_filter_view`;
CREATE TABLE `piwigo_search_filter_view` (
  `name` varchar(64) CHARACTER SET ascii NOT NULL,
  `config_json` json NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY  (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='admin search filter-view presets (replaces filters_views serialize() blob)';

--
-- Table structure for table `piwigo_sessions`
--

DROP TABLE IF EXISTS `piwigo_sessions`;
CREATE TABLE `piwigo_sessions` (
  `id` varchar(50) COLLATE utf8mb4_bin NOT NULL default '',
  `data` mediumtext NOT NULL,
  `expiration` datetime DEFAULT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_sites`
--

DROP TABLE IF EXISTS `piwigo_sites`;
CREATE TABLE `piwigo_sites` (
  `id` tinyint(4) NOT NULL auto_increment,
  `galleries_url` varchar(255) NOT NULL default '',
  PRIMARY KEY  (`id`),
  UNIQUE KEY `sites_ui1` (`galleries_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_tags`
--

DROP TABLE IF EXISTS `piwigo_tags`;
CREATE TABLE `piwigo_tags` (
  `id` smallint(5) unsigned NOT NULL auto_increment,
  `name` varchar(255) NOT NULL default '',
  `url_name` varchar(255) COLLATE utf8mb4_bin NOT NULL default '',
  `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (`id`),
  KEY `tags_i1` (`url_name`),
  KEY `lastmodified` (`lastmodified`),
  FULLTEXT KEY `tags_ft_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_themes`
--

DROP TABLE IF EXISTS `piwigo_themes`;
CREATE TABLE `piwigo_themes` (
  `id` varchar(64) NOT NULL default '',
  `version` varchar(64) NOT NULL default '0',
  `name` varchar(64) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_upgrade`
--

DROP TABLE IF EXISTS `piwigo_upgrade`;
CREATE TABLE `piwigo_upgrade` (
  `id` varchar(20) NOT NULL default '',
  `applied` datetime DEFAULT NULL,
  `description` varchar(255) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_user_access`
--

DROP TABLE IF EXISTS `piwigo_user_access`;
CREATE TABLE `piwigo_user_access` (
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `cat_id` smallint(5) unsigned NOT NULL default '0',
  PRIMARY KEY  (`user_id`,`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_user_auth_keys`
--

DROP TABLE IF EXISTS `piwigo_user_auth_keys`;
CREATE TABLE `piwigo_user_auth_keys` (
  `auth_key_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `auth_key` varchar(255) NOT NULL,
  `apikey_secret` VARCHAR(255) DEFAULT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `created_on` datetime NOT NULL,
  `duration` int(11) unsigned DEFAULT NULL,
  `expired_on` datetime NOT NULL,
  `apikey_name` VARCHAR(100) DEFAULT NULL,
  `key_type` VARCHAR(40) DEFAULT NULL,
  `revoked_on`  datetime DEFAULT NULL,
  `last_used_on` datetime DEFAULT NULL,
  `last_notified_on` datetime DEFAULT NULL,
  PRIMARY KEY (`auth_key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_user_cache`
--

DROP TABLE IF EXISTS `piwigo_user_cache`;
CREATE TABLE `piwigo_user_cache` (
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `need_update` tinyint(1) unsigned NOT NULL default 1,
  `cache_update_time` integer unsigned NOT NULL default 0,
  `forbidden_categories` JSON DEFAULT NULL,
  `nb_total_images` mediumint(8) unsigned default NULL,
  `last_photo_date` datetime DEFAULT NULL,
  `nb_available_tags` INT(5) DEFAULT NULL,
  `nb_available_comments` INT(5) DEFAULT NULL,
  `image_access_type` enum('NOT IN','IN') NOT NULL default 'NOT IN',
  `image_access_list` JSON DEFAULT NULL,
  PRIMARY KEY  (`user_id`),
  KEY `user_cache_need_update_idx` (`need_update`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_user_cache_categories`
--

DROP TABLE IF EXISTS `piwigo_user_cache_categories`;
CREATE TABLE `piwigo_user_cache_categories` (
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `cat_id` smallint(5) unsigned NOT NULL default '0',
  `date_last` datetime default NULL,
  `max_date_last` datetime default NULL,
  `nb_images` mediumint(8) unsigned NOT NULL default '0',
  `count_images` mediumint(8) unsigned default '0',
  `nb_categories` mediumint(8) unsigned default '0',
  `count_categories` mediumint(8) unsigned default '0',
  `user_representative_picture_id` mediumint(8) unsigned default NULL,
  PRIMARY KEY  (`user_id`,`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_user_feed`
--

DROP TABLE IF EXISTS `piwigo_user_feed`;
CREATE TABLE `piwigo_user_feed` (
  `id` varchar(50) COLLATE utf8mb4_bin NOT NULL default '',
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `last_check` datetime default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_user_group`
--

DROP TABLE IF EXISTS `piwigo_user_group`;
CREATE TABLE `piwigo_user_group` (
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `group_id` smallint(5) unsigned NOT NULL default '0',
  PRIMARY KEY  (`group_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_user_infos`
--

DROP TABLE IF EXISTS `piwigo_user_infos`;
CREATE TABLE `piwigo_user_infos` (
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `nb_image_page` smallint(3) unsigned NOT NULL default '15',
  `status` enum('webmaster','admin','normal','generic','guest') NOT NULL default 'guest',
  `language` varchar(50) NOT NULL default 'en_UK',
  `expand` tinyint(1) unsigned NOT NULL default 0,
  `show_nb_comments` tinyint(1) unsigned NOT NULL default 0,
  `show_nb_hits` tinyint(1) unsigned NOT NULL default 0,
  `recent_period` tinyint(3) unsigned NOT NULL default '7',
  `theme` varchar(255) NOT NULL default 'modus',
  `registration_date` datetime DEFAULT NULL,
  `enabled_high` tinyint(1) unsigned NOT NULL default 1,
  `level` tinyint unsigned NOT NULL default '0',
  `activation_key` varchar(255) default NULL,
  `activation_key_expire` datetime default NULL,
  `last_visit` datetime default NULL,
  `last_visit_from_history` tinyint(1) unsigned NOT NULL default 0,
  `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `preferences` json DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_user_mail_notification`
--

DROP TABLE IF EXISTS `piwigo_user_mail_notification`;
CREATE TABLE `piwigo_user_mail_notification` (
  `user_id` mediumint(8) unsigned NOT NULL default '0',
  `check_key` varchar(16) COLLATE utf8mb4_bin NOT NULL default '',
  `enabled` tinyint(1) unsigned NOT NULL default 0,
  `last_send` datetime default NULL,
  PRIMARY KEY  (`user_id`),
  UNIQUE KEY `user_mail_notification_ui1` (`check_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `piwigo_users`
--

DROP TABLE IF EXISTS `piwigo_users`;
CREATE TABLE `piwigo_users` (
  `id` mediumint(8) unsigned NOT NULL auto_increment,
  `username` varchar(100) COLLATE utf8mb4_bin NOT NULL default '',
  `password` varchar(255) default NULL,
  `mail_address` varchar(255) default NULL,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `users_ui1` (`username`),
  UNIQUE KEY `users_mail_idx` (`mail_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Foreign-key constraints.
-- ON DELETE CASCADE: deleting the parent removes child rows (junction
--   tables and rows that only exist as children of their parent).
-- ON DELETE SET NULL: deleting the parent leaves the child but blanks
--   the link (audit metadata, optional ownership pointers, hierarchies
--   that should promote children to root rather than vanish).
-- Three FKs are intentionally NOT declared because the column uses 0 as
-- a "system / anonymous" sentinel that has no users(id)=0 row:
--   piwigo_activity.performed_by  (read at MaintenanceController.php:641)
--   piwigo_history.user_id        (guest/unauthenticated browse tracking)
--   piwigo_rate.user_id           (anonymous rate rows; pairs with anonymous_id)
--

ALTER TABLE `piwigo_image_category`
  ADD CONSTRAINT `fk_image_category_image_id`    FOREIGN KEY (`image_id`)    REFERENCES `piwigo_images`(`id`)     ON DELETE CASCADE,
  ADD CONSTRAINT `fk_image_category_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_image_tag`
  ADD CONSTRAINT `fk_image_tag_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_image_tag_tag_id`   FOREIGN KEY (`tag_id`)   REFERENCES `piwigo_tags`(`id`)  ON DELETE CASCADE;

ALTER TABLE `piwigo_image_format`
  ADD CONSTRAINT `fk_image_format_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_comments`
  ADD CONSTRAINT `fk_comments_image_id`  FOREIGN KEY (`image_id`)  REFERENCES `piwigo_images`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_comments_author_id` FOREIGN KEY (`author_id`) REFERENCES `piwigo_users`(`id`)  ON DELETE SET NULL;

ALTER TABLE `piwigo_favorites`
  ADD CONSTRAINT `fk_favorites_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_favorites_user_id`  FOREIGN KEY (`user_id`)  REFERENCES `piwigo_users`(`id`)  ON DELETE CASCADE;

ALTER TABLE `piwigo_user_access`
  ADD CONSTRAINT `fk_user_access_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users`(`id`)      ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_access_cat_id`  FOREIGN KEY (`cat_id`)  REFERENCES `piwigo_categories`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_user_group`
  ADD CONSTRAINT `fk_user_group_user_id`  FOREIGN KEY (`user_id`)  REFERENCES `piwigo_users`(`id`)  ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_group_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_group_access`
  ADD CONSTRAINT `fk_group_access_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups`(`id`)     ON DELETE CASCADE,
  ADD CONSTRAINT `fk_group_access_cat_id`   FOREIGN KEY (`cat_id`)   REFERENCES `piwigo_categories`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_user_cache`
  ADD CONSTRAINT `fk_user_cache_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_user_cache_categories`
  ADD CONSTRAINT `fk_user_cache_categories_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users`(`id`)      ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_cache_categories_cat_id`  FOREIGN KEY (`cat_id`)  REFERENCES `piwigo_categories`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_user_infos`
  ADD CONSTRAINT `fk_user_infos_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_user_feed`
  ADD CONSTRAINT `fk_user_feed_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_user_mail_notification`
  ADD CONSTRAINT `fk_user_mail_notification_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_user_auth_keys`
  ADD CONSTRAINT `fk_user_auth_keys_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_caddie`
  ADD CONSTRAINT `fk_caddie_user_id`    FOREIGN KEY (`user_id`)    REFERENCES `piwigo_users`(`id`)  ON DELETE CASCADE,
  ADD CONSTRAINT `fk_caddie_element_id` FOREIGN KEY (`element_id`) REFERENCES `piwigo_images`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_lounge`
  ADD CONSTRAINT `fk_lounge_image_id`    FOREIGN KEY (`image_id`)    REFERENCES `piwigo_images`(`id`)     ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lounge_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_rate`
  ADD CONSTRAINT `fk_rate_element_id` FOREIGN KEY (`element_id`) REFERENCES `piwigo_images`(`id`) ON DELETE CASCADE;

ALTER TABLE `piwigo_categories`
  ADD CONSTRAINT `fk_categories_id_uppercat`               FOREIGN KEY (`id_uppercat`)               REFERENCES `piwigo_categories`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_categories_representative_picture_id` FOREIGN KEY (`representative_picture_id`) REFERENCES `piwigo_images`(`id`)     ON DELETE SET NULL;

ALTER TABLE `piwigo_images`
  ADD CONSTRAINT `fk_images_storage_category_id` FOREIGN KEY (`storage_category_id`) REFERENCES `piwigo_categories`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_images_added_by`            FOREIGN KEY (`added_by`)            REFERENCES `piwigo_users`(`id`)      ON DELETE SET NULL;

ALTER TABLE `piwigo_history`
  ADD CONSTRAINT `fk_history_image_id`    FOREIGN KEY (`image_id`)    REFERENCES `piwigo_images`(`id`)         ON DELETE SET NULL,
  ADD CONSTRAINT `fk_history_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories`(`id`)     ON DELETE SET NULL,
  ADD CONSTRAINT `fk_history_search_id`   FOREIGN KEY (`search_id`)   REFERENCES `piwigo_search`(`id`)         ON DELETE SET NULL,
  ADD CONSTRAINT `fk_history_format_id`   FOREIGN KEY (`format_id`)   REFERENCES `piwigo_image_format`(`format_id`)   ON DELETE SET NULL,
  ADD CONSTRAINT `fk_history_auth_key_id` FOREIGN KEY (`auth_key_id`) REFERENCES `piwigo_user_auth_keys`(`auth_key_id`) ON DELETE SET NULL;

ALTER TABLE `piwigo_search`
  ADD CONSTRAINT `fk_search_created_by` FOREIGN KEY (`created_by`)  REFERENCES `piwigo_users`(`id`)  ON DELETE SET NULL,
  ADD CONSTRAINT `fk_search_forked_from` FOREIGN KEY (`forked_from`) REFERENCES `piwigo_search`(`id`) ON DELETE SET NULL;
