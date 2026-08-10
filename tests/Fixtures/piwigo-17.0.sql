SET SESSION innodb_ft_enable_stopword = 0;
-- MySQL dump 10.13  Distrib 8.4.10, for Linux (x86_64)
--
-- Host: 127.0.0.1    Database: piwigo17_sql_test
-- ------------------------------------------------------
-- Server version	8.4.10-0ubuntu0.26.04.1

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

--
-- Table structure for table `activity`
--

DROP TABLE IF EXISTS `activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity` (
  `activity_id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `object` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'entity type the action applies to, e.g. user, photo, album, tag, plugin',
  `object_id` int unsigned NOT NULL COMMENT 'id of the affected object, or the target user id on a logout action',
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'action verb, e.g. add, delete, login, logout, autoupdate',
  `performed_by` mediumint unsigned DEFAULT NULL COMMENT 'acting user id, null for an unresolved or system actor',
  `session_idx` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'PHP session id active during the request, or none if there was no session',
  `ip_address` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'REMOTE_ADDR of the request that triggered the action',
  `occured_on` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'when the action was recorded',
  `details` json DEFAULT NULL COMMENT 'per-action heterogeneous payload, e.g. config diffs, batch-edit fields, install metadata',
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'browser user agent string, only captured on login actions',
  PRIMARY KEY (`activity_id`),
  KEY `fk_activity_performed_by` (`performed_by`),
  CONSTRAINT `fk_activity_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='general activity log of user and system actions, distinct from the tamper-evident audit_log';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity`
--

LOCK TABLES `activity` WRITE;
/*!40000 ALTER TABLE `activity` DISABLE KEYS */;
INSERT INTO `activity` VALUES (1,'system',3,'activate',NULL,'none','::1','2026-08-01 03:00:00','{\"script\": \"install\", \"theme_id\": \"default\"}',NULL),(2,'system',1,'install',NULL,'none','::1','2026-08-01 03:00:00','{\"script\": \"install\", \"version\": \"16.3.0\"}',NULL),(3,'user',1,'login',1,'aa35407a03d85ddb0c5cb929d012a4ee','::1','2026-08-01 03:00:00','{\"script\": \"install\"}','PiwigoFixtureRegen/1.0'),(4,'user',1,'login',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.session.login\"}','PiwigoFixtureRegen/1.0'),(5,'album',1,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.categories.add\"}',NULL),(6,'album',2,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.categories.add\"}',NULL),(7,'photo',1,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),(8,'photo',2,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),(9,'photo',3,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),(10,'photo',4,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),(11,'photo',5,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),(12,'tag',1,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.tags.add\"}',NULL),(13,'tag',2,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.tags.add\"}',NULL),(14,'tag',3,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.tags.add\"}',NULL),(15,'user',3,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.users.add\"}',NULL),(16,'user',4,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.users.add\"}',NULL),(17,'group',1,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.groups.add\"}',NULL),(18,'group',2,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.groups.add\"}',NULL),(19,'group',3,'add',1,'8b26bb61a41ac7950c20dc9236118216','::1','2026-08-01 03:00:00','{\"method\": \"pwg.groups.add\"}',NULL);
/*!40000 ALTER TABLE `activity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `actor_id` mediumint unsigned DEFAULT NULL COMMENT 'acting user id, null for an unattributed or system action',
  `action` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'action verb, e.g. delete, grant, revoke',
  `entity_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'audited entity type, e.g. group, permission',
  `entity_id` int unsigned DEFAULT NULL COMMENT 'id of the audited entity, null when not applicable',
  `before_json` json DEFAULT NULL COMMENT 'entity-agnostic snapshot before the change, null for a creation, folded into row_hash so must stay exactly what was recorded',
  `after_json` json DEFAULT NULL COMMENT 'entity-agnostic snapshot after the change, null for a deletion, folded into row_hash so must stay exactly what was recorded',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'REMOTE_ADDR of the request that performed the action',
  `created_at` datetime NOT NULL COMMENT 'when the action was recorded',
  `prev_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'row_hash of the previous row, null for the first row, forms the hash chain',
  `row_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sha256 of this row content plus prev_hash, tamper-evidence for the chain, see AuditService::computeHash',
  PRIMARY KEY (`id`),
  KEY `idx_audit_log_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_log_actor` (`actor_id`),
  KEY `idx_audit_log_created_at` (`created_at`),
  CONSTRAINT `fk_audit_log_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SEC-57 append-only, hash-chained audit trail of admin actions and permission changes';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (1,1,'create','group',1,NULL,'{\"name\": \"Editors\"}','::1','2026-08-01 00:00:00',NULL,'fb08569e0a181f3c519a47ed7faed20c576f9aee1d4ba3361bfb5d9bc9ffbbe0'),(2,1,'create','group',2,NULL,'{\"name\": \"Reviewers\"}','::1','2026-08-01 00:00:00','fb08569e0a181f3c519a47ed7faed20c576f9aee1d4ba3361bfb5d9bc9ffbbe0','425622dddcec305339ccef4d45d997d899b77bcd4fc4ead48ff7df01a6dbc53b'),(3,1,'create','group',3,NULL,'{\"name\": \"Guests\"}','::1','2026-08-01 00:00:00','425622dddcec305339ccef4d45d997d899b77bcd4fc4ead48ff7df01a6dbc53b','964f6fe6c7316cb7897260dffb899337c55e0d85c47c386f131533c5b209052f');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caddie`
--

DROP TABLE IF EXISTS `caddie`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `caddie` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'owning user id',
  `element_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'image id added to the caddie',
  PRIMARY KEY (`user_id`,`element_id`),
  KEY `fk_caddie_element_id` (`element_id`),
  CONSTRAINT `fk_caddie_element_id` FOREIGN KEY (`element_id`) REFERENCES `images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_caddie_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user temporary photo selection (caddie/basket) used by batch operations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caddie`
--

LOCK TABLES `caddie` WRITE;
/*!40000 ALTER TABLE `caddie` DISABLE KEYS */;
/*!40000 ALTER TABLE `caddie` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'album display name',
  `id_uppercat` smallint unsigned DEFAULT NULL COMMENT 'parent album id, null for a root album',
  `comment` text COLLATE utf8mb4_unicode_ci COMMENT 'album description shown on its page',
  `dir` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'filesystem subdirectory name for a physical, synchronized album, null for a virtual album',
  `rank` smallint unsigned DEFAULT NULL COMMENT 'sibling display order within the same parent, distinct from global_rank',
  `status` enum('public','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public' COMMENT 'private albums require an explicit user_access or group_access grant to view',
  `site_id` tinyint unsigned DEFAULT NULL COMMENT 'owning site id, resolves to sites.galleries_url for a physical album',
  `visible` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'whether the album is shown in navigation, forced false at creation if its parent is not visible',
  `representative_picture_id` mediumint unsigned DEFAULT NULL COMMENT 'image id used as the album thumbnail',
  `uppercats` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'comma-separated ancestor album id path, from root to this album',
  `commentable` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'whether photo comments are allowed for images in this album',
  `global_rank` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'full-tree sort key derived from rank along the ancestor path, used to order albums across different parents',
  `image_order` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'preferred ORDER BY expression for images in this album, inheritable to descendant albums',
  `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'unique URL-friendly slug for this album',
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_i3` (`permalink`),
  KEY `categories_i2` (`id_uppercat`),
  KEY `lastmodified` (`lastmodified`),
  KEY `fk_categories_representative_picture_id` (`representative_picture_id`),
  FULLTEXT KEY `categories_ft_name_comment` (`name`,`comment`) /*!50100 WITH PARSER `ngram` */ ,
  CONSTRAINT `fk_categories_id_uppercat` FOREIGN KEY (`id_uppercat`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_categories_representative_picture_id` FOREIGN KEY (`representative_picture_id`) REFERENCES `images` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='photo albums, both physical filesystem-synced and virtual';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Sample Album',NULL,NULL,NULL,1,'public',NULL,1,1,'1',1,'1',NULL,NULL,'2026-08-10 17:20:40'),(2,'Nested Sub Album',1,NULL,NULL,1,'public',NULL,1,4,'1,2',1,'1.1',NULL,NULL,'2026-08-10 17:20:40');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comments`
--

DROP TABLE IF EXISTS `comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `image_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'commented image id',
  `date` datetime DEFAULT NULL COMMENT 'when the comment was submitted',
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'display name shown with the comment, the account username for a logged-in user or the guest-entered name otherwise',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'guest-provided email address',
  `author_id` mediumint unsigned DEFAULT NULL COMMENT 'commenting user id, null for a guest comment',
  `anonymous_id` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'full IP address of a guest commenter, used for anti-flood throttling',
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'guest-provided homepage link',
  `content` longtext COLLATE utf8mb4_unicode_ci COMMENT 'comment body',
  `validated` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'moderation approval flag, gates visibility when comments_validation is enabled',
  `validation_date` datetime DEFAULT NULL COMMENT 'when the comment was approved',
  PRIMARY KEY (`id`),
  KEY `comments_i2` (`validation_date`),
  KEY `comments_i1` (`image_id`),
  KEY `fk_comments_author_id` (`author_id`),
  CONSTRAINT `fk_comments_author_id` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comments_image_id` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='visitor comments left on photos';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comments`
--

LOCK TABLES `comments` WRITE;
/*!40000 ALTER TABLE `comments` DISABLE KEYS */;
INSERT INTO `comments` VALUES (1,1,'2026-08-01 00:00:00','fixture_admin',NULL,1,'127.0.0.1',NULL,'Fixture comment for integration tests.',1,'2026-08-01 00:00:00'),(2,2,'2026-08-01 00:00:00','regular_user',NULL,3,'127.0.0.2',NULL,'Another perspective on this photo.',1,'2026-08-01 00:00:00'),(3,3,'2026-08-01 00:00:00','power_user',NULL,4,'127.0.0.3',NULL,'Great composition and colors!',1,'2026-08-01 00:00:00'),(4,1,'2026-08-01 00:00:00','power_user',NULL,4,'127.0.0.3',NULL,'I keep coming back to this one.',1,'2026-08-01 00:00:00'),(5,4,'2026-08-01 00:00:00','fixture_admin',NULL,1,'127.0.0.1',NULL,'Pending comment for moderation.',0,NULL);
/*!40000 ALTER TABLE `comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `config`
--

DROP TABLE IF EXISTS `config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `config` (
  `param` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'configuration key',
  `value` json DEFAULT NULL COMMENT 'JSON-encoded configuration value, see ConfigService::encode()/hydrate()',
  `comment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'human-readable description of the param, seeded for built-in settings by install/config.sql',
  PRIMARY KEY (`param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='configuration table';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `config`
--

LOCK TABLES `config` WRITE;
/*!40000 ALTER TABLE `config` DISABLE KEYS */;
INSERT INTO `config` VALUES ('activate_comments','true','Global parameter for usage of comments system'),('allow_user_customization','true','allow users to customize their gallery?'),('allow_user_registration','true','allow visitors to register?'),('comments_author_mandatory','false','Comment author is mandatory'),('comments_email_mandatory','false','Comment email is mandatory'),('comments_enable_website','true','Enable \"website\" field on add comment form'),('comments_forall','false','even guest not registered can post comments'),('comments_order','\"ASC\"','comments order on picture page and cie'),('comments_validation','true','administrators validate users comments before becoming visible'),('dashboard_check_for_updates','false',NULL),('data_dir_checked','\"1\"',NULL),('display_fromto','false',NULL),('email_admin_on_comment','false','Send an email to the administrators when a valid comment is entered'),('email_admin_on_comment_deletion','false','Send an email to the administrators when a comment is deleted'),('email_admin_on_comment_edition','false','Send an email to the administrators when a comment is modified'),('email_admin_on_comment_validation','true','Send an email to the administrators when a comment requires validation'),('email_admin_on_new_user','\"none\"','Send an email to theadministrators when a user registers'),('extents_for_templates','[]','Actived template-extension(s)'),('gallery_locked','false','Lock your gallery temporary for non admin users'),('gallery_title','\"Fixture Gallery\"','Title at top of each page and for RSS feed'),('history_admin','false','keep a history of administrator visits on your website'),('history_guest','true','keep a history of guest visits on your website'),('index_caddie_icon','true',NULL),('index_created_date_icon','true','Display calendar by creation date icon'),('index_edit_icon','true',NULL),('index_flat_icon','false','Display flat icon'),('index_new_icon','true','Display new icons next albums and pictures'),('index_posted_date_icon','true','Display calendar by posted date'),('index_search_in_set_action','\"true\"',NULL),('index_search_in_set_button','false',NULL),('index_sizes_icon','true',NULL),('index_slideshow_icon','true','Display slideshow icon'),('index_sort_order_input','true','Display image order selection list'),('last_major_update','\"2026-08-01 00:00:00\"',NULL),('log','true','keep an history of visits on your website'),('lounge_active','true',NULL),('mail_theme','\"clear\"',NULL),('menubar_filter_icon','false','Display filter icon'),('mobile_theme',NULL,NULL),('nb_categories_page','12','Param for categories pagination'),('nb_comment_page','10','number of comments to display on each page'),('nbm_complementary_mail_content','\"\"','Complementary mail content for notification by mail'),('nbm_send_detailed_content','true','Send detailed content for notification by mail'),('nbm_send_html_mail','true','Send mail on HTML format for notification by mail'),('nbm_send_mail_as','\"\"','Send mail as param value for notification by mail'),('nbm_send_recent_post_dates','true','Send recent post by dates for notification by mail'),('no_photo_yet','\"false\"',NULL),('obligatory_user_mail_address','false','Mail address is obligatory for users'),('order_by','\"ORDER BY date_available DESC, file ASC, id ASC\"','default photo order'),('order_by_inside_category','\"ORDER BY date_available DESC, file ASC, id ASC\"','default photo order inside category'),('original_resize','false',NULL),('original_resize_maxheight','2016',NULL),('original_resize_maxwidth','2016',NULL),('original_resize_quality','95',NULL),('page_banner','\"<h1>%gallery_title%</h1>\\n\\n<p>Welcome to my photo gallery</p>\"','html displayed on the top each page of your gallery'),('picture_caddie_icon','true',NULL),('picture_download_icon','true','Display download icon on picture page'),('picture_edit_icon','true',NULL),('picture_favorite_icon','true','Display favorite icon on picture page'),('picture_informations','{\"file\": false, \"tags\": true, \"author\": true, \"visits\": true, \"filesize\": false, \"posted_on\": true, \"categories\": true, \"created_on\": true, \"dimensions\": false, \"rating_score\": true, \"privacy_level\": true}','Information displayed on picture page'),('picture_menu','false','Show menubar on picture page'),('picture_metadata_icon','true','Display metadata icon on picture page'),('picture_navigation_icons','true','Display navigation icons on picture page'),('picture_navigation_thumb','true','Display navigation thumbnails on picture page'),('picture_representative_icon','true',NULL),('picture_sizes_icon','true',NULL),('picture_slideshow_icon','true','Display slideshow icon on picture page'),('piwigo_installed_version','\"16.3.0\"',NULL),('rate','true','Rating pictures feature is enabled'),('rate_anonymous','true','Rating pictures feature is also enabled for visitors'),('secret_key','\"d18ea6c8c92232821e4a3916a6806d7c8782c505\"','a secret key specific to the gallery for internal use'),('show_mobile_app_banner_in_admin','true',NULL),('show_mobile_app_banner_in_gallery','false',NULL),('show_piwigo_latest_news','false',NULL),('upload_detect_duplicate','true',NULL),('use_standard_pages','true',NULL),('user_can_delete_comment','false','administrators can allow user delete their own comments'),('user_can_edit_comment','false','administrators can allow user edit their own comments'),('webmaster_id','1',NULL),('week_starts_on','\"monday\"','Monday may not be the first day of the week');
/*!40000 ALTER TABLE `config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `derivative_settings`
--

DROP TABLE IF EXISTS `derivative_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `derivative_settings` (
  `id` smallint NOT NULL COMMENT 'settings row identifier',
  `default_quality` int NOT NULL DEFAULT '95' COMMENT 'default JPEG compression quality, 0 to 100, for generated derivative images',
  `watermark_json` json NOT NULL COMMENT 'encoded watermark configuration',
  `custom_json` json NOT NULL COMMENT 'encoded custom derivative-generation parameters',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='global derivative-image generation settings, read and written by ImageStdParams via DerivativeSettingsRepository';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `derivative_settings`
--

LOCK TABLES `derivative_settings` WRITE;
/*!40000 ALTER TABLE `derivative_settings` DISABLE KEYS */;
INSERT INTO `derivative_settings` VALUES (1,95,'{\"file\": \"\", \"xpos\": 50, \"ypos\": 50, \"opacity\": 100, \"xrepeat\": 0, \"yrepeat\": 0, \"min_size\": [500, 500]}','[]');
/*!40000 ALTER TABLE `derivative_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `derivative_size`
--

DROP TABLE IF EXISTS `derivative_size`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `derivative_size` (
  `name` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'derivative size name, e.g. thumb, medium, xxlarge',
  `enabled` smallint NOT NULL DEFAULT '1' COMMENT 'whether this derivative size is generated',
  `max_width` int NOT NULL DEFAULT '0' COMMENT 'maximum output width in pixels, see SizingParams',
  `max_height` int NOT NULL DEFAULT '0' COMMENT 'maximum output height in pixels, see SizingParams',
  `max_crop` decimal(5,4) NOT NULL DEFAULT '0.0000' COMMENT 'cropping ratio from 0, no cropping, to 1, max cropping, see SizingParams::max_crop',
  `min_width` int DEFAULT NULL COMMENT 'minimum output width required to allow cropping, see SizingParams::min_size',
  `min_height` int DEFAULT NULL COMMENT 'minimum output height required to allow cropping, see SizingParams::min_size',
  `sharpen` decimal(5,4) NOT NULL DEFAULT '0.0000' COMMENT 'sharpening amount from 0, none, to 1, max, see DerivativeParams::sharpen',
  `last_mod_time` int NOT NULL DEFAULT '0' COMMENT 'unix timestamp of the last parameter change, used to invalidate cached derivatives, see DerivativeParams::last_mod_time',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-named derivative size definitions, read and written by ImageStdParams via DerivativeSizeRepository';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `derivative_size`
--

LOCK TABLES `derivative_size` WRITE;
/*!40000 ALTER TABLE `derivative_size` DISABLE KEYS */;
INSERT INTO `derivative_size` VALUES ('2small',1,240,240,0.0000,NULL,NULL,0.0000,1786382439),('3xlarge',0,2232,1674,0.0000,NULL,NULL,0.0000,1786382439),('4xlarge',0,3000,2250,0.0000,NULL,NULL,0.0000,1786382439),('large',1,1008,756,0.0000,NULL,NULL,0.0000,1786382439),('medium',1,792,594,0.0000,NULL,NULL,0.0000,1786382439),('small',1,576,432,0.0000,NULL,NULL,0.0000,1786382439),('square',1,120,120,1.0000,120,120,0.0000,1786382439),('thumb',1,144,144,0.0000,NULL,NULL,0.0000,1786382439),('xlarge',1,1224,918,0.0000,NULL,NULL,0.0000,1786382439),('xsmall',1,432,324,0.0000,NULL,NULL,0.0000,1786382439),('xxlarge',1,1656,1242,0.0000,NULL,NULL,0.0000,1786382439);
/*!40000 ALTER TABLE `derivative_size` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `extension_ignored_updates`
--

DROP TABLE IF EXISTS `extension_ignored_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `extension_ignored_updates` (
  `extension_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'plugin, theme, or language, see ExtensionType',
  `extension_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'directory-name identifier of the extension whose update is being ignored',
  `ignored_at` datetime NOT NULL COMMENT 'when the update was dismissed',
  PRIMARY KEY (`extension_type`,`extension_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='extension updates an admin dismissed, read and written by ExtensionUpdateChecker via ExtensionIgnoredUpdateRepository';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `extension_ignored_updates`
--

LOCK TABLES `extension_ignored_updates` WRITE;
/*!40000 ALTER TABLE `extension_ignored_updates` DISABLE KEYS */;
/*!40000 ALTER TABLE `extension_ignored_updates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `favorites`
--

DROP TABLE IF EXISTS `favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `favorites` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'owning user id',
  `image_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'image the user marked as a favorite',
  PRIMARY KEY (`user_id`,`image_id`),
  KEY `fk_favorites_image_id` (`image_id`),
  CONSTRAINT `fk_favorites_image_id` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_favorites_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user favorited images';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `favorites`
--

LOCK TABLES `favorites` WRITE;
/*!40000 ALTER TABLE `favorites` DISABLE KEYS */;
INSERT INTO `favorites` VALUES (1,1),(1,3),(1,5);
/*!40000 ALTER TABLE `favorites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `group_access`
--

DROP TABLE IF EXISTS `group_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `group_access` (
  `group_id` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'granted group id',
  `cat_id` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'private album the group is granted access to',
  PRIMARY KEY (`group_id`,`cat_id`),
  KEY `fk_group_access_cat_id` (`cat_id`),
  CONSTRAINT `fk_group_access_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_access_group_id` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-group private album permission grants';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `group_access`
--

LOCK TABLES `group_access` WRITE;
/*!40000 ALTER TABLE `group_access` DISABLE KEYS */;
INSERT INTO `group_access` VALUES (1,1),(2,1),(3,1),(1,2);
/*!40000 ALTER TABLE `group_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `groups`
--

DROP TABLE IF EXISTS `groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'group display name, unique',
  `is_default` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'every newly registered user is automatically added to groups marked default',
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp, set on insert only',
  PRIMARY KEY (`id`),
  UNIQUE KEY `groups_ui1` (`name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='user groups for bulk permission and membership management';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `groups`
--

LOCK TABLES `groups` WRITE;
/*!40000 ALTER TABLE `groups` DISABLE KEYS */;
INSERT INTO `groups` VALUES (1,'Editors',0,'2026-08-01 03:00:00'),(2,'Reviewers',0,'2026-08-01 03:00:00'),(3,'Guests',0,'2026-08-01 03:00:00');
/*!40000 ALTER TABLE `groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `history`
--

DROP TABLE IF EXISTS `history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `date` date DEFAULT NULL COMMENT 'calendar date of the visit',
  `time` time NOT NULL DEFAULT '00:00:00' COMMENT 'time of day of the visit',
  `user_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'visiting user id, the guest user id for anonymous visitors',
  `IP` char(39) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'REMOTE_ADDR of the request, truncated to fit an IPv6 address',
  `section` enum('categories','tags','search','list','favorites','most_visited','best_rated','recent_pics','recent_cats') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'gallery navigation view the visit occurred in, plugin-defined sections are appended to this enum automatically',
  `category_id` smallint unsigned DEFAULT NULL COMMENT 'album being viewed, set when section is a category-based view',
  `search_id` int unsigned DEFAULT NULL COMMENT 'search being viewed, set when section is search',
  `tag_ids` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'comma-separated tag ids being viewed, set when section is tags, truncated to fit',
  `image_id` mediumint unsigned DEFAULT NULL COMMENT 'viewed image id, null for a listing/section page-view',
  `image_type` enum('picture','high','other') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'size the image was viewed at',
  `format_id` int unsigned DEFAULT NULL COMMENT 'image_format row downloaded or viewed, when applicable',
  `auth_key_id` int unsigned DEFAULT NULL COMMENT 'API auth key the request was authenticated with, if any',
  PRIMARY KEY (`id`),
  KEY `idx_history_date_desc` (`date` DESC,`id` DESC),
  KEY `fk_history_image_id` (`image_id`),
  KEY `fk_history_category_id` (`category_id`),
  KEY `fk_history_search_id` (`search_id`),
  KEY `fk_history_format_id` (`format_id`),
  KEY `fk_history_auth_key_id` (`auth_key_id`),
  KEY `fk_history_user_id` (`user_id`),
  CONSTRAINT `fk_history_auth_key_id` FOREIGN KEY (`auth_key_id`) REFERENCES `user_auth_keys` (`auth_key_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_format_id` FOREIGN KEY (`format_id`) REFERENCES `image_format` (`format_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_image_id` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_search_id` FOREIGN KEY (`search_id`) REFERENCES `search` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-visit page-view log, periodically rolled up into history_summary';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `history`
--

LOCK TABLES `history` WRITE;
/*!40000 ALTER TABLE `history` DISABLE KEYS */;
/*!40000 ALTER TABLE `history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `history_summary`
--

DROP TABLE IF EXISTS `history_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `history_summary` (
  `summary_id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `year` smallint NOT NULL DEFAULT '0' COMMENT 'rollup year',
  `month` tinyint DEFAULT NULL COMMENT 'rollup month, null for a year-level summary row',
  `day` tinyint DEFAULT NULL COMMENT 'rollup day, null for a year- or month-level summary row',
  `hour` tinyint DEFAULT NULL COMMENT 'rollup hour, null for a year-, month-, or day-level summary row',
  `nb_pages` int DEFAULT NULL COMMENT 'number of history page-views folded into this summary row',
  `history_id_from` int unsigned DEFAULT NULL COMMENT 'lowest history.id folded into this summary row',
  `history_id_to` int unsigned DEFAULT NULL COMMENT 'highest history.id folded into this summary row, the next run resumes past this id',
  PRIMARY KEY (`summary_id`),
  UNIQUE KEY `history_summary_ymdh` (`year`,`month`,`day`,`hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='year/month/day/hour rollup of history, one row per granularity level, letting old detail rows be purged';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `history_summary`
--

LOCK TABLES `history_summary` WRITE;
/*!40000 ALTER TABLE `history_summary` DISABLE KEYS */;
/*!40000 ALTER TABLE `history_summary` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `image_category`
--

DROP TABLE IF EXISTS `image_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `image_category` (
  `image_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'member image id',
  `category_id` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'album the image belongs to',
  `rank` mediumint unsigned DEFAULT NULL COMMENT 'manual sort position of the image within this specific album',
  PRIMARY KEY (`image_id`,`category_id`),
  KEY `image_category_i1` (`category_id`),
  CONSTRAINT `fk_image_category_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_image_category_image_id` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='image-to-album membership, an image can belong to more than one album';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `image_category`
--

LOCK TABLES `image_category` WRITE;
/*!40000 ALTER TABLE `image_category` DISABLE KEYS */;
INSERT INTO `image_category` VALUES (1,1,1),(2,1,2),(3,1,3),(4,2,1),(5,2,2);
/*!40000 ALTER TABLE `image_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `image_format`
--

DROP TABLE IF EXISTS `image_format`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `image_format` (
  `format_id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `image_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'image this alternate format file belongs to',
  `ext` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'file extension of this alternate format, e.g. a RAW file stored alongside the main JPEG',
  `filesize` mediumint unsigned DEFAULT NULL COMMENT 'file size of this alternate format in KB',
  PRIMARY KEY (`format_id`),
  KEY `fk_image_format_image_id` (`image_id`),
  CONSTRAINT `fk_image_format_image_id` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='alternate format files stored alongside an image (the multiple formats feature)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `image_format`
--

LOCK TABLES `image_format` WRITE;
/*!40000 ALTER TABLE `image_format` DISABLE KEYS */;
/*!40000 ALTER TABLE `image_format` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `image_tag`
--

DROP TABLE IF EXISTS `image_tag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `image_tag` (
  `image_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'tagged image id',
  `tag_id` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'tag applied to the image',
  PRIMARY KEY (`image_id`,`tag_id`),
  KEY `image_tag_i1` (`tag_id`),
  CONSTRAINT `fk_image_tag_image_id` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_image_tag_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='image-to-tag associations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `image_tag`
--

LOCK TABLES `image_tag` WRITE;
/*!40000 ALTER TABLE `image_tag` DISABLE KEYS */;
INSERT INTO `image_tag` VALUES (1,1),(2,1),(3,1),(1,2),(1,3);
/*!40000 ALTER TABLE `image_tag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `images`
--

DROP TABLE IF EXISTS `images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `images` (
  `id` mediumint unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'base filename of the original file',
  `date_available` datetime DEFAULT NULL COMMENT 'date the photo is considered added/visible in the gallery, can be mapped from EXIF/IPTC or admin-edited',
  `date_creation` datetime DEFAULT NULL COMMENT 'date the photo was taken, typically synced from EXIF/IPTC metadata',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'display title, distinct from the filename',
  `comment` text COLLATE utf8mb4_unicode_ci COMMENT 'photo description shown on its page',
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'photographer/author credit',
  `hit` int unsigned NOT NULL DEFAULT '0' COMMENT 'view counter',
  `filesize` mediumint unsigned DEFAULT NULL COMMENT 'original file size in KB',
  `width` smallint unsigned DEFAULT NULL COMMENT 'original pixel width',
  `height` smallint unsigned DEFAULT NULL COMMENT 'original pixel height',
  `coi` char(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'center of interest',
  `representative_ext` varchar(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'file extension of a separate representative thumbnail, for formats that cannot be thumbnailed directly, e.g. PDF/video',
  `date_metadata_update` date DEFAULT NULL COMMENT 'date the row was last synced from the file EXIF/IPTC metadata, null if never synced',
  `rating_score` float(5,2) unsigned DEFAULT NULL COMMENT 'bayesian average of rate ratings, recomputed by RateService::updateRatingScore',
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'full relative filesystem path to the original file',
  `storage_category_id` smallint unsigned DEFAULT NULL COMMENT 'album the file is physically stored under, distinct from possibly multiple image_category memberships',
  `level` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'minimum permission level required to view the image, see PwgImages::setPrivacyLevel and available_permission_levels',
  `md5sum` char(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'MD5 checksum of the original file, computed lazily for duplicate detection',
  `added_by` mediumint unsigned DEFAULT NULL COMMENT 'uploading user id',
  `rotation` tinyint unsigned DEFAULT NULL COMMENT 'pending quarter-turn rotation to apply when rendering, 0 to 3',
  `latitude` double(8,6) DEFAULT NULL COMMENT 'GPS latitude, from EXIF',
  `longitude` double(9,6) DEFAULT NULL COMMENT 'GPS longitude, from EXIF',
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp',
  PRIMARY KEY (`id`),
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
  KEY `idx_images_date_desc` (`date_available` DESC,`id` DESC),
  KEY `fk_images_added_by` (`added_by`),
  FULLTEXT KEY `images_ft_name_comment` (`name`,`comment`) /*!50100 WITH PARSER `ngram` */ ,
  FULLTEXT KEY `images_ft_author` (`author`) /*!50100 WITH PARSER `ngram` */ ,
  CONSTRAINT `fk_images_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_images_storage_category_id` FOREIGN KEY (`storage_category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='photo/media metadata and file location, one row per uploaded image';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `images`
--

LOCK TABLES `images` WRITE;
/*!40000 ALTER TABLE `images` DISABLE KEYS */;
INSERT INTO `images` VALUES (1,'fixture-photo-1.jpg','2026-08-01 00:00:00',NULL,'Photo 1',NULL,NULL,0,1,200,150,NULL,NULL,'2026-08-10',4.50,'upload/2026/08/01/20260801000000-2e7e6c90.jpg',NULL,0,'2e7ee450c4a4cffe42945205029782b9',1,0,NULL,NULL,'2026-08-10 17:20:41'),(2,'fixture-photo-2.jpg','2026-08-01 00:00:00',NULL,'Photo 2',NULL,NULL,0,1,200,150,NULL,NULL,'2026-08-10',3.00,'upload/2026/08/01/20260801000000-4a014fb5.jpg',NULL,0,'4a010138f010067cfc713afb6dcf45e1',1,0,NULL,NULL,'2026-08-10 17:20:41'),(3,'fixture-photo-3.jpg','2026-08-01 00:00:00',NULL,'Photo 3',NULL,NULL,0,1,200,150,NULL,NULL,'2026-08-10',5.00,'upload/2026/08/01/20260801000000-a6a09035.jpg',NULL,0,'a6a04acded208db63890b74c4252a012',1,0,NULL,NULL,'2026-08-10 17:20:41'),(4,'fixture-photo-4.jpg','2026-08-01 00:00:00',NULL,'Photo 4',NULL,NULL,0,1,200,150,NULL,NULL,'2026-08-10',2.00,'upload/2026/08/01/20260801000000-3df6d267.jpg',NULL,0,'3df6bd0ebb6f22ea988f2ffb1c3a9566',1,0,NULL,NULL,'2026-08-10 17:20:41'),(5,'fixture-photo-5.jpg','2026-08-01 00:00:00',NULL,'Photo 5',NULL,NULL,0,1,200,150,NULL,NULL,'2026-08-10',NULL,'upload/2026/08/01/20260801000000-4b01ecda.jpg',NULL,0,'4b01d21f3d56009c3b1f913fafda86c5',1,0,NULL,NULL,'2026-08-10 17:20:41');
/*!40000 ALTER TABLE `images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `integrity_ignored_anomalies`
--

DROP TABLE IF EXISTS `integrity_ignored_anomalies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `integrity_ignored_anomalies` (
  `anomaly_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'add_anomaly()-generated md5 id, see CheckIntegrity',
  `piwigo_version` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Piwigo version the anomaly was ignored under',
  `ignored_at` datetime NOT NULL COMMENT 'when the anomaly was dismissed',
  PRIMARY KEY (`anomaly_id`,`piwigo_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='integrity-check anomalies an admin dismissed, read and written by CheckIntegrity via IntegrityIgnoredAnomalyRepository';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `integrity_ignored_anomalies`
--

LOCK TABLES `integrity_ignored_anomalies` WRITE;
/*!40000 ALTER TABLE `integrity_ignored_anomalies` DISABLE KEYS */;
/*!40000 ALTER TABLE `integrity_ignored_anomalies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `languages`
--

DROP TABLE IF EXISTS `languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `languages` (
  `id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'language directory-name identifier, e.g. en_UK, row existence alone means installed and active',
  `version` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0' COMMENT 'installed language pack version string',
  `name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'human-readable language display name',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed/active language packs, row deleted outright on deactivation';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `languages`
--

LOCK TABLES `languages` WRITE;
/*!40000 ALTER TABLE `languages` DISABLE KEYS */;
INSERT INTO `languages` VALUES ('en_UK','16.3.0','English (Great Britain)');
/*!40000 ALTER TABLE `languages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lounge`
--

DROP TABLE IF EXISTS `lounge`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lounge` (
  `image_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'newly uploaded image pending album association',
  `category_id` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'album the image is intended for once the lounge is emptied',
  PRIMARY KEY (`image_id`,`category_id`),
  KEY `fk_lounge_category_id` (`category_id`),
  CONSTRAINT `fk_lounge_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lounge_image_id` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='pending image-to-album associations, applied in bulk by ImageService::emptyLounge';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lounge`
--

LOCK TABLES `lounge` WRITE;
/*!40000 ALTER TABLE `lounge` DISABLE KEYS */;
/*!40000 ALTER TABLE `lounge` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `old_permalinks`
--

DROP TABLE IF EXISTS `old_permalinks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `old_permalinks` (
  `cat_id` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'album the removed permalink used to point to',
  `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'the retired URL slug, kept so it is not immediately reusable by another album',
  `date_deleted` datetime DEFAULT NULL COMMENT 'when the permalink was retired',
  `last_hit` datetime DEFAULT NULL COMMENT 'when the dead permalink was last visited',
  `hit` int unsigned NOT NULL DEFAULT '0' COMMENT 'visit count against the dead permalink',
  PRIMARY KEY (`permalink`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='retired album permalinks, kept to block reuse and shown on the admin permalinks page';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `old_permalinks`
--

LOCK TABLES `old_permalinks` WRITE;
/*!40000 ALTER TABLE `old_permalinks` DISABLE KEYS */;
INSERT INTO `old_permalinks` VALUES (1,'old-sample-album','2026-08-01 00:00:00','2026-08-01 00:00:00',42);
/*!40000 ALTER TABLE `old_permalinks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plugin_migrations`
--

DROP TABLE IF EXISTS `plugin_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugin_migrations` (
  `plugin_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'directory-name identifier of the plugin that ran this migration',
  `version` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'plugin-internal migration version identifier',
  `executed_at` datetime NOT NULL COMMENT 'when this plugin migration ran',
  PRIMARY KEY (`plugin_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-plugin install/update history, read and written by ExtensionLifecycle via PluginMigrationRepository, not a real migration runner';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plugin_migrations`
--

LOCK TABLES `plugin_migrations` WRITE;
/*!40000 ALTER TABLE `plugin_migrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `plugin_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plugins`
--

DROP TABLE IF EXISTS `plugins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugins` (
  `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'plugin directory-name identifier, row existence alone means installed, active or not',
  `state` enum('inactive','active') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inactive' COMMENT 'whether the installed plugin is currently active',
  `version` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0' COMMENT 'installed plugin version string',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed plugins and their active/inactive state';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plugins`
--

LOCK TABLES `plugins` WRITE;
/*!40000 ALTER TABLE `plugins` DISABLE KEYS */;
/*!40000 ALTER TABLE `plugins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rate`
--

DROP TABLE IF EXISTS `rate`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'rating user id, the guest user id for anonymous visitors',
  `element_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'rated image id',
  `anonymous_id` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'truncated IP address identifying an anonymous rater, from the anonymous_rater cookie',
  `rate` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'submitted rating value, restricted to the configured rate_items',
  `date` date DEFAULT NULL COMMENT 'date the rate was submitted',
  PRIMARY KEY (`element_id`,`user_id`,`anonymous_id`),
  KEY `fk_rate_user_id` (`user_id`),
  CONSTRAINT `fk_rate_element_id` FOREIGN KEY (`element_id`) REFERENCES `images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rate_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user or per-anonymous-visitor image ratings, aggregated into images.rating_score';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rate`
--

LOCK TABLES `rate` WRITE;
/*!40000 ALTER TABLE `rate` DISABLE KEYS */;
INSERT INTO `rate` VALUES (1,1,'',5,'2026-08-01'),(3,1,'',4,'2026-08-01'),(4,2,'',3,'2026-08-01'),(1,3,'',5,'2026-08-01'),(3,4,'',2,'2026-08-01');
/*!40000 ALTER TABLE `rate` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `search`
--

DROP TABLE IF EXISTS `search`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `search` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `search_uuid` char(23) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'public, shareable identifier for this saved search, used in URLs instead of id',
  `created_on` datetime DEFAULT NULL COMMENT 'when the search was saved',
  `created_by` mediumint unsigned DEFAULT NULL COMMENT 'user id who saved the search, null for an anonymous search',
  `forked_from` int unsigned DEFAULT NULL COMMENT 'search this one was refined/derived from, null for an original search',
  `rules` json DEFAULT NULL COMMENT 'encoded search criteria (query terms, filters) evaluated by SearchService',
  PRIMARY KEY (`id`),
  KEY `fk_search_created_by` (`created_by`),
  KEY `fk_search_forked_from` (`forked_from`),
  CONSTRAINT `fk_search_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_search_forked_from` FOREIGN KEY (`forked_from`) REFERENCES `search` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='saved/shareable search queries';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `search`
--

LOCK TABLES `search` WRITE;
/*!40000 ALTER TABLE `search` DISABLE KEYS */;
/*!40000 ALTER TABLE `search` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `search_filter_view`
--

DROP TABLE IF EXISTS `search_filter_view`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `search_filter_view` (
  `name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'saved filter view name',
  `config_json` json NOT NULL COMMENT 'encoded search filter configuration',
  `created_at` datetime NOT NULL COMMENT 'when the filter view was saved',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='named, reusable saved search-filter presets, unused: not read or written by any repository or service in this codebase';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `search_filter_view`
--

LOCK TABLES `search_filter_view` WRITE;
/*!40000 ALTER TABLE `search_filter_view` DISABLE KEYS */;
/*!40000 ALTER TABLE `search_filter_view` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'composite PHP session id, IP-hash-prefixed by SessionService',
  `data` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'serialized PHP session payload',
  `expiration` datetime DEFAULT NULL COMMENT 'when this session becomes invalid',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP session storage backend';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('34bb05492022dffd5d7444b693113ca8','','2026-08-01 00:00:00'),('8b26bb61a41ac7950c20dc9236118216','pwg_uid|i:1;connected_with|s:16:\"ws_session_login\";','2026-08-01 00:00:00'),('bb77aa7f255304e7c638d749d2a4f82b','','2026-08-01 00:00:00'),('c8bfc2dcb05251d7c919f4b982e6e9ce','','2026-08-01 00:00:00'),('cdf494f6f432ed52db4751d178131ec2','','2026-08-01 00:00:00'),('f21a97b3987cc3e8ff7913769214095b','','2026-08-01 00:00:00');
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sites`
--

DROP TABLE IF EXISTS `sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sites` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key, referenced by categories.site_id',
  `galleries_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'base path or URL this site synchronizes photos from, local or remote (see UrlService::urlIsRemote)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `sites_ui1` (`galleries_url`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='multi-site photo sources synchronized into albums';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sites`
--

LOCK TABLES `sites` WRITE;
/*!40000 ALTER TABLE `sites` DISABLE KEYS */;
INSERT INTO `sites` VALUES (1,'/home/torres/piwigo17-rewrite-sql/galleries/');
/*!40000 ALTER TABLE `sites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tags`
--

DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'tag display name',
  `url_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'URL-friendly slug derived from name',
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp, set on insert only',
  PRIMARY KEY (`id`),
  KEY `tags_i1` (`url_name`),
  KEY `lastmodified` (`lastmodified`),
  FULLTEXT KEY `tags_ft_name` (`name`) /*!50100 WITH PARSER `ngram` */ 
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='photo tags/keywords';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tags`
--

LOCK TABLES `tags` WRITE;
/*!40000 ALTER TABLE `tags` DISABLE KEYS */;
INSERT INTO `tags` VALUES (1,'nature','nature','2026-08-01 03:00:00'),(2,'travel','travel','2026-08-01 03:00:00'),(3,'family','family','2026-08-01 03:00:00');
/*!40000 ALTER TABLE `tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `themes`
--

DROP TABLE IF EXISTS `themes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `themes` (
  `id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'theme directory-name identifier, referenced by user_infos.theme, row existence alone means installed and active',
  `version` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0' COMMENT 'installed theme version string',
  `name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'human-readable theme display name',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed/active themes, row deleted outright on deactivation';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `themes`
--

LOCK TABLES `themes` WRITE;
/*!40000 ALTER TABLE `themes` DISABLE KEYS */;
/*!40000 ALTER TABLE `themes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_access`
--

DROP TABLE IF EXISTS `user_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_access` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'granted user id',
  `cat_id` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'private album the user is granted access to',
  PRIMARY KEY (`user_id`,`cat_id`),
  KEY `fk_user_access_cat_id` (`cat_id`),
  CONSTRAINT `fk_user_access_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_access_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user private album permission grants';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_access`
--

LOCK TABLES `user_access` WRITE;
/*!40000 ALTER TABLE `user_access` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_auth_keys`
--

DROP TABLE IF EXISTS `user_auth_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_auth_keys` (
  `auth_key_id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `auth_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'the token value: a random persistent-login token for key_type=auth_key, or the public pkid-... identifier for key_type=api_key',
  `apikey_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'hashed secret half of a key_type=api_key pair, null for auth_key rows',
  `user_id` mediumint unsigned NOT NULL COMMENT 'owning user id',
  `created_on` datetime NOT NULL COMMENT 'when the key was issued',
  `duration` int unsigned DEFAULT NULL COMMENT 'requested key lifetime, seconds for auth_key rows or days for api_key rows, see expired_on for the actual cutoff',
  `expired_on` datetime NOT NULL COMMENT 'when the key stops being valid',
  `apikey_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'user-given label for a key_type=api_key row, null for auth_key rows',
  `key_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'auth_key for a persistent-login/URL-login token, api_key for a personal API key',
  `revoked_on` datetime DEFAULT NULL COMMENT 'when the key was manually revoked, null if still live',
  `last_used_on` datetime DEFAULT NULL COMMENT 'when the key last authenticated a request',
  `last_notified_on` datetime DEFAULT NULL COMMENT 'when the owner was last emailed an expiration notice',
  PRIMARY KEY (`auth_key_id`),
  KEY `fk_user_auth_keys_user_id` (`user_id`),
  CONSTRAINT `fk_user_auth_keys_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='persistent-login tokens and personal API keys, two row shapes sharing one table';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_auth_keys`
--

LOCK TABLES `user_auth_keys` WRITE;
/*!40000 ALTER TABLE `user_auth_keys` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_auth_keys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_failed_logins`
--

DROP TABLE IF EXISTS `user_failed_logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_failed_logins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
  `user_id` mediumint unsigned DEFAULT NULL COMMENT 'targeted user id, if the attempted username resolved to a real account',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'REMOTE_ADDR the failed login attempt came from',
  `attempted_at` datetime NOT NULL COMMENT 'when the failed attempt occurred',
  PRIMARY KEY (`id`),
  KEY `idx_user_failed_logins_user_time` (`user_id`,`attempted_at`),
  KEY `idx_user_failed_logins_ip_time` (`ip`,`attempted_at`),
  CONSTRAINT `fk_user_failed_logins_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='failed login attempts, read and written by AuthService::pwgLogin() via UserFailedLoginRepository to back its dual-scope (username + IP) lockout';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_failed_logins`
--

LOCK TABLES `user_failed_logins` WRITE;
/*!40000 ALTER TABLE `user_failed_logins` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_failed_logins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_feed`
--

DROP TABLE IF EXISTS `user_feed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_feed` (
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'private feed token, passed as ?feed= to authenticate as the owning user without a login',
  `user_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'user this feed token authenticates as',
  `last_check` datetime DEFAULT NULL COMMENT 'when this feed URL was last polled',
  PRIMARY KEY (`id`),
  KEY `fk_user_feed_user_id` (`user_id`),
  CONSTRAINT `fk_user_feed_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user private RSS feed tokens';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_feed`
--

LOCK TABLES `user_feed` WRITE;
/*!40000 ALTER TABLE `user_feed` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_feed` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_group`
--

DROP TABLE IF EXISTS `user_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_group` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'member user id',
  `group_id` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'group the user belongs to',
  PRIMARY KEY (`group_id`,`user_id`),
  KEY `fk_user_group_user_id` (`user_id`),
  CONSTRAINT `fk_user_group_group_id` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_group_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='user to group membership';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_group`
--

LOCK TABLES `user_group` WRITE;
/*!40000 ALTER TABLE `user_group` DISABLE KEYS */;
INSERT INTO `user_group` VALUES (1,1),(3,1),(3,2),(4,3);
/*!40000 ALTER TABLE `user_group` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_infos`
--

DROP TABLE IF EXISTS `user_infos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_infos` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'the owning users.id row, application-assigned, never auto-generated here',
  `nb_image_page` smallint unsigned NOT NULL DEFAULT '15' COMMENT 'photos per page preference',
  `status` enum('webmaster','admin','normal','generic','guest') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'guest' COMMENT 'account role, gates admin access and permission checks',
  `language` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_UK' COMMENT 'interface language, references languages.id',
  `expand` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'whether the album tree auto-expands in the menu',
  `show_nb_comments` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'whether comment counts are shown alongside thumbnails',
  `show_nb_hits` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'whether view counts are shown alongside thumbnails',
  `recent_period` tinyint unsigned NOT NULL DEFAULT '7' COMMENT 'number of days considered recent for the recent photos/albums views',
  `theme` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'modus' COMMENT 'interface theme, references themes.id',
  `registration_date` datetime DEFAULT NULL COMMENT 'account creation date',
  `enabled_high` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'whether the user may view/download the original, high-definition photo',
  `level` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'effective permission level, gates access to images.level-restricted photos',
  `activation_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'hashed password-reset token, see AuthService::setActivationKey and password.php',
  `activation_key_expire` datetime DEFAULT NULL COMMENT 'when activation_key stops being valid',
  `last_visit` datetime DEFAULT NULL COMMENT 'when the user was last seen, refreshed once per session length',
  `last_visit_from_history` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'whether last_visit was already backfilled from the history table, avoids repeating that lookup',
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp',
  `preferences` json DEFAULT NULL COMMENT 'generic per-user key-value bag for preferences with no dedicated column',
  PRIMARY KEY (`user_id`),
  KEY `lastmodified` (`lastmodified`),
  CONSTRAINT `fk_user_infos_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user profile and preferences, one row per users.id';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_infos`
--

LOCK TABLES `user_infos` WRITE;
/*!40000 ALTER TABLE `user_infos` DISABLE KEYS */;
INSERT INTO `user_infos` VALUES (1,15,'webmaster','en_UK',0,0,0,7,'default','2026-08-01 00:00:00',1,8,NULL,NULL,NULL,0,'2026-08-10 17:20:39','{\"show_whats_new_16\": false}'),(2,15,'guest','en_UK',0,0,0,7,'default','2026-08-01 00:00:00',1,0,NULL,NULL,NULL,0,'2026-08-01 03:00:00',NULL),(3,15,'normal','en_UK',0,0,0,7,'default','2026-08-01 00:00:00',1,0,NULL,NULL,NULL,0,'2026-08-01 03:00:00',NULL),(4,15,'normal','en_UK',0,0,0,7,'default','2026-08-01 00:00:00',1,0,NULL,NULL,NULL,0,'2026-08-01 03:00:00',NULL);
/*!40000 ALTER TABLE `user_infos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_mail_notification`
--

DROP TABLE IF EXISTS `user_mail_notification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_mail_notification` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0' COMMENT 'subscribing user id',
  `check_key` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'private token used in subscribe/unsubscribe confirmation email links',
  `enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'whether the user currently receives new-photo notification emails',
  `last_send` datetime DEFAULT NULL COMMENT 'when a notification email was last sent to this user',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_mail_notification_ui1` (`check_key`),
  CONSTRAINT `fk_user_mail_notification_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user new-photo email notification subscriptions';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_mail_notification`
--

LOCK TABLES `user_mail_notification` WRITE;
/*!40000 ALTER TABLE `user_mail_notification` DISABLE KEYS */;
INSERT INTO `user_mail_notification` VALUES (1,'abcdef1234567890',1,'2026-08-01 00:00:00'),(3,'ghijkl9876543210',0,NULL);
/*!40000 ALTER TABLE `user_mail_notification` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` mediumint unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key, referenced by user_id everywhere else',
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT 'login name, unique',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'hashed login password',
  `mail_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'account email address',
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_ui1` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='core login accounts, column names configurable via CurrentConfig::userFields for multi-auth integrations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'fixture_admin','$2y$04$7uUmJSEmEZgleEd3n6GFzuB/uKIzJmjqNBHE5OSx65uVaz7pIyPK2','fixture_admin@example.test'),(2,'guest',NULL,NULL),(3,'regular_user','$2y$04$5iHho2h8WHWpsthi7sIHbOx0Sl9Tv.a7i2UQpqOH.KmYXISugJ8WC',NULL),(4,'power_user','$2y$04$qo4pdN6PHJzR/qcol0qRl.zXOTP0tu34A1v6YC0tr1gQsvSIYS2Rm',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-10 14:20:42
