-- MySQL dump 10.13  Distrib 8.4.6, for Win64 (x86_64)
--
-- Host: localhost    Database: piwigo_fixture_build
-- ------------------------------------------------------
-- Server version	8.4.6

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
  `object` varchar(255) NOT NULL,
  `object_id` int unsigned NOT NULL,
  `action` varchar(255) NOT NULL,
  `performed_by` mediumint unsigned NOT NULL,
  `session_idx` varchar(255) NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `occured_on` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `details` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`activity_id`)
) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_activity`
--

LOCK TABLES `piwigo_activity` WRITE;
/*!40000 ALTER TABLE `piwigo_activity` DISABLE KEYS */;
INSERT INTO `piwigo_activity` VALUES (1,'system',1,'install',0,'none','::1','2026-05-07 17:30:01','a:2:{s:7:\"version\";s:6:\"16.3.0\";s:6:\"script\";s:5:\"index\";}',NULL),(2,'user',1,'login',0,'6e440f13927a0527e0fe20506844382b','::1','2026-05-07 17:30:01','a:1:{s:6:\"script\";s:5:\"index\";}','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.7727.15 Safari/537.36'),(3,'user',1,'login',2,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:02','a:1:{s:6:\"method\";s:17:\"pwg.session.login\";}','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.7727.15 Safari/537.36'),(4,'album',1,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:03','a:1:{s:6:\"method\";s:18:\"pwg.categories.add\";}',NULL),(5,'album',2,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:03','a:1:{s:6:\"method\";s:18:\"pwg.categories.add\";}',NULL),(6,'photo',1,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:03','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(7,'photo',2,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:04','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(8,'photo',3,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:05','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(9,'photo',4,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:06','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(10,'photo',5,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:06','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(11,'tag',1,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:07','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),(12,'tag',2,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:07','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),(13,'tag',3,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:07','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),(14,'user',3,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:08','a:1:{s:6:\"method\";s:13:\"pwg.users.add\";}',NULL),(15,'user',4,'add',1,'686d6953fb1b9a77b8b6f1d9c37cd0e6','::1','2026-05-07 17:30:08','a:1:{s:6:\"method\";s:13:\"pwg.users.add\";}',NULL);
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
  `element_id` mediumint NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`,`element_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `name` varchar(255) NOT NULL DEFAULT '',
  `id_uppercat` smallint unsigned DEFAULT NULL,
  `comment` text,
  `dir` varchar(255) DEFAULT NULL,
  `rank` smallint unsigned DEFAULT NULL,
  `status` enum('public','private') NOT NULL DEFAULT 'public',
  `site_id` tinyint unsigned DEFAULT NULL,
  `visible` enum('true','false') NOT NULL DEFAULT 'true',
  `representative_picture_id` mediumint unsigned DEFAULT NULL,
  `uppercats` varchar(255) NOT NULL DEFAULT '',
  `commentable` enum('true','false') NOT NULL DEFAULT 'true',
  `global_rank` varchar(255) DEFAULT NULL,
  `image_order` varchar(128) DEFAULT NULL,
  `permalink` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin DEFAULT NULL,
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_i3` (`permalink`),
  KEY `categories_i2` (`id_uppercat`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_categories`
--

LOCK TABLES `piwigo_categories` WRITE;
/*!40000 ALTER TABLE `piwigo_categories` DISABLE KEYS */;
INSERT INTO `piwigo_categories` VALUES (1,'Sample Album',NULL,NULL,NULL,1,'public',NULL,'true',1,'1','true','1',NULL,NULL,'2026-05-07 17:30:04'),(2,'Nested Sub Album',1,NULL,NULL,1,'public',NULL,'true',4,'1,2','true','1.1',NULL,NULL,'2026-05-07 17:30:06');
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
  `date` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `author` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `author_id` mediumint unsigned DEFAULT NULL,
  `anonymous_id` varchar(45) NOT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `content` longtext,
  `validated` enum('true','false') NOT NULL DEFAULT 'false',
  `validation_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comments_i2` (`validation_date`),
  KEY `comments_i1` (`image_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_comments`
--

LOCK TABLES `piwigo_comments` WRITE;
/*!40000 ALTER TABLE `piwigo_comments` DISABLE KEYS */;
INSERT INTO `piwigo_comments` VALUES (1,1,'2026-05-07 14:30:07','fixture_admin',NULL,1,'127.0.0.1',NULL,'Fixture comment for integration tests.','true','2026-05-07 14:30:07');
/*!40000 ALTER TABLE `piwigo_comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_config`
--

DROP TABLE IF EXISTS `piwigo_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_config` (
  `param` varchar(40) NOT NULL DEFAULT '',
  `value` text,
  `comment` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`param`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COMMENT='configuration table';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_config`
--

LOCK TABLES `piwigo_config` WRITE;
/*!40000 ALTER TABLE `piwigo_config` DISABLE KEYS */;
INSERT INTO `piwigo_config` VALUES ('activate_comments','false','Global parameter for usage of comments system'),('nb_comment_page','10','number of comments to display on each page'),('log','true','keep an history of visits on your website'),('comments_validation','true','administrators validate users comments before becoming visible'),('comments_forall','false','even guest not registered can post comments'),('comments_order','ASC','comments order on picture page and cie'),('comments_author_mandatory','false','Comment author is mandatory'),('comments_email_mandatory','false','Comment email is mandatory'),('comments_enable_website','true','Enable \"website\" field on add comment form'),('user_can_delete_comment','false','administrators can allow user delete their own comments'),('user_can_edit_comment','false','administrators can allow user edit their own comments'),('email_admin_on_comment_edition','false','Send an email to the administrators when a comment is modified'),('email_admin_on_comment_deletion','false','Send an email to the administrators when a comment is deleted'),('gallery_locked','false','Lock your gallery temporary for non admin users'),('gallery_title','Fixture Gallery','Title at top of each page and for RSS feed'),('rate','false','Rating pictures feature is enabled'),('rate_anonymous','true','Rating pictures feature is also enabled for visitors'),('page_banner','<h1>%gallery_title%</h1>\n\n<p>Welcome to my photo gallery</p>','html displayed on the top each page of your gallery'),('history_admin','false','keep a history of administrator visits on your website'),('history_guest','true','keep a history of guest visits on your website'),('allow_user_registration','true','allow visitors to register?'),('allow_user_customization','true','allow users to customize their gallery?'),('nb_categories_page','12','Param for categories pagination'),('nbm_send_html_mail','true','Send mail on HTML format for notification by mail'),('nbm_send_mail_as','','Send mail as param value for notification by mail'),('nbm_send_detailed_content','true','Send detailed content for notification by mail'),('nbm_complementary_mail_content','','Complementary mail content for notification by mail'),('nbm_send_recent_post_dates','true','Send recent post by dates for notification by mail'),('email_admin_on_new_user','none','Send an email to theadministrators when a user registers'),('email_admin_on_comment','false','Send an email to the administrators when a valid comment is entered'),('email_admin_on_comment_validation','true','Send an email to the administrators when a comment requires validation'),('obligatory_user_mail_address','false','Mail address is obligatory for users'),('c13y_ignore',NULL,'List of ignored anomalies'),('extents_for_templates','a:0:{}','Actived template-extension(s)'),('blk_menubar','','Menubar options'),('menubar_filter_icon','false','Display filter icon'),('index_sort_order_input','true','Display image order selection list'),('index_flat_icon','false','Display flat icon'),('index_posted_date_icon','true','Display calendar by posted date'),('index_created_date_icon','true','Display calendar by creation date icon'),('index_slideshow_icon','true','Display slideshow icon'),('index_new_icon','true','Display new icons next albums and pictures'),('picture_metadata_icon','true','Display metadata icon on picture page'),('picture_slideshow_icon','true','Display slideshow icon on picture page'),('picture_favorite_icon','true','Display favorite icon on picture page'),('picture_download_icon','true','Display download icon on picture page'),('picture_navigation_icons','true','Display navigation icons on picture page'),('picture_navigation_thumb','true','Display navigation thumbnails on picture page'),('picture_menu','false','Show menubar on picture page'),('picture_informations','a:11:{s:6:\"author\";b:1;s:10:\"created_on\";b:1;s:9:\"posted_on\";b:1;s:10:\"dimensions\";b:0;s:4:\"file\";b:0;s:8:\"filesize\";b:0;s:4:\"tags\";b:1;s:10:\"categories\";b:1;s:6:\"visits\";b:1;s:12:\"rating_score\";b:1;s:13:\"privacy_level\";b:1;}','Information displayed on picture page'),('week_starts_on','monday','Monday may not be the first day of the week'),('updates_ignored','a:3:{s:7:\"plugins\";a:0:{}s:6:\"themes\";a:0:{}s:9:\"languages\";a:0:{}}','Extensions ignored for update'),('order_by','ORDER BY date_available DESC, file ASC, id ASC','default photo order'),('order_by_inside_category','ORDER BY date_available DESC, file ASC, id ASC','default photo order inside category'),('original_resize','false',NULL),('original_resize_maxwidth','2016',NULL),('original_resize_maxheight','2016',NULL),('original_resize_quality','95',NULL),('mobile_theme',NULL,NULL),('mail_theme','clear',NULL),('picture_sizes_icon','true',NULL),('index_sizes_icon','true',NULL),('index_edit_icon','true',NULL),('index_caddie_icon','true',NULL),('display_fromto','false',NULL),('picture_edit_icon','true',NULL),('picture_caddie_icon','true',NULL),('picture_representative_icon','true',NULL),('show_mobile_app_banner_in_admin','true',NULL),('show_mobile_app_banner_in_gallery','false',NULL),('index_search_in_set_button','false',NULL),('index_search_in_set_action','true',NULL),('upload_detect_duplicate','true',NULL),('webmaster_id','1',NULL),('use_standard_pages','true',NULL),('secret_key','3045005d33319b2581fe5d2eee930c84bc57463b','a secret key specific to the gallery for internal use'),('piwigo_db_version','17',NULL),('derivatives','a:4:{s:1:\"d\";a:9:{s:6:\"square\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:120;i:1;i:120;}s:8:\"max_crop\";i:1;s:8:\"min_size\";a:2:{i:0;i:120;i:1;i:120;}}s:7:\"sharpen\";i:0;}s:5:\"thumb\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:144;i:1;i:144;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"2small\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:240;i:1;i:240;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"xsmall\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:432;i:1;i:324;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:5:\"small\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:576;i:1;i:432;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"medium\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:792;i:1;i:594;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:5:\"large\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:1008;i:1;i:756;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"xlarge\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:1224;i:1;i:918;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:7:\"xxlarge\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:1656;i:1;i:1242;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}}s:1:\"q\";i:95;s:1:\"w\";O:28:\"Piwigo\\Image\\WatermarkParams\":7:{s:4:\"file\";s:0:\"\";s:8:\"min_size\";a:2:{i:0;i:500;i:1;i:500;}s:4:\"xpos\";i:50;s:4:\"ypos\";i:50;s:7:\"xrepeat\";i:0;s:7:\"yrepeat\";i:0;s:7:\"opacity\";i:100;}s:1:\"c\";a:0:{}}',NULL),('disabled_derivatives','a:2:{s:7:\"3xlarge\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:2232;i:1;i:1674;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:7:\"4xlarge\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1778175002;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:3000;i:1;i:2250;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}}',NULL),('piwigo_installed_version','17.0.0',NULL),('last_major_update','2026-05-07 17:30:02',NULL),('data_dir_checked','1',NULL),('lounge_active','true',NULL);
/*!40000 ALTER TABLE `piwigo_config` ENABLE KEYS */;
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
  PRIMARY KEY (`user_id`,`image_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  PRIMARY KEY (`group_id`,`cat_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `name` varchar(255) NOT NULL DEFAULT '',
  `is_default` enum('true','false') NOT NULL DEFAULT 'false',
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groups_ui1` (`name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `date` date NOT NULL DEFAULT '1970-01-01',
  `time` time NOT NULL DEFAULT '00:00:00',
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `IP` char(39) NOT NULL DEFAULT '',
  `section` enum('categories','tags','search','list','favorites','most_visited','best_rated','recent_pics','recent_cats') DEFAULT NULL,
  `category_id` smallint DEFAULT NULL,
  `search_id` int unsigned DEFAULT NULL,
  `tag_ids` varchar(50) DEFAULT NULL,
  `image_id` mediumint DEFAULT NULL,
  `image_type` enum('picture','high','other') DEFAULT NULL,
  `format_id` int unsigned DEFAULT NULL,
  `auth_key_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `year` smallint NOT NULL DEFAULT '0',
  `month` tinyint DEFAULT NULL,
  `day` tinyint DEFAULT NULL,
  `hour` tinyint DEFAULT NULL,
  `nb_pages` int DEFAULT NULL,
  `history_id_from` int unsigned DEFAULT NULL,
  `history_id_to` int unsigned DEFAULT NULL,
  UNIQUE KEY `history_summary_ymdh` (`year`,`month`,`day`,`hour`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  KEY `image_category_i1` (`category_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `ext` varchar(255) NOT NULL,
  `filesize` mediumint unsigned DEFAULT NULL,
  PRIMARY KEY (`format_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  KEY `image_tag_i1` (`tag_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_image_tag`
--

LOCK TABLES `piwigo_image_tag` WRITE;
/*!40000 ALTER TABLE `piwigo_image_tag` DISABLE KEYS */;
INSERT INTO `piwigo_image_tag` VALUES (1,1),(1,2),(1,3),(2,1),(3,1);
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
  `file` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `date_available` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `date_creation` datetime DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `comment` text,
  `author` varchar(255) DEFAULT NULL,
  `hit` int unsigned NOT NULL DEFAULT '0',
  `filesize` mediumint unsigned DEFAULT NULL,
  `width` smallint unsigned DEFAULT NULL,
  `height` smallint unsigned DEFAULT NULL,
  `coi` char(4) DEFAULT NULL COMMENT 'center of interest',
  `representative_ext` varchar(4) DEFAULT NULL,
  `date_metadata_update` date DEFAULT NULL,
  `rating_score` float(5,2) unsigned DEFAULT NULL,
  `path` varchar(255) NOT NULL DEFAULT '',
  `storage_category_id` smallint unsigned DEFAULT NULL,
  `level` tinyint unsigned NOT NULL DEFAULT '0',
  `md5sum` char(32) DEFAULT NULL,
  `added_by` mediumint unsigned NOT NULL DEFAULT '0',
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
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_images`
--

LOCK TABLES `piwigo_images` WRITE;
/*!40000 ALTER TABLE `piwigo_images` DISABLE KEYS */;
INSERT INTO `piwigo_images` VALUES (1,'fixture-photo-1.jpg','2026-05-07 17:30:03',NULL,'Photo 1',NULL,NULL,0,1,200,150,NULL,NULL,'2026-05-07',NULL,'./upload/2026/05/07/20260507173003-eedd9b00.jpg',NULL,0,'eedd069e93365605e739cb212ce85c70',1,0,NULL,NULL,'2026-05-07 17:30:03'),(2,'fixture-photo-2.jpg','2026-05-07 17:30:04',NULL,'Photo 2',NULL,NULL,0,2,200,150,NULL,NULL,'2026-05-07',NULL,'./upload/2026/05/07/20260507173004-4e2b2278.jpg',NULL,0,'4e2b6a045d55f1f5e79b43d24ecb9c92',1,0,NULL,NULL,'2026-05-07 17:30:04'),(3,'fixture-photo-3.jpg','2026-05-07 17:30:05',NULL,'Photo 3',NULL,NULL,0,2,200,150,NULL,NULL,'2026-05-07',NULL,'./upload/2026/05/07/20260507173005-2e00e3d6.jpg',NULL,0,'2e00c0fa8adeef2b9516bd972133ce09',1,0,NULL,NULL,'2026-05-07 17:30:05'),(4,'fixture-photo-4.jpg','2026-05-07 17:30:05',NULL,'Photo 4',NULL,NULL,0,2,200,150,NULL,NULL,'2026-05-07',NULL,'./upload/2026/05/07/20260507173005-79d14ff1.jpg',NULL,0,'79d197144c543fa5b584fc09939b4762',1,0,NULL,NULL,'2026-05-07 17:30:06'),(5,'fixture-photo-5.jpg','2026-05-07 17:30:06',NULL,'Photo 5',NULL,NULL,0,2,200,150,NULL,NULL,'2026-05-07',NULL,'./upload/2026/05/07/20260507173006-87a17a2c.jpg',NULL,0,'87a161c7ecefd53170f45aa28242d1f3',1,0,NULL,NULL,'2026-05-07 17:30:06');
/*!40000 ALTER TABLE `piwigo_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_languages`
--

DROP TABLE IF EXISTS `piwigo_languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_languages` (
  `id` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(64) NOT NULL DEFAULT '0',
  `name` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_languages`
--

LOCK TABLES `piwigo_languages` WRITE;
/*!40000 ALTER TABLE `piwigo_languages` DISABLE KEYS */;
INSERT INTO `piwigo_languages` VALUES ('en_GB','0','English');
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
  PRIMARY KEY (`image_id`,`category_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_lounge`
--

LOCK TABLES `piwigo_lounge` WRITE;
/*!40000 ALTER TABLE `piwigo_lounge` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_lounge` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_migration_versions`
--

DROP TABLE IF EXISTS `piwigo_migration_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_migration_versions` (
  `version` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `executed_at` datetime DEFAULT NULL,
  `execution_time` int DEFAULT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_migration_versions`
--

LOCK TABLES `piwigo_migration_versions` WRITE;
/*!40000 ALTER TABLE `piwigo_migration_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_migration_versions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_old_permalinks`
--

DROP TABLE IF EXISTS `piwigo_old_permalinks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_old_permalinks` (
  `cat_id` smallint unsigned NOT NULL DEFAULT '0',
  `permalink` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `date_deleted` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `last_hit` datetime DEFAULT NULL,
  `hit` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`permalink`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_old_permalinks`
--

LOCK TABLES `piwigo_old_permalinks` WRITE;
/*!40000 ALTER TABLE `piwigo_old_permalinks` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_old_permalinks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_plugins`
--

DROP TABLE IF EXISTS `piwigo_plugins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_plugins` (
  `id` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `state` enum('inactive','active') NOT NULL DEFAULT 'inactive',
  `version` varchar(64) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_plugins`
--

LOCK TABLES `piwigo_plugins` WRITE;
/*!40000 ALTER TABLE `piwigo_plugins` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_plugins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_plugin_migrations`
--

DROP TABLE IF EXISTS `piwigo_plugin_migrations`;
CREATE TABLE `piwigo_plugin_migrations` (
  `plugin_id` varchar(64) NOT NULL,
  `version` varchar(191) NOT NULL,
  `executed_at` datetime NOT NULL,
  PRIMARY KEY (`plugin_id`,`version`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;

--
-- Table structure for table `piwigo_rate`
--

DROP TABLE IF EXISTS `piwigo_rate`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_rate` (
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `element_id` mediumint unsigned NOT NULL DEFAULT '0',
  `anonymous_id` varchar(45) NOT NULL DEFAULT '',
  `rate` tinyint unsigned NOT NULL DEFAULT '0',
  `date` date NOT NULL DEFAULT '1970-01-01',
  PRIMARY KEY (`element_id`,`user_id`,`anonymous_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `search_uuid` char(23) DEFAULT NULL,
  `created_on` datetime DEFAULT NULL,
  `created_by` mediumint unsigned DEFAULT NULL,
  `forked_from` int unsigned DEFAULT NULL,
  `rules` text,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_search`
--

LOCK TABLES `piwigo_search` WRITE;
/*!40000 ALTER TABLE `piwigo_search` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_search` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_sessions`
--

DROP TABLE IF EXISTS `piwigo_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_sessions` (
  `id` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `data` mediumtext NOT NULL,
  `expiration` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_sessions`
--

LOCK TABLES `piwigo_sessions` WRITE;
/*!40000 ALTER TABLE `piwigo_sessions` DISABLE KEYS */;
INSERT INTO `piwigo_sessions` VALUES ('6e440f13927a0527e0fe20506844382b','pwg_uid|i:1;connected_with|s:6:\"pwg_ui\";','2026-05-07 14:30:01');
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
  `galleries_url` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `sites_ui1` (`galleries_url`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_sites`
--

LOCK TABLES `piwigo_sites` WRITE;
/*!40000 ALTER TABLE `piwigo_sites` DISABLE KEYS */;
INSERT INTO `piwigo_sites` VALUES (1,'./galleries/');
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
  `name` varchar(255) NOT NULL DEFAULT '',
  `url_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tags_i1` (`url_name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_tags`
--

LOCK TABLES `piwigo_tags` WRITE;
/*!40000 ALTER TABLE `piwigo_tags` DISABLE KEYS */;
INSERT INTO `piwigo_tags` VALUES (1,'nature','nature','2026-05-07 17:30:07'),(2,'travel','travel','2026-05-07 17:30:07'),(3,'family','family','2026-05-07 17:30:07');
/*!40000 ALTER TABLE `piwigo_tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_themes`
--

DROP TABLE IF EXISTS `piwigo_themes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_themes` (
  `id` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(64) NOT NULL DEFAULT '0',
  `name` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `id` varchar(20) NOT NULL DEFAULT '',
  `applied` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_upgrade`
--

LOCK TABLES `piwigo_upgrade` WRITE;
/*!40000 ALTER TABLE `piwigo_upgrade` DISABLE KEYS */;
INSERT INTO `piwigo_upgrade` VALUES ('181','2026-05-07 14:30:01','Piwigo 15.0.0 schema baseline');
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
  PRIMARY KEY (`user_id`,`cat_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `auth_key` varchar(255) NOT NULL,
  `apikey_secret` varchar(255) DEFAULT NULL,
  `user_id` mediumint unsigned NOT NULL,
  `created_on` datetime NOT NULL,
  `duration` int unsigned DEFAULT NULL,
  `expired_on` datetime NOT NULL,
  `apikey_name` varchar(100) DEFAULT NULL,
  `key_type` varchar(40) DEFAULT NULL,
  `revoked_on` datetime DEFAULT NULL,
  `last_used_on` datetime DEFAULT NULL,
  `last_notified_on` datetime DEFAULT NULL,
  PRIMARY KEY (`auth_key_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `need_update` enum('true','false') NOT NULL DEFAULT 'true',
  `cache_update_time` int unsigned NOT NULL DEFAULT '0',
  `forbidden_categories` mediumtext,
  `nb_total_images` mediumint unsigned DEFAULT NULL,
  `last_photo_date` datetime DEFAULT NULL,
  `nb_available_tags` int DEFAULT NULL,
  `nb_available_comments` int DEFAULT NULL,
  `image_access_type` enum('NOT IN','IN') NOT NULL DEFAULT 'NOT IN',
  `image_access_list` mediumtext,
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_cache`
--

LOCK TABLES `piwigo_user_cache` WRITE;
/*!40000 ALTER TABLE `piwigo_user_cache` DISABLE KEYS */;
INSERT INTO `piwigo_user_cache` VALUES (1,'false',1778175007,'0',5,'2026-05-07 17:30:06',NULL,NULL,'NOT IN','0');
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
  PRIMARY KEY (`user_id`,`cat_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_cache_categories`
--

LOCK TABLES `piwigo_user_cache_categories` WRITE;
/*!40000 ALTER TABLE `piwigo_user_cache_categories` DISABLE KEYS */;
INSERT INTO `piwigo_user_cache_categories` VALUES (1,1,'2026-05-07 17:30:05','2026-05-07 17:30:06',3,5,1,1,NULL),(1,2,'2026-05-07 17:30:06','2026-05-07 17:30:06',2,2,0,0,NULL);
/*!40000 ALTER TABLE `piwigo_user_cache_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_feed`
--

DROP TABLE IF EXISTS `piwigo_user_feed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_feed` (
  `id` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `last_check` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  PRIMARY KEY (`group_id`,`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `status` enum('webmaster','admin','normal','generic','guest') NOT NULL DEFAULT 'guest',
  `language` varchar(50) NOT NULL DEFAULT 'en_UK',
  `expand` enum('true','false') NOT NULL DEFAULT 'false',
  `show_nb_comments` enum('true','false') NOT NULL DEFAULT 'false',
  `show_nb_hits` enum('true','false') NOT NULL DEFAULT 'false',
  `recent_period` tinyint unsigned NOT NULL DEFAULT '7',
  `theme` varchar(255) NOT NULL DEFAULT 'modus',
  `registration_date` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `enabled_high` enum('true','false') NOT NULL DEFAULT 'true',
  `level` tinyint unsigned NOT NULL DEFAULT '0',
  `activation_key` varchar(255) DEFAULT NULL,
  `activation_key_expire` datetime DEFAULT NULL,
  `last_visit` datetime DEFAULT NULL,
  `last_visit_from_history` enum('true','false') NOT NULL DEFAULT 'false',
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `preferences` text,
  PRIMARY KEY (`user_id`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_infos`
--

LOCK TABLES `piwigo_user_infos` WRITE;
/*!40000 ALTER TABLE `piwigo_user_infos` DISABLE KEYS */;
INSERT INTO `piwigo_user_infos` VALUES (1,15,'webmaster','en_GB','false','false','false',7,'modus','2026-05-07 17:30:01','true',8,NULL,NULL,NULL,'false','2026-05-07 17:30:01',NULL),(2,15,'guest','en_GB','false','false','false',7,'modus','2026-05-07 17:30:01','true',0,NULL,NULL,NULL,'false','2026-05-07 17:30:01',NULL),(3,15,'normal','en_GB','false','false','false',7,'modus','2026-05-07 17:30:08','true',0,NULL,NULL,NULL,'false','2026-05-07 17:30:01',NULL),(4,15,'normal','en_GB','false','false','false',7,'modus','2026-05-07 17:30:08','true',0,NULL,NULL,NULL,'false','2026-05-07 17:30:01',NULL);
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
  `check_key` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `enabled` enum('true','false') NOT NULL DEFAULT 'false',
  `last_send` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_mail_notification_ui1` (`check_key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
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
  `username` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `password` varchar(255) DEFAULT NULL,
  `mail_address` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_ui1` (`username`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_users`
--

LOCK TABLES `piwigo_users` WRITE;
/*!40000 ALTER TABLE `piwigo_users` DISABLE KEYS */;
INSERT INTO `piwigo_users` VALUES (1,'fixture_admin','$2y$12$8NUnj9dPbHKtGMnx4vineu8G4mtzj0aXJcjqk2FCVmKusgP6YoWsS','fixture_admin@example.test'),(2,'guest',NULL,NULL),(3,'regular_user','$2y$12$MA/CSOAc3jf7Z6AOQ28hOuHqtkAxS.87BYjPJ89byrUOo8azPw2Ci',NULL),(4,'power_user','$2y$12$wuVz0SjDBYRo94bXZ2etTu.Fq.CL5JFcxcVCDXdmDaHwfHifO6DCW',NULL);
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

-- Dump completed on 2026-05-07 14:30:09
