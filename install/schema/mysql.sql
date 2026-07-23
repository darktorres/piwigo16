/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_activity` (
  `activity_id` int unsigned NOT NULL AUTO_INCREMENT,
  `object` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_id` int unsigned NOT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `performed_by` mediumint unsigned DEFAULT NULL,
  `session_idx` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occured_on` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `details` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`activity_id`),
  KEY `IDX_BCC601B599EB8EA2` (`performed_by`),
  CONSTRAINT `fk_activity_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` mediumint unsigned DEFAULT NULL,
  `action` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` int unsigned DEFAULT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `prev_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `row_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_log_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_log_actor` (`actor_id`),
  KEY `idx_audit_log_created_at` (`created_at`),
  CONSTRAINT `fk_audit_log_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_caddie` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `element_id` mediumint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`,`element_id`),
  KEY `IDX_468BA6E9A76ED395` (`user_id`),
  KEY `IDX_468BA6E91F1F2A24` (`element_id`),
  CONSTRAINT `fk_caddie_element_id` FOREIGN KEY (`element_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_caddie_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_categories` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id_uppercat` smallint unsigned DEFAULT NULL,
  `comment` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `dir` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rank` smallint unsigned DEFAULT NULL,
  `status` enum('public','private') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `site_id` tinyint unsigned DEFAULT NULL,
  `visible` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'true',
  `representative_picture_id` mediumint unsigned DEFAULT NULL,
  `uppercats` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `commentable` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'true',
  `global_rank` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_order` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_i3` (`permalink`),
  KEY `categories_i2` (`id_uppercat`),
  KEY `lastmodified` (`lastmodified`),
  KEY `IDX_EDCE31CB9E69CEC8` (`representative_picture_id`),
  FULLTEXT KEY `categories_ft_name_comment` (`name`,`comment`),
  CONSTRAINT `fk_categories_id_uppercat` FOREIGN KEY (`id_uppercat`) REFERENCES `piwigo_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_categories_representative_picture_id` FOREIGN KEY (`representative_picture_id`) REFERENCES `piwigo_images` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `date` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `author` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author_id` mediumint unsigned DEFAULT NULL,
  `anonymous_id` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `website_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `validated` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `validation_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comments_i2` (`validation_date`),
  KEY `comments_i1` (`image_id`),
  KEY `IDX_4F2C9EC5F675F31B` (`author_id`),
  CONSTRAINT `fk_comments_author_id` FOREIGN KEY (`author_id`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comments_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_config` (
  `param` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `comment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='configuration table';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_derivative_settings` (
  `id` smallint NOT NULL,
  `default_quality` int NOT NULL DEFAULT '95',
  `watermark_json` json NOT NULL,
  `custom_json` json NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_derivative_size` (
  `name` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` smallint NOT NULL DEFAULT '1',
  `max_width` int NOT NULL DEFAULT '0',
  `max_height` int NOT NULL DEFAULT '0',
  `max_crop` decimal(5,4) NOT NULL DEFAULT '0.0000',
  `min_width` int DEFAULT NULL,
  `min_height` int DEFAULT NULL,
  `sharpen` decimal(5,4) NOT NULL DEFAULT '0.0000',
  `last_mod_time` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_extension_ignored_updates` (
  `extension_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ignored_at` datetime NOT NULL,
  PRIMARY KEY (`extension_type`,`extension_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_favorites` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`,`image_id`),
  KEY `IDX_D4CC2D143DA5256D` (`image_id`),
  KEY `IDX_D4CC2D14A76ED395` (`user_id`),
  CONSTRAINT `fk_favorites_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_favorites_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_group_access` (
  `group_id` smallint unsigned NOT NULL DEFAULT '0',
  `cat_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`group_id`,`cat_id`),
  KEY `IDX_9EA42F0CFE54D947` (`group_id`),
  KEY `IDX_9EA42F0CE6ADA943` (`cat_id`),
  CONSTRAINT `fk_group_access_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_access_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_groups` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_default` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groups_ui1` (`name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL DEFAULT '1970-01-01',
  `time` time NOT NULL DEFAULT '00:00:00',
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `IP` char(39) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `section` enum('categories','tags','search','list','favorites','most_visited','best_rated','recent_pics','recent_cats') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` smallint unsigned DEFAULT NULL,
  `search_id` int unsigned DEFAULT NULL,
  `tag_ids` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_id` mediumint unsigned DEFAULT NULL,
  `image_type` enum('picture','high','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `format_id` int unsigned DEFAULT NULL,
  `auth_key_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_4FC8C5D13DA5256D` (`image_id`),
  KEY `IDX_4FC8C5D112469DE2` (`category_id`),
  KEY `IDX_4FC8C5D1650760A9` (`search_id`),
  KEY `IDX_4FC8C5D1D629F605` (`format_id`),
  KEY `IDX_4FC8C5D12DB7F2D1` (`auth_key_id`),
  KEY `IDX_4FC8C5D1A76ED395` (`user_id`),
  KEY `idx_history_date_desc` (`date` DESC,`id` DESC),
  CONSTRAINT `fk_history_auth_key_id` FOREIGN KEY (`auth_key_id`) REFERENCES `piwigo_user_auth_keys` (`auth_key_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_format_id` FOREIGN KEY (`format_id`) REFERENCES `piwigo_image_format` (`format_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_search_id` FOREIGN KEY (`search_id`) REFERENCES `piwigo_search` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_history_summary` (
  `year` smallint NOT NULL DEFAULT '0',
  `month` tinyint DEFAULT NULL,
  `day` tinyint DEFAULT NULL,
  `hour` tinyint DEFAULT NULL,
  `nb_pages` int DEFAULT NULL,
  `history_id_from` int unsigned DEFAULT NULL,
  `history_id_to` int unsigned DEFAULT NULL,
  UNIQUE KEY `history_summary_ymdh` (`year`,`month`,`day`,`hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_image_category` (
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `category_id` smallint unsigned NOT NULL DEFAULT '0',
  `rank` mediumint unsigned DEFAULT NULL,
  PRIMARY KEY (`image_id`,`category_id`),
  KEY `image_category_i1` (`category_id`),
  KEY `IDX_F583509E3DA5256D` (`image_id`),
  CONSTRAINT `fk_image_category_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_image_category_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_image_format` (
  `format_id` int unsigned NOT NULL AUTO_INCREMENT,
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `ext` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `filesize` mediumint unsigned DEFAULT NULL,
  PRIMARY KEY (`format_id`),
  KEY `IDX_3AECF09F3DA5256D` (`image_id`),
  CONSTRAINT `fk_image_format_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_image_tag` (
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `tag_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`image_id`,`tag_id`),
  KEY `image_tag_i1` (`tag_id`),
  KEY `IDX_6BC62A313DA5256D` (`image_id`),
  CONSTRAINT `fk_image_tag_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_image_tag_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `piwigo_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_images` (
  `id` mediumint unsigned NOT NULL AUTO_INCREMENT,
  `file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `date_available` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `date_creation` datetime DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `author` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hit` int unsigned NOT NULL DEFAULT '0',
  `filesize` mediumint unsigned DEFAULT NULL,
  `width` smallint unsigned DEFAULT NULL,
  `height` smallint unsigned DEFAULT NULL,
  `coi` char(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'center of interest',
  `representative_ext` varchar(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_metadata_update` date DEFAULT NULL,
  `rating_score` float(5,2) unsigned DEFAULT NULL,
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `storage_category_id` smallint unsigned DEFAULT NULL,
  `level` tinyint unsigned NOT NULL DEFAULT '0',
  `md5sum` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_by` mediumint unsigned DEFAULT NULL,
  `rotation` tinyint unsigned DEFAULT NULL,
  `latitude` double(8,6) DEFAULT NULL,
  `longitude` double(9,6) DEFAULT NULL,
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `images_i2` (`date_available`),
  KEY `images_i3` (`rating_score`),
  KEY `images_i4` (`hit`),
  KEY `images_i5` (`date_creation`),
  KEY `images_i1` (`storage_category_id`),
  KEY `images_i6` (`latitude`),
  KEY `images_i7` (`path`),
  KEY `lastmodified` (`lastmodified`),
  KEY `IDX_4F19DCB8699B6BAF` (`added_by`),
  KEY `idx_images_date_desc` (`date_available` DESC,`id` DESC),
  FULLTEXT KEY `images_ft_name_comment` (`name`,`comment`),
  FULLTEXT KEY `images_ft_author` (`author`),
  CONSTRAINT `fk_images_added_by` FOREIGN KEY (`added_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_images_storage_category_id` FOREIGN KEY (`storage_category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_integrity_ignored_anomalies` (
  `anomaly_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `piwigo_version` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ignored_at` datetime NOT NULL,
  PRIMARY KEY (`anomaly_id`,`piwigo_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_languages` (
  `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_lounge` (
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `category_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`image_id`,`category_id`),
  KEY `IDX_735C32FF3DA5256D` (`image_id`),
  KEY `IDX_735C32FF12469DE2` (`category_id`),
  CONSTRAINT `fk_lounge_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lounge_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_old_permalinks` (
  `cat_id` smallint unsigned NOT NULL DEFAULT '0',
  `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `date_deleted` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `last_hit` datetime DEFAULT NULL,
  `hit` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`permalink`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_plugin_migrations` (
  `plugin_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `executed_at` datetime NOT NULL,
  PRIMARY KEY (`plugin_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_plugins` (
  `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `state` enum('inactive','active') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inactive',
  `version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_rate` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `element_id` mediumint unsigned NOT NULL DEFAULT '0',
  `anonymous_id` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `rate` tinyint unsigned NOT NULL DEFAULT '0',
  `date` date NOT NULL DEFAULT '1970-01-01',
  PRIMARY KEY (`element_id`,`user_id`,`anonymous_id`),
  KEY `IDX_1069AEF21F1F2A24` (`element_id`),
  KEY `IDX_1069AEF2A76ED395` (`user_id`),
  CONSTRAINT `fk_rate_element_id` FOREIGN KEY (`element_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rate_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_search` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `search_uuid` char(23) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_on` datetime DEFAULT NULL,
  `created_by` mediumint unsigned DEFAULT NULL,
  `forked_from` int unsigned DEFAULT NULL,
  `rules` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `IDX_1BF6B975DE12AB56` (`created_by`),
  KEY `IDX_1BF6B975C828EDAC` (`forked_from`),
  CONSTRAINT `fk_search_created_by` FOREIGN KEY (`created_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_search_forked_from` FOREIGN KEY (`forked_from`) REFERENCES `piwigo_search` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_search_filter_view` (
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_json` json NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_sessions` (
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_sites` (
  `id` tinyint NOT NULL AUTO_INCREMENT,
  `galleries_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `sites_ui1` (`galleries_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_tags` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `url_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tags_i1` (`url_name`),
  KEY `lastmodified` (`lastmodified`),
  FULLTEXT KEY `tags_ft_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_themes` (
  `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_upgrade` (
  `id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `applied` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_access` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `cat_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`,`cat_id`),
  KEY `IDX_2C33FF4CA76ED395` (`user_id`),
  KEY `IDX_2C33FF4CE6ADA943` (`cat_id`),
  CONSTRAINT `fk_user_access_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_access_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_auth_keys` (
  `auth_key_id` int unsigned NOT NULL AUTO_INCREMENT,
  `auth_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `apikey_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` mediumint unsigned NOT NULL,
  `created_on` datetime NOT NULL,
  `duration` int unsigned DEFAULT NULL,
  `expired_on` datetime NOT NULL,
  `apikey_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `key_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `revoked_on` datetime DEFAULT NULL,
  `last_used_on` datetime DEFAULT NULL,
  `last_notified_on` datetime DEFAULT NULL,
  PRIMARY KEY (`auth_key_id`),
  KEY `IDX_E677EE4BA76ED395` (`user_id`),
  CONSTRAINT `fk_user_auth_keys_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_cache` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `need_update` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'true',
  `cache_update_time` int unsigned NOT NULL DEFAULT '0',
  `forbidden_categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `nb_total_images` mediumint unsigned DEFAULT NULL,
  `last_photo_date` datetime DEFAULT NULL,
  `nb_available_tags` int DEFAULT NULL,
  `nb_available_comments` int DEFAULT NULL,
  `image_access_type` enum('NOT IN','IN') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NOT IN',
  `image_access_list` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_cache_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_cache_categories` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `cat_id` smallint unsigned NOT NULL DEFAULT '0',
  `date_last` datetime DEFAULT NULL,
  `max_date_last` datetime DEFAULT NULL,
  `nb_images` mediumint unsigned NOT NULL DEFAULT '0',
  `count_images` mediumint unsigned DEFAULT '0',
  `nb_categories` mediumint unsigned DEFAULT '0',
  `count_categories` mediumint unsigned DEFAULT '0',
  `user_representative_picture_id` mediumint unsigned DEFAULT NULL,
  PRIMARY KEY (`user_id`,`cat_id`),
  KEY `IDX_F3CC0BFBA76ED395` (`user_id`),
  KEY `IDX_F3CC0BFBE6ADA943` (`cat_id`),
  CONSTRAINT `fk_user_cache_categories_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_cache_categories_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_failed_logins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint unsigned DEFAULT NULL,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_failed_logins_user_time` (`user_id`,`attempted_at`),
  KEY `idx_user_failed_logins_ip_time` (`ip`,`attempted_at`),
  KEY `IDX_B27CBE76A76ED395` (`user_id`),
  CONSTRAINT `fk_user_failed_logins_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_feed` (
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `last_check` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_69644583A76ED395` (`user_id`),
  CONSTRAINT `fk_user_feed_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_group` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `group_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`group_id`,`user_id`),
  KEY `IDX_583FC83EA76ED395` (`user_id`),
  KEY `IDX_583FC83EFE54D947` (`group_id`),
  CONSTRAINT `fk_user_group_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_group_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_infos` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `nb_image_page` smallint unsigned NOT NULL DEFAULT '15',
  `status` enum('webmaster','admin','normal','generic','guest') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'guest',
  `language` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_UK',
  `expand` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `show_nb_comments` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `show_nb_hits` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `recent_period` tinyint unsigned NOT NULL DEFAULT '7',
  `theme` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'modus',
  `registration_date` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `enabled_high` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'true',
  `level` tinyint unsigned NOT NULL DEFAULT '0',
  `activation_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activation_key_expire` datetime DEFAULT NULL,
  `last_visit` datetime DEFAULT NULL,
  `last_visit_from_history` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `preferences` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`user_id`),
  KEY `lastmodified` (`lastmodified`),
  CONSTRAINT `fk_user_infos_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_mail_notification` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `check_key` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `enabled` enum('true','false') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `last_send` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_mail_notification_ui1` (`check_key`),
  CONSTRAINT `fk_user_mail_notification_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_users` (
  `id` mediumint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mail_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_ui1` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
