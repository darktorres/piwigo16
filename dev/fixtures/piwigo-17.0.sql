-- MySQL dump 10.13  Distrib 8.4.8, for Linux (x86_64)
--
-- Host: localhost    Database: piwigo_fixture_build
-- ------------------------------------------------------
-- Server version	8.4.8-0ubuntu1

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
-- Table structure for table `piwigo_activity`
--

DROP TABLE IF EXISTS `piwigo_activity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_activity` (
  `activity_id` int unsigned NOT NULL AUTO_INCREMENT,
  `object` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_id` int unsigned NOT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `performed_by` mediumint unsigned DEFAULT NULL,
  `session_idx` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occured_on` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`activity_id`),
  KEY `fk_activity_performed_by` (`performed_by`),
  CONSTRAINT `fk_activity_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_activity`
--

LOCK TABLES `piwigo_activity` WRITE;
/*!40000 ALTER TABLE `piwigo_activity` DISABLE KEYS */;
INSERT INTO `piwigo_activity` VALUES (1,'system',1,'install',2,'none','::1','2026-05-18 11:34:51','{\"version\":\"17.0.0\",\"script\":\"index\"}',''),(2,'user',1,'login',1,'bd443525969b8b2190340707c85a0304','::1','2026-05-18 11:34:51','{\"script\":\"index\"}','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.7778.96 Safari/537.36'),(3,'user',1,'login',2,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:52','{\"method\":\"pwg.session.login\"}','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.7778.96 Safari/537.36'),(4,'album',1,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:52','{\"method\":\"pwg.categories.add\"}',''),(5,'album',2,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:53','{\"method\":\"pwg.categories.add\"}',''),(6,'photo',1,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:53','{\"method\":\"pwg.images.addSimple\",\"added_with\":\"app\"}',''),(7,'photo',2,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:53','{\"method\":\"pwg.images.addSimple\",\"added_with\":\"app\"}',''),(8,'photo',3,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:53','{\"method\":\"pwg.images.addSimple\",\"added_with\":\"app\"}',''),(9,'photo',4,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:54','{\"method\":\"pwg.images.addSimple\",\"added_with\":\"app\"}',''),(10,'photo',5,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:54','{\"method\":\"pwg.images.addSimple\",\"added_with\":\"app\"}',''),(11,'tag',1,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:55','{\"method\":\"pwg.tags.add\"}',''),(12,'tag',2,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:55','{\"method\":\"pwg.tags.add\"}',''),(13,'tag',3,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:55','{\"method\":\"pwg.tags.add\"}',''),(14,'user',3,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:55','{\"method\":\"pwg.users.add\"}',''),(15,'user',4,'add',1,'ce974bb133cd6831cdfb31809a2cfc09','::1','2026-05-18 11:34:56','{\"method\":\"pwg.users.add\"}','');
/*!40000 ALTER TABLE `piwigo_activity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_caddie`
--

DROP TABLE IF EXISTS `piwigo_caddie`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_caddie` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `element_id` mediumint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`,`element_id`),
  KEY `fk_caddie_element_id` (`element_id`),
  CONSTRAINT `fk_caddie_element_id` FOREIGN KEY (`element_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_caddie_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_caddie`
--

LOCK TABLES `piwigo_caddie` WRITE;
/*!40000 ALTER TABLE `piwigo_caddie` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_caddie` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_categories`
--

DROP TABLE IF EXISTS `piwigo_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_categories` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id_uppercat` smallint unsigned DEFAULT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci,
  `dir` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rank` smallint unsigned DEFAULT NULL,
  `status` enum('public','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `site_id` tinyint unsigned DEFAULT NULL,
  `visible` tinyint unsigned NOT NULL DEFAULT '1',
  `representative_picture_id` mediumint unsigned DEFAULT NULL,
  `uppercats` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `commentable` tinyint unsigned NOT NULL DEFAULT '1',
  `global_rank` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_order` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_i3` (`permalink`),
  KEY `categories_i2` (`id_uppercat`),
  KEY `categories_status_idx` (`status`),
  KEY `lastmodified` (`lastmodified`),
  KEY `fk_categories_representative_picture_id` (`representative_picture_id`),
  FULLTEXT KEY `categories_ft_name_comment` (`name`,`comment`),
  CONSTRAINT `fk_categories_id_uppercat` FOREIGN KEY (`id_uppercat`) REFERENCES `piwigo_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_categories_representative_picture_id` FOREIGN KEY (`representative_picture_id`) REFERENCES `piwigo_images` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_categories`
--

LOCK TABLES `piwigo_categories` WRITE;
/*!40000 ALTER TABLE `piwigo_categories` DISABLE KEYS */;
INSERT INTO `piwigo_categories` VALUES (1,'Sample Album',NULL,NULL,NULL,1,'public',NULL,1,1,'1',1,'1',NULL,NULL,'2026-05-18 11:34:53'),(2,'Nested Sub Album',1,NULL,NULL,1,'public',NULL,1,4,'1,2',1,'1.1',NULL,NULL,'2026-05-18 11:34:54');
/*!40000 ALTER TABLE `piwigo_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_comments`
--

DROP TABLE IF EXISTS `piwigo_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `date` datetime DEFAULT NULL,
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author_id` mediumint unsigned DEFAULT NULL,
  `anonymous_id` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `validated` tinyint unsigned NOT NULL DEFAULT '0',
  `validation_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comments_i2` (`validation_date`),
  KEY `comments_image_validated_idx` (`image_id`,`validated`),
  KEY `fk_comments_author_id` (`author_id`),
  CONSTRAINT `fk_comments_author_id` FOREIGN KEY (`author_id`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comments_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_comments`
--

LOCK TABLES `piwigo_comments` WRITE;
/*!40000 ALTER TABLE `piwigo_comments` DISABLE KEYS */;
INSERT INTO `piwigo_comments` VALUES (1,1,'2026-05-18 08:34:55','fixture_admin',NULL,1,'127.0.0.1',NULL,'Fixture comment for integration tests.',1,'2026-05-18 08:34:55');
/*!40000 ALTER TABLE `piwigo_comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_config`
--

DROP TABLE IF EXISTS `piwigo_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_config` (
  `param` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `value` json DEFAULT NULL,
  `comment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='configuration table';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_config`
--

LOCK TABLES `piwigo_config` WRITE;
/*!40000 ALTER TABLE `piwigo_config` DISABLE KEYS */;
INSERT INTO `piwigo_config` VALUES ('activate_comments','false','Global parameter for usage of comments system'),('allow_user_customization','true','allow users to customize their gallery?'),('allow_user_registration','true','allow visitors to register?'),('blk_menubar','\"\"','Menubar options'),('comments_author_mandatory','false','Comment author is mandatory'),('comments_email_mandatory','false','Comment email is mandatory'),('comments_enable_website','true','Enable \"website\" field on add comment form'),('comments_forall','false','even guest not registered can post comments'),('comments_order','\"ASC\"','comments order on picture page and cie'),('comments_validation','true','administrators validate users comments before becoming visible'),('data_dir_checked','1',NULL),('display_fromto','false',NULL),('email_admin_on_comment','false','Send an email to the administrators when a valid comment is entered'),('email_admin_on_comment_deletion','false','Send an email to the administrators when a comment is deleted'),('email_admin_on_comment_edition','false','Send an email to the administrators when a comment is modified'),('email_admin_on_comment_validation','true','Send an email to the administrators when a comment requires validation'),('email_admin_on_new_user','\"none\"','Send an email to theadministrators when a user registers'),('extents_for_templates','[]','Actived template-extension(s)'),('gallery_locked','false','Lock your gallery temporary for non admin users'),('gallery_title','\"Fixture Gallery\"','Title at top of each page and for RSS feed'),('history_admin','false','keep a history of administrator visits on your website'),('history_guest','true','keep a history of guest visits on your website'),('index_caddie_icon','true',NULL),('index_created_date_icon','true','Display calendar by creation date icon'),('index_edit_icon','true',NULL),('index_flat_icon','false','Display flat icon'),('index_new_icon','true','Display new icons next albums and pictures'),('index_posted_date_icon','true','Display calendar by posted date'),('index_search_in_set_action','true',NULL),('index_search_in_set_button','false',NULL),('index_sizes_icon','true',NULL),('index_slideshow_icon','true','Display slideshow icon'),('index_sort_order_input','true','Display image order selection list'),('last_major_update','\"2026-05-18 11:34:52\"',NULL),('log','true','keep an history of visits on your website'),('lounge_active','true',NULL),('mail_theme','\"clear\"',NULL),('menubar_filter_icon','false','Display filter icon'),('mobile_theme',NULL,NULL),('nb_categories_page','12','Param for categories pagination'),('nb_comment_page','10','number of comments to display on each page'),('nbm_complementary_mail_content','\"\"','Complementary mail content for notification by mail'),('nbm_send_detailed_content','true','Send detailed content for notification by mail'),('nbm_send_html_mail','true','Send mail on HTML format for notification by mail'),('nbm_send_mail_as','\"\"','Send mail as param value for notification by mail'),('nbm_send_recent_post_dates','true','Send recent post by dates for notification by mail'),('obligatory_user_mail_address','false','Mail address is obligatory for users'),('order_by','[{\"dir\": \"DESC\", \"field\": \"date_available\"}, {\"dir\": \"ASC\", \"field\": \"file\"}, {\"dir\": \"ASC\", \"field\": \"id\"}]','default photo order'),('order_by_inside_category','[{\"dir\": \"DESC\", \"field\": \"date_available\"}, {\"dir\": \"ASC\", \"field\": \"file\"}, {\"dir\": \"ASC\", \"field\": \"id\"}]','default photo order inside category'),('original_resize','false',NULL),('original_resize_maxheight','2016',NULL),('original_resize_maxwidth','2016',NULL),('original_resize_quality','95',NULL),('page_banner','\"<h1>%gallery_title%</h1>\\n\\n<p>Welcome to my photo gallery</p>\"','html displayed on the top each page of your gallery'),('picture_caddie_icon','true',NULL),('picture_download_icon','true','Display download icon on picture page'),('picture_edit_icon','true',NULL),('picture_favorite_icon','true','Display favorite icon on picture page'),('picture_informations','{\"file\": false, \"tags\": true, \"author\": true, \"visits\": true, \"filesize\": false, \"posted_on\": true, \"categories\": true, \"created_on\": true, \"dimensions\": false, \"rating_score\": true, \"privacy_level\": true}','Information displayed on picture page'),('picture_menu','false','Show menubar on picture page'),('picture_metadata_icon','true','Display metadata icon on picture page'),('picture_navigation_icons','true','Display navigation icons on picture page'),('picture_navigation_thumb','true','Display navigation thumbnails on picture page'),('picture_representative_icon','true',NULL),('picture_sizes_icon','true',NULL),('picture_slideshow_icon','true','Display slideshow icon on picture page'),('piwigo_db_version','\"17\"',NULL),('piwigo_installed_version','\"17.0.0\"',NULL),('rate','false','Rating pictures feature is enabled'),('rate_anonymous','true','Rating pictures feature is also enabled for visitors'),('secret_key','\"f168a29d004fdb0086d6f9a35c90a11de2e41379\"',NULL),('show_mobile_app_banner_in_admin','true',NULL),('show_mobile_app_banner_in_gallery','false',NULL),('upload_detect_duplicate','true',NULL),('use_standard_pages','true',NULL),('user_can_delete_comment','false','administrators can allow user delete their own comments'),('user_can_edit_comment','false','administrators can allow user edit their own comments'),('webmaster_id','1',NULL),('week_starts_on','\"monday\"','Monday may not be the first day of the week');
/*!40000 ALTER TABLE `piwigo_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_derivative_settings`
--

DROP TABLE IF EXISTS `piwigo_derivative_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_derivative_settings` (
  `id` tinyint NOT NULL,
  `default_quality` int NOT NULL DEFAULT '95',
  `watermark_json` json NOT NULL,
  `custom_json` json NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='singleton-row: JPEG quality + watermark + custom-size recency map';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_derivative_settings`
--

LOCK TABLES `piwigo_derivative_settings` WRITE;
/*!40000 ALTER TABLE `piwigo_derivative_settings` DISABLE KEYS */;
INSERT INTO `piwigo_derivative_settings` VALUES (1,95,'{\"file\": \"\", \"xpos\": 50, \"ypos\": 50, \"opacity\": 100, \"xrepeat\": 0, \"yrepeat\": 0, \"min_size\": [500, 500]}','[]');
/*!40000 ALTER TABLE `piwigo_derivative_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_derivative_size`
--

DROP TABLE IF EXISTS `piwigo_derivative_size`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_derivative_size` (
  `name` varchar(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `max_width` int NOT NULL DEFAULT '0',
  `max_height` int NOT NULL DEFAULT '0',
  `max_crop` decimal(5,4) NOT NULL DEFAULT '0.0000',
  `min_width` int DEFAULT NULL,
  `min_height` int DEFAULT NULL,
  `sharpen` decimal(5,4) NOT NULL DEFAULT '0.0000',
  `last_mod_time` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-size derivative params (replaces the derivatives serialize() blob)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_derivative_size`
--

LOCK TABLES `piwigo_derivative_size` WRITE;
/*!40000 ALTER TABLE `piwigo_derivative_size` DISABLE KEYS */;
INSERT INTO `piwigo_derivative_size` VALUES ('2small',1,240,240,0.0000,NULL,NULL,0.0000,1779104091),('3xlarge',0,2232,1674,0.0000,NULL,NULL,0.0000,1779104091),('4xlarge',0,3000,2250,0.0000,NULL,NULL,0.0000,1779104091),('large',1,1008,756,0.0000,NULL,NULL,0.0000,1779104091),('medium',1,792,594,0.0000,NULL,NULL,0.0000,1779104091),('small',1,576,432,0.0000,NULL,NULL,0.0000,1779104091),('square',1,120,120,1.0000,120,120,0.0000,1779104091),('thumb',1,144,144,0.0000,NULL,NULL,0.0000,1779104091),('xlarge',1,1224,918,0.0000,NULL,NULL,0.0000,1779104091),('xsmall',1,432,324,0.0000,NULL,NULL,0.0000,1779104091),('xxlarge',1,1656,1242,0.0000,NULL,NULL,0.0000,1779104091);
/*!40000 ALTER TABLE `piwigo_derivative_size` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_extension_ignored_updates`
--

DROP TABLE IF EXISTS `piwigo_extension_ignored_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_extension_ignored_updates` (
  `extension_type` enum('plugins','themes','languages') CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `extension_id` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `ignored_at` datetime NOT NULL,
  PRIMARY KEY (`extension_type`,`extension_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='extensions the admin has asked not to notify about (replaces updates_ignored serialize() blob)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_extension_ignored_updates`
--

LOCK TABLES `piwigo_extension_ignored_updates` WRITE;
/*!40000 ALTER TABLE `piwigo_extension_ignored_updates` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_extension_ignored_updates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_favorites`
--

DROP TABLE IF EXISTS `piwigo_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_favorites` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`,`image_id`),
  KEY `fk_favorites_image_id` (`image_id`),
  CONSTRAINT `fk_favorites_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_favorites_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_favorites`
--

LOCK TABLES `piwigo_favorites` WRITE;
/*!40000 ALTER TABLE `piwigo_favorites` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_favorites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_group_access`
--

DROP TABLE IF EXISTS `piwigo_group_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_group_access` (
  `group_id` smallint unsigned NOT NULL DEFAULT '0',
  `cat_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`group_id`,`cat_id`),
  KEY `fk_group_access_cat_id` (`cat_id`),
  CONSTRAINT `fk_group_access_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_access_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_group_access`
--

LOCK TABLES `piwigo_group_access` WRITE;
/*!40000 ALTER TABLE `piwigo_group_access` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_group_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_groups`
--

DROP TABLE IF EXISTS `piwigo_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_groups` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_default` tinyint unsigned NOT NULL DEFAULT '0',
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groups_ui1` (`name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_groups`
--

LOCK TABLES `piwigo_groups` WRITE;
/*!40000 ALTER TABLE `piwigo_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_history`
--

DROP TABLE IF EXISTS `piwigo_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `date` date DEFAULT NULL,
  `time` time NOT NULL DEFAULT '00:00:00',
  `user_id` mediumint unsigned NOT NULL,
  `IP` char(39) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `section` enum('categories','tags','search','list','favorites','most_visited','best_rated','recent_pics','recent_cats') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` smallint unsigned DEFAULT NULL,
  `search_id` int unsigned DEFAULT NULL,
  `tag_ids` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_id` mediumint unsigned DEFAULT NULL,
  `image_type` enum('picture','high','other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `format_id` int unsigned DEFAULT NULL,
  `auth_key_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_history_image_id` (`image_id`),
  KEY `fk_history_category_id` (`category_id`),
  KEY `fk_history_search_id` (`search_id`),
  KEY `fk_history_format_id` (`format_id`),
  KEY `fk_history_auth_key_id` (`auth_key_id`),
  KEY `fk_history_user_id` (`user_id`),
  CONSTRAINT `fk_history_auth_key_id` FOREIGN KEY (`auth_key_id`) REFERENCES `piwigo_user_auth_keys` (`auth_key_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_format_id` FOREIGN KEY (`format_id`) REFERENCES `piwigo_image_format` (`format_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_search_id` FOREIGN KEY (`search_id`) REFERENCES `piwigo_search` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_history_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_history`
--

LOCK TABLES `piwigo_history` WRITE;
/*!40000 ALTER TABLE `piwigo_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_history_summary`
--

DROP TABLE IF EXISTS `piwigo_history_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_history_summary` (
  `summary_id` int unsigned NOT NULL AUTO_INCREMENT,
  `year` smallint NOT NULL DEFAULT '0',
  `month` tinyint DEFAULT NULL,
  `day` tinyint DEFAULT NULL,
  `hour` tinyint DEFAULT NULL,
  `nb_pages` int DEFAULT NULL,
  `history_id_from` int unsigned DEFAULT NULL,
  `history_id_to` int unsigned DEFAULT NULL,
  PRIMARY KEY (`summary_id`),
  UNIQUE KEY `history_summary_ymdh` (`year`,`month`,`day`,`hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_history_summary`
--

LOCK TABLES `piwigo_history_summary` WRITE;
/*!40000 ALTER TABLE `piwigo_history_summary` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_history_summary` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_image_category`
--

DROP TABLE IF EXISTS `piwigo_image_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_image_category` (
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `category_id` smallint unsigned NOT NULL DEFAULT '0',
  `rank` mediumint unsigned DEFAULT NULL,
  PRIMARY KEY (`image_id`,`category_id`),
  KEY `image_category_i1` (`category_id`),
  CONSTRAINT `fk_image_category_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_image_category_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_image_category`
--

LOCK TABLES `piwigo_image_category` WRITE;
/*!40000 ALTER TABLE `piwigo_image_category` DISABLE KEYS */;
INSERT INTO `piwigo_image_category` VALUES (1,1,1),(2,1,2),(3,1,3),(4,2,1),(5,2,2);
/*!40000 ALTER TABLE `piwigo_image_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_image_format`
--

DROP TABLE IF EXISTS `piwigo_image_format`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_image_format` (
  `format_id` int unsigned NOT NULL AUTO_INCREMENT,
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `ext` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filesize` mediumint unsigned DEFAULT NULL,
  PRIMARY KEY (`format_id`),
  KEY `fk_image_format_image_id` (`image_id`),
  CONSTRAINT `fk_image_format_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_image_format`
--

LOCK TABLES `piwigo_image_format` WRITE;
/*!40000 ALTER TABLE `piwigo_image_format` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_image_format` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_image_tag`
--

DROP TABLE IF EXISTS `piwigo_image_tag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_image_tag` (
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `tag_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`image_id`,`tag_id`),
  KEY `image_tag_i1` (`tag_id`),
  CONSTRAINT `fk_image_tag_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_image_tag_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `piwigo_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_image_tag`
--

LOCK TABLES `piwigo_image_tag` WRITE;
/*!40000 ALTER TABLE `piwigo_image_tag` DISABLE KEYS */;
INSERT INTO `piwigo_image_tag` VALUES (1,1),(2,1),(3,1),(1,2),(1,3);
/*!40000 ALTER TABLE `piwigo_image_tag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_images`
--

DROP TABLE IF EXISTS `piwigo_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_images` (
  `id` mediumint unsigned NOT NULL AUTO_INCREMENT,
  `file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `date_available` datetime DEFAULT NULL,
  `date_creation` datetime DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci,
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hit` int unsigned NOT NULL DEFAULT '0',
  `filesize` mediumint unsigned DEFAULT NULL,
  `width` smallint unsigned DEFAULT NULL,
  `height` smallint unsigned DEFAULT NULL,
  `coi` char(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'center of interest',
  `representative_ext` varchar(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_metadata_update` date DEFAULT NULL,
  `rating_score` float(5,2) unsigned DEFAULT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `storage_category_id` smallint unsigned DEFAULT NULL,
  `level` tinyint unsigned NOT NULL DEFAULT '0',
  `md5sum` char(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_by` mediumint unsigned DEFAULT NULL,
  `rotation` tinyint unsigned DEFAULT NULL,
  `latitude` double(8,6) DEFAULT NULL,
  `longitude` double(9,6) DEFAULT NULL,
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `images_i2` (`date_available`),
  KEY `images_i3` (`rating_score`),
  KEY `images_i4` (`hit`),
  KEY `images_i5` (`date_creation`),
  KEY `images_i1` (`storage_category_id`),
  KEY `images_i6` (`latitude`),
  KEY `images_i7` (`path`),
  KEY `images_added_by_idx` (`added_by`),
  KEY `lastmodified` (`lastmodified`),
  FULLTEXT KEY `images_ft_name_comment` (`name`,`comment`),
  FULLTEXT KEY `images_ft_author` (`author`),
  CONSTRAINT `fk_images_added_by` FOREIGN KEY (`added_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_images_storage_category_id` FOREIGN KEY (`storage_category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_images`
--

LOCK TABLES `piwigo_images` WRITE;
/*!40000 ALTER TABLE `piwigo_images` DISABLE KEYS */;
INSERT INTO `piwigo_images` VALUES (1,'fixture-photo-1.jpg','2026-05-18 11:34:53',NULL,'Photo 1',NULL,NULL,0,1,200,150,NULL,NULL,'2026-05-18',NULL,'./upload/2026/05/18/20260518113453-55466850.jpg',NULL,0,'5546fa1edd0462ac09601a7bb95d6a72',1,0,NULL,NULL,'2026-05-18 11:34:53'),(2,'fixture-photo-2.jpg','2026-05-18 11:34:53',NULL,'Photo 2',NULL,NULL,0,2,200,150,NULL,NULL,'2026-05-18',NULL,'./upload/2026/05/18/20260518113453-93015f27.jpg',NULL,0,'9301b1d8c5aae30c2caf6d595dfd098f',1,0,NULL,NULL,'2026-05-18 11:34:53'),(3,'fixture-photo-3.jpg','2026-05-18 11:34:53',NULL,'Photo 3',NULL,NULL,0,2,200,150,NULL,NULL,'2026-05-18',NULL,'./upload/2026/05/18/20260518113453-1ff9a554.jpg',NULL,0,'1ff94252c3f9068be2700af24d13ec3e',1,0,NULL,NULL,'2026-05-18 11:34:54'),(4,'fixture-photo-4.jpg','2026-05-18 11:34:54',NULL,'Photo 4',NULL,NULL,0,2,200,150,NULL,NULL,'2026-05-18',NULL,'./upload/2026/05/18/20260518113454-104b12d5.jpg',NULL,0,'104bdc0e650a06975e0939e885765625',1,0,NULL,NULL,'2026-05-18 11:34:54'),(5,'fixture-photo-5.jpg','2026-05-18 11:34:54',NULL,'Photo 5',NULL,NULL,0,2,200,150,NULL,NULL,'2026-05-18',NULL,'./upload/2026/05/18/20260518113454-e862c293.jpg',NULL,0,'e862826f53575b0892b832e5f4b088e0',1,0,NULL,NULL,'2026-05-18 11:34:54');
/*!40000 ALTER TABLE `piwigo_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_integrity_ignored_anomalies`
--

DROP TABLE IF EXISTS `piwigo_integrity_ignored_anomalies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_integrity_ignored_anomalies` (
  `anomaly_id` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `piwigo_version` varchar(16) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `ignored_at` datetime NOT NULL,
  PRIMARY KEY (`anomaly_id`,`piwigo_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='integrity-check anomalies the admin has acknowledged (replaces c13y_ignore serialize() blob)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_integrity_ignored_anomalies`
--

LOCK TABLES `piwigo_integrity_ignored_anomalies` WRITE;
/*!40000 ALTER TABLE `piwigo_integrity_ignored_anomalies` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_integrity_ignored_anomalies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_languages`
--

DROP TABLE IF EXISTS `piwigo_languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_languages` (
  `id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `version` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_languages`
--

LOCK TABLES `piwigo_languages` WRITE;
/*!40000 ALTER TABLE `piwigo_languages` DISABLE KEYS */;
INSERT INTO `piwigo_languages` VALUES ('en_US','0','English (US)');
/*!40000 ALTER TABLE `piwigo_languages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_lounge`
--

DROP TABLE IF EXISTS `piwigo_lounge`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_lounge` (
  `image_id` mediumint unsigned NOT NULL DEFAULT '0',
  `category_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`image_id`,`category_id`),
  KEY `fk_lounge_category_id` (`category_id`),
  CONSTRAINT `fk_lounge_category_id` FOREIGN KEY (`category_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lounge_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_lounge`
--

LOCK TABLES `piwigo_lounge` WRITE;
/*!40000 ALTER TABLE `piwigo_lounge` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_lounge` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_messenger_messages`
--

DROP TABLE IF EXISTS `piwigo_messenger_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_messenger_messages` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `body` longtext NOT NULL,
  `headers` longtext NOT NULL,
  `queue_name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL,
  `available_at` datetime NOT NULL,
  `delivered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_FBD6EF10FB7336F0E3BD61CE16BA31DBBF396750` (`queue_name`,`available_at`,`delivered_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_messenger_messages`
--

LOCK TABLES `piwigo_messenger_messages` WRITE;
/*!40000 ALTER TABLE `piwigo_messenger_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_messenger_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_old_permalinks`
--

DROP TABLE IF EXISTS `piwigo_old_permalinks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_old_permalinks` (
  `cat_id` smallint unsigned NOT NULL DEFAULT '0',
  `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `date_deleted` datetime DEFAULT NULL,
  `last_hit` datetime DEFAULT NULL,
  `hit` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`permalink`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_old_permalinks`
--

LOCK TABLES `piwigo_old_permalinks` WRITE;
/*!40000 ALTER TABLE `piwigo_old_permalinks` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_old_permalinks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_plugin_migrations`
--

DROP TABLE IF EXISTS `piwigo_plugin_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_plugin_migrations` (
  `plugin_id` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `version` varchar(191) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `executed_at` datetime NOT NULL,
  PRIMARY KEY (`plugin_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_plugin_migrations`
--

LOCK TABLES `piwigo_plugin_migrations` WRITE;
/*!40000 ALTER TABLE `piwigo_plugin_migrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_plugin_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_plugins`
--

DROP TABLE IF EXISTS `piwigo_plugins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_plugins` (
  `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `state` enum('inactive','active') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inactive',
  `version` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_plugins`
--

LOCK TABLES `piwigo_plugins` WRITE;
/*!40000 ALTER TABLE `piwigo_plugins` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_plugins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_rate`
--

DROP TABLE IF EXISTS `piwigo_rate`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_rate` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `element_id` mediumint unsigned NOT NULL DEFAULT '0',
  `anonymous_id` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `rate` tinyint unsigned NOT NULL DEFAULT '0',
  `date` date DEFAULT NULL,
  PRIMARY KEY (`element_id`,`user_id`,`anonymous_id`),
  CONSTRAINT `fk_rate_element_id` FOREIGN KEY (`element_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_rate`
--

LOCK TABLES `piwigo_rate` WRITE;
/*!40000 ALTER TABLE `piwigo_rate` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_rate` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_search`
--

DROP TABLE IF EXISTS `piwigo_search`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_search` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `search_uuid` char(23) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_on` datetime DEFAULT NULL,
  `created_by` mediumint unsigned DEFAULT NULL,
  `forked_from` int unsigned DEFAULT NULL,
  `rules` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_search_created_by` (`created_by`),
  KEY `fk_search_forked_from` (`forked_from`),
  CONSTRAINT `fk_search_created_by` FOREIGN KEY (`created_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_search_forked_from` FOREIGN KEY (`forked_from`) REFERENCES `piwigo_search` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_search`
--

LOCK TABLES `piwigo_search` WRITE;
/*!40000 ALTER TABLE `piwigo_search` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_search` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_search_filter_view`
--

DROP TABLE IF EXISTS `piwigo_search_filter_view`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_search_filter_view` (
  `name` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `config_json` json NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='admin search filter-view presets (replaces filters_views serialize() blob)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_search_filter_view`
--

LOCK TABLES `piwigo_search_filter_view` WRITE;
/*!40000 ALTER TABLE `piwigo_search_filter_view` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_search_filter_view` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_sessions`
--

DROP TABLE IF EXISTS `piwigo_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_sessions` (
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `data` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_sessions`
--

LOCK TABLES `piwigo_sessions` WRITE;
/*!40000 ALTER TABLE `piwigo_sessions` DISABLE KEYS */;
INSERT INTO `piwigo_sessions` VALUES ('bd443525969b8b2190340707c85a0304','pwg_uid|i:1;connected_with|s:6:\"pwg_ui\";','2026-05-18 08:34:51'),('ce974bb133cd6831cdfb31809a2cfc09','pwg_uid|i:1;connected_with|s:16:\"ws_session_login\";','2026-05-18 08:34:56');
/*!40000 ALTER TABLE `piwigo_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_sites`
--

DROP TABLE IF EXISTS `piwigo_sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_sites` (
  `id` tinyint NOT NULL AUTO_INCREMENT,
  `galleries_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `sites_ui1` (`galleries_url`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_sites`
--

LOCK TABLES `piwigo_sites` WRITE;
/*!40000 ALTER TABLE `piwigo_sites` DISABLE KEYS */;
INSERT INTO `piwigo_sites` VALUES (1,'/home/torres/piwigo16/galleries/');
/*!40000 ALTER TABLE `piwigo_sites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_tags`
--

DROP TABLE IF EXISTS `piwigo_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_tags` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `url_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tags_i1` (`url_name`),
  KEY `lastmodified` (`lastmodified`),
  FULLTEXT KEY `tags_ft_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_tags`
--

LOCK TABLES `piwigo_tags` WRITE;
/*!40000 ALTER TABLE `piwigo_tags` DISABLE KEYS */;
INSERT INTO `piwigo_tags` VALUES (1,'nature','nature','2026-05-18 11:34:55'),(2,'travel','travel','2026-05-18 11:34:55'),(3,'family','family','2026-05-18 11:34:55');
/*!40000 ALTER TABLE `piwigo_tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_themes`
--

DROP TABLE IF EXISTS `piwigo_themes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_themes` (
  `id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `version` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_themes`
--

LOCK TABLES `piwigo_themes` WRITE;
/*!40000 ALTER TABLE `piwigo_themes` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_themes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_upgrade`
--

DROP TABLE IF EXISTS `piwigo_upgrade`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_upgrade` (
  `id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `applied` datetime DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_upgrade`
--

LOCK TABLES `piwigo_upgrade` WRITE;
/*!40000 ALTER TABLE `piwigo_upgrade` DISABLE KEYS */;
INSERT INTO `piwigo_upgrade` VALUES ('181','2026-05-18 08:34:51','Piwigo 15.0.0 schema baseline');
/*!40000 ALTER TABLE `piwigo_upgrade` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_access`
--

DROP TABLE IF EXISTS `piwigo_user_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_access` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `cat_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`,`cat_id`),
  KEY `fk_user_access_cat_id` (`cat_id`),
  CONSTRAINT `fk_user_access_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_access_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_access`
--

LOCK TABLES `piwigo_user_access` WRITE;
/*!40000 ALTER TABLE `piwigo_user_access` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_user_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_auth_keys`
--

DROP TABLE IF EXISTS `piwigo_user_auth_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_auth_keys` (
  `auth_key_id` int unsigned NOT NULL AUTO_INCREMENT,
  `auth_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apikey_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` mediumint unsigned NOT NULL,
  `created_on` datetime NOT NULL,
  `duration` int unsigned DEFAULT NULL,
  `expired_on` datetime NOT NULL,
  `apikey_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `key_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `revoked_on` datetime DEFAULT NULL,
  `last_used_on` datetime DEFAULT NULL,
  `last_notified_on` datetime DEFAULT NULL,
  PRIMARY KEY (`auth_key_id`),
  KEY `fk_user_auth_keys_user_id` (`user_id`),
  CONSTRAINT `fk_user_auth_keys_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_auth_keys`
--

LOCK TABLES `piwigo_user_auth_keys` WRITE;
/*!40000 ALTER TABLE `piwigo_user_auth_keys` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_user_auth_keys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_cache`
--

DROP TABLE IF EXISTS `piwigo_user_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_cache` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `need_update` tinyint unsigned NOT NULL DEFAULT '1',
  `cache_update_time` int unsigned NOT NULL DEFAULT '0',
  `forbidden_categories` json DEFAULT NULL,
  `nb_total_images` mediumint unsigned DEFAULT NULL,
  `last_photo_date` datetime DEFAULT NULL,
  `nb_available_tags` int DEFAULT NULL,
  `nb_available_comments` int DEFAULT NULL,
  `image_access_type` enum('NOT IN','IN') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NOT IN',
  `image_access_list` json DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `user_cache_need_update_idx` (`need_update`),
  CONSTRAINT `fk_user_cache_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_cache`
--

LOCK TABLES `piwigo_user_cache` WRITE;
/*!40000 ALTER TABLE `piwigo_user_cache` DISABLE KEYS */;
INSERT INTO `piwigo_user_cache` VALUES (1,0,1779104096,'[0]',5,'2026-05-18 11:34:54',NULL,NULL,'NOT IN','[0]');
/*!40000 ALTER TABLE `piwigo_user_cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_cache_categories`
--

DROP TABLE IF EXISTS `piwigo_user_cache_categories`;
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
  KEY `fk_user_cache_categories_cat_id` (`cat_id`),
  CONSTRAINT `fk_user_cache_categories_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_cache_categories_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_cache_categories`
--

LOCK TABLES `piwigo_user_cache_categories` WRITE;
/*!40000 ALTER TABLE `piwigo_user_cache_categories` DISABLE KEYS */;
INSERT INTO `piwigo_user_cache_categories` VALUES (1,1,'2026-05-18 11:34:53','2026-05-18 11:34:54',3,5,1,1,NULL),(1,2,'2026-05-18 11:34:54','2026-05-18 11:34:54',2,2,0,0,NULL);
/*!40000 ALTER TABLE `piwigo_user_cache_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_feed`
--

DROP TABLE IF EXISTS `piwigo_user_feed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_feed` (
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `last_check` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_user_feed_user_id` (`user_id`),
  CONSTRAINT `fk_user_feed_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_feed`
--

LOCK TABLES `piwigo_user_feed` WRITE;
/*!40000 ALTER TABLE `piwigo_user_feed` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_user_feed` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_group`
--

DROP TABLE IF EXISTS `piwigo_user_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_group` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `group_id` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`group_id`,`user_id`),
  KEY `fk_user_group_user_id` (`user_id`),
  CONSTRAINT `fk_user_group_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_group_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_group`
--

LOCK TABLES `piwigo_user_group` WRITE;
/*!40000 ALTER TABLE `piwigo_user_group` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_user_group` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_infos`
--

DROP TABLE IF EXISTS `piwigo_user_infos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_infos` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `nb_image_page` smallint unsigned NOT NULL DEFAULT '15',
  `status` enum('webmaster','admin','normal','generic','guest') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'guest',
  `language` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_UK',
  `expand` tinyint unsigned NOT NULL DEFAULT '0',
  `show_nb_comments` tinyint unsigned NOT NULL DEFAULT '0',
  `show_nb_hits` tinyint unsigned NOT NULL DEFAULT '0',
  `recent_period` tinyint unsigned NOT NULL DEFAULT '7',
  `theme` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'modus',
  `registration_date` datetime DEFAULT NULL,
  `enabled_high` tinyint unsigned NOT NULL DEFAULT '1',
  `level` tinyint unsigned NOT NULL DEFAULT '0',
  `activation_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activation_key_expire` datetime DEFAULT NULL,
  `last_visit` datetime DEFAULT NULL,
  `last_visit_from_history` tinyint unsigned NOT NULL DEFAULT '0',
  `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `preferences` json DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `lastmodified` (`lastmodified`),
  CONSTRAINT `fk_user_infos_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_infos`
--

LOCK TABLES `piwigo_user_infos` WRITE;
/*!40000 ALTER TABLE `piwigo_user_infos` DISABLE KEYS */;
INSERT INTO `piwigo_user_infos` VALUES (1,15,'webmaster','en_US',0,0,0,7,'modus','2026-05-18 11:34:51',1,8,NULL,NULL,NULL,0,'2026-05-18 11:34:51','[]'),(2,15,'guest','en_US',0,0,0,7,'modus','2026-05-18 11:34:51',1,0,NULL,NULL,NULL,0,'2026-05-18 11:34:51',NULL),(3,15,'normal','en_US',0,0,0,7,'modus','2026-05-18 11:34:55',1,0,NULL,NULL,NULL,0,'2026-05-18 11:34:51',NULL),(4,15,'normal','en_US',0,0,0,7,'modus','2026-05-18 11:34:56',1,0,NULL,NULL,NULL,0,'2026-05-18 11:34:51',NULL);
/*!40000 ALTER TABLE `piwigo_user_infos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_mail_notification`
--

DROP TABLE IF EXISTS `piwigo_user_mail_notification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_mail_notification` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `check_key` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `last_send` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_mail_notification_ui1` (`check_key`),
  CONSTRAINT `fk_user_mail_notification_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_mail_notification`
--

LOCK TABLES `piwigo_user_mail_notification` WRITE;
/*!40000 ALTER TABLE `piwigo_user_mail_notification` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_user_mail_notification` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_users`
--

DROP TABLE IF EXISTS `piwigo_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_users` (
  `id` mediumint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mail_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_ui1` (`username`),
  UNIQUE KEY `users_mail_idx` (`mail_address`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_users`
--

LOCK TABLES `piwigo_users` WRITE;
/*!40000 ALTER TABLE `piwigo_users` DISABLE KEYS */;
INSERT INTO `piwigo_users` VALUES (1,'fixture_admin','$2y$12$w2VoETssf4oP2w9Rs9Jeoeica1xi3S79CKMwHENgbCtNwuJQnyLPu','fixture_admin@example.test'),(2,'guest',NULL,NULL),(3,'regular_user','$2y$12$0LsNZD9yeVS/87PKcLkdd.EfFBUK0Z97gg/J/48ONTo/Y/FbhtyxK',NULL),(4,'power_user','$2y$12$Dnfxg0g8FQ1AXWyFxvi6eOvPJfR0vPkHw2uVc.aIgNtZI3ny7oVFa',NULL);
/*!40000 ALTER TABLE `piwigo_users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-18  8:34:56
