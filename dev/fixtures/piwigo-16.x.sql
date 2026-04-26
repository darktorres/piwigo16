/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.16-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: piwigo
-- ------------------------------------------------------
-- Server version	10.11.16-MariaDB-ubu2204

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_activity` (
  `activity_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `object` varchar(255) NOT NULL,
  `object_id` int(11) unsigned NOT NULL,
  `action` varchar(255) NOT NULL,
  `performed_by` mediumint(8) unsigned NOT NULL,
  `session_idx` varchar(255) NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `occured_on` timestamp NULL DEFAULT current_timestamp(),
  `details` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`activity_id`)
) ENGINE=MyISAM AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_activity`
--

LOCK TABLES `piwigo_activity` WRITE;
/*!40000 ALTER TABLE `piwigo_activity` DISABLE KEYS */;
INSERT INTO `piwigo_activity` VALUES
(1,'system',1,'install',0,'none','172.21.0.1','2026-04-26 21:43:37','a:2:{s:7:\"version\";s:6:\"16.3.0\";s:6:\"script\";s:7:\"install\";}',NULL),
(2,'user',1,'login',1,'5f20a9e2c1d234aa0f31d3e426c04a2f','172.21.0.1','2026-04-26 21:43:37','a:1:{s:6:\"script\";s:7:\"install\";}','curl/8.18.0'),
(3,'user',1,'login',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:43:53','a:1:{s:6:\"method\";s:17:\"pwg.session.login\";}','curl/8.18.0'),
(4,'album',1,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:44:05','a:1:{s:6:\"method\";s:18:\"pwg.categories.add\";}',NULL),
(5,'album',2,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:44:05','a:1:{s:6:\"method\";s:18:\"pwg.categories.add\";}',NULL),
(6,'album',3,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:44:05','a:1:{s:6:\"method\";s:18:\"pwg.categories.add\";}',NULL),
(7,'photo',1,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:44:30','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),
(8,'photo',2,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:09','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),
(9,'photo',3,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:09','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),
(10,'photo',4,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:10','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),
(11,'photo',5,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:10','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),
(12,'photo',6,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:10','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),
(13,'photo',7,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:10','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),
(14,'photo',8,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:11','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),
(15,'tag',1,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:22','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),
(16,'tag',2,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:22','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),
(17,'tag',3,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:22','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),
(18,'tag',4,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:22','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),
(19,'user',3,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:37','a:1:{s:6:\"method\";s:13:\"pwg.users.add\";}',NULL),
(20,'user',4,'add',1,'4916c9a38da1b48ec385404eb6fd55b3','172.21.0.1','2026-04-26 21:46:37','a:1:{s:6:\"method\";s:13:\"pwg.users.add\";}',NULL);
/*!40000 ALTER TABLE `piwigo_activity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_caddie`
--

DROP TABLE IF EXISTS `piwigo_caddie`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_caddie` (
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `element_id` mediumint(8) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`element_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_categories` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `id_uppercat` smallint(5) unsigned DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `dir` varchar(255) DEFAULT NULL,
  `rank` smallint(5) unsigned DEFAULT NULL,
  `status` enum('public','private') NOT NULL DEFAULT 'public',
  `site_id` tinyint(4) unsigned DEFAULT NULL,
  `visible` enum('true','false') NOT NULL DEFAULT 'true',
  `representative_picture_id` mediumint(8) unsigned DEFAULT NULL,
  `uppercats` varchar(255) NOT NULL DEFAULT '',
  `commentable` enum('true','false') NOT NULL DEFAULT 'true',
  `global_rank` varchar(255) DEFAULT NULL,
  `image_order` varchar(128) DEFAULT NULL,
  `permalink` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin DEFAULT NULL,
  `lastmodified` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_i3` (`permalink`),
  KEY `categories_i2` (`id_uppercat`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_categories`
--

LOCK TABLES `piwigo_categories` WRITE;
/*!40000 ALTER TABLE `piwigo_categories` DISABLE KEYS */;
INSERT INTO `piwigo_categories` VALUES
(1,'Landscapes',NULL,'Beautiful landscapes',NULL,2,'public',NULL,'true',NULL,'1','true','2',NULL,NULL,'2026-04-26 21:44:05'),
(2,'Portraits',NULL,'Portrait photography',NULL,1,'public',NULL,'true',NULL,'2','true','1',NULL,NULL,'2026-04-26 21:44:05'),
(3,'Mountain Trails',1,'Sub-album of Landscapes',NULL,1,'public',NULL,'true',NULL,'1,3','true','2.1',NULL,NULL,'2026-04-26 21:44:05');
/*!40000 ALTER TABLE `piwigo_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_comments`
--

DROP TABLE IF EXISTS `piwigo_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_comments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `date` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `author` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `author_id` mediumint(8) unsigned DEFAULT NULL,
  `anonymous_id` varchar(45) NOT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `validated` enum('true','false') NOT NULL DEFAULT 'false',
  `validation_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comments_i2` (`validation_date`),
  KEY `comments_i1` (`image_id`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_comments`
--

LOCK TABLES `piwigo_comments` WRITE;
/*!40000 ALTER TABLE `piwigo_comments` DISABLE KEYS */;
INSERT INTO `piwigo_comments` VALUES
(1,2,'2026-04-26 21:47:37','fixture_admin','fixture@example.test',1,'127.0.0.1',NULL,'Stunning sunset colours! The golden light is perfect.','true','2026-04-26 21:47:37'),
(2,6,'2026-04-26 21:47:37','viewer_alice','alice@example.test',3,'127.0.0.2',NULL,'Beautiful portrait lighting. Very professional.','true','2026-04-26 21:47:37'),
(3,4,'2026-04-26 21:47:37','uploader_bob','bob@example.test',4,'127.0.0.3',NULL,'Love the rocky trail perspective — great depth.','true','2026-04-26 21:47:37');
/*!40000 ALTER TABLE `piwigo_comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_config`
--

DROP TABLE IF EXISTS `piwigo_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_config` (
  `param` varchar(40) NOT NULL DEFAULT '',
  `value` text DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`param`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='configuration table';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_config`
--

LOCK TABLES `piwigo_config` WRITE;
/*!40000 ALTER TABLE `piwigo_config` DISABLE KEYS */;
INSERT INTO `piwigo_config` VALUES
('activate_comments','true','Global parameter for usage of comments system'),
('nb_comment_page','10','number of comments to display on each page'),
('log','1','keep an history of visits on your website'),
('comments_validation','true','administrators validate users comments before becoming visible'),
('comments_forall','true','even guest not registered can post comments'),
('comments_order','ASC','comments order on picture page and cie'),
('comments_author_mandatory','false','Comment author is mandatory'),
('comments_email_mandatory','false','Comment email is mandatory'),
('comments_enable_website','true','Enable \"website\" field on add comment form'),
('user_can_delete_comment','false','administrators can allow user delete their own comments'),
('user_can_edit_comment','false','administrators can allow user edit their own comments'),
('email_admin_on_comment_edition','false','Send an email to the administrators when a comment is modified'),
('email_admin_on_comment_deletion','false','Send an email to the administrators when a comment is deleted'),
('gallery_locked','false','Lock your gallery temporary for non admin users'),
('gallery_title','Fixture Gallery 16.x','Title at top of each page and for RSS feed'),
('rate','false','Rating pictures feature is enabled'),
('rate_anonymous','true','Rating pictures feature is also enabled for visitors'),
('page_banner','<h1>%gallery_title%</h1>\n\n<p>Welcome to my photo gallery</p>','html displayed on the top each page of your gallery'),
('history_admin','1','keep a history of administrator visits on your website'),
('history_guest','true','keep a history of guest visits on your website'),
('allow_user_registration','true','allow visitors to register?'),
('allow_user_customization','true','allow users to customize their gallery?'),
('nb_categories_page','12','Param for categories pagination'),
('nbm_send_html_mail','true','Send mail on HTML format for notification by mail'),
('nbm_send_mail_as','','Send mail as param value for notification by mail'),
('nbm_send_detailed_content','true','Send detailed content for notification by mail'),
('nbm_complementary_mail_content','','Complementary mail content for notification by mail'),
('nbm_send_recent_post_dates','true','Send recent post by dates for notification by mail'),
('email_admin_on_new_user','none','Send an email to theadministrators when a user registers'),
('email_admin_on_comment','false','Send an email to the administrators when a valid comment is entered'),
('email_admin_on_comment_validation','true','Send an email to the administrators when a comment requires validation'),
('obligatory_user_mail_address','false','Mail address is obligatory for users'),
('c13y_ignore',NULL,'List of ignored anomalies'),
('extents_for_templates','a:0:{}','Actived template-extension(s)'),
('blk_menubar','','Menubar options'),
('menubar_filter_icon','false','Display filter icon'),
('index_sort_order_input','true','Display image order selection list'),
('index_flat_icon','false','Display flat icon'),
('index_posted_date_icon','true','Display calendar by posted date'),
('index_created_date_icon','true','Display calendar by creation date icon'),
('index_slideshow_icon','true','Display slideshow icon'),
('index_new_icon','true','Display new icons next albums and pictures'),
('picture_metadata_icon','true','Display metadata icon on picture page'),
('picture_slideshow_icon','true','Display slideshow icon on picture page'),
('picture_favorite_icon','true','Display favorite icon on picture page'),
('picture_download_icon','true','Display download icon on picture page'),
('picture_navigation_icons','true','Display navigation icons on picture page'),
('picture_navigation_thumb','true','Display navigation thumbnails on picture page'),
('picture_menu','false','Show menubar on picture page'),
('picture_informations','a:11:{s:6:\"author\";b:1;s:10:\"created_on\";b:1;s:9:\"posted_on\";b:1;s:10:\"dimensions\";b:0;s:4:\"file\";b:0;s:8:\"filesize\";b:0;s:4:\"tags\";b:1;s:10:\"categories\";b:1;s:6:\"visits\";b:1;s:12:\"rating_score\";b:1;s:13:\"privacy_level\";b:1;}','Information displayed on picture page'),
('week_starts_on','monday','Monday may not be the first day of the week'),
('updates_ignored','a:3:{s:7:\"plugins\";a:0:{}s:6:\"themes\";a:0:{}s:9:\"languages\";a:0:{}}','Extensions ignored for update'),
('order_by','ORDER BY date_available DESC, file ASC, id ASC','default photo order'),
('order_by_inside_category','ORDER BY date_available DESC, file ASC, id ASC','default photo order inside category'),
('original_resize','false',NULL),
('original_resize_maxwidth','2016',NULL),
('original_resize_maxheight','2016',NULL),
('original_resize_quality','95',NULL),
('mobile_theme',NULL,NULL),
('mail_theme','clear',NULL),
('picture_sizes_icon','true',NULL),
('index_sizes_icon','true',NULL),
('index_edit_icon','true',NULL),
('index_caddie_icon','true',NULL),
('display_fromto','false',NULL),
('picture_edit_icon','true',NULL),
('picture_caddie_icon','true',NULL),
('picture_representative_icon','true',NULL),
('show_mobile_app_banner_in_admin','true',NULL),
('show_mobile_app_banner_in_gallery','false',NULL),
('index_search_in_set_button','false',NULL),
('index_search_in_set_action','true',NULL),
('upload_detect_duplicate','false',NULL),
('webmaster_id','1',NULL),
('use_standard_pages','true',NULL),
('secret_key','354ae42a53b6ebf2bb195feea62351c2c328e026','a secret key specific to the gallery for internal use'),
('piwigo_db_version','16',NULL),
('derivatives','a:4:{s:1:\"d\";a:9:{s:6:\"square\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:120;i:1;i:120;}s:8:\"max_crop\";i:1;s:8:\"min_size\";a:2:{i:0;i:120;i:1;i:120;}}s:7:\"sharpen\";i:0;}s:5:\"thumb\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:144;i:1;i:144;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"2small\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:240;i:1;i:240;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"xsmall\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:432;i:1;i:324;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:5:\"small\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:576;i:1;i:432;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"medium\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:792;i:1;i:594;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:5:\"large\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:1008;i:1;i:756;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"xlarge\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:1224;i:1;i:918;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:7:\"xxlarge\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:1656;i:1;i:1242;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}}s:1:\"q\";i:95;s:1:\"w\";O:15:\"WatermarkParams\":7:{s:4:\"file\";s:0:\"\";s:8:\"min_size\";a:2:{i:0;i:500;i:1;i:500;}s:4:\"xpos\";i:50;s:4:\"ypos\";i:50;s:7:\"xrepeat\";i:0;s:7:\"yrepeat\";i:0;s:7:\"opacity\";i:100;}s:1:\"c\";a:0:{}}',NULL),
('disabled_derivatives','a:2:{s:7:\"3xlarge\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:2232;i:1;i:1674;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:7:\"4xlarge\";O:16:\"DerivativeParams\":3:{s:13:\"last_mod_time\";i:1777239832;s:6:\"sizing\";O:12:\"SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:3000;i:1;i:2250;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}}',NULL),
('piwigo_installed_version','16.3.0',NULL),
('last_major_update','2026-04-26 21:43:52',NULL),
('data_dir_checked','1',NULL),
('lounge_active','true',NULL);
/*!40000 ALTER TABLE `piwigo_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_favorites`
--

DROP TABLE IF EXISTS `piwigo_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_favorites` (
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`image_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_group_access` (
  `group_id` smallint(5) unsigned NOT NULL DEFAULT 0,
  `cat_id` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`group_id`,`cat_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_groups` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `is_default` enum('true','false') NOT NULL DEFAULT 'false',
  `lastmodified` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `groups_ui1` (`name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL DEFAULT '1970-01-01',
  `time` time NOT NULL DEFAULT '00:00:00',
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `IP` char(39) NOT NULL DEFAULT '',
  `section` enum('categories','tags','search','list','favorites','most_visited','best_rated','recent_pics','recent_cats') DEFAULT NULL,
  `category_id` smallint(5) DEFAULT NULL,
  `search_id` int(10) unsigned DEFAULT NULL,
  `tag_ids` varchar(50) DEFAULT NULL,
  `image_id` mediumint(8) DEFAULT NULL,
  `image_type` enum('picture','high','other') DEFAULT NULL,
  `format_id` int(11) unsigned DEFAULT NULL,
  `auth_key_id` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_history_summary` (
  `year` smallint(4) NOT NULL DEFAULT 0,
  `month` tinyint(2) DEFAULT NULL,
  `day` tinyint(2) DEFAULT NULL,
  `hour` tinyint(2) DEFAULT NULL,
  `nb_pages` int(11) DEFAULT NULL,
  `history_id_from` int(10) unsigned DEFAULT NULL,
  `history_id_to` int(10) unsigned DEFAULT NULL,
  UNIQUE KEY `history_summary_ymdh` (`year`,`month`,`day`,`hour`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_image_category` (
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `category_id` smallint(5) unsigned NOT NULL DEFAULT 0,
  `rank` mediumint(8) unsigned DEFAULT NULL,
  PRIMARY KEY (`image_id`,`category_id`),
  KEY `image_category_i1` (`category_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_image_category`
--

LOCK TABLES `piwigo_image_category` WRITE;
/*!40000 ALTER TABLE `piwigo_image_category` DISABLE KEYS */;
INSERT INTO `piwigo_image_category` VALUES
(9,3,1),
(10,1,2),
(11,2,3),
(12,3,4),
(13,1,5),
(14,2,6),
(15,3,7),
(16,1,8),
(17,2,9),
(18,3,10),
(19,1,11),
(20,2,12),
(21,3,13),
(22,1,14),
(23,2,15),
(24,3,16),
(25,1,17),
(26,2,18),
(27,3,19),
(28,1,20),
(29,2,21),
(30,3,22),
(31,1,23),
(32,2,24),
(33,3,25),
(34,1,26),
(35,2,27),
(36,3,28),
(37,1,29),
(38,2,30),
(39,3,31),
(40,1,32),
(41,2,33),
(42,3,34),
(43,1,35),
(44,2,36),
(45,3,37),
(46,1,38),
(47,2,39),
(48,3,40),
(49,1,41),
(50,2,42),
(51,3,43),
(52,1,44),
(53,2,45),
(54,3,46),
(55,1,47),
(56,2,48),
(57,3,49),
(58,1,50),
(59,2,51),
(60,3,52),
(61,1,53),
(62,2,54),
(63,3,55),
(64,1,56),
(65,2,57),
(66,3,58),
(67,1,59),
(68,2,60),
(69,3,61),
(70,1,62),
(71,2,63),
(72,3,64),
(73,1,65),
(74,2,66),
(75,3,67),
(76,1,68),
(77,2,69),
(78,3,70),
(79,1,71),
(80,2,72),
(81,3,73),
(82,1,74),
(83,2,75),
(84,3,76),
(85,1,77),
(86,2,78),
(87,3,79),
(88,1,80),
(89,2,81),
(90,3,82),
(91,1,83),
(92,2,84),
(93,1,85),
(94,2,86),
(95,3,87),
(96,1,88),
(97,2,89),
(98,3,90),
(99,1,91),
(100,2,92),
(101,3,93),
(102,1,94),
(103,2,95),
(104,3,96),
(105,1,97),
(106,2,98),
(107,3,99),
(108,1,100),
(109,2,101),
(110,3,102),
(111,1,103),
(112,2,104),
(113,3,105),
(114,1,106),
(115,2,107),
(116,3,108),
(117,1,109),
(118,2,110),
(119,3,111),
(120,1,112),
(121,2,113),
(122,3,114),
(123,1,115),
(124,2,116),
(125,3,117),
(126,1,118),
(127,2,119),
(128,3,120),
(129,1,121),
(130,2,122),
(131,3,123),
(132,1,124),
(133,2,125),
(134,3,126),
(135,1,127),
(136,2,128),
(137,3,129),
(138,1,130),
(139,2,131),
(140,3,132),
(141,1,133),
(142,2,134),
(143,3,135),
(144,1,136),
(145,2,137),
(146,3,138),
(147,1,139),
(148,2,140),
(149,3,141),
(150,1,142),
(151,2,143),
(152,3,144),
(153,1,145),
(154,2,146),
(155,3,147),
(156,1,148),
(157,2,149),
(158,3,150),
(159,1,151),
(160,2,152),
(161,3,153),
(162,1,154),
(163,2,155),
(164,3,156),
(165,1,157),
(166,2,158),
(167,3,159),
(168,1,160),
(169,2,161),
(170,3,162),
(171,1,163),
(172,2,164),
(173,3,165),
(174,1,166),
(175,2,167),
(176,3,168),
(177,1,169),
(178,2,170),
(179,3,171),
(180,1,172),
(181,2,173),
(182,3,174),
(183,1,175),
(184,2,176),
(185,3,177),
(186,1,178),
(187,2,179),
(188,3,180),
(189,1,181),
(190,2,182),
(191,3,183),
(192,1,184),
(193,2,185),
(194,3,186),
(195,1,187),
(196,2,188),
(197,3,189),
(198,1,190),
(199,2,191),
(200,3,192),
(201,1,193),
(202,2,194),
(203,3,195),
(204,1,196),
(205,2,197),
(206,3,198),
(207,1,199),
(208,2,200),
(209,3,201),
(210,1,202),
(211,2,203),
(212,3,204),
(213,1,205),
(214,2,206),
(215,3,207),
(216,1,208),
(217,2,209),
(218,3,210),
(219,1,211),
(220,2,212),
(221,3,213),
(222,1,214),
(223,2,215),
(224,3,216),
(225,1,217),
(226,2,218),
(227,3,219),
(228,1,220),
(229,2,221),
(230,3,222),
(231,1,223),
(232,2,224),
(233,3,225),
(234,1,226),
(235,2,227),
(236,3,228),
(237,1,229),
(238,2,230),
(239,3,231),
(240,1,232),
(241,2,233),
(242,3,234),
(243,1,235),
(244,2,236),
(245,3,237),
(246,1,238),
(247,2,239),
(248,3,240),
(249,1,241),
(250,2,242),
(251,3,243),
(252,1,244),
(253,2,245),
(254,3,246),
(255,1,247),
(256,2,248),
(257,3,249),
(258,1,250),
(259,2,251),
(260,3,252),
(261,1,253),
(262,2,254),
(263,3,255),
(264,1,256),
(265,2,257),
(266,3,258),
(267,1,259),
(268,2,260),
(269,3,261),
(270,1,262),
(271,2,263),
(272,3,264),
(273,1,265),
(274,2,266),
(275,3,267),
(276,1,268),
(277,2,269),
(278,3,270),
(279,1,271),
(280,2,272),
(281,3,273),
(282,1,274),
(283,2,275),
(284,3,276),
(285,1,277),
(286,2,278),
(287,3,279),
(288,1,280),
(289,2,281),
(290,3,282),
(291,1,283),
(292,2,284),
(293,3,285),
(294,1,286),
(295,2,287),
(296,3,288),
(297,1,289),
(298,2,290),
(299,3,291),
(300,1,292),
(301,2,293),
(302,3,294),
(303,1,295),
(304,2,296),
(305,3,297),
(306,1,298),
(307,2,299),
(308,3,300),
(309,1,301),
(310,2,302),
(311,3,303),
(312,1,304),
(313,2,305),
(314,3,306),
(315,1,307),
(316,2,308),
(317,3,309),
(318,1,310),
(319,2,311),
(320,3,312),
(321,1,313),
(322,2,314),
(323,3,315),
(324,1,316),
(325,2,317),
(326,3,318),
(327,1,319),
(328,2,320),
(329,3,321),
(330,1,322),
(331,2,323),
(332,3,324),
(333,1,325),
(334,2,326),
(335,3,327),
(336,1,328),
(337,2,329),
(338,3,330),
(339,1,331),
(340,2,332),
(341,3,333),
(342,1,334),
(343,2,335),
(344,3,336),
(345,1,337),
(346,2,338),
(347,3,339),
(348,1,340),
(349,2,341),
(350,3,342),
(351,1,343),
(352,2,344),
(353,3,345),
(354,1,346),
(355,2,347),
(356,3,348),
(357,1,349),
(358,2,350),
(359,3,351),
(360,1,352),
(361,2,353),
(362,3,354),
(363,1,355),
(364,2,356),
(365,3,357),
(366,1,358),
(367,2,359),
(368,3,360),
(369,1,361),
(370,2,362),
(371,3,363),
(372,1,364),
(373,2,365),
(374,3,366),
(375,1,367),
(376,2,368),
(377,3,369),
(378,1,370),
(379,2,371),
(380,3,372),
(381,1,373),
(382,2,374),
(383,3,375),
(384,1,376),
(385,2,377),
(386,3,378),
(387,1,379),
(388,2,380),
(389,3,381),
(390,1,382),
(391,2,383);
/*!40000 ALTER TABLE `piwigo_image_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_image_format`
--

DROP TABLE IF EXISTS `piwigo_image_format`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_image_format` (
  `format_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `ext` varchar(255) NOT NULL,
  `filesize` mediumint(9) unsigned DEFAULT NULL,
  PRIMARY KEY (`format_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_image_tag` (
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `tag_id` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`image_id`,`tag_id`),
  KEY `image_tag_i1` (`tag_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_image_tag`
--

LOCK TABLES `piwigo_image_tag` WRITE;
/*!40000 ALTER TABLE `piwigo_image_tag` DISABLE KEYS */;
INSERT INTO `piwigo_image_tag` VALUES
(2,1),
(2,3),
(3,1),
(3,3),
(4,1),
(4,3),
(4,4),
(5,1),
(5,3),
(5,4),
(6,2),
(7,2),
(8,2),
(9,2),
(10,3),
(11,4),
(12,1),
(13,2),
(14,3),
(15,4),
(16,1),
(17,2),
(18,3),
(19,4),
(20,1),
(21,2),
(22,3),
(23,4),
(24,1),
(25,2),
(26,3),
(27,4),
(28,1),
(29,2),
(30,3),
(31,4),
(32,1),
(33,2),
(34,3),
(35,4),
(36,1),
(37,2),
(38,3),
(39,4),
(40,1),
(41,2),
(42,3),
(43,4),
(44,1),
(45,2),
(46,3),
(47,4),
(48,1),
(49,2),
(50,3),
(51,4),
(52,1),
(53,2),
(54,3),
(55,4),
(56,1),
(57,2),
(58,3),
(59,4),
(60,1),
(61,2),
(62,3),
(63,4),
(64,1),
(65,2),
(66,3),
(67,4),
(68,1),
(69,2),
(70,3),
(71,4),
(72,1),
(73,2),
(74,3),
(75,4),
(76,1),
(77,2),
(78,3),
(79,4),
(80,1),
(81,2),
(82,3),
(83,4),
(84,1),
(85,2),
(86,3),
(87,4),
(88,1),
(89,2),
(90,3),
(91,4),
(92,1),
(93,2),
(94,3),
(95,4),
(96,1),
(97,2),
(98,3),
(99,4),
(100,1),
(101,2),
(102,3),
(103,4),
(104,1),
(105,2),
(106,3),
(107,4),
(108,1),
(109,2),
(110,3),
(111,4),
(112,1),
(113,2),
(114,3),
(115,4),
(116,1),
(117,2),
(118,3),
(119,4),
(120,1),
(121,2),
(122,3),
(123,4),
(124,1),
(125,2),
(126,3),
(127,4),
(128,1),
(129,2),
(130,3),
(131,4),
(132,1),
(133,2),
(134,3),
(135,4),
(136,1),
(137,2),
(138,3),
(139,4),
(140,1),
(141,2),
(142,3),
(143,4),
(144,1),
(145,2),
(146,3),
(147,4),
(148,1),
(149,2),
(150,3),
(151,4),
(152,1),
(153,2),
(154,3),
(155,4),
(156,1),
(157,2),
(158,3),
(159,4),
(160,1),
(161,2),
(162,3),
(163,4),
(164,1),
(165,2),
(166,3),
(167,4),
(168,1),
(169,2),
(170,3),
(171,4),
(172,1),
(173,2),
(174,3),
(175,4),
(176,1),
(177,2),
(178,3),
(179,4),
(180,1),
(181,2),
(182,3),
(183,4),
(184,1),
(185,2),
(186,3),
(187,4),
(188,1),
(189,2),
(190,3),
(191,4),
(192,1),
(193,2),
(194,3),
(195,4),
(196,1),
(197,2),
(198,3),
(199,4),
(200,1),
(201,2),
(202,3),
(203,4),
(204,1),
(205,2),
(206,3),
(207,4),
(208,1),
(209,2),
(210,3),
(211,4),
(212,1),
(213,2),
(214,3),
(215,4),
(216,1),
(217,2),
(218,3),
(219,4),
(220,1),
(221,2),
(222,3),
(223,4),
(224,1),
(225,2),
(226,3),
(227,4),
(228,1),
(229,2),
(230,3),
(231,4),
(232,1),
(233,2),
(234,3),
(235,4),
(236,1),
(237,2),
(238,3),
(239,4),
(240,1),
(241,2),
(242,3),
(243,4),
(244,1),
(245,2),
(246,3),
(247,4),
(248,1),
(249,2),
(250,3),
(251,4),
(252,1),
(253,2),
(254,3),
(255,4),
(256,1),
(257,2),
(258,3),
(259,4),
(260,1),
(261,2),
(262,3),
(263,4),
(264,1),
(265,2),
(266,3),
(267,4),
(268,1),
(269,2),
(270,3),
(271,4),
(272,1),
(273,2),
(274,3),
(275,4),
(276,1),
(277,2),
(278,3),
(279,4),
(280,1),
(281,2),
(282,3),
(283,4),
(284,1),
(285,2),
(286,3),
(287,4),
(288,1),
(289,2),
(290,3),
(291,4),
(292,1),
(293,2),
(294,3),
(295,4),
(296,1),
(297,2),
(298,3),
(299,4),
(300,1),
(301,2),
(302,3),
(303,4),
(304,1),
(305,2),
(306,3),
(307,4),
(308,1),
(309,2),
(310,3),
(311,4),
(312,1),
(313,2),
(314,3),
(315,4),
(316,1),
(317,2),
(318,3),
(319,4),
(320,1),
(321,2),
(322,3),
(323,4),
(324,1),
(325,2),
(326,3),
(327,4),
(328,1),
(329,2),
(330,3),
(331,4),
(332,1),
(333,2),
(334,3),
(335,4),
(336,1),
(337,2),
(338,3),
(339,4),
(340,1),
(341,2),
(342,3),
(343,4),
(344,1),
(345,2),
(346,3),
(347,4),
(348,1),
(349,2),
(350,3),
(351,4),
(352,1),
(353,2),
(354,3),
(355,4),
(356,1),
(357,2),
(358,3),
(359,4),
(360,1),
(361,2),
(362,3),
(363,4),
(364,1),
(365,2),
(366,3),
(367,4),
(368,1),
(369,2),
(370,3),
(371,4),
(372,1),
(373,2),
(374,3),
(375,4),
(376,1),
(377,2),
(378,3),
(379,4),
(380,1),
(381,2),
(382,3),
(383,4),
(384,1),
(385,2),
(386,3),
(387,4),
(388,1),
(389,2),
(390,3),
(391,4);
/*!40000 ALTER TABLE `piwigo_image_tag` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_images`
--

DROP TABLE IF EXISTS `piwigo_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_images` (
  `id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `file` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `date_available` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `date_creation` datetime DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `author` varchar(255) DEFAULT NULL,
  `hit` int(10) unsigned NOT NULL DEFAULT 0,
  `filesize` mediumint(9) unsigned DEFAULT NULL,
  `width` smallint(9) unsigned DEFAULT NULL,
  `height` smallint(9) unsigned DEFAULT NULL,
  `coi` char(4) DEFAULT NULL COMMENT 'center of interest',
  `representative_ext` varchar(4) DEFAULT NULL,
  `date_metadata_update` date DEFAULT NULL,
  `rating_score` float(5,2) unsigned DEFAULT NULL,
  `path` varchar(255) NOT NULL DEFAULT '',
  `storage_category_id` smallint(5) unsigned DEFAULT NULL,
  `level` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `md5sum` char(32) DEFAULT NULL,
  `added_by` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rotation` tinyint(3) unsigned DEFAULT NULL,
  `latitude` double(8,6) DEFAULT NULL,
  `longitude` double(9,6) DEFAULT NULL,
  `lastmodified` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `images_i2` (`date_available`),
  KEY `images_i3` (`rating_score`),
  KEY `images_i4` (`hit`),
  KEY `images_i5` (`date_creation`),
  KEY `images_i1` (`storage_category_id`),
  KEY `images_i6` (`latitude`),
  KEY `images_i7` (`path`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM AUTO_INCREMENT=392 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_images`
--

LOCK TABLES `piwigo_images` WRITE;
/*!40000 ALTER TABLE `piwigo_images` DISABLE KEYS */;
INSERT INTO `piwigo_images` VALUES
(1,'piwigo_test_1.jpg','2026-04-26 21:44:30',NULL,'Valley Mist','Morning fog in the valley',NULL,0,0,100,75,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/26/20260426214430-e953b9ff.jpg',NULL,0,'e95343fa2e9dafb5b50605c5bc29412f',1,0,NULL,NULL,'2026-04-26 21:44:40'),
(2,'piwigo_seed_1.png','2026-04-26 21:46:09',NULL,'Mountain Sunset','Golden hour over the peaks',NULL,0,0,80,60,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/26/20260426214609-b5afeb63.png',NULL,0,'b5af4769c3c3501e71a835a6d82c8539',1,0,NULL,NULL,'2026-04-26 21:46:49'),
(3,'piwigo_seed_2.png','2026-04-26 21:46:09',NULL,'Valley Mist','Morning fog in the valley',NULL,0,0,80,60,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/26/20260426214609-5d8cf23b.png',NULL,0,'5d8c15a676f4196ec7a61ce8681b9ddb',1,0,NULL,NULL,'2026-04-26 21:46:49'),
(4,'piwigo_seed_3.png','2026-04-26 21:46:10',NULL,'Rocky Trail','Narrow path through boulders',NULL,0,0,80,60,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/26/20260426214610-426af3f9.png',NULL,0,'426a102287eab84542f73b528807b36d',1,0,NULL,NULL,'2026-04-26 21:46:49'),
(5,'piwigo_seed_4.png','2026-04-26 21:46:10',NULL,'Pine Forest','Dense pine trees in summer',NULL,0,0,80,60,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/26/20260426214610-356c55b6.png',NULL,0,'356cad63ed4b3a954df16bd421b3e673',1,0,NULL,NULL,'2026-04-26 21:46:49'),
(6,'piwigo_seed_5.png','2026-04-26 21:46:10',NULL,'Sunlit Portrait','Natural light portrait study',NULL,0,0,80,60,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/26/20260426214610-7580b7a3.png',NULL,0,'7580fe3e6dd7f152004af48e59ca7499',1,0,NULL,NULL,'2026-04-26 21:46:49'),
(7,'piwigo_seed_6.png','2026-04-26 21:46:10',NULL,'Studio Shot','Classic studio portrait',NULL,0,0,80,60,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/26/20260426214610-a8eb6804.png',NULL,0,'a8eba1e46036c7bd94d13c5491311092',1,0,NULL,NULL,'2026-04-26 21:46:49'),
(8,'piwigo_seed_7.png','2026-04-26 21:46:11',NULL,'Street Portrait','Candid urban portrait',NULL,0,0,80,60,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/26/20260426214611-32438cb4.png',NULL,0,'324382bdcc4f186cf3a71d2ae86b97dc',1,0,NULL,NULL,'2026-04-26 21:46:49'),
(9,'bulk_photo_9.png','2025-09-17 21:48:20','2026-02-16 21:48:20','Bulk Photo 9','Auto-generated fixture photo number 9 for testing purposes','fixture_admin',130,19586,1898,1190,NULL,NULL,'2026-04-11',NULL,'./upload/2026/04/bulk_photo_9.png',NULL,0,'1a022c11bbb4278cf5bb587c498bcf94',1,NULL,NULL,NULL,'2026-04-06 21:48:20'),
(10,'bulk_photo_10.png','2025-06-11 21:48:20','2025-09-27 21:48:20','Bulk Photo 10','Auto-generated fixture photo number 10 for testing purposes','fixture_admin',165,8168,1789,906,NULL,NULL,'2026-04-20',NULL,'./upload/2026/04/bulk_photo_10.png',NULL,0,'78c096abbdffd47f593e4594bce4b993',1,NULL,NULL,NULL,'2026-04-23 21:48:20'),
(11,'bulk_photo_11.png','2025-05-17 21:48:20','2025-08-22 21:48:20','Bulk Photo 11','Auto-generated fixture photo number 11 for testing purposes','fixture_admin',173,9016,1961,1410,NULL,NULL,'2026-04-09',NULL,'./upload/2026/04/bulk_photo_11.png',NULL,0,'e0285b085e893793b4d031123f97fedb',1,NULL,NULL,NULL,'2026-04-18 21:48:20'),
(12,'bulk_photo_12.png','2025-09-04 21:48:20','2025-08-17 21:48:20','Bulk Photo 12','Auto-generated fixture photo number 12 for testing purposes','fixture_admin',160,19667,1384,1048,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/bulk_photo_12.png',NULL,0,'c2d7c99035a698c09134a8c28b921d22',1,NULL,NULL,NULL,'2026-04-07 21:48:20'),
(13,'bulk_photo_13.png','2026-02-27 21:48:20','2024-08-10 21:48:20','Bulk Photo 13','Auto-generated fixture photo number 13 for testing purposes','fixture_admin',158,10435,1556,1451,NULL,NULL,'2026-04-01',NULL,'./upload/2026/04/bulk_photo_13.png',NULL,0,'6eead5a676abda02565197430f94e17d',1,NULL,NULL,NULL,'2026-04-16 21:48:20'),
(14,'bulk_photo_14.png','2026-01-08 21:48:20','2025-07-15 21:48:20','Bulk Photo 14','Auto-generated fixture photo number 14 for testing purposes','fixture_admin',13,6616,1537,1125,NULL,NULL,'2026-04-24',NULL,'./upload/2026/04/bulk_photo_14.png',NULL,0,'f2c155eeac856eec6bbf5c4022f0bd54',1,NULL,NULL,NULL,'2026-04-08 21:48:20'),
(15,'bulk_photo_15.png','2025-06-04 21:48:20','2025-02-14 21:48:20','Bulk Photo 15','Auto-generated fixture photo number 15 for testing purposes','fixture_admin',61,16046,1802,1427,NULL,NULL,'2026-04-24',NULL,'./upload/2026/04/bulk_photo_15.png',NULL,0,'eb22f7c3f3e3d5449d7eaa8b7caaf93a',1,NULL,NULL,NULL,'2026-04-05 21:48:20'),
(16,'bulk_photo_16.png','2026-02-02 21:48:20','2026-03-30 21:48:20','Bulk Photo 16','Auto-generated fixture photo number 16 for testing purposes','fixture_admin',100,10510,1427,953,NULL,NULL,'2026-04-15',NULL,'./upload/2026/04/bulk_photo_16.png',NULL,0,'f4dc380c3e26750e13e1c9dff1d3db41',1,NULL,NULL,NULL,'2026-04-03 21:48:20'),
(17,'bulk_photo_17.png','2025-07-09 21:48:20','2025-02-09 21:48:20','Bulk Photo 17','Auto-generated fixture photo number 17 for testing purposes','fixture_admin',125,9272,1712,1331,NULL,NULL,'2026-04-03',NULL,'./upload/2026/04/bulk_photo_17.png',NULL,0,'7379eef323e44586bfbe75b7990be0e0',1,NULL,NULL,NULL,'2026-04-12 21:48:20'),
(18,'bulk_photo_18.png','2026-04-06 21:48:20','2024-08-21 21:48:20','Bulk Photo 18','Auto-generated fixture photo number 18 for testing purposes','fixture_admin',5,13983,839,864,NULL,NULL,'2026-04-15',NULL,'./upload/2026/04/bulk_photo_18.png',NULL,0,'177792a29217c1944f82ee9b659fc50b',1,NULL,NULL,NULL,'2026-03-28 21:48:20'),
(19,'bulk_photo_19.png','2025-07-28 21:48:20','2024-09-05 21:48:20','Bulk Photo 19','Auto-generated fixture photo number 19 for testing purposes','fixture_admin',172,17484,1557,1164,NULL,NULL,'2026-04-19',NULL,'./upload/2026/04/bulk_photo_19.png',NULL,0,'78d1d966e75672734470f83f82366826',1,NULL,NULL,NULL,'2026-04-16 21:48:20'),
(20,'bulk_photo_20.png','2025-05-11 21:48:20','2024-10-04 21:48:20','Bulk Photo 20','Auto-generated fixture photo number 20 for testing purposes','fixture_admin',4,16320,1741,1151,NULL,NULL,'2026-04-05',NULL,'./upload/2026/04/bulk_photo_20.png',NULL,0,'d015bbe2419f6c8f4997c972072ad781',1,NULL,NULL,NULL,'2026-04-05 21:48:20'),
(21,'bulk_photo_21.png','2025-11-20 21:48:20','2026-04-11 21:48:20','Bulk Photo 21','Auto-generated fixture photo number 21 for testing purposes','fixture_admin',161,19748,1392,1062,NULL,NULL,'2026-04-24',NULL,'./upload/2026/04/bulk_photo_21.png',NULL,0,'07c39cf252fb2b671dbf51740c05fcb6',1,NULL,NULL,NULL,'2026-03-30 21:48:20'),
(22,'bulk_photo_22.png','2026-01-16 21:48:20','2025-01-06 21:48:20','Bulk Photo 22','Auto-generated fixture photo number 22 for testing purposes','fixture_admin',85,7116,1621,1353,NULL,NULL,'2026-04-23',NULL,'./upload/2026/04/bulk_photo_22.png',NULL,0,'2d5d068debfb25d256a02966c6bcf33b',1,NULL,NULL,NULL,'2026-04-22 21:48:20'),
(23,'bulk_photo_23.png','2025-12-22 21:48:20','2025-10-04 21:48:20','Bulk Photo 23','Auto-generated fixture photo number 23 for testing purposes','fixture_admin',73,19955,1859,980,NULL,NULL,'2026-04-13',NULL,'./upload/2026/04/bulk_photo_23.png',NULL,0,'323cd822874017c5937719dc3d656d56',1,NULL,NULL,NULL,'2026-04-25 21:48:20'),
(24,'bulk_photo_24.png','2025-06-13 21:48:20','2025-12-09 21:48:20','Bulk Photo 24','Auto-generated fixture photo number 24 for testing purposes','fixture_admin',68,6305,1629,622,NULL,NULL,'2026-04-25',NULL,'./upload/2026/04/bulk_photo_24.png',NULL,0,'be97b4d7d99e0692ee02b3eb4832288b',1,NULL,NULL,NULL,'2026-04-21 21:48:20'),
(25,'bulk_photo_25.png','2025-07-22 21:48:20','2025-10-17 21:48:20','Bulk Photo 25','Auto-generated fixture photo number 25 for testing purposes','fixture_admin',4,9177,1462,1312,NULL,NULL,'2026-04-18',NULL,'./upload/2026/04/bulk_photo_25.png',NULL,0,'75616c5c0389aed6f445626483b563ce',1,NULL,NULL,NULL,'2026-04-23 21:48:20'),
(26,'bulk_photo_26.png','2025-08-05 21:48:20','2025-10-30 21:48:20','Bulk Photo 26','Auto-generated fixture photo number 26 for testing purposes','fixture_admin',10,12346,1346,1240,NULL,NULL,'2026-04-21',NULL,'./upload/2026/04/bulk_photo_26.png',NULL,0,'ac9e0ef1ac04053aa412c9b03ed220c6',1,NULL,NULL,NULL,'2026-04-02 21:48:20'),
(27,'bulk_photo_27.png','2025-10-01 21:48:20','2025-08-15 21:48:20','Bulk Photo 27','Auto-generated fixture photo number 27 for testing purposes','fixture_admin',8,6489,1589,1339,NULL,NULL,'2026-04-22',NULL,'./upload/2026/04/bulk_photo_27.png',NULL,0,'798133668af0e88030afd0150c385252',1,NULL,NULL,NULL,'2026-04-20 21:48:20'),
(28,'bulk_photo_28.png','2025-09-08 21:48:20','2025-04-03 21:48:20','Bulk Photo 28','Auto-generated fixture photo number 28 for testing purposes','fixture_admin',153,7980,1924,1444,NULL,NULL,'2026-03-31',NULL,'./upload/2026/04/bulk_photo_28.png',NULL,0,'7ffd3e019d840e248f26a8851f7aa4d1',1,NULL,NULL,NULL,'2026-04-09 21:48:20'),
(29,'bulk_photo_29.png','2025-12-26 21:48:20','2024-07-26 21:48:20','Bulk Photo 29','Auto-generated fixture photo number 29 for testing purposes','fixture_admin',75,8287,1044,792,NULL,NULL,'2026-04-13',NULL,'./upload/2026/04/bulk_photo_29.png',NULL,0,'d41582628c9dfbb77655bf0d1bb70066',1,NULL,NULL,NULL,'2026-04-07 21:48:20'),
(30,'bulk_photo_30.png','2025-06-12 21:48:20','2025-07-01 21:48:20','Bulk Photo 30','Auto-generated fixture photo number 30 for testing purposes','fixture_admin',87,19290,1359,1017,NULL,NULL,'2026-03-30',NULL,'./upload/2026/04/bulk_photo_30.png',NULL,0,'b4aaf628fcdecf52fbf7a407f4658688',1,NULL,NULL,NULL,'2026-04-20 21:48:20'),
(31,'bulk_photo_31.png','2026-01-02 21:48:20','2024-06-27 21:48:20','Bulk Photo 31','Auto-generated fixture photo number 31 for testing purposes','fixture_admin',128,11364,1252,1053,NULL,NULL,'2026-04-15',NULL,'./upload/2026/04/bulk_photo_31.png',NULL,0,'1ad51befac0c439f7961a9024fa12f7a',1,NULL,NULL,NULL,'2026-04-14 21:48:20'),
(32,'bulk_photo_32.png','2025-05-13 21:48:20','2025-04-22 21:48:20','Bulk Photo 32','Auto-generated fixture photo number 32 for testing purposes','fixture_admin',133,16913,848,1303,NULL,NULL,'2026-04-03',NULL,'./upload/2026/04/bulk_photo_32.png',NULL,0,'fb9a3469fb39912978e2b99e4dba4362',1,NULL,NULL,NULL,'2026-04-09 21:48:20'),
(33,'bulk_photo_33.png','2025-10-09 21:48:20','2024-04-30 21:48:20','Bulk Photo 33','Auto-generated fixture photo number 33 for testing purposes','fixture_admin',67,15143,1366,843,NULL,NULL,'2026-03-29',NULL,'./upload/2026/04/bulk_photo_33.png',NULL,0,'b05016c03e4fb3247734d34760379c86',1,NULL,NULL,NULL,'2026-03-31 21:48:20'),
(34,'bulk_photo_34.png','2025-10-02 21:48:20','2025-12-05 21:48:20','Bulk Photo 34','Auto-generated fixture photo number 34 for testing purposes','fixture_admin',57,17487,1227,828,NULL,NULL,'2026-04-21',NULL,'./upload/2026/04/bulk_photo_34.png',NULL,0,'e2bd472dabcae178b66b7779c610be53',1,NULL,NULL,NULL,'2026-04-20 21:48:20'),
(35,'bulk_photo_35.png','2025-10-09 21:48:20','2026-03-20 21:48:20','Bulk Photo 35','Auto-generated fixture photo number 35 for testing purposes','fixture_admin',122,18614,1673,1407,NULL,NULL,'2026-04-17',NULL,'./upload/2026/04/bulk_photo_35.png',NULL,0,'97746276798bd7b0619ec3ef3091b324',1,NULL,NULL,NULL,'2026-04-02 21:48:20'),
(36,'bulk_photo_36.png','2026-01-27 21:48:20','2024-11-06 21:48:20','Bulk Photo 36','Auto-generated fixture photo number 36 for testing purposes','fixture_admin',186,11395,1414,755,NULL,NULL,'2026-04-17',NULL,'./upload/2026/04/bulk_photo_36.png',NULL,0,'9b24c1ff1cc243d4456ccc1578060494',1,NULL,NULL,NULL,'2026-04-23 21:48:20'),
(37,'bulk_photo_37.png','2025-09-07 21:48:20','2024-09-16 21:48:20','Bulk Photo 37','Auto-generated fixture photo number 37 for testing purposes','fixture_admin',23,6669,1383,1437,NULL,NULL,'2026-04-21',NULL,'./upload/2026/04/bulk_photo_37.png',NULL,0,'22097e583926d4f3dbfc7d85092c49ce',1,NULL,NULL,NULL,'2026-04-21 21:48:20'),
(38,'bulk_photo_38.png','2026-01-15 21:48:20','2024-07-21 21:48:20','Bulk Photo 38','Auto-generated fixture photo number 38 for testing purposes','fixture_admin',115,7830,1356,1140,NULL,NULL,'2026-04-08',NULL,'./upload/2026/04/bulk_photo_38.png',NULL,0,'330f7ed34dc7401bd30b251e4dab2749',1,NULL,NULL,NULL,'2026-04-19 21:48:20'),
(39,'bulk_photo_39.png','2025-11-27 21:48:20','2025-09-05 21:48:20','Bulk Photo 39','Auto-generated fixture photo number 39 for testing purposes','fixture_admin',72,17653,1013,895,NULL,NULL,'2026-04-23',NULL,'./upload/2026/04/bulk_photo_39.png',NULL,0,'c5e7c6e3dc26b1bc4b566415570e9a1c',1,NULL,NULL,NULL,'2026-04-09 21:48:20'),
(40,'bulk_photo_40.png','2025-10-23 21:48:20','2024-08-25 21:48:20','Bulk Photo 40','Auto-generated fixture photo number 40 for testing purposes','fixture_admin',129,15914,1728,1173,NULL,NULL,'2026-04-01',NULL,'./upload/2026/04/bulk_photo_40.png',NULL,0,'ce76713ea27f089dac61e6c03f6ab0d4',1,NULL,NULL,NULL,'2026-04-15 21:48:20'),
(41,'bulk_photo_41.png','2025-12-04 21:48:20','2024-10-04 21:48:20','Bulk Photo 41','Auto-generated fixture photo number 41 for testing purposes','fixture_admin',144,8354,1031,731,NULL,NULL,'2026-04-22',NULL,'./upload/2026/04/bulk_photo_41.png',NULL,0,'74c94dfe713fa6980f188025c5d78088',1,NULL,NULL,NULL,'2026-04-16 21:48:20'),
(42,'bulk_photo_42.png','2026-02-08 21:48:20','2026-03-17 21:48:20','Bulk Photo 42','Auto-generated fixture photo number 42 for testing purposes','fixture_admin',127,4505,1087,692,NULL,NULL,'2026-04-03',NULL,'./upload/2026/04/bulk_photo_42.png',NULL,0,'42b1d62113ef843177d6cd50f811b6f4',1,NULL,NULL,NULL,'2026-04-07 21:48:20'),
(43,'bulk_photo_43.png','2025-05-26 21:48:20','2025-02-04 21:48:20','Bulk Photo 43','Auto-generated fixture photo number 43 for testing purposes','fixture_admin',60,14884,1391,980,NULL,NULL,'2026-04-07',NULL,'./upload/2026/04/bulk_photo_43.png',NULL,0,'4f8f736ac1fadc977401c1f94c35259b',1,NULL,NULL,NULL,'2026-03-30 21:48:20'),
(44,'bulk_photo_44.png','2025-08-27 21:48:20','2025-03-05 21:48:20','Bulk Photo 44','Auto-generated fixture photo number 44 for testing purposes','fixture_admin',174,14304,1523,675,NULL,NULL,'2026-04-08',NULL,'./upload/2026/04/bulk_photo_44.png',NULL,0,'686c4dacedc6df1b4f4ebed1a25361ff',1,NULL,NULL,NULL,'2026-04-02 21:48:20'),
(45,'bulk_photo_45.png','2026-02-21 21:48:20','2025-05-16 21:48:20','Bulk Photo 45','Auto-generated fixture photo number 45 for testing purposes','fixture_admin',167,16272,1188,887,NULL,NULL,'2026-04-08',NULL,'./upload/2026/04/bulk_photo_45.png',NULL,0,'5dedecde1889ea145e0f1e70a7b2fe51',1,NULL,NULL,NULL,'2026-04-22 21:48:20'),
(46,'bulk_photo_46.png','2025-05-19 21:48:20','2025-11-25 21:48:20','Bulk Photo 46','Auto-generated fixture photo number 46 for testing purposes','fixture_admin',45,12264,1875,1437,NULL,NULL,'2026-03-29',NULL,'./upload/2026/04/bulk_photo_46.png',NULL,0,'5de7662ccf6c966dea464a00205e532c',1,NULL,NULL,NULL,'2026-04-25 21:48:20'),
(47,'bulk_photo_47.png','2026-01-11 21:48:20','2025-08-24 21:48:20','Bulk Photo 47','Auto-generated fixture photo number 47 for testing purposes','fixture_admin',162,4830,1787,1462,NULL,NULL,'2026-04-17',NULL,'./upload/2026/04/bulk_photo_47.png',NULL,0,'b1eb809c30647e86656c3c26c0526b95',1,NULL,NULL,NULL,'2026-04-04 21:48:20'),
(48,'bulk_photo_48.png','2025-07-16 21:48:20','2025-01-10 21:48:20','Bulk Photo 48','Auto-generated fixture photo number 48 for testing purposes','fixture_admin',178,12353,1919,690,NULL,NULL,'2026-04-05',NULL,'./upload/2026/04/bulk_photo_48.png',NULL,0,'4ef6f4859f341a1f27e18841f3303f9e',1,NULL,NULL,NULL,'2026-04-20 21:48:20'),
(49,'bulk_photo_49.png','2025-05-19 21:48:20','2026-03-12 21:48:20','Bulk Photo 49','Auto-generated fixture photo number 49 for testing purposes','fixture_admin',100,9080,900,1020,NULL,NULL,'2026-04-24',NULL,'./upload/2026/04/bulk_photo_49.png',NULL,0,'cb0b4173a2068e18b614c34963d7ffc0',1,NULL,NULL,NULL,'2026-04-26 21:48:20'),
(50,'bulk_photo_50.png','2025-07-04 21:48:20','2026-04-10 21:48:20','Bulk Photo 50','Auto-generated fixture photo number 50 for testing purposes','fixture_admin',135,8972,1433,1236,NULL,NULL,'2026-03-29',NULL,'./upload/2026/04/bulk_photo_50.png',NULL,0,'fbb33430505fb2aebd3ebd4133532f29',1,NULL,NULL,NULL,'2026-04-07 21:48:20'),
(51,'bulk_photo_51.png','2025-12-09 21:48:20','2024-06-02 21:48:20','Bulk Photo 51','Auto-generated fixture photo number 51 for testing purposes','fixture_admin',122,7404,1071,1044,NULL,NULL,'2026-04-03',NULL,'./upload/2026/04/bulk_photo_51.png',NULL,0,'0cc3fc94f0616ed1710bf9641e02e5fb',1,NULL,NULL,NULL,'2026-04-13 21:48:20'),
(52,'bulk_photo_52.png','2025-05-18 21:48:20','2025-09-04 21:48:20','Bulk Photo 52','Auto-generated fixture photo number 52 for testing purposes','fixture_admin',156,19313,1319,862,NULL,NULL,'2026-04-22',NULL,'./upload/2026/04/bulk_photo_52.png',NULL,0,'e289efa364fb47100eef2bb0a26f3e21',1,NULL,NULL,NULL,'2026-03-30 21:48:20'),
(53,'bulk_photo_53.png','2026-03-07 21:48:20','2024-06-26 21:48:20','Bulk Photo 53','Auto-generated fixture photo number 53 for testing purposes','fixture_admin',35,6057,936,765,NULL,NULL,'2026-04-09',NULL,'./upload/2026/04/bulk_photo_53.png',NULL,0,'be4e60bbd31f6f3baf628758645f7989',1,NULL,NULL,NULL,'2026-04-16 21:48:20'),
(54,'bulk_photo_54.png','2025-05-11 21:48:20','2024-09-28 21:48:20','Bulk Photo 54','Auto-generated fixture photo number 54 for testing purposes','fixture_admin',11,18622,1283,846,NULL,NULL,'2026-04-22',NULL,'./upload/2026/04/bulk_photo_54.png',NULL,0,'2b5cfd9b77f8865022c719c4eec40e91',1,NULL,NULL,NULL,'2026-03-28 21:48:20'),
(55,'bulk_photo_55.png','2025-11-13 21:48:20','2025-09-23 21:48:20','Bulk Photo 55','Auto-generated fixture photo number 55 for testing purposes','fixture_admin',23,15384,1040,1379,NULL,NULL,'2026-04-05',NULL,'./upload/2026/04/bulk_photo_55.png',NULL,0,'2f660f26d72a60e1be0d00857d604578',1,NULL,NULL,NULL,'2026-04-25 21:48:20'),
(56,'bulk_photo_56.png','2026-03-21 21:48:20','2025-09-01 21:48:20','Bulk Photo 56','Auto-generated fixture photo number 56 for testing purposes','fixture_admin',65,14441,1142,1022,NULL,NULL,'2026-04-12',NULL,'./upload/2026/04/bulk_photo_56.png',NULL,0,'84322e276d2e81a5c9398709b83d7299',1,NULL,NULL,NULL,'2026-04-25 21:48:20'),
(57,'bulk_photo_57.png','2025-07-18 21:48:20','2024-11-18 21:48:20','Bulk Photo 57','Auto-generated fixture photo number 57 for testing purposes','fixture_admin',54,7449,1105,1161,NULL,NULL,'2026-04-16',NULL,'./upload/2026/04/bulk_photo_57.png',NULL,0,'8d81598e6cf1d43caa6f1144ede836b2',1,NULL,NULL,NULL,'2026-03-30 21:48:20'),
(58,'bulk_photo_58.png','2025-10-15 21:48:20','2024-07-21 21:48:20','Bulk Photo 58','Auto-generated fixture photo number 58 for testing purposes','fixture_admin',165,11658,1900,736,NULL,NULL,'2026-04-26',NULL,'./upload/2026/04/bulk_photo_58.png',NULL,0,'111790f57f7e00351218147555c51d97',1,NULL,NULL,NULL,'2026-04-10 21:48:20'),
(59,'bulk_photo_59.png','2025-07-03 21:48:20','2025-07-21 21:48:20','Bulk Photo 59','Auto-generated fixture photo number 59 for testing purposes','fixture_admin',92,6837,1390,1434,NULL,NULL,'2026-04-22',NULL,'./upload/2026/04/bulk_photo_59.png',NULL,0,'4b23249e6284972323444514f4936a53',1,NULL,NULL,NULL,'2026-04-26 21:48:20'),
(60,'bulk_photo_60.png','2025-09-04 21:48:20','2026-01-18 21:48:20','Bulk Photo 60','Auto-generated fixture photo number 60 for testing purposes','fixture_admin',149,9225,1273,1495,NULL,NULL,'2026-04-03',NULL,'./upload/2026/04/bulk_photo_60.png',NULL,0,'d95a115e6a4bd657bf2f928810a1532a',1,NULL,NULL,NULL,'2026-03-28 21:48:20'),
(61,'bulk_photo_61.png','2025-11-07 21:48:20','2025-06-11 21:48:20','Bulk Photo 61','Auto-generated fixture photo number 61 for testing purposes','fixture_admin',156,13764,1636,1187,NULL,NULL,'2026-04-21',NULL,'./upload/2026/04/bulk_photo_61.png',NULL,0,'e14e007489aed5ba999d79131259ab4d',1,NULL,NULL,NULL,'2026-03-30 21:48:20'),
(62,'bulk_photo_62.png','2026-04-21 21:48:20','2025-08-07 21:48:20','Bulk Photo 62','Auto-generated fixture photo number 62 for testing purposes','fixture_admin',150,14899,978,1231,NULL,NULL,'2026-04-25',NULL,'./upload/2026/04/bulk_photo_62.png',NULL,0,'1efcea735ad1ea19b3feb36d79828afd',1,NULL,NULL,NULL,'2026-04-21 21:48:20'),
(63,'bulk_photo_63.png','2025-07-17 21:48:20','2025-09-12 21:48:20','Bulk Photo 63','Auto-generated fixture photo number 63 for testing purposes','fixture_admin',43,6684,1017,961,NULL,NULL,'2026-04-13',NULL,'./upload/2026/04/bulk_photo_63.png',NULL,0,'6885eacccdd2aab3f092d71ca9277e3d',1,NULL,NULL,NULL,'2026-04-23 21:48:20'),
(64,'bulk_photo_64.png','2026-02-13 21:48:20','2025-01-17 21:48:20','Bulk Photo 64','Auto-generated fixture photo number 64 for testing purposes','fixture_admin',117,4366,1226,1238,NULL,NULL,'2026-04-12',NULL,'./upload/2026/04/bulk_photo_64.png',NULL,0,'cfaa98f07477d4e01b589d96029e63ca',1,NULL,NULL,NULL,'2026-04-19 21:48:20'),
(65,'bulk_photo_65.png','2025-06-09 21:48:20','2025-02-01 21:48:20','Bulk Photo 65','Auto-generated fixture photo number 65 for testing purposes','fixture_admin',87,9329,1228,1308,NULL,NULL,'2026-04-01',NULL,'./upload/2026/04/bulk_photo_65.png',NULL,0,'51128ed2097a2a52de80337d1cff87d5',1,NULL,NULL,NULL,'2026-03-29 21:48:20'),
(66,'bulk_photo_66.png','2026-02-10 21:48:20','2026-01-06 21:48:20','Bulk Photo 66','Auto-generated fixture photo number 66 for testing purposes','fixture_admin',27,7813,1731,749,NULL,NULL,'2026-04-11',NULL,'./upload/2026/04/bulk_photo_66.png',NULL,0,'17b91955ec3baf9d53922cbd44965b2c',1,NULL,NULL,NULL,'2026-04-26 21:48:20'),
(67,'bulk_photo_67.png','2025-10-16 21:48:20','2025-01-29 21:48:20','Bulk Photo 67','Auto-generated fixture photo number 67 for testing purposes','fixture_admin',103,15779,946,963,NULL,NULL,'2026-04-07',NULL,'./upload/2026/04/bulk_photo_67.png',NULL,0,'a54cc13784a38e02dc40e512c242cd8b',1,NULL,NULL,NULL,'2026-04-25 21:48:20'),
(68,'bulk_photo_68.png','2026-01-22 21:48:20','2025-12-25 21:48:20','Bulk Photo 68','Auto-generated fixture photo number 68 for testing purposes','fixture_admin',12,17016,1852,1449,NULL,NULL,'2026-04-24',NULL,'./upload/2026/04/bulk_photo_68.png',NULL,0,'0cb078f0c836aadcc57ba52fcab4e141',1,NULL,NULL,NULL,'2026-04-08 21:48:20'),
(69,'bulk_photo_69.png','2025-06-28 21:48:20','2025-10-01 21:48:20','Bulk Photo 69','Auto-generated fixture photo number 69 for testing purposes','fixture_admin',187,17174,1173,676,NULL,NULL,'2026-04-12',NULL,'./upload/2026/04/bulk_photo_69.png',NULL,0,'3072cc3c47b951cbf2cb9f2b47d8df29',1,NULL,NULL,NULL,'2026-04-21 21:48:20'),
(70,'bulk_photo_70.png','2025-10-23 21:48:20','2024-05-29 21:48:20','Bulk Photo 70','Auto-generated fixture photo number 70 for testing purposes','fixture_admin',50,10239,1035,1331,NULL,NULL,'2026-04-12',NULL,'./upload/2026/04/bulk_photo_70.png',NULL,0,'a8ea547770260c55cdb1b4e57d8e6666',1,NULL,NULL,NULL,'2026-03-30 21:48:20'),
(71,'bulk_photo_71.png','2026-02-12 21:48:20','2025-11-02 21:48:20','Bulk Photo 71','Auto-generated fixture photo number 71 for testing purposes','fixture_admin',118,7958,1347,1086,NULL,NULL,'2026-04-16',NULL,'./upload/2026/04/bulk_photo_71.png',NULL,0,'9479abe7323b3f679727f866855af554',1,NULL,NULL,NULL,'2026-04-25 21:48:20'),
(72,'bulk_photo_72.png','2026-02-03 21:48:20','2024-04-27 21:48:20','Bulk Photo 72','Auto-generated fixture photo number 72 for testing purposes','fixture_admin',62,13213,1921,1452,NULL,NULL,'2026-03-30',NULL,'./upload/2026/04/bulk_photo_72.png',NULL,0,'aa9808b178b7e8feffd73687abe1c678',1,NULL,NULL,NULL,'2026-04-02 21:48:20'),
(73,'bulk_photo_73.png','2026-01-13 21:48:20','2024-05-16 21:48:20','Bulk Photo 73','Auto-generated fixture photo number 73 for testing purposes','fixture_admin',3,6556,1700,844,NULL,NULL,'2026-04-23',NULL,'./upload/2026/04/bulk_photo_73.png',NULL,0,'c3e95a3d796a588fa363fc4790b4708c',1,NULL,NULL,NULL,'2026-04-05 21:48:20'),
(74,'bulk_photo_74.png','2025-12-28 21:48:20','2025-06-10 21:48:20','Bulk Photo 74','Auto-generated fixture photo number 74 for testing purposes','fixture_admin',42,16112,969,992,NULL,NULL,'2026-04-04',NULL,'./upload/2026/04/bulk_photo_74.png',NULL,0,'2f50f363b1fd6bd84c0171035a8d9218',1,NULL,NULL,NULL,'2026-04-13 21:48:20'),
(75,'bulk_photo_75.png','2026-04-07 21:48:20','2024-07-27 21:48:20','Bulk Photo 75','Auto-generated fixture photo number 75 for testing purposes','fixture_admin',43,11368,1581,1387,NULL,NULL,'2026-04-14',NULL,'./upload/2026/04/bulk_photo_75.png',NULL,0,'ba22c7c424debdccb11cf3ccf47511e1',1,NULL,NULL,NULL,'2026-04-12 21:48:20'),
(76,'bulk_photo_76.png','2026-03-18 21:48:20','2026-01-30 21:48:20','Bulk Photo 76','Auto-generated fixture photo number 76 for testing purposes','fixture_admin',54,4072,1046,612,NULL,NULL,'2026-04-13',NULL,'./upload/2026/04/bulk_photo_76.png',NULL,0,'884687357a00abfa1a961c2708ba4b3b',1,NULL,NULL,NULL,'2026-04-20 21:48:20'),
(77,'bulk_photo_77.png','2025-07-13 21:48:20','2025-10-25 21:48:20','Bulk Photo 77','Auto-generated fixture photo number 77 for testing purposes','fixture_admin',178,15374,1849,816,NULL,NULL,'2026-04-09',NULL,'./upload/2026/04/bulk_photo_77.png',NULL,0,'4f4620b44c5995d436023819987d2947',1,NULL,NULL,NULL,'2026-04-21 21:48:20'),
(78,'bulk_photo_78.png','2026-03-17 21:48:20','2026-03-20 21:48:20','Bulk Photo 78','Auto-generated fixture photo number 78 for testing purposes','fixture_admin',184,11272,1408,756,NULL,NULL,'2026-04-16',NULL,'./upload/2026/04/bulk_photo_78.png',NULL,0,'d7d8b643bebf734fd366b363ce73bada',1,NULL,NULL,NULL,'2026-04-20 21:48:20'),
(79,'bulk_photo_79.png','2025-04-28 21:48:20','2025-08-06 21:48:20','Bulk Photo 79','Auto-generated fixture photo number 79 for testing purposes','fixture_admin',163,4143,1506,1425,NULL,NULL,'2026-04-02',NULL,'./upload/2026/04/bulk_photo_79.png',NULL,0,'97c4be45179ec15a578cac4a4fbb1d05',1,NULL,NULL,NULL,'2026-04-16 21:48:20'),
(80,'bulk_photo_80.png','2026-01-01 21:48:20','2025-04-17 21:48:20','Bulk Photo 80','Auto-generated fixture photo number 80 for testing purposes','fixture_admin',123,12587,1805,1123,NULL,NULL,'2026-04-15',NULL,'./upload/2026/04/bulk_photo_80.png',NULL,0,'712c0d3915e9785c48af1186ad21c26d',1,NULL,NULL,NULL,'2026-04-20 21:48:20'),
(81,'bulk_photo_81.png','2025-06-02 21:48:20','2024-08-09 21:48:20','Bulk Photo 81','Auto-generated fixture photo number 81 for testing purposes','fixture_admin',116,9366,1921,1198,NULL,NULL,'2026-04-11',NULL,'./upload/2026/04/bulk_photo_81.png',NULL,0,'ab820730c9ef7e6274a01e353ed657ed',1,NULL,NULL,NULL,'2026-04-08 21:48:20'),
(82,'bulk_photo_82.png','2025-10-19 21:48:20','2024-10-30 21:48:20','Bulk Photo 82','Auto-generated fixture photo number 82 for testing purposes','fixture_admin',33,13467,1355,1086,NULL,NULL,'2026-04-17',NULL,'./upload/2026/04/bulk_photo_82.png',NULL,0,'872ff7eeb247c705584bf1486c0fd8e0',1,NULL,NULL,NULL,'2026-03-29 21:48:20'),
(83,'bulk_photo_83.png','2025-07-18 21:48:20','2026-03-23 21:48:20','Bulk Photo 83','Auto-generated fixture photo number 83 for testing purposes','fixture_admin',182,10569,1186,939,NULL,NULL,'2026-03-30',NULL,'./upload/2026/04/bulk_photo_83.png',NULL,0,'dd79231a3146c3661ca41379c0f8fd86',1,NULL,NULL,NULL,'2026-04-12 21:48:20'),
(84,'bulk_photo_84.png','2025-09-22 21:48:20','2025-03-21 21:48:20','Bulk Photo 84','Auto-generated fixture photo number 84 for testing purposes','fixture_admin',194,7513,1010,797,NULL,NULL,'2026-04-09',NULL,'./upload/2026/04/bulk_photo_84.png',NULL,0,'8e157e526f285ecc5a0fb5e43cb01b0a',1,NULL,NULL,NULL,'2026-04-21 21:48:20'),
(85,'bulk_photo_85.png','2026-02-04 21:48:20','2025-03-14 21:48:20','Bulk Photo 85','Auto-generated fixture photo number 85 for testing purposes','fixture_admin',25,19278,1273,697,NULL,NULL,'2026-04-16',NULL,'./upload/2026/04/bulk_photo_85.png',NULL,0,'0e45979663c43bf307039e5227e734e1',1,NULL,NULL,NULL,'2026-04-12 21:48:20'),
(86,'bulk_photo_86.png','2026-01-12 21:48:20','2026-04-17 21:48:20','Bulk Photo 86','Auto-generated fixture photo number 86 for testing purposes','fixture_admin',41,19926,1226,1312,NULL,NULL,'2026-03-31',NULL,'./upload/2026/04/bulk_photo_86.png',NULL,0,'c3c115a888405fe36c95ce97775ca665',1,NULL,NULL,NULL,'2026-04-24 21:48:20'),
(87,'bulk_photo_87.png','2025-07-16 21:48:20','2025-01-24 21:48:20','Bulk Photo 87','Auto-generated fixture photo number 87 for testing purposes','fixture_admin',158,5177,1995,1283,NULL,NULL,'2026-04-02',NULL,'./upload/2026/04/bulk_photo_87.png',NULL,0,'d0c124bdb9b3d565152d3241e99e645f',1,NULL,NULL,NULL,'2026-04-04 21:48:20'),
(88,'bulk_photo_88.png','2025-12-15 21:48:20','2025-03-24 21:48:20','Bulk Photo 88','Auto-generated fixture photo number 88 for testing purposes','fixture_admin',127,12892,1836,1184,NULL,NULL,'2026-04-07',NULL,'./upload/2026/04/bulk_photo_88.png',NULL,0,'8ba403b75e46ef976fe87e9756b18ecc',1,NULL,NULL,NULL,'2026-04-16 21:48:20'),
(89,'bulk_photo_89.png','2025-08-02 21:48:20','2025-01-17 21:48:20','Bulk Photo 89','Auto-generated fixture photo number 89 for testing purposes','fixture_admin',197,4340,977,1208,NULL,NULL,'2026-03-29',NULL,'./upload/2026/04/bulk_photo_89.png',NULL,0,'151897cbfdba2c3124876138568439da',1,NULL,NULL,NULL,'2026-04-07 21:48:20'),
(90,'bulk_photo_90.png','2025-11-04 21:48:20','2025-07-03 21:48:20','Bulk Photo 90','Auto-generated fixture photo number 90 for testing purposes','fixture_admin',121,17187,1151,1493,NULL,NULL,'2026-04-24',NULL,'./upload/2026/04/bulk_photo_90.png',NULL,0,'ec92451a9caa1e998a0de53735624b89',1,NULL,NULL,NULL,'2026-04-13 21:48:20'),
(91,'bulk_photo_91.png','2025-05-05 21:48:20','2025-03-28 21:48:20','Bulk Photo 91','Auto-generated fixture photo number 91 for testing purposes','fixture_admin',155,8119,1951,623,NULL,NULL,'2026-04-19',NULL,'./upload/2026/04/bulk_photo_91.png',NULL,0,'ee338315f85158ef62155ecdb2dc691c',1,NULL,NULL,NULL,'2026-04-21 21:48:20'),
(92,'bulk_photo_92.png','2026-03-13 21:48:20','2026-02-22 21:48:20','Bulk Photo 92','Auto-generated fixture photo number 92 for testing purposes','fixture_admin',13,5324,1048,1309,NULL,NULL,'2026-04-17',NULL,'./upload/2026/04/bulk_photo_92.png',NULL,0,'d66a851e120fbd2e2b79e855736c655e',1,NULL,NULL,NULL,'2026-04-20 21:48:20'),
(93,'extra_photo_1.png','2026-02-23 21:49:10','2024-11-13 21:49:10','Extra Photo 1 — Reflections on still water','This is photo number 1 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',54,16961,2488,1703,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_1.png',NULL,0,'b9b447dc47af94f12ef53948fa61c935',1,NULL,NULL,NULL,'2026-04-14 21:49:10'),
(94,'extra_photo_101.png','2025-12-11 21:49:10','2025-11-10 21:49:10','Extra Photo 101 — Reflections on still water','This is photo number 101 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',12,18491,1464,1183,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_101.png',NULL,0,'0ba6889f06540112d48bb9c65d355025',1,NULL,NULL,NULL,'2026-03-05 21:49:10'),
(95,'extra_photo_201.png','2025-09-11 21:49:10','2025-04-20 21:49:10','Extra Photo 201 — Reflections on still water','This is photo number 201 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',337,28405,1740,1720,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_201.png',NULL,0,'0418d7b9bdc36b331a4a808f4b6ae941',1,NULL,NULL,NULL,'2026-04-14 21:49:10'),
(96,'extra_photo_2.png','2026-01-13 21:49:10','2024-09-14 21:49:10','Extra Photo 2 — Urban architecture detail','This is photo number 2 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',95,20697,1377,2053,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_2.png',NULL,0,'8cc64f6fde1cfd6db886b2872719302d',1,NULL,NULL,NULL,'2026-03-16 21:49:10'),
(97,'extra_photo_102.png','2026-03-13 21:49:10','2025-04-11 21:49:10','Extra Photo 102 — Urban architecture detail','This is photo number 102 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',120,23541,2428,1787,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_102.png',NULL,0,'4c878861a7882385a1c7ea21b299cfaa',1,NULL,NULL,NULL,'2026-03-25 21:49:10'),
(98,'extra_photo_202.png','2025-06-03 21:49:10','2024-08-27 21:49:10','Extra Photo 202 — Urban architecture detail','This is photo number 202 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',235,28544,3286,2047,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_202.png',NULL,0,'a067d3f7b0d6c349e5963670b83e11f8',1,NULL,NULL,NULL,'2026-04-09 21:49:10'),
(99,'extra_photo_3.png','2026-03-18 21:49:10','2024-12-21 21:49:10','Extra Photo 3 — Coastal cliffs at dusk','This is photo number 3 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',21,12589,3189,1715,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_3.png',NULL,0,'4901daf85ecf33897f637becde7e78df',1,NULL,NULL,NULL,'2026-04-04 21:49:10'),
(100,'extra_photo_103.png','2026-03-04 21:49:10','2025-02-05 21:49:10','Extra Photo 103 — Coastal cliffs at dusk','This is photo number 103 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',306,13594,1987,2307,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_103.png',NULL,0,'05b7c818946cce5469f70e25e9759d8d',1,NULL,NULL,NULL,'2026-03-14 21:49:10'),
(101,'extra_photo_203.png','2025-07-06 21:49:10','2024-08-15 21:49:10','Extra Photo 203 — Coastal cliffs at dusk','This is photo number 203 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',414,22334,2398,1926,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_203.png',NULL,0,'929ff6ee05e9dd534b364050704465ed',1,NULL,NULL,NULL,'2026-04-25 21:49:10'),
(102,'extra_photo_4.png','2026-04-24 21:49:10','2024-05-21 21:49:10','Extra Photo 4 — Wildflower meadow at dawn','This is photo number 4 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',404,11411,1886,807,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_4.png',NULL,0,'6f43cbcb7fe0c20a0961086858247b2c',1,NULL,NULL,NULL,'2026-04-17 21:49:10'),
(103,'extra_photo_104.png','2025-07-07 21:49:10','2025-04-03 21:49:10','Extra Photo 104 — Wildflower meadow at dawn','This is photo number 104 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',122,23126,2198,1103,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_104.png',NULL,0,'d772b2d0314d5ff6611bf9fe2004aaa7',1,NULL,NULL,NULL,'2026-03-15 21:49:10'),
(104,'extra_photo_204.png','2025-05-20 21:49:10','2025-03-01 21:49:10','Extra Photo 204 — Wildflower meadow at dawn','This is photo number 204 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',38,23693,1292,1168,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_204.png',NULL,0,'3abb7ca735f298d80f94350e9e2331bb',1,NULL,NULL,NULL,'2026-04-24 21:49:10'),
(105,'extra_photo_5.png','2025-10-29 21:49:10','2025-08-17 21:49:10','Extra Photo 5 — Autumn colours in the forest','This is photo number 5 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',129,14046,2364,1872,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_5.png',NULL,0,'3fb2b0f669d3c62522c8710f9cc098bc',1,NULL,NULL,NULL,'2026-03-04 21:49:10'),
(106,'extra_photo_105.png','2025-11-07 21:49:10','2025-01-09 21:49:10','Extra Photo 105 — Autumn colours in the forest','This is photo number 105 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',418,13729,2844,1936,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_105.png',NULL,0,'b00a7078919c384593c276175f22b8ac',1,NULL,NULL,NULL,'2026-03-28 21:49:10'),
(107,'extra_photo_205.png','2025-12-21 21:49:10','2025-10-30 21:49:10','Extra Photo 205 — Autumn colours in the forest','This is photo number 205 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',91,12366,2066,1214,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_205.png',NULL,0,'53ede25b649157163f18d2ad9e7296f9',1,NULL,NULL,NULL,'2026-04-14 21:49:10'),
(108,'extra_photo_6.png','2026-01-13 21:49:10','2024-10-02 21:49:10','Extra Photo 6 — Reflections on still water','This is photo number 6 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',31,31278,2779,1409,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_6.png',NULL,0,'db4753abb450c60035580b830e6d9489',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(109,'extra_photo_106.png','2025-10-24 21:49:10','2024-10-31 21:49:10','Extra Photo 106 — Reflections on still water','This is photo number 106 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',98,26056,1614,1769,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_106.png',NULL,0,'07116c2b1722a12b734545f03b7bad3b',1,NULL,NULL,NULL,'2026-03-27 21:49:10'),
(110,'extra_photo_206.png','2025-07-29 21:49:10','2025-12-13 21:49:10','Extra Photo 206 — Reflections on still water','This is photo number 206 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',342,29087,2004,865,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_206.png',NULL,0,'4522b9586be3baa6411f067b7e53b4cd',1,NULL,NULL,NULL,'2026-04-15 21:49:10'),
(111,'extra_photo_7.png','2025-06-15 21:49:10','2024-11-08 21:49:10','Extra Photo 7 — Urban architecture detail','This is photo number 7 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',32,11029,2252,2101,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_7.png',NULL,0,'36b4a637af8e1bb2428a8811ee94a575',1,NULL,NULL,NULL,'2026-03-12 21:49:10'),
(112,'extra_photo_107.png','2026-01-02 21:49:10','2025-09-05 21:49:10','Extra Photo 107 — Urban architecture detail','This is photo number 107 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',328,15848,2789,1329,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_107.png',NULL,0,'6efcce95db54aa389de2b618d95e37e2',1,NULL,NULL,NULL,'2026-03-17 21:49:10'),
(113,'extra_photo_207.png','2025-12-20 21:49:10','2024-11-03 21:49:10','Extra Photo 207 — Urban architecture detail','This is photo number 207 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',323,8426,1556,1903,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_207.png',NULL,0,'1b500b471734c41e2a121246b1fe4806',1,NULL,NULL,NULL,'2026-04-26 21:49:10'),
(114,'extra_photo_8.png','2025-05-16 21:49:10','2024-11-17 21:49:10','Extra Photo 8 — Coastal cliffs at dusk','This is photo number 8 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',380,23490,3467,2058,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_8.png',NULL,0,'14d3ce385040ee6732266622c9b7a09e',1,NULL,NULL,NULL,'2026-04-21 21:49:10'),
(115,'extra_photo_108.png','2026-03-07 21:49:10','2025-07-15 21:49:10','Extra Photo 108 — Coastal cliffs at dusk','This is photo number 108 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',271,20912,1351,1921,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_108.png',NULL,0,'06c9c3021117fbb71acba3060f72aebf',1,NULL,NULL,NULL,'2026-04-08 21:49:10'),
(116,'extra_photo_208.png','2025-11-05 21:49:10','2025-06-28 21:49:10','Extra Photo 208 — Coastal cliffs at dusk','This is photo number 208 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',328,8919,1734,2399,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_208.png',NULL,0,'d4499c06da2333be6305f2405127e39b',1,NULL,NULL,NULL,'2026-04-07 21:49:10'),
(117,'extra_photo_9.png','2025-09-02 21:49:10','2025-10-26 21:49:10','Extra Photo 9 — Wildflower meadow at dawn','This is photo number 9 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',152,26575,3496,1538,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_9.png',NULL,0,'872cb583d990924e8d151a695b9b69a7',1,NULL,NULL,NULL,'2026-03-31 21:49:10'),
(118,'extra_photo_109.png','2025-07-05 21:49:10','2024-11-12 21:49:10','Extra Photo 109 — Wildflower meadow at dawn','This is photo number 109 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',102,28323,2685,1687,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_109.png',NULL,0,'05f623f715dfd5d4ddbd144b09e40e1e',1,NULL,NULL,NULL,'2026-03-03 21:49:10'),
(119,'extra_photo_209.png','2025-05-29 21:49:10','2024-09-08 21:49:10','Extra Photo 209 — Wildflower meadow at dawn','This is photo number 209 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',170,14224,1855,1740,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_209.png',NULL,0,'3436c4ce5a6acbf018a4de998b1029ff',1,NULL,NULL,NULL,'2026-04-19 21:49:10'),
(120,'extra_photo_100.png','2025-06-25 21:49:10','2024-09-02 21:49:10','Extra Photo 100 — Autumn colours in the forest','This is photo number 100 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',303,21621,1235,1393,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_100.png',NULL,0,'679fe415eed120fa232bbcbc5dc682ba',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(121,'extra_photo_200.png','2025-05-18 21:49:10','2025-10-12 21:49:10','Extra Photo 200 — Autumn colours in the forest','This is photo number 200 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',262,27580,2413,928,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_200.png',NULL,0,'eb0e995acf9567ac28fcbb3612ee5097',1,NULL,NULL,NULL,'2026-03-04 21:49:10'),
(122,'extra_photo_11.png','2026-02-18 21:49:10','2025-10-14 21:49:10','Extra Photo 11 — Reflections on still water','This is photo number 11 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',390,10519,1635,1747,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_11.png',NULL,0,'30516fdfdd02045db7700c854fa26792',1,NULL,NULL,NULL,'2026-04-01 21:49:10'),
(123,'extra_photo_111.png','2026-01-04 21:49:10','2025-09-24 21:49:10','Extra Photo 111 — Reflections on still water','This is photo number 111 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',271,28055,2513,1166,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_111.png',NULL,0,'393f876fcdbf8c3abd12e462012c6da3',1,NULL,NULL,NULL,'2026-03-27 21:49:10'),
(124,'extra_photo_211.png','2025-06-28 21:49:10','2025-01-16 21:49:10','Extra Photo 211 — Reflections on still water','This is photo number 211 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',348,21800,3080,1107,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_211.png',NULL,0,'f0918b1cad2567fe4ddd41d9edf92db1',1,NULL,NULL,NULL,'2026-03-21 21:49:10'),
(125,'extra_photo_12.png','2025-11-04 21:49:10','2025-03-22 21:49:10','Extra Photo 12 — Urban architecture detail','This is photo number 12 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',157,30349,2903,2007,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_12.png',NULL,0,'2a5ebc2852d15f4406dd0495e9b63346',1,NULL,NULL,NULL,'2026-03-19 21:49:10'),
(126,'extra_photo_112.png','2025-05-10 21:49:10','2024-07-26 21:49:10','Extra Photo 112 — Urban architecture detail','This is photo number 112 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',246,28059,2881,2393,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_112.png',NULL,0,'bd32fb00aa1bcc3dd76678c405d37632',1,NULL,NULL,NULL,'2026-03-05 21:49:10'),
(127,'extra_photo_212.png','2025-12-01 21:49:10','2025-08-01 21:49:10','Extra Photo 212 — Urban architecture detail','This is photo number 212 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',320,10410,2589,1748,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_212.png',NULL,0,'8cab1d3704df5c6f8e2a7f35a5a895a9',1,NULL,NULL,NULL,'2026-04-13 21:49:10'),
(128,'extra_photo_13.png','2025-12-15 21:49:10','2026-01-22 21:49:10','Extra Photo 13 — Coastal cliffs at dusk','This is photo number 13 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',278,17538,1959,1422,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_13.png',NULL,0,'8ed18e633e6cfbf9114a0aac3941d0b0',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(129,'extra_photo_113.png','2025-07-02 21:49:10','2026-02-16 21:49:10','Extra Photo 113 — Coastal cliffs at dusk','This is photo number 113 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',11,28006,1432,2372,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_113.png',NULL,0,'5fcde5298a2bd6fa4cca0e51db6682f9',1,NULL,NULL,NULL,'2026-03-20 21:49:10'),
(130,'extra_photo_213.png','2026-02-24 21:49:10','2024-05-13 21:49:10','Extra Photo 213 — Coastal cliffs at dusk','This is photo number 213 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',190,31300,2906,1829,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_213.png',NULL,0,'2cddb872f6569edf7bfd5c1bd32f0ec2',1,NULL,NULL,NULL,'2026-04-21 21:49:10'),
(131,'extra_photo_14.png','2025-10-30 21:49:10','2025-12-03 21:49:10','Extra Photo 14 — Wildflower meadow at dawn','This is photo number 14 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',258,31897,2221,1025,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_14.png',NULL,0,'db62739b7818bc1cf3dbdb8259ab3407',1,NULL,NULL,NULL,'2026-04-01 21:49:10'),
(132,'extra_photo_114.png','2025-08-08 21:49:10','2025-09-21 21:49:10','Extra Photo 114 — Wildflower meadow at dawn','This is photo number 114 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',171,27742,1396,2307,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_114.png',NULL,0,'90f756c55b2a9d137076ad3a82bb3100',1,NULL,NULL,NULL,'2026-03-30 21:49:10'),
(133,'extra_photo_214.png','2025-10-26 21:49:10','2026-02-07 21:49:10','Extra Photo 214 — Wildflower meadow at dawn','This is photo number 214 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',16,28224,1476,877,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_214.png',NULL,0,'27328197cf25dc453df249244cfcc687',1,NULL,NULL,NULL,'2026-03-04 21:49:10'),
(134,'extra_photo_15.png','2025-12-24 21:49:10','2026-04-25 21:49:10','Extra Photo 15 — Autumn colours in the forest','This is photo number 15 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',497,31325,3293,1512,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_15.png',NULL,0,'dd76dbef41242001ba12f497ae25be7e',1,NULL,NULL,NULL,'2026-03-21 21:49:10'),
(135,'extra_photo_115.png','2025-08-08 21:49:10','2024-10-29 21:49:10','Extra Photo 115 — Autumn colours in the forest','This is photo number 115 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',291,24278,2740,1079,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_115.png',NULL,0,'1b12ff7b10efcb684e2abffb93baa1d9',1,NULL,NULL,NULL,'2026-03-01 21:49:10'),
(136,'extra_photo_215.png','2026-02-06 21:49:10','2025-10-30 21:49:10','Extra Photo 215 — Autumn colours in the forest','This is photo number 215 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',283,10452,3144,1991,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_215.png',NULL,0,'f623b60620cad16ffae0982f8398e043',1,NULL,NULL,NULL,'2026-04-09 21:49:10'),
(137,'extra_photo_16.png','2026-02-03 21:49:10','2025-10-26 21:49:10','Extra Photo 16 — Reflections on still water','This is photo number 16 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',287,10973,3348,967,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_16.png',NULL,0,'d5d36c95331a41643763b52709f8f1d8',1,NULL,NULL,NULL,'2026-03-07 21:49:10'),
(138,'extra_photo_116.png','2025-06-13 21:49:10','2024-08-18 21:49:10','Extra Photo 116 — Reflections on still water','This is photo number 116 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',304,20281,2955,996,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_116.png',NULL,0,'f546e6d48d8fcb8c7ec7a8144946aa89',1,NULL,NULL,NULL,'2026-04-01 21:49:10'),
(139,'extra_photo_216.png','2025-08-05 21:49:10','2025-07-29 21:49:10','Extra Photo 216 — Reflections on still water','This is photo number 216 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',341,15263,2308,1440,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_216.png',NULL,0,'6488f484c3a6c55710b8181404594344',1,NULL,NULL,NULL,'2026-03-20 21:49:10'),
(140,'extra_photo_17.png','2025-06-07 21:49:10','2025-03-01 21:49:10','Extra Photo 17 — Urban architecture detail','This is photo number 17 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',113,17764,2043,1658,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_17.png',NULL,0,'e2e306d37106a1997313bbd691aff14c',1,NULL,NULL,NULL,'2026-03-20 21:49:10'),
(141,'extra_photo_117.png','2025-10-13 21:49:10','2024-09-28 21:49:10','Extra Photo 117 — Urban architecture detail','This is photo number 117 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',168,15666,2606,2356,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_117.png',NULL,0,'ef99ccdeb6afba40dcf421efbac58f75',1,NULL,NULL,NULL,'2026-04-20 21:49:10'),
(142,'extra_photo_217.png','2025-09-16 21:49:10','2024-11-07 21:49:10','Extra Photo 217 — Urban architecture detail','This is photo number 217 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',419,31775,2251,1148,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_217.png',NULL,0,'6124adb6601373acfb6b73ced5fc36bd',1,NULL,NULL,NULL,'2026-03-11 21:49:10'),
(143,'extra_photo_18.png','2026-02-04 21:49:10','2024-09-27 21:49:10','Extra Photo 18 — Coastal cliffs at dusk','This is photo number 18 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',139,8705,1938,1520,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_18.png',NULL,0,'87bd1fbe7c5676f9e56a8ab837ad9751',1,NULL,NULL,NULL,'2026-04-07 21:49:10'),
(144,'extra_photo_118.png','2026-01-12 21:49:10','2025-06-05 21:49:10','Extra Photo 118 — Coastal cliffs at dusk','This is photo number 118 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',186,20609,2422,2353,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_118.png',NULL,0,'85e1e3903294292818c051c9c2fb407d',1,NULL,NULL,NULL,'2026-04-07 21:49:10'),
(145,'extra_photo_218.png','2025-08-06 21:49:10','2025-01-27 21:49:10','Extra Photo 218 — Coastal cliffs at dusk','This is photo number 218 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',476,29475,2678,1432,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_218.png',NULL,0,'72f59af4fec2457f80744fc114c48ca7',1,NULL,NULL,NULL,'2026-04-19 21:49:10'),
(146,'extra_photo_19.png','2025-11-11 21:49:10','2024-07-14 21:49:10','Extra Photo 19 — Wildflower meadow at dawn','This is photo number 19 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',47,27099,2869,946,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_19.png',NULL,0,'2a1638bb92304ea97d9fcd0df3331e41',1,NULL,NULL,NULL,'2026-04-04 21:49:10'),
(147,'extra_photo_119.png','2025-09-28 21:49:10','2024-10-07 21:49:10','Extra Photo 119 — Wildflower meadow at dawn','This is photo number 119 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',72,17556,2535,1739,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_119.png',NULL,0,'68dae0ece8f9ba5eec86703ec37cbc65',1,NULL,NULL,NULL,'2026-04-10 21:49:10'),
(148,'extra_photo_219.png','2025-09-28 21:49:10','2026-02-26 21:49:10','Extra Photo 219 — Wildflower meadow at dawn','This is photo number 219 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',338,11382,2815,2308,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_219.png',NULL,0,'365f0bf001161ed03ce5a796bb9e3669',1,NULL,NULL,NULL,'2026-03-16 21:49:10'),
(149,'extra_photo_10.png','2025-09-05 21:49:10','2026-01-29 21:49:10','Extra Photo 10 — Autumn colours in the forest','This is photo number 10 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',338,8659,1454,1516,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_10.png',NULL,0,'33133047fcb2c0d35b1d8682a737c817',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(150,'extra_photo_110.png','2026-01-23 21:49:10','2025-04-14 21:49:10','Extra Photo 110 — Autumn colours in the forest','This is photo number 110 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',410,21188,1888,2058,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_110.png',NULL,0,'9fbdf67454043310e9f8e9d0951d927b',1,NULL,NULL,NULL,'2026-04-22 21:49:10'),
(151,'extra_photo_210.png','2025-04-28 21:49:10','2024-10-11 21:49:10','Extra Photo 210 — Autumn colours in the forest','This is photo number 210 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',430,31841,2122,2308,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_210.png',NULL,0,'391e87d1f02c5c136c3e3f1737256c6f',1,NULL,NULL,NULL,'2026-03-24 21:49:10'),
(152,'extra_photo_21.png','2025-05-08 21:49:10','2025-12-27 21:49:10','Extra Photo 21 — Reflections on still water','This is photo number 21 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',461,10729,3125,1871,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_21.png',NULL,0,'2b2fd7b5bdec6dbcd5e3446598dc508f',1,NULL,NULL,NULL,'2026-03-01 21:49:10'),
(153,'extra_photo_121.png','2025-08-12 21:49:10','2024-12-01 21:49:10','Extra Photo 121 — Reflections on still water','This is photo number 121 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',193,28020,1219,1661,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_121.png',NULL,0,'9aa904fc4673b9eddd7c10567403c13f',1,NULL,NULL,NULL,'2026-03-17 21:49:10'),
(154,'extra_photo_221.png','2025-08-06 21:49:10','2025-02-09 21:49:10','Extra Photo 221 — Reflections on still water','This is photo number 221 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',428,19409,3129,1751,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_221.png',NULL,0,'fff5a74eec1a0e4b5b99833bd1625637',1,NULL,NULL,NULL,'2026-03-24 21:49:10'),
(155,'extra_photo_22.png','2026-04-20 21:49:10','2025-07-02 21:49:10','Extra Photo 22 — Urban architecture detail','This is photo number 22 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',495,25388,2764,938,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_22.png',NULL,0,'d346749b691c82c243928be95232c058',1,NULL,NULL,NULL,'2026-03-29 21:49:10'),
(156,'extra_photo_122.png','2026-03-13 21:49:10','2025-12-16 21:49:10','Extra Photo 122 — Urban architecture detail','This is photo number 122 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',267,11268,1380,2346,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_122.png',NULL,0,'86976483d757707c2ee1ee809f97f7d4',1,NULL,NULL,NULL,'2026-03-21 21:49:10'),
(157,'extra_photo_222.png','2026-03-05 21:49:10','2024-07-12 21:49:10','Extra Photo 222 — Urban architecture detail','This is photo number 222 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',22,20850,2505,980,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_222.png',NULL,0,'9a44ef639ddbcd759a4f62718d2ffe2e',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(158,'extra_photo_23.png','2025-12-30 21:49:10','2024-09-07 21:49:10','Extra Photo 23 — Coastal cliffs at dusk','This is photo number 23 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',58,11153,1938,1031,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_23.png',NULL,0,'05e60bdad7d97561b823d50cf96cb6c7',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(159,'extra_photo_123.png','2025-10-01 21:49:10','2025-06-05 21:49:10','Extra Photo 123 — Coastal cliffs at dusk','This is photo number 123 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',261,14517,3099,1029,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_123.png',NULL,0,'cb9df998da5b689433a91a9acd93350c',1,NULL,NULL,NULL,'2026-04-06 21:49:10'),
(160,'extra_photo_223.png','2026-01-14 21:49:10','2025-07-22 21:49:10','Extra Photo 223 — Coastal cliffs at dusk','This is photo number 223 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',32,12221,2847,2246,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_223.png',NULL,0,'146e146682a917aeabbd7d627808e58b',1,NULL,NULL,NULL,'2026-03-30 21:49:10'),
(161,'extra_photo_24.png','2025-09-21 21:49:10','2025-02-20 21:49:10','Extra Photo 24 — Wildflower meadow at dawn','This is photo number 24 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',80,8979,2926,1557,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_24.png',NULL,0,'29771d6c7c4a2f4b0f447916aa7ce3a2',1,NULL,NULL,NULL,'2026-04-14 21:49:10'),
(162,'extra_photo_124.png','2025-09-07 21:49:10','2025-04-02 21:49:10','Extra Photo 124 — Wildflower meadow at dawn','This is photo number 124 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',383,13698,3323,1937,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_124.png',NULL,0,'e3c9a42832940dacf4844ee8e83d4e89',1,NULL,NULL,NULL,'2026-03-04 21:49:10'),
(163,'extra_photo_224.png','2025-12-14 21:49:10','2026-01-18 21:49:10','Extra Photo 224 — Wildflower meadow at dawn','This is photo number 224 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',286,19077,2612,1691,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_224.png',NULL,0,'dc9b260640512a367d6e3b69b24f8aa5',1,NULL,NULL,NULL,'2026-04-25 21:49:10'),
(164,'extra_photo_25.png','2025-11-20 21:49:10','2026-02-15 21:49:10','Extra Photo 25 — Autumn colours in the forest','This is photo number 25 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',92,23333,2731,1239,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_25.png',NULL,0,'2ec77808b818c867914633a450a03a67',1,NULL,NULL,NULL,'2026-03-30 21:49:10'),
(165,'extra_photo_125.png','2025-11-06 21:49:10','2024-05-20 21:49:10','Extra Photo 125 — Autumn colours in the forest','This is photo number 125 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',215,14112,3550,1011,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_125.png',NULL,0,'ad19320c59c6494e70149ea4dbdffae5',1,NULL,NULL,NULL,'2026-03-14 21:49:10'),
(166,'extra_photo_225.png','2026-02-05 21:49:10','2024-06-17 21:49:10','Extra Photo 225 — Autumn colours in the forest','This is photo number 225 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',492,11314,2965,1223,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_225.png',NULL,0,'cf22c8142c788e99fd5b6bf41adffb59',1,NULL,NULL,NULL,'2026-04-20 21:49:10'),
(167,'extra_photo_26.png','2025-07-14 21:49:10','2025-02-25 21:49:10','Extra Photo 26 — Reflections on still water','This is photo number 26 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',278,8853,2416,1483,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_26.png',NULL,0,'881d74e3cb9f7bd0af1774fba239d1c4',1,NULL,NULL,NULL,'2026-03-21 21:49:10'),
(168,'extra_photo_126.png','2025-07-10 21:49:10','2026-01-22 21:49:10','Extra Photo 126 — Reflections on still water','This is photo number 126 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',131,30293,3248,1569,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_126.png',NULL,0,'de9438b10a8280280df66d648fa9cc6e',1,NULL,NULL,NULL,'2026-03-07 21:49:10'),
(169,'extra_photo_226.png','2025-07-19 21:49:10','2025-08-26 21:49:10','Extra Photo 226 — Reflections on still water','This is photo number 226 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',174,25873,2825,1042,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_226.png',NULL,0,'41e8ca38c2355f05d7799ecd5441a75a',1,NULL,NULL,NULL,'2026-03-14 21:49:10'),
(170,'extra_photo_27.png','2026-02-23 21:49:10','2024-12-15 21:49:10','Extra Photo 27 — Urban architecture detail','This is photo number 27 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',446,18170,2250,2268,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_27.png',NULL,0,'15d81cf09d579c325d1c7c9b50a2000d',1,NULL,NULL,NULL,'2026-04-10 21:49:10'),
(171,'extra_photo_127.png','2025-09-10 21:49:10','2025-09-17 21:49:10','Extra Photo 127 — Urban architecture detail','This is photo number 127 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',318,14517,2279,1493,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_127.png',NULL,0,'84abedb55ca84e98310c3d058d247c54',1,NULL,NULL,NULL,'2026-03-08 21:49:10'),
(172,'extra_photo_227.png','2025-07-12 21:49:10','2025-04-30 21:49:10','Extra Photo 227 — Urban architecture detail','This is photo number 227 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',54,9381,3506,1811,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_227.png',NULL,0,'e643b09d835f569e0728c9f8be2382d8',1,NULL,NULL,NULL,'2026-04-10 21:49:10'),
(173,'extra_photo_28.png','2025-10-30 21:49:10','2025-01-31 21:49:10','Extra Photo 28 — Coastal cliffs at dusk','This is photo number 28 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',307,13438,1888,2006,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_28.png',NULL,0,'b32999fb39f3c42aab31c8056fa2df43',1,NULL,NULL,NULL,'2026-03-03 21:49:10'),
(174,'extra_photo_128.png','2026-01-14 21:49:10','2024-12-13 21:49:10','Extra Photo 128 — Coastal cliffs at dusk','This is photo number 128 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',286,27547,2046,1313,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_128.png',NULL,0,'d5f7087e68acba04b1a799e99a43cb74',1,NULL,NULL,NULL,'2026-03-25 21:49:10'),
(175,'extra_photo_228.png','2025-07-22 21:49:10','2025-12-12 21:49:10','Extra Photo 228 — Coastal cliffs at dusk','This is photo number 228 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',317,22899,1673,997,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_228.png',NULL,0,'bcc8ef12f1a82c0089c7fc0721bdf04c',1,NULL,NULL,NULL,'2026-04-25 21:49:10'),
(176,'extra_photo_29.png','2025-07-22 21:49:10','2024-11-04 21:49:10','Extra Photo 29 — Wildflower meadow at dawn','This is photo number 29 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',199,26653,2859,1002,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_29.png',NULL,0,'27adcc33f2d51088264041f09eff8e09',1,NULL,NULL,NULL,'2026-03-24 21:49:10'),
(177,'extra_photo_129.png','2025-11-30 21:49:10','2025-08-13 21:49:10','Extra Photo 129 — Wildflower meadow at dawn','This is photo number 129 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',272,24035,2897,1650,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_129.png',NULL,0,'dc87005a2e1cb6a3d080190961f76d1d',1,NULL,NULL,NULL,'2026-03-25 21:49:10'),
(178,'extra_photo_229.png','2026-03-26 21:49:10','2024-09-03 21:49:10','Extra Photo 229 — Wildflower meadow at dawn','This is photo number 229 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',426,27133,2226,1995,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_229.png',NULL,0,'af359c9f5124a19fac93f6970d8d93b4',1,NULL,NULL,NULL,'2026-03-30 21:49:10'),
(179,'extra_photo_20.png','2026-04-18 21:49:10','2024-10-18 21:49:10','Extra Photo 20 — Autumn colours in the forest','This is photo number 20 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',367,17271,2950,1580,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_20.png',NULL,0,'11b3261db27d2f0e172c8b176037861b',1,NULL,NULL,NULL,'2026-04-11 21:49:10'),
(180,'extra_photo_120.png','2025-07-11 21:49:10','2025-11-26 21:49:10','Extra Photo 120 — Autumn colours in the forest','This is photo number 120 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',330,24357,2221,932,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_120.png',NULL,0,'efa590f05b19b99869b2e06d05dda7ec',1,NULL,NULL,NULL,'2026-04-18 21:49:10'),
(181,'extra_photo_220.png','2025-11-18 21:49:10','2024-10-08 21:49:10','Extra Photo 220 — Autumn colours in the forest','This is photo number 220 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',279,19396,2870,888,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_220.png',NULL,0,'a1fa689c95b8c7ac9f0f7adefc7c1ab7',1,NULL,NULL,NULL,'2026-04-15 21:49:10'),
(182,'extra_photo_31.png','2025-07-19 21:49:10','2025-09-19 21:49:10','Extra Photo 31 — Reflections on still water','This is photo number 31 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',91,8360,2461,1734,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_31.png',NULL,0,'606021117650d04c26728957c71c4da8',1,NULL,NULL,NULL,'2026-04-06 21:49:10'),
(183,'extra_photo_131.png','2025-05-12 21:49:10','2024-10-21 21:49:10','Extra Photo 131 — Reflections on still water','This is photo number 131 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',457,15270,3050,2313,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_131.png',NULL,0,'3788a75ff740bb60c62ad20267a35976',1,NULL,NULL,NULL,'2026-04-01 21:49:10'),
(184,'extra_photo_231.png','2026-01-26 21:49:10','2024-05-09 21:49:10','Extra Photo 231 — Reflections on still water','This is photo number 231 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',86,30093,1395,1833,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_231.png',NULL,0,'0e074a9e562ccee79e4326cd41bdede9',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(185,'extra_photo_32.png','2025-05-02 21:49:10','2024-05-13 21:49:10','Extra Photo 32 — Urban architecture detail','This is photo number 32 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',463,24808,2935,1621,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_32.png',NULL,0,'0ddaf1ad0109ec406ef4f278ae69a8fa',1,NULL,NULL,NULL,'2026-04-03 21:49:10'),
(186,'extra_photo_132.png','2025-11-11 21:49:10','2026-02-27 21:49:10','Extra Photo 132 — Urban architecture detail','This is photo number 132 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',17,30409,2552,827,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_132.png',NULL,0,'6e7d0565cdaed6dd41e688c6b9684529',1,NULL,NULL,NULL,'2026-04-03 21:49:10'),
(187,'extra_photo_232.png','2025-05-24 21:49:10','2025-06-12 21:49:10','Extra Photo 232 — Urban architecture detail','This is photo number 232 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',204,25602,2260,813,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_232.png',NULL,0,'030fc6bc2ef739a50987b27277b90175',1,NULL,NULL,NULL,'2026-03-15 21:49:10'),
(188,'extra_photo_33.png','2025-10-06 21:49:10','2025-01-21 21:49:10','Extra Photo 33 — Coastal cliffs at dusk','This is photo number 33 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',243,21043,1813,1836,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_33.png',NULL,0,'6a2804bd428469937e3c199afb31be57',1,NULL,NULL,NULL,'2026-03-29 21:49:10'),
(189,'extra_photo_133.png','2025-11-26 21:49:10','2025-01-03 21:49:10','Extra Photo 133 — Coastal cliffs at dusk','This is photo number 133 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',16,12787,3357,2234,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_133.png',NULL,0,'10d374231fb4e06a51cb1d829aaf56d0',1,NULL,NULL,NULL,'2026-03-10 21:49:10'),
(190,'extra_photo_233.png','2026-01-29 21:49:10','2024-08-20 21:49:10','Extra Photo 233 — Coastal cliffs at dusk','This is photo number 233 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',244,29979,1475,2122,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_233.png',NULL,0,'18a1884c2ae7eac2243abc3531e22ba3',1,NULL,NULL,NULL,'2026-03-10 21:49:10'),
(191,'extra_photo_34.png','2025-11-08 21:49:10','2024-05-29 21:49:10','Extra Photo 34 — Wildflower meadow at dawn','This is photo number 34 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',192,9420,1536,1636,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_34.png',NULL,0,'3734d05ec2aeadf3cd45dc7671ab5130',1,NULL,NULL,NULL,'2026-04-15 21:49:10'),
(192,'extra_photo_134.png','2025-12-01 21:49:10','2025-06-22 21:49:10','Extra Photo 134 — Wildflower meadow at dawn','This is photo number 134 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',455,14960,2915,1926,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_134.png',NULL,0,'d899176f2123bac67d2dc1a5cbb1119f',1,NULL,NULL,NULL,'2026-04-04 21:49:10'),
(193,'extra_photo_234.png','2025-07-19 21:49:10','2024-11-17 21:49:10','Extra Photo 234 — Wildflower meadow at dawn','This is photo number 234 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',145,15042,2627,950,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_234.png',NULL,0,'bb6776170812bdd1917391fac8379e4d',1,NULL,NULL,NULL,'2026-03-16 21:49:10'),
(194,'extra_photo_35.png','2026-03-05 21:49:10','2024-12-25 21:49:10','Extra Photo 35 — Autumn colours in the forest','This is photo number 35 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',452,20535,3350,2259,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_35.png',NULL,0,'175ac8d02d1f80dce9beadbdd2e237cb',1,NULL,NULL,NULL,'2026-03-05 21:49:10'),
(195,'extra_photo_135.png','2025-09-06 21:49:10','2025-03-14 21:49:10','Extra Photo 135 — Autumn colours in the forest','This is photo number 135 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',442,26005,1426,1154,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_135.png',NULL,0,'d83d232625f2a0062eb980c69b61af65',1,NULL,NULL,NULL,'2026-03-08 21:49:10'),
(196,'extra_photo_235.png','2025-11-09 21:49:10','2024-08-28 21:49:10','Extra Photo 235 — Autumn colours in the forest','This is photo number 235 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',385,16625,2365,1360,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_235.png',NULL,0,'502a301ffa8a73e7fa8353ce7bed28fb',1,NULL,NULL,NULL,'2026-04-09 21:49:10'),
(197,'extra_photo_36.png','2025-11-25 21:49:10','2025-11-30 21:49:10','Extra Photo 36 — Reflections on still water','This is photo number 36 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',381,13077,3038,1111,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_36.png',NULL,0,'6090d7e2e7a8712f41d1644acc62c3cb',1,NULL,NULL,NULL,'2026-03-17 21:49:10'),
(198,'extra_photo_136.png','2025-07-08 21:49:10','2024-05-16 21:49:10','Extra Photo 136 — Reflections on still water','This is photo number 136 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',232,17658,2684,2215,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_136.png',NULL,0,'82727c90cff91ea659707d446d5fb8bb',1,NULL,NULL,NULL,'2026-03-23 21:49:10'),
(199,'extra_photo_236.png','2026-02-18 21:49:10','2025-11-18 21:49:10','Extra Photo 236 — Reflections on still water','This is photo number 236 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',270,9071,2647,2210,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_236.png',NULL,0,'4996283b09fe01f2bade8edfa7d31e15',1,NULL,NULL,NULL,'2026-03-22 21:49:10'),
(200,'extra_photo_37.png','2025-12-23 21:49:10','2024-06-25 21:49:10','Extra Photo 37 — Urban architecture detail','This is photo number 37 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',284,10145,2973,1481,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_37.png',NULL,0,'47f999e09621c10c435f0ae6361285a9',1,NULL,NULL,NULL,'2026-03-03 21:49:10'),
(201,'extra_photo_137.png','2026-01-11 21:49:10','2024-11-29 21:49:10','Extra Photo 137 — Urban architecture detail','This is photo number 137 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',326,11651,3126,1690,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_137.png',NULL,0,'e289e214dadacc413f396fa37d0123c4',1,NULL,NULL,NULL,'2026-04-04 21:49:10'),
(202,'extra_photo_237.png','2026-02-11 21:49:10','2024-07-10 21:49:10','Extra Photo 237 — Urban architecture detail','This is photo number 237 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',436,24240,3029,2049,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_237.png',NULL,0,'ab9b2e03b1cfa7404f61940739d45750',1,NULL,NULL,NULL,'2026-03-20 21:49:10'),
(203,'extra_photo_38.png','2025-07-27 21:49:10','2024-07-19 21:49:10','Extra Photo 38 — Coastal cliffs at dusk','This is photo number 38 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',91,14170,2970,2268,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_38.png',NULL,0,'db96d4344e5845b5f19811513ee17f55',1,NULL,NULL,NULL,'2026-04-04 21:49:10'),
(204,'extra_photo_138.png','2026-03-14 21:49:10','2025-05-14 21:49:10','Extra Photo 138 — Coastal cliffs at dusk','This is photo number 138 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',9,24084,1901,1521,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_138.png',NULL,0,'5b629a70b810d1bf3c6e27b9d7b0cba0',1,NULL,NULL,NULL,'2026-04-04 21:49:10'),
(205,'extra_photo_238.png','2025-10-12 21:49:10','2025-03-20 21:49:10','Extra Photo 238 — Coastal cliffs at dusk','This is photo number 238 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',73,9948,3513,1722,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_238.png',NULL,0,'022a30deac70fbda0ecac1f50583c717',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(206,'extra_photo_39.png','2026-02-02 21:49:10','2025-12-22 21:49:10','Extra Photo 39 — Wildflower meadow at dawn','This is photo number 39 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',85,16159,1647,2258,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_39.png',NULL,0,'9ac5c3f50512ffda7414282204ae3fab',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(207,'extra_photo_139.png','2026-01-23 21:49:10','2025-10-01 21:49:10','Extra Photo 139 — Wildflower meadow at dawn','This is photo number 139 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',326,17893,1446,1243,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_139.png',NULL,0,'b3f11cf70c0d1cf55f69728615e65b21',1,NULL,NULL,NULL,'2026-04-22 21:49:10'),
(208,'extra_photo_239.png','2025-10-03 21:49:10','2025-03-05 21:49:10','Extra Photo 239 — Wildflower meadow at dawn','This is photo number 239 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',88,11978,1917,2396,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_239.png',NULL,0,'8a324ec1fc69e20c1582d8f92b751961',1,NULL,NULL,NULL,'2026-04-21 21:49:10'),
(209,'extra_photo_30.png','2025-11-05 21:49:10','2026-02-28 21:49:10','Extra Photo 30 — Autumn colours in the forest','This is photo number 30 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',489,23798,2050,2076,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_30.png',NULL,0,'d059dcbf032f2e4d0b72514d323780d1',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(210,'extra_photo_130.png','2026-01-31 21:49:10','2025-07-14 21:49:10','Extra Photo 130 — Autumn colours in the forest','This is photo number 130 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',131,11371,3385,1009,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_130.png',NULL,0,'b4e647d86126857ea02722569d868a3a',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(211,'extra_photo_230.png','2026-02-03 21:49:10','2025-08-08 21:49:10','Extra Photo 230 — Autumn colours in the forest','This is photo number 230 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',55,19566,1384,2300,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_230.png',NULL,0,'04fc2a4dcbc00ce0aab51fe155f1efbd',1,NULL,NULL,NULL,'2026-03-30 21:49:10'),
(212,'extra_photo_41.png','2025-11-04 21:49:10','2026-04-20 21:49:10','Extra Photo 41 — Reflections on still water','This is photo number 41 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',308,9307,2215,2322,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_41.png',NULL,0,'cb518f28bcc575fa6c01693f4fd97e98',1,NULL,NULL,NULL,'2026-03-28 21:49:10'),
(213,'extra_photo_141.png','2025-09-22 21:49:10','2025-04-28 21:49:10','Extra Photo 141 — Reflections on still water','This is photo number 141 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',354,9134,1469,1470,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_141.png',NULL,0,'bda45246b71fd83f0145e303ea3554cb',1,NULL,NULL,NULL,'2026-03-12 21:49:10'),
(214,'extra_photo_241.png','2025-10-11 21:49:10','2025-06-20 21:49:10','Extra Photo 241 — Reflections on still water','This is photo number 241 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',252,13920,2926,2172,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_241.png',NULL,0,'ad6b7c6767eb36dd1fd1a36f8625c4db',1,NULL,NULL,NULL,'2026-04-19 21:49:10'),
(215,'extra_photo_42.png','2026-03-31 21:49:10','2024-05-15 21:49:10','Extra Photo 42 — Urban architecture detail','This is photo number 42 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',329,16770,3246,1068,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_42.png',NULL,0,'4aeed1882ca76ea107024c5bdd4421c1',1,NULL,NULL,NULL,'2026-04-10 21:49:10'),
(216,'extra_photo_142.png','2025-06-02 21:49:10','2024-12-30 21:49:10','Extra Photo 142 — Urban architecture detail','This is photo number 142 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',301,8864,2083,1972,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_142.png',NULL,0,'2d55be5b660a801905a009918e987788',1,NULL,NULL,NULL,'2026-03-24 21:49:10'),
(217,'extra_photo_242.png','2025-09-19 21:49:10','2025-09-03 21:49:10','Extra Photo 242 — Urban architecture detail','This is photo number 242 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',406,10225,1265,2171,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_242.png',NULL,0,'6dd3c6c856cd7a3d8249879463ad35cd',1,NULL,NULL,NULL,'2026-04-14 21:49:10'),
(218,'extra_photo_43.png','2025-11-11 21:49:10','2024-12-29 21:49:10','Extra Photo 43 — Coastal cliffs at dusk','This is photo number 43 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',471,25432,3130,2150,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_43.png',NULL,0,'4515ef3f662edf6bb825067a7b4f377c',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(219,'extra_photo_143.png','2025-10-28 21:49:10','2026-03-16 21:49:10','Extra Photo 143 — Coastal cliffs at dusk','This is photo number 143 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',401,28149,3098,1498,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_143.png',NULL,0,'64cc0297bffdc6705492de9fd8755bea',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(220,'extra_photo_243.png','2025-07-29 21:49:10','2025-09-30 21:49:10','Extra Photo 243 — Coastal cliffs at dusk','This is photo number 243 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',98,11038,1303,2137,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_243.png',NULL,0,'dc422ca2117891e3f550b2462b3be943',1,NULL,NULL,NULL,'2026-04-23 21:49:10'),
(221,'extra_photo_44.png','2025-07-28 21:49:10','2025-02-28 21:49:10','Extra Photo 44 — Wildflower meadow at dawn','This is photo number 44 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',328,21210,3069,1189,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_44.png',NULL,0,'222519a516d5069d05d4ad64f8e4db61',1,NULL,NULL,NULL,'2026-03-05 21:49:10'),
(222,'extra_photo_144.png','2025-08-23 21:49:10','2024-11-06 21:49:10','Extra Photo 144 — Wildflower meadow at dawn','This is photo number 144 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',323,8665,1678,2262,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_144.png',NULL,0,'6dc1980bacfbe73a484867aaa9493e4b',1,NULL,NULL,NULL,'2026-02-27 21:49:10'),
(223,'extra_photo_244.png','2026-03-14 21:49:10','2024-12-14 21:49:10','Extra Photo 244 — Wildflower meadow at dawn','This is photo number 244 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',25,13022,3342,2138,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_244.png',NULL,0,'24e9b593a6d07d993544f2342b17299e',1,NULL,NULL,NULL,'2026-03-27 21:49:10'),
(224,'extra_photo_45.png','2026-04-24 21:49:10','2025-04-07 21:49:10','Extra Photo 45 — Autumn colours in the forest','This is photo number 45 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',305,19388,2496,1243,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_45.png',NULL,0,'3bd37191ff6bffefa8b124ede7703494',1,NULL,NULL,NULL,'2026-03-12 21:49:10'),
(225,'extra_photo_145.png','2025-04-30 21:49:10','2024-12-29 21:49:10','Extra Photo 145 — Autumn colours in the forest','This is photo number 145 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',169,25012,2463,1608,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_145.png',NULL,0,'9f2b3082acffb233fc42a13215e0b4dc',1,NULL,NULL,NULL,'2026-03-01 21:49:10'),
(226,'extra_photo_245.png','2026-02-04 21:49:10','2025-10-08 21:49:10','Extra Photo 245 — Autumn colours in the forest','This is photo number 245 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',351,24604,2038,1873,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_245.png',NULL,0,'24a58b772a44fff051d2f9c1d1d85982',1,NULL,NULL,NULL,'2026-04-08 21:49:10'),
(227,'extra_photo_46.png','2025-10-19 21:49:10','2024-12-17 21:49:10','Extra Photo 46 — Reflections on still water','This is photo number 46 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',418,11520,1734,1879,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_46.png',NULL,0,'5649f45286d7b9698911bc4700cff03a',1,NULL,NULL,NULL,'2026-03-15 21:49:10'),
(228,'extra_photo_146.png','2025-10-28 21:49:10','2025-08-08 21:49:10','Extra Photo 146 — Reflections on still water','This is photo number 146 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',153,19141,2157,1763,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_146.png',NULL,0,'594e8002e707e718df9cfe7f79c3e260',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(229,'extra_photo_246.png','2026-01-20 21:49:10','2024-07-26 21:49:10','Extra Photo 246 — Reflections on still water','This is photo number 246 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',294,15626,3171,1045,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_246.png',NULL,0,'a84b4cb5d438b24747b97f1cf5b96203',1,NULL,NULL,NULL,'2026-04-08 21:49:10'),
(230,'extra_photo_47.png','2026-04-06 21:49:10','2025-07-29 21:49:10','Extra Photo 47 — Urban architecture detail','This is photo number 47 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',346,16263,2747,1104,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_47.png',NULL,0,'e51fc62dae04e8c481457d45680cee04',1,NULL,NULL,NULL,'2026-04-25 21:49:10'),
(231,'extra_photo_147.png','2025-10-18 21:49:10','2025-03-17 21:49:10','Extra Photo 147 — Urban architecture detail','This is photo number 147 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',105,17408,1978,1512,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_147.png',NULL,0,'95fcd8817d2a183dca261bc4ebdda191',1,NULL,NULL,NULL,'2026-04-11 21:49:10'),
(232,'extra_photo_247.png','2025-05-19 21:49:10','2024-06-21 21:49:10','Extra Photo 247 — Urban architecture detail','This is photo number 247 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',402,14000,3211,1504,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_247.png',NULL,0,'a9da222156b685273d2ac572461d09a2',1,NULL,NULL,NULL,'2026-03-16 21:49:10'),
(233,'extra_photo_48.png','2026-03-16 21:49:10','2025-04-17 21:49:10','Extra Photo 48 — Coastal cliffs at dusk','This is photo number 48 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',109,21310,1482,2279,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_48.png',NULL,0,'29066cd729a72bbe8ea90b73ac4f7dea',1,NULL,NULL,NULL,'2026-04-10 21:49:10'),
(234,'extra_photo_148.png','2025-09-29 21:49:10','2026-03-12 21:49:10','Extra Photo 148 — Coastal cliffs at dusk','This is photo number 148 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',295,26480,1379,902,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_148.png',NULL,0,'a9a2f9f6beae95e1ea668c200ff77808',1,NULL,NULL,NULL,'2026-04-21 21:49:10'),
(235,'extra_photo_248.png','2026-01-13 21:49:10','2026-01-20 21:49:10','Extra Photo 248 — Coastal cliffs at dusk','This is photo number 248 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',406,23952,3326,1496,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_248.png',NULL,0,'422a14438ba4e849b63c2a73ea8bf872',1,NULL,NULL,NULL,'2026-03-26 21:49:10'),
(236,'extra_photo_49.png','2026-01-10 21:49:10','2024-07-10 21:49:10','Extra Photo 49 — Wildflower meadow at dawn','This is photo number 49 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',306,17026,1294,910,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_49.png',NULL,0,'438203de3335c58d90f9fff7afe6d6fd',1,NULL,NULL,NULL,'2026-04-13 21:49:10'),
(237,'extra_photo_149.png','2025-05-22 21:49:10','2024-05-23 21:49:10','Extra Photo 149 — Wildflower meadow at dawn','This is photo number 149 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',15,14242,1704,1233,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_149.png',NULL,0,'48eade510f3c738018018a727df73f82',1,NULL,NULL,NULL,'2026-03-14 21:49:10'),
(238,'extra_photo_249.png','2025-07-08 21:49:10','2024-08-21 21:49:10','Extra Photo 249 — Wildflower meadow at dawn','This is photo number 249 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',397,18872,3315,877,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_249.png',NULL,0,'5e36243ba0cbc7b9ed96b6052f154258',1,NULL,NULL,NULL,'2026-03-22 21:49:10'),
(239,'extra_photo_40.png','2025-06-21 21:49:10','2025-06-06 21:49:10','Extra Photo 40 — Autumn colours in the forest','This is photo number 40 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',339,9501,1858,1095,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_40.png',NULL,0,'a04b20f239751845bb96dfe68f834480',1,NULL,NULL,NULL,'2026-04-21 21:49:10'),
(240,'extra_photo_140.png','2025-05-17 21:49:10','2025-06-20 21:49:10','Extra Photo 140 — Autumn colours in the forest','This is photo number 140 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',145,12362,1282,1802,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_140.png',NULL,0,'001dce6578e28d80bfa2ccd98dd9475f',1,NULL,NULL,NULL,'2026-04-25 21:49:10'),
(241,'extra_photo_240.png','2026-01-17 21:49:10','2025-10-11 21:49:10','Extra Photo 240 — Autumn colours in the forest','This is photo number 240 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',268,28924,2996,1003,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_240.png',NULL,0,'4b70c63fb0c5c19f662ffe819c7bbca1',1,NULL,NULL,NULL,'2026-04-03 21:49:10'),
(242,'extra_photo_51.png','2025-10-01 21:49:10','2024-12-21 21:49:10','Extra Photo 51 — Reflections on still water','This is photo number 51 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',329,14623,2167,1099,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_51.png',NULL,0,'53ab435c9ad648cbaf45163cdc9dea93',1,NULL,NULL,NULL,'2026-03-14 21:49:10'),
(243,'extra_photo_151.png','2026-04-01 21:49:10','2025-12-20 21:49:10','Extra Photo 151 — Reflections on still water','This is photo number 151 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',329,26577,3341,1022,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_151.png',NULL,0,'c1561c2da64526ac44dea1b7d67ccda3',1,NULL,NULL,NULL,'2026-04-25 21:49:10'),
(244,'extra_photo_251.png','2025-08-24 21:49:10','2025-09-14 21:49:10','Extra Photo 251 — Reflections on still water','This is photo number 251 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',260,24463,3274,1222,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_251.png',NULL,0,'0e38a50fe5a70eeb1ad70f65856994ee',1,NULL,NULL,NULL,'2026-03-14 21:49:10'),
(245,'extra_photo_52.png','2025-06-21 21:49:10','2026-03-16 21:49:10','Extra Photo 52 — Urban architecture detail','This is photo number 52 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',369,20512,2140,1433,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_52.png',NULL,0,'0a3e41bc2a230b0b2c713365b9930201',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(246,'extra_photo_152.png','2025-06-28 21:49:10','2024-11-03 21:49:10','Extra Photo 152 — Urban architecture detail','This is photo number 152 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',101,27209,2141,1697,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_152.png',NULL,0,'915f48b3182be80cb01a4cd3205df752',1,NULL,NULL,NULL,'2026-03-20 21:49:10'),
(247,'extra_photo_252.png','2025-11-12 21:49:10','2025-07-18 21:49:10','Extra Photo 252 — Urban architecture detail','This is photo number 252 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',287,25147,3231,941,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_252.png',NULL,0,'96ab6574a2241deb63a26ec5e7bb22d7',1,NULL,NULL,NULL,'2026-03-03 21:49:10'),
(248,'extra_photo_53.png','2026-01-23 21:49:10','2025-03-11 21:49:10','Extra Photo 53 — Coastal cliffs at dusk','This is photo number 53 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',27,22010,3006,819,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_53.png',NULL,0,'f625df4b01db46069cdbcab86c5253ad',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(249,'extra_photo_153.png','2025-05-03 21:49:10','2025-04-30 21:49:10','Extra Photo 153 — Coastal cliffs at dusk','This is photo number 153 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',266,12346,1931,2370,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_153.png',NULL,0,'eff866abaf5c9adad09fcd2750068f19',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(250,'extra_photo_253.png','2026-04-20 21:49:10','2026-01-31 21:49:10','Extra Photo 253 — Coastal cliffs at dusk','This is photo number 253 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',263,14794,3205,1325,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_253.png',NULL,0,'9bd6ce2bb5be0f44dd18335779672baa',1,NULL,NULL,NULL,'2026-04-18 21:49:10'),
(251,'extra_photo_54.png','2025-08-18 21:49:10','2026-03-26 21:49:10','Extra Photo 54 — Wildflower meadow at dawn','This is photo number 54 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',72,22324,2518,2329,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_54.png',NULL,0,'8b26db33a613876cc74d5f77cc12309e',1,NULL,NULL,NULL,'2026-04-19 21:49:10'),
(252,'extra_photo_154.png','2025-07-10 21:49:10','2025-03-03 21:49:10','Extra Photo 154 — Wildflower meadow at dawn','This is photo number 154 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',246,25657,1684,2082,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_154.png',NULL,0,'0bd61c658d6d67867d616f57e5c59538',1,NULL,NULL,NULL,'2026-04-02 21:49:10'),
(253,'extra_photo_254.png','2025-09-16 21:49:10','2024-08-23 21:49:10','Extra Photo 254 — Wildflower meadow at dawn','This is photo number 254 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',181,15190,2183,1038,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_254.png',NULL,0,'43b121a8deb7c032b9d1903e84e295f3',1,NULL,NULL,NULL,'2026-03-26 21:49:10'),
(254,'extra_photo_55.png','2026-03-07 21:49:10','2026-01-11 21:49:10','Extra Photo 55 — Autumn colours in the forest','This is photo number 55 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',153,10339,2563,1676,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_55.png',NULL,0,'c88d669fe16048d890e935867dece62b',1,NULL,NULL,NULL,'2026-04-24 21:49:10'),
(255,'extra_photo_155.png','2025-10-16 21:49:10','2025-04-05 21:49:10','Extra Photo 155 — Autumn colours in the forest','This is photo number 155 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',34,26150,2579,1766,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_155.png',NULL,0,'7f6682a06529bca11aaab9592dff56dc',1,NULL,NULL,NULL,'2026-04-09 21:49:10'),
(256,'extra_photo_255.png','2025-08-24 21:49:10','2025-05-19 21:49:10','Extra Photo 255 — Autumn colours in the forest','This is photo number 255 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',164,13800,1725,1391,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_255.png',NULL,0,'444558baae2e30e6832811c937447037',1,NULL,NULL,NULL,'2026-04-15 21:49:10'),
(257,'extra_photo_56.png','2025-06-20 21:49:10','2024-12-16 21:49:10','Extra Photo 56 — Reflections on still water','This is photo number 56 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',423,12647,2226,1692,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_56.png',NULL,0,'f5171add7eb6a6e9755f77c1319c2637',1,NULL,NULL,NULL,'2026-03-27 21:49:10'),
(258,'extra_photo_156.png','2025-06-18 21:49:10','2024-10-21 21:49:10','Extra Photo 156 — Reflections on still water','This is photo number 156 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',110,28085,2446,937,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_156.png',NULL,0,'3624fb0e4ab951a1330b30002138c8d5',1,NULL,NULL,NULL,'2026-03-05 21:49:10'),
(259,'extra_photo_256.png','2026-03-23 21:49:10','2024-08-03 21:49:10','Extra Photo 256 — Reflections on still water','This is photo number 256 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',19,22305,3278,1668,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_256.png',NULL,0,'5c0914b73aa31a33095b60d380542701',1,NULL,NULL,NULL,'2026-04-20 21:49:10'),
(260,'extra_photo_57.png','2025-05-17 21:49:10','2025-07-23 21:49:10','Extra Photo 57 — Urban architecture detail','This is photo number 57 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',34,12828,3120,1435,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_57.png',NULL,0,'9bd153d5dba7e77ca2fa6104aa92748b',1,NULL,NULL,NULL,'2026-03-22 21:49:10'),
(261,'extra_photo_157.png','2025-08-01 21:49:10','2024-06-20 21:49:10','Extra Photo 157 — Urban architecture detail','This is photo number 157 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',207,15173,1801,1371,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_157.png',NULL,0,'9f44bdc83f97dc24979c4b71cbe5cf3f',1,NULL,NULL,NULL,'2026-04-24 21:49:10'),
(262,'extra_photo_257.png','2026-03-23 21:49:10','2025-07-25 21:49:10','Extra Photo 257 — Urban architecture detail','This is photo number 257 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',299,28633,2410,2309,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_257.png',NULL,0,'adc19bcbd65d4135889dc41132862367',1,NULL,NULL,NULL,'2026-04-14 21:49:10'),
(263,'extra_photo_58.png','2026-02-17 21:49:10','2025-09-03 21:49:10','Extra Photo 58 — Coastal cliffs at dusk','This is photo number 58 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',27,15265,2042,2155,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_58.png',NULL,0,'395a7f1e58b65ea83233e149006d539b',1,NULL,NULL,NULL,'2026-04-16 21:49:10'),
(264,'extra_photo_158.png','2025-12-13 21:49:10','2025-09-22 21:49:10','Extra Photo 158 — Coastal cliffs at dusk','This is photo number 158 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',188,31938,3252,1252,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_158.png',NULL,0,'501a86ade25eb309ed9f4f1fd3910e40',1,NULL,NULL,NULL,'2026-03-07 21:49:10'),
(265,'extra_photo_258.png','2025-12-03 21:49:10','2025-06-18 21:49:10','Extra Photo 258 — Coastal cliffs at dusk','This is photo number 258 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',476,19657,2562,1410,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_258.png',NULL,0,'676d96783876c6cc9ea713940e51f6c6',1,NULL,NULL,NULL,'2026-04-14 21:49:10'),
(266,'extra_photo_59.png','2025-06-10 21:49:10','2024-10-07 21:49:10','Extra Photo 59 — Wildflower meadow at dawn','This is photo number 59 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',124,29888,3156,1344,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_59.png',NULL,0,'c7203975156466040817446a6309bd7c',1,NULL,NULL,NULL,'2026-04-11 21:49:10'),
(267,'extra_photo_159.png','2026-01-23 21:49:10','2025-04-16 21:49:10','Extra Photo 159 — Wildflower meadow at dawn','This is photo number 159 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',403,19651,1221,1743,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_159.png',NULL,0,'8d670a8385fef53e345e422d88941213',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(268,'extra_photo_259.png','2025-06-28 21:49:10','2025-07-16 21:49:10','Extra Photo 259 — Wildflower meadow at dawn','This is photo number 259 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',229,11039,1814,2238,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_259.png',NULL,0,'00398f5696945307c812f7a3041b18d6',1,NULL,NULL,NULL,'2026-03-14 21:49:10'),
(269,'extra_photo_50.png','2025-05-16 21:49:10','2025-03-22 21:49:10','Extra Photo 50 — Autumn colours in the forest','This is photo number 50 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',449,28511,2576,1287,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_50.png',NULL,0,'ac108e70259549e2bae9bd0f330e6f3c',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(270,'extra_photo_150.png','2026-03-22 21:49:10','2026-02-25 21:49:10','Extra Photo 150 — Autumn colours in the forest','This is photo number 150 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',61,16687,2262,1003,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_150.png',NULL,0,'03cc0e4b62862fb9a3e2df5d115f1279',1,NULL,NULL,NULL,'2026-04-08 21:49:10'),
(271,'extra_photo_250.png','2026-03-01 21:49:10','2024-08-08 21:49:10','Extra Photo 250 — Autumn colours in the forest','This is photo number 250 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',410,20793,1683,1451,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_250.png',NULL,0,'01e2cf41b29a5a20268070d4d1c9b25b',1,NULL,NULL,NULL,'2026-04-01 21:49:10'),
(272,'extra_photo_61.png','2025-05-19 21:49:10','2025-07-12 21:49:10','Extra Photo 61 — Reflections on still water','This is photo number 61 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',79,22750,2624,998,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_61.png',NULL,0,'e38b8126cf60c9060aefa163639d3661',1,NULL,NULL,NULL,'2026-03-07 21:49:10'),
(273,'extra_photo_161.png','2025-06-28 21:49:10','2025-01-27 21:49:10','Extra Photo 161 — Reflections on still water','This is photo number 161 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',314,14677,2405,1884,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_161.png',NULL,0,'ac40e3f036e545f79589e64305267b43',1,NULL,NULL,NULL,'2026-03-05 21:49:10'),
(274,'extra_photo_261.png','2025-12-12 21:49:10','2025-11-22 21:49:10','Extra Photo 261 — Reflections on still water','This is photo number 261 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',475,10808,2956,1292,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_261.png',NULL,0,'5418b44ab67affef255e65b8bc93ca61',1,NULL,NULL,NULL,'2026-04-06 21:49:10'),
(275,'extra_photo_62.png','2025-07-12 21:49:10','2024-06-18 21:49:10','Extra Photo 62 — Urban architecture detail','This is photo number 62 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',133,21156,3459,900,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_62.png',NULL,0,'786d54d34cb768fe4fcc4389ab295770',1,NULL,NULL,NULL,'2026-03-28 21:49:10'),
(276,'extra_photo_162.png','2026-01-23 21:49:10','2024-09-13 21:49:10','Extra Photo 162 — Urban architecture detail','This is photo number 162 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',139,31348,1258,1126,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_162.png',NULL,0,'ce772873d5b6a5715e00566d56c6175c',1,NULL,NULL,NULL,'2026-03-01 21:49:10'),
(277,'extra_photo_262.png','2026-03-12 21:49:10','2024-10-07 21:49:10','Extra Photo 262 — Urban architecture detail','This is photo number 262 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',253,12975,2439,2336,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_262.png',NULL,0,'6e5d34cde77ba25329e85a64906e07d9',1,NULL,NULL,NULL,'2026-04-11 21:49:10'),
(278,'extra_photo_63.png','2025-12-08 21:49:10','2026-01-01 21:49:10','Extra Photo 63 — Coastal cliffs at dusk','This is photo number 63 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',320,25436,2909,1409,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_63.png',NULL,0,'582c356c86c1528d09245748861c166c',1,NULL,NULL,NULL,'2026-03-11 21:49:10'),
(279,'extra_photo_163.png','2025-08-14 21:49:10','2025-12-02 21:49:10','Extra Photo 163 — Coastal cliffs at dusk','This is photo number 163 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',445,28647,2705,1688,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_163.png',NULL,0,'aa60d997aaffe5a76a3f78a4e5e97d53',1,NULL,NULL,NULL,'2026-03-04 21:49:10'),
(280,'extra_photo_263.png','2025-07-04 21:49:10','2025-07-29 21:49:10','Extra Photo 263 — Coastal cliffs at dusk','This is photo number 263 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',211,31970,2941,1812,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_263.png',NULL,0,'24f065453b6418cd8d56b0dcffa0a120',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(281,'extra_photo_64.png','2026-04-14 21:49:10','2025-11-26 21:49:10','Extra Photo 64 — Wildflower meadow at dawn','This is photo number 64 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',468,9590,2443,1426,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_64.png',NULL,0,'05978b86e627679633e7d6a78acb81cd',1,NULL,NULL,NULL,'2026-04-02 21:49:10'),
(282,'extra_photo_164.png','2025-06-24 21:49:10','2024-05-02 21:49:10','Extra Photo 164 — Wildflower meadow at dawn','This is photo number 164 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',220,13470,3160,1439,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_164.png',NULL,0,'65e637c497dcd758e4b3364e43789644',1,NULL,NULL,NULL,'2026-03-25 21:49:10'),
(283,'extra_photo_264.png','2025-10-11 21:49:10','2026-03-09 21:49:10','Extra Photo 264 — Wildflower meadow at dawn','This is photo number 264 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',353,16114,2560,2113,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_264.png',NULL,0,'13cddc17111d4a8f267e0d809299cc9d',1,NULL,NULL,NULL,'2026-04-02 21:49:10'),
(284,'extra_photo_65.png','2025-10-05 21:49:10','2025-03-02 21:49:10','Extra Photo 65 — Autumn colours in the forest','This is photo number 65 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',102,15175,3310,1599,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_65.png',NULL,0,'ce31d0f21b973ad3e246b48827866ab0',1,NULL,NULL,NULL,'2026-03-06 21:49:10'),
(285,'extra_photo_165.png','2025-07-05 21:49:10','2025-05-28 21:49:10','Extra Photo 165 — Autumn colours in the forest','This is photo number 165 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',429,30293,1354,1656,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_165.png',NULL,0,'9f511ebfa45192072ca7ab2e7eb9264c',1,NULL,NULL,NULL,'2026-03-28 21:49:10'),
(286,'extra_photo_265.png','2025-07-04 21:49:10','2025-02-08 21:49:10','Extra Photo 265 — Autumn colours in the forest','This is photo number 265 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',298,12066,1329,2019,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_265.png',NULL,0,'1766676f1ea7eba44dd6284149ed7e07',1,NULL,NULL,NULL,'2026-03-19 21:49:10'),
(287,'extra_photo_66.png','2025-05-14 21:49:10','2024-09-06 21:49:10','Extra Photo 66 — Reflections on still water','This is photo number 66 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',116,25133,3285,1123,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_66.png',NULL,0,'44759ff6c7c15274b63089c8dbd2adf6',1,NULL,NULL,NULL,'2026-04-02 21:49:10'),
(288,'extra_photo_166.png','2025-11-26 21:49:10','2024-08-02 21:49:10','Extra Photo 166 — Reflections on still water','This is photo number 166 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',43,27994,3371,840,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_166.png',NULL,0,'31f91eb7d8eaa39634eda70afc91eaca',1,NULL,NULL,NULL,'2026-04-02 21:49:10'),
(289,'extra_photo_266.png','2025-05-01 21:49:10','2024-11-28 21:49:10','Extra Photo 266 — Reflections on still water','This is photo number 266 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',279,24261,2909,1645,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_266.png',NULL,0,'fabe0d1ff5332409ed2912a1bb0f65f7',1,NULL,NULL,NULL,'2026-03-27 21:49:10'),
(290,'extra_photo_67.png','2025-05-19 21:49:10','2025-12-14 21:49:10','Extra Photo 67 — Urban architecture detail','This is photo number 67 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',47,30234,2034,2333,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_67.png',NULL,0,'ec3e946bb20f0474a455b146d4a07825',1,NULL,NULL,NULL,'2026-03-13 21:49:10'),
(291,'extra_photo_167.png','2025-06-12 21:49:10','2026-02-01 21:49:10','Extra Photo 167 — Urban architecture detail','This is photo number 167 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',478,18587,2001,1352,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_167.png',NULL,0,'66576a77498142e1875f032a9351fc3a',1,NULL,NULL,NULL,'2026-03-14 21:49:10'),
(292,'extra_photo_267.png','2025-09-21 21:49:10','2024-09-24 21:49:10','Extra Photo 267 — Urban architecture detail','This is photo number 267 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',93,21296,1699,1405,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_267.png',NULL,0,'8f20845ec654236d3ae9d726a7beba9c',1,NULL,NULL,NULL,'2026-04-10 21:49:10'),
(293,'extra_photo_68.png','2026-02-13 21:49:10','2025-12-04 21:49:10','Extra Photo 68 — Coastal cliffs at dusk','This is photo number 68 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',190,15712,2307,1347,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_68.png',NULL,0,'3b9c175474870dd4320324719ba99074',1,NULL,NULL,NULL,'2026-04-07 21:49:10'),
(294,'extra_photo_168.png','2025-09-14 21:49:10','2026-02-19 21:49:10','Extra Photo 168 — Coastal cliffs at dusk','This is photo number 168 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',304,26462,1250,2076,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_168.png',NULL,0,'e67f567c370db8feadc0d4a8fe9e7d70',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(295,'extra_photo_268.png','2026-01-29 21:49:10','2025-06-26 21:49:10','Extra Photo 268 — Coastal cliffs at dusk','This is photo number 268 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',184,22131,3214,1489,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_268.png',NULL,0,'7742d6a8a22d2739ea35a10a10c8d9b0',1,NULL,NULL,NULL,'2026-03-19 21:49:10'),
(296,'extra_photo_69.png','2025-06-05 21:49:10','2025-03-21 21:49:10','Extra Photo 69 — Wildflower meadow at dawn','This is photo number 69 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',36,25204,2073,1871,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_69.png',NULL,0,'dc6460690045b0525336fd667517ad7b',1,NULL,NULL,NULL,'2026-04-11 21:49:10'),
(297,'extra_photo_169.png','2026-01-16 21:49:10','2025-02-05 21:49:10','Extra Photo 169 — Wildflower meadow at dawn','This is photo number 169 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',110,14596,2907,1972,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_169.png',NULL,0,'30e26fe1d907a908e816a5d71b9dfb18',1,NULL,NULL,NULL,'2026-03-26 21:49:10'),
(298,'extra_photo_269.png','2025-11-15 21:49:10','2025-01-17 21:49:10','Extra Photo 269 — Wildflower meadow at dawn','This is photo number 269 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',425,16322,1630,2170,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_269.png',NULL,0,'fe88554a6b58a48de059ebb20855c89f',1,NULL,NULL,NULL,'2026-03-13 21:49:10'),
(299,'extra_photo_60.png','2026-03-04 21:49:10','2025-04-20 21:49:10','Extra Photo 60 — Autumn colours in the forest','This is photo number 60 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',50,31480,2613,813,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_60.png',NULL,0,'1e67f3b80c85b5a94ab89dbe53e94805',1,NULL,NULL,NULL,'2026-04-10 21:49:10'),
(300,'extra_photo_160.png','2025-12-16 21:49:10','2024-05-16 21:49:10','Extra Photo 160 — Autumn colours in the forest','This is photo number 160 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',392,31996,2752,1176,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_160.png',NULL,0,'a3b633b10266781c3886eda23c3a6331',1,NULL,NULL,NULL,'2026-04-12 21:49:10'),
(301,'extra_photo_260.png','2025-11-05 21:49:10','2025-01-04 21:49:10','Extra Photo 260 — Autumn colours in the forest','This is photo number 260 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',427,15474,3582,845,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_260.png',NULL,0,'0c371820e7eba9f75c5096602663f525',1,NULL,NULL,NULL,'2026-04-17 21:49:10'),
(302,'extra_photo_71.png','2025-08-01 21:49:10','2025-12-15 21:49:10','Extra Photo 71 — Reflections on still water','This is photo number 71 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',351,31320,2997,2126,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_71.png',NULL,0,'ba95f9e416ff811a797b493157936796',1,NULL,NULL,NULL,'2026-03-04 21:49:10'),
(303,'extra_photo_171.png','2026-04-24 21:49:10','2025-08-26 21:49:10','Extra Photo 171 — Reflections on still water','This is photo number 171 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',326,14292,2045,2358,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_171.png',NULL,0,'cb063c30cf47037571d57d3753fa027b',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(304,'extra_photo_271.png','2026-03-04 21:49:10','2025-09-23 21:49:10','Extra Photo 271 — Reflections on still water','This is photo number 271 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',15,14570,1858,1679,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_271.png',NULL,0,'9eb39116f14a9e1473e742379b87c4c5',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(305,'extra_photo_72.png','2025-05-02 21:49:10','2026-01-15 21:49:10','Extra Photo 72 — Urban architecture detail','This is photo number 72 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',372,15445,1953,1821,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_72.png',NULL,0,'b06e1e65186c47f74b0a7e2621a15643',1,NULL,NULL,NULL,'2026-04-11 21:49:10'),
(306,'extra_photo_172.png','2025-12-22 21:49:10','2024-05-24 21:49:10','Extra Photo 172 — Urban architecture detail','This is photo number 172 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',391,8610,3069,2109,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_172.png',NULL,0,'7abd9cc936284954381679c4fd8d96d0',1,NULL,NULL,NULL,'2026-03-12 21:49:10'),
(307,'extra_photo_272.png','2025-12-31 21:49:10','2025-08-25 21:49:10','Extra Photo 272 — Urban architecture detail','This is photo number 272 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',355,21343,2747,1689,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_272.png',NULL,0,'9562de292ec242979dd47fe9041b37d5',1,NULL,NULL,NULL,'2026-03-07 21:49:10'),
(308,'extra_photo_73.png','2025-10-06 21:49:10','2025-11-05 21:49:10','Extra Photo 73 — Coastal cliffs at dusk','This is photo number 73 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',259,29236,3285,1903,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_73.png',NULL,0,'32afab23008897215aa03fc85db17bde',1,NULL,NULL,NULL,'2026-03-07 21:49:10'),
(309,'extra_photo_173.png','2026-03-08 21:49:10','2025-12-31 21:49:10','Extra Photo 173 — Coastal cliffs at dusk','This is photo number 173 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',195,19300,1641,1610,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_173.png',NULL,0,'46bc500b8d7a25a9ea95a69390eaf6c5',1,NULL,NULL,NULL,'2026-02-27 21:49:10'),
(310,'extra_photo_273.png','2025-12-08 21:49:10','2024-05-22 21:49:10','Extra Photo 273 — Coastal cliffs at dusk','This is photo number 273 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',341,20564,2557,1213,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_273.png',NULL,0,'1d8b122199cb75ff5e8e97ccbc1b1b40',1,NULL,NULL,NULL,'2026-03-22 21:49:10'),
(311,'extra_photo_74.png','2026-02-14 21:49:10','2025-12-03 21:49:10','Extra Photo 74 — Wildflower meadow at dawn','This is photo number 74 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',200,17838,3234,814,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_74.png',NULL,0,'5aafb5cb0f3e01e55422576dcfabc1a7',1,NULL,NULL,NULL,'2026-03-27 21:49:10'),
(312,'extra_photo_174.png','2025-11-01 21:49:10','2024-06-27 21:49:10','Extra Photo 174 — Wildflower meadow at dawn','This is photo number 174 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',62,29028,1216,1449,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_174.png',NULL,0,'409514b03c9c1dcf43fe84246b101c89',1,NULL,NULL,NULL,'2026-04-26 21:49:10'),
(313,'extra_photo_274.png','2025-06-28 21:49:10','2026-01-30 21:49:10','Extra Photo 274 — Wildflower meadow at dawn','This is photo number 274 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',52,12015,2454,978,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_274.png',NULL,0,'1dee1114358942f7be35a81f2513362f',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(314,'extra_photo_75.png','2025-09-14 21:49:10','2026-02-11 21:49:10','Extra Photo 75 — Autumn colours in the forest','This is photo number 75 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',333,8603,1505,1699,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_75.png',NULL,0,'3550061b82f746b0c4729e0585a24b94',1,NULL,NULL,NULL,'2026-04-01 21:49:10'),
(315,'extra_photo_175.png','2025-11-12 21:49:10','2024-05-12 21:49:10','Extra Photo 175 — Autumn colours in the forest','This is photo number 175 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',268,26040,1548,1553,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_175.png',NULL,0,'e2f0dc312c7316327ef3a72422d45094',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(316,'extra_photo_275.png','2026-02-20 21:49:10','2026-01-09 21:49:10','Extra Photo 275 — Autumn colours in the forest','This is photo number 275 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',97,20720,1360,1992,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_275.png',NULL,0,'cc4492863dd87771ae15d46bf3f28982',1,NULL,NULL,NULL,'2026-03-26 21:49:10'),
(317,'extra_photo_76.png','2025-12-07 21:49:10','2025-08-11 21:49:10','Extra Photo 76 — Reflections on still water','This is photo number 76 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',307,8374,1754,972,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_76.png',NULL,0,'779b8b793313a0f49fc81d6619fd0275',1,NULL,NULL,NULL,'2026-03-07 21:49:10'),
(318,'extra_photo_176.png','2025-06-01 21:49:10','2024-05-10 21:49:10','Extra Photo 176 — Reflections on still water','This is photo number 176 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',99,9252,2788,1044,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_176.png',NULL,0,'633df0ec2c7379db63122ea9f1e24fb0',1,NULL,NULL,NULL,'2026-03-11 21:49:10'),
(319,'extra_photo_276.png','2025-11-17 21:49:10','2024-08-11 21:49:10','Extra Photo 276 — Reflections on still water','This is photo number 276 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',477,13108,1677,1371,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_276.png',NULL,0,'893038fbeae039e1e81df70a7b67f9b3',1,NULL,NULL,NULL,'2026-04-15 21:49:10'),
(320,'extra_photo_77.png','2025-06-13 21:49:10','2024-10-04 21:49:10','Extra Photo 77 — Urban architecture detail','This is photo number 77 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',147,11319,3128,1764,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_77.png',NULL,0,'3865d894d4200e4b5b089558fc0c2d5b',1,NULL,NULL,NULL,'2026-03-21 21:49:10'),
(321,'extra_photo_177.png','2026-02-10 21:49:10','2025-11-12 21:49:10','Extra Photo 177 — Urban architecture detail','This is photo number 177 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',255,29011,3226,1752,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_177.png',NULL,0,'ee7317c6d8c54794b5a8300caa7e7f55',1,NULL,NULL,NULL,'2026-03-31 21:49:10'),
(322,'extra_photo_277.png','2025-11-18 21:49:10','2024-08-12 21:49:10','Extra Photo 277 — Urban architecture detail','This is photo number 277 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',475,12641,1479,803,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_277.png',NULL,0,'55fe1c389a8812c85ad517a0365a9ab1',1,NULL,NULL,NULL,'2026-03-18 21:49:10'),
(323,'extra_photo_78.png','2026-01-05 21:49:10','2025-04-01 21:49:10','Extra Photo 78 — Coastal cliffs at dusk','This is photo number 78 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',380,12776,2911,2350,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_78.png',NULL,0,'d7efc9d7dc45ee277c65df4fbf8ae6ec',1,NULL,NULL,NULL,'2026-03-15 21:49:10'),
(324,'extra_photo_178.png','2025-09-12 21:49:10','2024-05-07 21:49:10','Extra Photo 178 — Coastal cliffs at dusk','This is photo number 178 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',34,17353,2974,1645,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_178.png',NULL,0,'a892d8dc2f74b3c20c10e3c4c8592867',1,NULL,NULL,NULL,'2026-04-01 21:49:10'),
(325,'extra_photo_278.png','2025-10-13 21:49:10','2025-07-06 21:49:10','Extra Photo 278 — Coastal cliffs at dusk','This is photo number 278 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',204,28075,3492,1228,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_278.png',NULL,0,'3e7bbafa59920bd0f8971b34d9a6da16',1,NULL,NULL,NULL,'2026-03-29 21:49:10'),
(326,'extra_photo_79.png','2025-10-05 21:49:10','2025-08-01 21:49:10','Extra Photo 79 — Wildflower meadow at dawn','This is photo number 79 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',84,25741,1657,1977,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_79.png',NULL,0,'8c6877e602d9e2be2be0e6ed062188e1',1,NULL,NULL,NULL,'2026-04-20 21:49:10'),
(327,'extra_photo_179.png','2025-12-26 21:49:10','2025-08-26 21:49:10','Extra Photo 179 — Wildflower meadow at dawn','This is photo number 179 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',338,17086,3274,1096,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_179.png',NULL,0,'e905bc82db1491fe24efcfaed13551b7',1,NULL,NULL,NULL,'2026-04-06 21:49:10'),
(328,'extra_photo_279.png','2026-03-15 21:49:10','2025-02-28 21:49:10','Extra Photo 279 — Wildflower meadow at dawn','This is photo number 279 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',272,31696,1925,1679,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_279.png',NULL,0,'acb65ace348d5a3ee656c4fa0623d271',1,NULL,NULL,NULL,'2026-03-07 21:49:10'),
(329,'extra_photo_70.png','2025-10-03 21:49:10','2025-09-30 21:49:10','Extra Photo 70 — Autumn colours in the forest','This is photo number 70 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',369,28068,3518,1314,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_70.png',NULL,0,'6cfbf55ae6947c953faeb570dd1ad613',1,NULL,NULL,NULL,'2026-03-15 21:49:10'),
(330,'extra_photo_170.png','2025-09-27 21:49:10','2024-10-11 21:49:10','Extra Photo 170 — Autumn colours in the forest','This is photo number 170 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',56,14123,3447,2265,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_170.png',NULL,0,'d35f3cf4fdd41832ee4e93432d522668',1,NULL,NULL,NULL,'2026-03-11 21:49:10'),
(331,'extra_photo_270.png','2026-03-17 21:49:10','2025-10-31 21:49:10','Extra Photo 270 — Autumn colours in the forest','This is photo number 270 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',438,23814,2790,1340,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_270.png',NULL,0,'a8e08588fd32bf033af8dbabbd3b3890',1,NULL,NULL,NULL,'2026-03-16 21:49:10'),
(332,'extra_photo_81.png','2025-11-01 21:49:10','2025-09-04 21:49:10','Extra Photo 81 — Reflections on still water','This is photo number 81 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',78,27642,2692,1847,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_81.png',NULL,0,'59e5e3a0dadb1b3eeb378286e775d991',1,NULL,NULL,NULL,'2026-04-02 21:49:10'),
(333,'extra_photo_181.png','2026-03-31 21:49:10','2026-01-14 21:49:10','Extra Photo 181 — Reflections on still water','This is photo number 181 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',243,8271,2630,2315,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_181.png',NULL,0,'3f600686a610356fcfea451ae497069a',1,NULL,NULL,NULL,'2026-03-01 21:49:10'),
(334,'extra_photo_281.png','2025-06-06 21:49:10','2025-02-06 21:49:10','Extra Photo 281 — Reflections on still water','This is photo number 281 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',189,9611,1675,2063,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_281.png',NULL,0,'14097e57d2b11fa23a3369a0cb6a7905',1,NULL,NULL,NULL,'2026-04-05 21:49:10'),
(335,'extra_photo_82.png','2025-12-03 21:49:10','2024-06-21 21:49:10','Extra Photo 82 — Urban architecture detail','This is photo number 82 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',215,17123,2662,2249,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_82.png',NULL,0,'f21855f6652edf920b8a44b42a625d8b',1,NULL,NULL,NULL,'2026-03-15 21:49:10'),
(336,'extra_photo_182.png','2025-07-11 21:49:10','2024-08-10 21:49:10','Extra Photo 182 — Urban architecture detail','This is photo number 182 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',450,30595,1204,1097,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_182.png',NULL,0,'e1d1652841c4e8eb8fa8f4d19f9f7e1b',1,NULL,NULL,NULL,'2026-03-02 21:49:10'),
(337,'extra_photo_282.png','2026-04-06 21:49:10','2025-04-21 21:49:10','Extra Photo 282 — Urban architecture detail','This is photo number 282 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',185,15988,2524,2017,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_282.png',NULL,0,'e4f97aeecc9617b8ba52566ee13d89fc',1,NULL,NULL,NULL,'2026-04-18 21:49:10'),
(338,'extra_photo_83.png','2025-11-10 21:49:10','2024-08-10 21:49:10','Extra Photo 83 — Coastal cliffs at dusk','This is photo number 83 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',446,29723,3225,1606,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_83.png',NULL,0,'a867c57546711cb485f19c30ca9a24b5',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(339,'extra_photo_183.png','2025-11-19 21:49:10','2025-12-02 21:49:10','Extra Photo 183 — Coastal cliffs at dusk','This is photo number 183 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',348,29210,1991,2397,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_183.png',NULL,0,'8ff54cd6be907c371b957aaab8232f28',1,NULL,NULL,NULL,'2026-04-26 21:49:10'),
(340,'extra_photo_283.png','2026-04-21 21:49:10','2026-03-05 21:49:10','Extra Photo 283 — Coastal cliffs at dusk','This is photo number 283 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',156,16439,3161,850,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_283.png',NULL,0,'e10d5015fbc72466eb67d3cd51f44bb8',1,NULL,NULL,NULL,'2026-03-15 21:49:10'),
(341,'extra_photo_84.png','2025-11-18 21:49:10','2026-03-13 21:49:10','Extra Photo 84 — Wildflower meadow at dawn','This is photo number 84 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',499,27358,1293,2039,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_84.png',NULL,0,'421c4a41e503e4cd0d895915c5edfdd7',1,NULL,NULL,NULL,'2026-03-12 21:49:10'),
(342,'extra_photo_184.png','2025-11-08 21:49:10','2026-03-22 21:49:10','Extra Photo 184 — Wildflower meadow at dawn','This is photo number 184 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',424,10299,3443,1417,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_184.png',NULL,0,'ffc301bf0d00507cab6de9f06f9dd65c',1,NULL,NULL,NULL,'2026-04-19 21:49:10'),
(343,'extra_photo_284.png','2025-11-05 21:49:10','2024-05-04 21:49:10','Extra Photo 284 — Wildflower meadow at dawn','This is photo number 284 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',264,24253,3113,2328,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_284.png',NULL,0,'050cafe228d76d8738b08a887ab2b76d',1,NULL,NULL,NULL,'2026-04-04 21:49:10'),
(344,'extra_photo_85.png','2026-04-08 21:49:10','2026-02-11 21:49:10','Extra Photo 85 — Autumn colours in the forest','This is photo number 85 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',180,19857,2135,1547,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_85.png',NULL,0,'78246c93ad05fa04858a8d027b4b8d3f',1,NULL,NULL,NULL,'2026-04-16 21:49:10'),
(345,'extra_photo_185.png','2025-11-19 21:49:10','2024-12-25 21:49:10','Extra Photo 185 — Autumn colours in the forest','This is photo number 185 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',17,12130,3015,1224,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_185.png',NULL,0,'ebe1d2f8889b81874a1f7edcea07108b',1,NULL,NULL,NULL,'2026-04-23 21:49:10'),
(346,'extra_photo_285.png','2025-10-30 21:49:10','2025-10-09 21:49:10','Extra Photo 285 — Autumn colours in the forest','This is photo number 285 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',450,24406,2917,1643,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_285.png',NULL,0,'4888293a389a1bcfb23798d2c42e7064',1,NULL,NULL,NULL,'2026-03-28 21:49:10'),
(347,'extra_photo_86.png','2025-06-14 21:49:10','2024-08-03 21:49:10','Extra Photo 86 — Reflections on still water','This is photo number 86 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',361,8456,3426,1728,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_86.png',NULL,0,'f70094d11049131e3fdff23a31e01016',1,NULL,NULL,NULL,'2026-04-19 21:49:10'),
(348,'extra_photo_186.png','2025-06-18 21:49:10','2024-06-18 21:49:10','Extra Photo 186 — Reflections on still water','This is photo number 186 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',34,21408,2609,1216,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_186.png',NULL,0,'7581e62a4e23ca1bb29fed530d0a42b2',1,NULL,NULL,NULL,'2026-03-25 21:49:10'),
(349,'extra_photo_286.png','2025-05-25 21:49:10','2024-05-03 21:49:10','Extra Photo 286 — Reflections on still water','This is photo number 286 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',93,31071,1790,1352,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_286.png',NULL,0,'3673a8ee9879fda11fdfe6893e087ff6',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(350,'extra_photo_87.png','2025-05-28 21:49:10','2025-02-18 21:49:10','Extra Photo 87 — Urban architecture detail','This is photo number 87 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',113,16580,1454,1530,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_87.png',NULL,0,'7e7742c7046f30ba35ba96526489c1ca',1,NULL,NULL,NULL,'2026-02-28 21:49:10'),
(351,'extra_photo_187.png','2025-11-09 21:49:10','2025-07-03 21:49:10','Extra Photo 187 — Urban architecture detail','This is photo number 187 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',327,9323,1939,1401,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_187.png',NULL,0,'0d0d6b4b5844af84bf90f9ff93d03100',1,NULL,NULL,NULL,'2026-02-28 21:49:10'),
(352,'extra_photo_287.png','2025-09-03 21:49:10','2025-08-01 21:49:10','Extra Photo 287 — Urban architecture detail','This is photo number 287 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',448,17277,1775,861,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_287.png',NULL,0,'8bb6c2f144bafb1c84726d3697d8ff2c',1,NULL,NULL,NULL,'2026-03-29 21:49:10'),
(353,'extra_photo_88.png','2026-01-25 21:49:10','2024-08-27 21:49:10','Extra Photo 88 — Coastal cliffs at dusk','This is photo number 88 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',206,21526,2594,1141,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_88.png',NULL,0,'6b749fc9ab5169dbeb6bb23562310e37',1,NULL,NULL,NULL,'2026-04-07 21:49:10'),
(354,'extra_photo_188.png','2025-05-01 21:49:10','2024-05-27 21:49:10','Extra Photo 188 — Coastal cliffs at dusk','This is photo number 188 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',414,14531,3295,1677,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_188.png',NULL,0,'7575ef9091f152416463eeaa1309976c',1,NULL,NULL,NULL,'2026-04-19 21:49:10'),
(355,'extra_photo_288.png','2025-05-08 21:49:10','2025-05-17 21:49:10','Extra Photo 288 — Coastal cliffs at dusk','This is photo number 288 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',226,28518,3391,801,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_288.png',NULL,0,'61392d682eda502a74dae9a4ac5cc7e6',1,NULL,NULL,NULL,'2026-04-11 21:49:10'),
(356,'extra_photo_89.png','2025-12-29 21:49:10','2024-08-29 21:49:10','Extra Photo 89 — Wildflower meadow at dawn','This is photo number 89 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',87,17132,2112,2014,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_89.png',NULL,0,'ae369658af75b39fdf0d31772e49019b',1,NULL,NULL,NULL,'2026-03-18 21:49:10'),
(357,'extra_photo_189.png','2025-04-27 21:49:10','2026-04-11 21:49:10','Extra Photo 189 — Wildflower meadow at dawn','This is photo number 189 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',58,20519,1814,1943,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_189.png',NULL,0,'1ef481852e626c0b2c4aa6440dd108ae',1,NULL,NULL,NULL,'2026-03-09 21:49:10'),
(358,'extra_photo_289.png','2025-06-08 21:49:10','2024-04-27 21:49:10','Extra Photo 289 — Wildflower meadow at dawn','This is photo number 289 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',172,25566,2693,2266,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_289.png',NULL,0,'5844ab5a68eccd88484cabbf050325a4',1,NULL,NULL,NULL,'2026-03-15 21:49:10'),
(359,'extra_photo_80.png','2025-06-30 21:49:10','2024-05-19 21:49:10','Extra Photo 80 — Autumn colours in the forest','This is photo number 80 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',189,31696,3119,857,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_80.png',NULL,0,'fabafb6d7b4cf15a295e61164156a7f3',1,NULL,NULL,NULL,'2026-03-11 21:49:10'),
(360,'extra_photo_180.png','2025-07-09 21:49:10','2025-01-04 21:49:10','Extra Photo 180 — Autumn colours in the forest','This is photo number 180 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',434,17254,1968,1509,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_180.png',NULL,0,'76a9bd8c51036a5ee431b42ea59fb726',1,NULL,NULL,NULL,'2026-04-11 21:49:10'),
(361,'extra_photo_280.png','2025-05-14 21:49:10','2024-05-04 21:49:10','Extra Photo 280 — Autumn colours in the forest','This is photo number 280 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',45,19654,1575,1320,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_280.png',NULL,0,'41815dd40463cdedd4c800eb7b50caf5',1,NULL,NULL,NULL,'2026-04-17 21:49:10'),
(362,'extra_photo_91.png','2025-07-07 21:49:10','2025-03-17 21:49:10','Extra Photo 91 — Reflections on still water','This is photo number 91 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',182,11703,2829,2289,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_91.png',NULL,0,'bf275aa95d8fc17181dbbe80fa407f7c',1,NULL,NULL,NULL,'2026-03-20 21:49:10'),
(363,'extra_photo_191.png','2026-01-06 21:49:10','2025-01-02 21:49:10','Extra Photo 191 — Reflections on still water','This is photo number 191 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',189,30046,2299,1657,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_191.png',NULL,0,'8554d5028663559e5ed4044d278bd09d',1,NULL,NULL,NULL,'2026-04-08 21:49:10'),
(364,'extra_photo_291.png','2025-05-29 21:49:10','2025-01-10 21:49:10','Extra Photo 291 — Reflections on still water','This is photo number 291 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',248,21056,1753,1632,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_291.png',NULL,0,'c1f3baaa990bc244d26e9950facd7ad6',1,NULL,NULL,NULL,'2026-03-03 21:49:10'),
(365,'extra_photo_92.png','2025-04-29 21:49:10','2025-11-06 21:49:10','Extra Photo 92 — Urban architecture detail','This is photo number 92 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',97,14463,3027,800,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_92.png',NULL,0,'4856af51fbaa298e3fb835b825ec7b6c',1,NULL,NULL,NULL,'2026-03-15 21:49:10'),
(366,'extra_photo_192.png','2025-09-27 21:49:10','2024-10-23 21:49:10','Extra Photo 192 — Urban architecture detail','This is photo number 192 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',14,29246,2009,849,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_192.png',NULL,0,'9507b1a3b405b1602ed5ba32c1b6f089',1,NULL,NULL,NULL,'2026-04-18 21:49:10'),
(367,'extra_photo_292.png','2025-09-11 21:49:10','2024-12-09 21:49:10','Extra Photo 292 — Urban architecture detail','This is photo number 292 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',287,27464,1985,1125,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_292.png',NULL,0,'67b9d3f7bcedf77e6b22d617695a27f0',1,NULL,NULL,NULL,'2026-04-24 21:49:10'),
(368,'extra_photo_93.png','2025-10-04 21:49:10','2024-12-02 21:49:10','Extra Photo 93 — Coastal cliffs at dusk','This is photo number 93 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',409,31836,2424,1713,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_93.png',NULL,0,'50c06522fd2408074fcceef424f032be',1,NULL,NULL,NULL,'2026-04-07 21:49:10'),
(369,'extra_photo_193.png','2025-05-28 21:49:10','2025-02-18 21:49:10','Extra Photo 193 — Coastal cliffs at dusk','This is photo number 193 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',110,15859,3538,2223,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_193.png',NULL,0,'5da5a95eabbaf3816c0bfde53f39e9ec',1,NULL,NULL,NULL,'2026-03-26 21:49:10'),
(370,'extra_photo_293.png','2025-05-10 21:49:10','2025-11-09 21:49:10','Extra Photo 293 — Coastal cliffs at dusk','This is photo number 293 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',134,23727,2321,1392,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_293.png',NULL,0,'1a35c9903b541cb46986ea36edeac1e6',1,NULL,NULL,NULL,'2026-03-31 21:49:10'),
(371,'extra_photo_94.png','2026-03-07 21:49:10','2025-08-21 21:49:10','Extra Photo 94 — Wildflower meadow at dawn','This is photo number 94 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',143,17924,1697,2073,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_94.png',NULL,0,'a9b545db294ff24f9a269cfe5981b09b',1,NULL,NULL,NULL,'2026-04-05 21:49:10'),
(372,'extra_photo_194.png','2025-11-30 21:49:10','2024-06-03 21:49:10','Extra Photo 194 — Wildflower meadow at dawn','This is photo number 194 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',263,26860,2043,1438,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_194.png',NULL,0,'12cb0b7c700af1cda0b2677317d9e7ff',1,NULL,NULL,NULL,'2026-03-01 21:49:10'),
(373,'extra_photo_294.png','2025-10-25 21:49:10','2024-12-03 21:49:10','Extra Photo 294 — Wildflower meadow at dawn','This is photo number 294 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',489,27284,1389,2375,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_294.png',NULL,0,'8cce05ed5d56a955fb294220d794dc0f',1,NULL,NULL,NULL,'2026-03-16 21:49:10'),
(374,'extra_photo_95.png','2025-11-05 21:49:10','2025-09-14 21:49:10','Extra Photo 95 — Autumn colours in the forest','This is photo number 95 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',60,24329,1295,1052,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_95.png',NULL,0,'794afead04ee6b5bf28fc699b8059534',1,NULL,NULL,NULL,'2026-03-17 21:49:10'),
(375,'extra_photo_195.png','2025-06-10 21:49:10','2025-07-20 21:49:10','Extra Photo 195 — Autumn colours in the forest','This is photo number 195 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',142,14419,2363,1795,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_195.png',NULL,0,'ce7b7201e3dd62dacde2222ce5249e21',1,NULL,NULL,NULL,'2026-03-18 21:49:10'),
(376,'extra_photo_295.png','2025-11-26 21:49:10','2026-02-09 21:49:10','Extra Photo 295 — Autumn colours in the forest','This is photo number 295 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',138,9841,2519,1630,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_295.png',NULL,0,'bce9d9a6ddd8223578b0803e3d9855b4',1,NULL,NULL,NULL,'2026-03-01 21:49:10'),
(377,'extra_photo_96.png','2026-02-24 21:49:10','2026-04-25 21:49:10','Extra Photo 96 — Reflections on still water','This is photo number 96 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','fixture_admin',255,21166,1701,1438,NULL,NULL,NULL,NULL,'./upload/2024/01/extra_photo_96.png',NULL,0,'550ea3ee758a85d8293b08c55d40756d',1,NULL,NULL,NULL,'2026-04-04 21:49:10'),
(378,'extra_photo_196.png','2025-09-04 21:49:10','2026-02-09 21:49:10','Extra Photo 196 — Reflections on still water','This is photo number 196 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','viewer_alice',298,24089,2549,2080,NULL,NULL,NULL,NULL,'./upload/2024/05/extra_photo_196.png',NULL,0,'a2d7834a912f1a49c497a7f4be0bb73d',1,NULL,NULL,NULL,'2026-04-08 21:49:10'),
(379,'extra_photo_296.png','2026-02-21 21:49:10','2024-06-14 21:49:10','Extra Photo 296 — Reflections on still water','This is photo number 296 in the fixture dataset. It was taken during a photography excursion and shows perfect mirror reflections on a calm lake surface. Exposure settings were carefully chosen to capture the mood.','uploader_bob',69,29525,1358,1823,NULL,NULL,NULL,NULL,'./upload/2024/09/extra_photo_296.png',NULL,0,'1f7161b56e5a85ea7192d693b5fc45bf',1,NULL,NULL,NULL,'2026-02-26 21:49:10'),
(380,'extra_photo_97.png','2026-03-29 21:49:10','2025-07-18 21:49:10','Extra Photo 97 — Urban architecture detail','This is photo number 97 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','viewer_alice',352,16672,2864,1412,NULL,NULL,NULL,NULL,'./upload/2024/02/extra_photo_97.png',NULL,0,'42e38af086e53614d280e75e5fd97f07',1,NULL,NULL,NULL,'2026-03-07 21:49:10'),
(381,'extra_photo_197.png','2026-04-17 21:49:10','2025-01-25 21:49:10','Extra Photo 197 — Urban architecture detail','This is photo number 197 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','uploader_bob',24,16880,2885,1440,NULL,NULL,NULL,NULL,'./upload/2024/06/extra_photo_197.png',NULL,0,'e509b14ddf4fc6ce7efd845643a74e3c',1,NULL,NULL,NULL,'2026-03-04 21:49:10'),
(382,'extra_photo_297.png','2026-01-15 21:49:10','2024-12-01 21:49:10','Extra Photo 297 — Urban architecture detail','This is photo number 297 in the fixture dataset. It was taken during a photography excursion and shows intricate architectural details of a historic building. Exposure settings were carefully chosen to capture the mood.','fixture_admin',334,13940,1747,1437,NULL,NULL,NULL,NULL,'./upload/2024/10/extra_photo_297.png',NULL,0,'6e588cd8a31c34e1fcfb399ba6dc2695',1,NULL,NULL,NULL,'2026-04-08 21:49:10'),
(383,'extra_photo_98.png','2025-12-22 21:49:10','2024-09-21 21:49:10','Extra Photo 98 — Coastal cliffs at dusk','This is photo number 98 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','uploader_bob',479,17620,1503,1486,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_98.png',NULL,0,'27011bd909ad3ff606e5c1bca0fe363f',1,NULL,NULL,NULL,'2026-03-12 21:49:10'),
(384,'extra_photo_198.png','2025-10-12 21:49:10','2025-07-08 21:49:10','Extra Photo 198 — Coastal cliffs at dusk','This is photo number 198 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','fixture_admin',192,25355,2306,1016,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_198.png',NULL,0,'068ba6e54292c90b59198468dcdc7b09',1,NULL,NULL,NULL,'2026-04-09 21:49:10'),
(385,'extra_photo_298.png','2026-04-05 21:49:10','2025-06-23 21:49:10','Extra Photo 298 — Coastal cliffs at dusk','This is photo number 298 in the fixture dataset. It was taken during a photography excursion and shows dramatic coastal cliffs illuminated at dusk. Exposure settings were carefully chosen to capture the mood.','viewer_alice',462,16588,1241,818,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_298.png',NULL,0,'b64ae369e58a4d7dd0a248b0ce029d4c',1,NULL,NULL,NULL,'2026-04-26 21:49:10'),
(386,'extra_photo_99.png','2026-04-25 21:49:10','2026-04-26 21:49:10','Extra Photo 99 — Wildflower meadow at dawn','This is photo number 99 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','fixture_admin',492,30268,2838,1806,NULL,NULL,NULL,NULL,'./upload/2024/04/extra_photo_99.png',NULL,0,'da5f1c7552aa15c9de568b14b3c7b2b4',1,NULL,NULL,NULL,'2026-04-21 21:49:10'),
(387,'extra_photo_199.png','2025-09-19 21:49:10','2024-11-21 21:49:10','Extra Photo 199 — Wildflower meadow at dawn','This is photo number 199 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','viewer_alice',383,24630,1591,1978,NULL,NULL,NULL,NULL,'./upload/2024/08/extra_photo_199.png',NULL,0,'c8c92045c65aa541899cdb53bfac948b',1,NULL,NULL,NULL,'2026-04-15 21:49:10'),
(388,'extra_photo_299.png','2025-07-25 21:49:10','2025-12-01 21:49:10','Extra Photo 299 — Wildflower meadow at dawn','This is photo number 299 in the fixture dataset. It was taken during a photography excursion and shows a vibrant wildflower meadow just after sunrise. Exposure settings were carefully chosen to capture the mood.','uploader_bob',366,9542,1494,1472,NULL,NULL,NULL,NULL,'./upload/2024/12/extra_photo_299.png',NULL,0,'46143495c3fbc1d175f6818889c6bcd7',1,NULL,NULL,NULL,'2026-03-13 21:49:10'),
(389,'extra_photo_90.png','2025-11-27 21:49:10','2024-08-13 21:49:10','Extra Photo 90 — Autumn colours in the forest','This is photo number 90 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','fixture_admin',12,21737,3085,1135,NULL,NULL,NULL,NULL,'./upload/2024/07/extra_photo_90.png',NULL,0,'76ce1c36076e8417cd10b34e29093089',1,NULL,NULL,NULL,'2026-03-16 21:49:10'),
(390,'extra_photo_190.png','2025-06-25 21:49:10','2026-02-07 21:49:10','Extra Photo 190 — Autumn colours in the forest','This is photo number 190 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','viewer_alice',11,27085,3374,1031,NULL,NULL,NULL,NULL,'./upload/2024/11/extra_photo_190.png',NULL,0,'5d6e058ea9f7c488287a1d2cfe79a0d7',1,NULL,NULL,NULL,'2026-04-26 21:49:10'),
(391,'extra_photo_290.png','2025-09-23 21:49:10','2024-06-07 21:49:10','Extra Photo 290 — Autumn colours in the forest','This is photo number 290 in the fixture dataset. It was taken during a photography excursion and shows beautiful autumn foliage with warm tones. Exposure settings were carefully chosen to capture the mood.','uploader_bob',468,28663,2380,2202,NULL,NULL,NULL,NULL,'./upload/2024/03/extra_photo_290.png',NULL,0,'c02c684ec25cfebede1e7e41adc2537e',1,NULL,NULL,NULL,'2026-03-03 21:49:10');
/*!40000 ALTER TABLE `piwigo_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_languages`
--

DROP TABLE IF EXISTS `piwigo_languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_languages` (
  `id` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(64) NOT NULL DEFAULT '0',
  `name` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_languages`
--

LOCK TABLES `piwigo_languages` WRITE;
/*!40000 ALTER TABLE `piwigo_languages` DISABLE KEYS */;
INSERT INTO `piwigo_languages` VALUES
('en_UK','auto','English [UK]');
/*!40000 ALTER TABLE `piwigo_languages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_lounge`
--

DROP TABLE IF EXISTS `piwigo_lounge`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_lounge` (
  `image_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `category_id` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`image_id`,`category_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_lounge`
--

LOCK TABLES `piwigo_lounge` WRITE;
/*!40000 ALTER TABLE `piwigo_lounge` DISABLE KEYS */;
INSERT INTO `piwigo_lounge` VALUES
(1,1),
(1,2),
(1,3),
(2,1),
(3,1),
(4,3),
(5,3),
(6,2),
(7,2),
(8,2);
/*!40000 ALTER TABLE `piwigo_lounge` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_old_permalinks`
--

DROP TABLE IF EXISTS `piwigo_old_permalinks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_old_permalinks` (
  `cat_id` smallint(5) unsigned NOT NULL DEFAULT 0,
  `permalink` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `date_deleted` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `last_hit` datetime DEFAULT NULL,
  `hit` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`permalink`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_plugins` (
  `id` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `state` enum('inactive','active') NOT NULL DEFAULT 'inactive',
  `version` varchar(64) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_rate` (
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `element_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `anonymous_id` varchar(45) NOT NULL DEFAULT '',
  `rate` tinyint(2) unsigned NOT NULL DEFAULT 0,
  `date` date NOT NULL DEFAULT '1970-01-01',
  PRIMARY KEY (`element_id`,`user_id`,`anonymous_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_search` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `search_uuid` char(23) DEFAULT NULL,
  `created_on` datetime DEFAULT NULL,
  `created_by` mediumint(8) unsigned DEFAULT NULL,
  `forked_from` int(10) unsigned DEFAULT NULL,
  `rules` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_sessions` (
  `id` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `data` mediumtext NOT NULL,
  `expiration` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_sessions`
--

LOCK TABLES `piwigo_sessions` WRITE;
/*!40000 ALTER TABLE `piwigo_sessions` DISABLE KEYS */;
INSERT INTO `piwigo_sessions` VALUES
('AC154916c9a38da1b48ec385404eb6fd55b3','pwg_uid|i:1;connected_with|s:16:\"ws_session_login\";','2026-04-26 21:47:17');
/*!40000 ALTER TABLE `piwigo_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_sites`
--

DROP TABLE IF EXISTS `piwigo_sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_sites` (
  `id` tinyint(4) NOT NULL AUTO_INCREMENT,
  `galleries_url` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `sites_ui1` (`galleries_url`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_sites`
--

LOCK TABLES `piwigo_sites` WRITE;
/*!40000 ALTER TABLE `piwigo_sites` DISABLE KEYS */;
INSERT INTO `piwigo_sites` VALUES
(1,'./galleries/');
/*!40000 ALTER TABLE `piwigo_sites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_tags`
--

DROP TABLE IF EXISTS `piwigo_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_tags` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `url_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `lastmodified` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tags_i1` (`url_name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_tags`
--

LOCK TABLES `piwigo_tags` WRITE;
/*!40000 ALTER TABLE `piwigo_tags` DISABLE KEYS */;
INSERT INTO `piwigo_tags` VALUES
(1,'nature','nature','2026-04-26 21:46:22'),
(2,'portrait','portrait','2026-04-26 21:46:22'),
(3,'mountain','mountain','2026-04-26 21:46:22'),
(4,'outdoor','outdoor','2026-04-26 21:46:22');
/*!40000 ALTER TABLE `piwigo_tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_themes`
--

DROP TABLE IF EXISTS `piwigo_themes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_themes` (
  `id` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(64) NOT NULL DEFAULT '0',
  `name` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_upgrade` (
  `id` varchar(20) NOT NULL DEFAULT '',
  `applied` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_upgrade`
--

LOCK TABLES `piwigo_upgrade` WRITE;
/*!40000 ALTER TABLE `piwigo_upgrade` DISABLE KEYS */;
INSERT INTO `piwigo_upgrade` VALUES
('61','2026-04-26 21:43:36','upgrade included in installation'),
('62','2026-04-26 21:43:36','upgrade included in installation'),
('63','2026-04-26 21:43:36','upgrade included in installation'),
('64','2026-04-26 21:43:36','upgrade included in installation'),
('65','2026-04-26 21:43:36','upgrade included in installation'),
('66','2026-04-26 21:43:36','upgrade included in installation'),
('67','2026-04-26 21:43:36','upgrade included in installation'),
('68','2026-04-26 21:43:36','upgrade included in installation'),
('69','2026-04-26 21:43:36','upgrade included in installation'),
('70','2026-04-26 21:43:36','upgrade included in installation'),
('71','2026-04-26 21:43:36','upgrade included in installation'),
('72','2026-04-26 21:43:36','upgrade included in installation'),
('73','2026-04-26 21:43:36','upgrade included in installation'),
('74','2026-04-26 21:43:36','upgrade included in installation'),
('75','2026-04-26 21:43:36','upgrade included in installation'),
('76','2026-04-26 21:43:36','upgrade included in installation'),
('77','2026-04-26 21:43:36','upgrade included in installation'),
('78','2026-04-26 21:43:36','upgrade included in installation'),
('79','2026-04-26 21:43:36','upgrade included in installation'),
('80','2026-04-26 21:43:36','upgrade included in installation'),
('81','2026-04-26 21:43:36','upgrade included in installation'),
('82','2026-04-26 21:43:36','upgrade included in installation'),
('83','2026-04-26 21:43:36','upgrade included in installation'),
('84','2026-04-26 21:43:36','upgrade included in installation'),
('85','2026-04-26 21:43:36','upgrade included in installation'),
('86','2026-04-26 21:43:36','upgrade included in installation'),
('87','2026-04-26 21:43:36','upgrade included in installation'),
('88','2026-04-26 21:43:36','upgrade included in installation'),
('89','2026-04-26 21:43:36','upgrade included in installation'),
('90','2026-04-26 21:43:36','upgrade included in installation'),
('91','2026-04-26 21:43:36','upgrade included in installation'),
('92','2026-04-26 21:43:36','upgrade included in installation'),
('93','2026-04-26 21:43:36','upgrade included in installation'),
('94','2026-04-26 21:43:36','upgrade included in installation'),
('95','2026-04-26 21:43:36','upgrade included in installation'),
('96','2026-04-26 21:43:36','upgrade included in installation'),
('97','2026-04-26 21:43:36','upgrade included in installation'),
('98','2026-04-26 21:43:36','upgrade included in installation'),
('99','2026-04-26 21:43:36','upgrade included in installation'),
('100','2026-04-26 21:43:36','upgrade included in installation'),
('101','2026-04-26 21:43:36','upgrade included in installation'),
('102','2026-04-26 21:43:36','upgrade included in installation'),
('103','2026-04-26 21:43:36','upgrade included in installation'),
('104','2026-04-26 21:43:36','upgrade included in installation'),
('105','2026-04-26 21:43:36','upgrade included in installation'),
('106','2026-04-26 21:43:36','upgrade included in installation'),
('107','2026-04-26 21:43:36','upgrade included in installation'),
('108','2026-04-26 21:43:36','upgrade included in installation'),
('109','2026-04-26 21:43:36','upgrade included in installation'),
('110','2026-04-26 21:43:36','upgrade included in installation'),
('111','2026-04-26 21:43:36','upgrade included in installation'),
('112','2026-04-26 21:43:36','upgrade included in installation'),
('113','2026-04-26 21:43:36','upgrade included in installation'),
('114','2026-04-26 21:43:36','upgrade included in installation'),
('115','2026-04-26 21:43:36','upgrade included in installation'),
('116','2026-04-26 21:43:36','upgrade included in installation'),
('117','2026-04-26 21:43:36','upgrade included in installation'),
('118','2026-04-26 21:43:36','upgrade included in installation'),
('119','2026-04-26 21:43:36','upgrade included in installation'),
('120','2026-04-26 21:43:36','upgrade included in installation'),
('121','2026-04-26 21:43:36','upgrade included in installation'),
('122','2026-04-26 21:43:36','upgrade included in installation'),
('123','2026-04-26 21:43:36','upgrade included in installation'),
('124','2026-04-26 21:43:36','upgrade included in installation'),
('125','2026-04-26 21:43:36','upgrade included in installation'),
('126','2026-04-26 21:43:36','upgrade included in installation'),
('127','2026-04-26 21:43:36','upgrade included in installation'),
('128','2026-04-26 21:43:36','upgrade included in installation'),
('129','2026-04-26 21:43:36','upgrade included in installation'),
('130','2026-04-26 21:43:36','upgrade included in installation'),
('131','2026-04-26 21:43:36','upgrade included in installation'),
('132','2026-04-26 21:43:36','upgrade included in installation'),
('133','2026-04-26 21:43:36','upgrade included in installation'),
('134','2026-04-26 21:43:36','upgrade included in installation'),
('135','2026-04-26 21:43:36','upgrade included in installation'),
('136','2026-04-26 21:43:36','upgrade included in installation'),
('137','2026-04-26 21:43:36','upgrade included in installation'),
('138','2026-04-26 21:43:36','upgrade included in installation'),
('139','2026-04-26 21:43:36','upgrade included in installation'),
('140','2026-04-26 21:43:36','upgrade included in installation'),
('141','2026-04-26 21:43:36','upgrade included in installation'),
('142','2026-04-26 21:43:36','upgrade included in installation'),
('143','2026-04-26 21:43:36','upgrade included in installation'),
('144','2026-04-26 21:43:36','upgrade included in installation'),
('145','2026-04-26 21:43:36','upgrade included in installation'),
('146','2026-04-26 21:43:36','upgrade included in installation'),
('147','2026-04-26 21:43:36','upgrade included in installation'),
('148','2026-04-26 21:43:36','upgrade included in installation'),
('149','2026-04-26 21:43:36','upgrade included in installation'),
('150','2026-04-26 21:43:36','upgrade included in installation'),
('151','2026-04-26 21:43:36','upgrade included in installation'),
('152','2026-04-26 21:43:36','upgrade included in installation'),
('153','2026-04-26 21:43:36','upgrade included in installation'),
('154','2026-04-26 21:43:36','upgrade included in installation'),
('155','2026-04-26 21:43:36','upgrade included in installation'),
('156','2026-04-26 21:43:36','upgrade included in installation'),
('157','2026-04-26 21:43:36','upgrade included in installation'),
('158','2026-04-26 21:43:36','upgrade included in installation'),
('159','2026-04-26 21:43:36','upgrade included in installation'),
('160','2026-04-26 21:43:36','upgrade included in installation'),
('161','2026-04-26 21:43:36','upgrade included in installation'),
('162','2026-04-26 21:43:36','upgrade included in installation'),
('163','2026-04-26 21:43:36','upgrade included in installation'),
('164','2026-04-26 21:43:36','upgrade included in installation'),
('165','2026-04-26 21:43:36','upgrade included in installation'),
('166','2026-04-26 21:43:36','upgrade included in installation'),
('167','2026-04-26 21:43:36','upgrade included in installation'),
('168','2026-04-26 21:43:36','upgrade included in installation'),
('169','2026-04-26 21:43:36','upgrade included in installation'),
('170','2026-04-26 21:43:36','upgrade included in installation'),
('171','2026-04-26 21:43:36','upgrade included in installation'),
('172','2026-04-26 21:43:36','upgrade included in installation'),
('173','2026-04-26 21:43:36','upgrade included in installation'),
('174','2026-04-26 21:43:36','upgrade included in installation'),
('175','2026-04-26 21:43:36','upgrade included in installation'),
('176','2026-04-26 21:43:36','upgrade included in installation'),
('177','2026-04-26 21:43:36','upgrade included in installation'),
('178','2026-04-26 21:43:36','upgrade included in installation'),
('179','2026-04-26 21:43:36','upgrade included in installation'),
('180','2026-04-26 21:43:36','upgrade included in installation'),
('181','2026-04-26 21:43:36','upgrade included in installation');
/*!40000 ALTER TABLE `piwigo_upgrade` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_access`
--

DROP TABLE IF EXISTS `piwigo_user_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_access` (
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `cat_id` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`cat_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_auth_keys` (
  `auth_key_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `auth_key` varchar(255) NOT NULL,
  `apikey_secret` varchar(255) DEFAULT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `created_on` datetime NOT NULL,
  `duration` int(11) unsigned DEFAULT NULL,
  `expired_on` datetime NOT NULL,
  `apikey_name` varchar(100) DEFAULT NULL,
  `key_type` varchar(40) DEFAULT NULL,
  `revoked_on` datetime DEFAULT NULL,
  `last_used_on` datetime DEFAULT NULL,
  `last_notified_on` datetime DEFAULT NULL,
  PRIMARY KEY (`auth_key_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_cache` (
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `need_update` enum('true','false') NOT NULL DEFAULT 'true',
  `cache_update_time` int(10) unsigned NOT NULL DEFAULT 0,
  `forbidden_categories` mediumtext DEFAULT NULL,
  `nb_total_images` mediumint(8) unsigned DEFAULT NULL,
  `last_photo_date` datetime DEFAULT NULL,
  `nb_available_tags` int(5) DEFAULT NULL,
  `nb_available_comments` int(5) DEFAULT NULL,
  `image_access_type` enum('NOT IN','IN') NOT NULL DEFAULT 'NOT IN',
  `image_access_list` mediumtext DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_cache`
--

LOCK TABLES `piwigo_user_cache` WRITE;
/*!40000 ALTER TABLE `piwigo_user_cache` DISABLE KEYS */;
INSERT INTO `piwigo_user_cache` VALUES
(1,'false',1777240017,'0',0,NULL,NULL,NULL,'NOT IN','0');
/*!40000 ALTER TABLE `piwigo_user_cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_cache_categories`
--

DROP TABLE IF EXISTS `piwigo_user_cache_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_cache_categories` (
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `cat_id` smallint(5) unsigned NOT NULL DEFAULT 0,
  `date_last` datetime DEFAULT NULL,
  `max_date_last` datetime DEFAULT NULL,
  `nb_images` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `count_images` mediumint(8) unsigned DEFAULT 0,
  `nb_categories` mediumint(8) unsigned DEFAULT 0,
  `count_categories` mediumint(8) unsigned DEFAULT 0,
  `user_representative_picture_id` mediumint(8) unsigned DEFAULT NULL,
  PRIMARY KEY (`user_id`,`cat_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_cache_categories`
--

LOCK TABLES `piwigo_user_cache_categories` WRITE;
/*!40000 ALTER TABLE `piwigo_user_cache_categories` DISABLE KEYS */;
INSERT INTO `piwigo_user_cache_categories` VALUES
(1,2,NULL,NULL,0,0,0,0,NULL),
(1,1,NULL,NULL,0,0,1,1,NULL),
(1,3,NULL,NULL,0,0,0,0,NULL);
/*!40000 ALTER TABLE `piwigo_user_cache_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_feed`
--

DROP TABLE IF EXISTS `piwigo_user_feed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_feed` (
  `id` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `last_check` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_group` (
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `group_id` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`group_id`,`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_infos` (
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `nb_image_page` smallint(3) unsigned NOT NULL DEFAULT 15,
  `status` enum('webmaster','admin','normal','generic','guest') NOT NULL DEFAULT 'guest',
  `language` varchar(50) NOT NULL DEFAULT 'en_UK',
  `expand` enum('true','false') NOT NULL DEFAULT 'false',
  `show_nb_comments` enum('true','false') NOT NULL DEFAULT 'false',
  `show_nb_hits` enum('true','false') NOT NULL DEFAULT 'false',
  `recent_period` tinyint(3) unsigned NOT NULL DEFAULT 7,
  `theme` varchar(255) NOT NULL DEFAULT 'modus',
  `registration_date` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `enabled_high` enum('true','false') NOT NULL DEFAULT 'true',
  `level` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `activation_key` varchar(255) DEFAULT NULL,
  `activation_key_expire` datetime DEFAULT NULL,
  `last_visit` datetime DEFAULT NULL,
  `last_visit_from_history` enum('true','false') NOT NULL DEFAULT 'false',
  `lastmodified` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `preferences` text DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_infos`
--

LOCK TABLES `piwigo_user_infos` WRITE;
/*!40000 ALTER TABLE `piwigo_user_infos` DISABLE KEYS */;
INSERT INTO `piwigo_user_infos` VALUES
(1,15,'webmaster','en_UK','false','false','false',7,'modus','2026-04-26 21:43:36','true',8,NULL,NULL,NULL,'false','2026-04-26 21:43:37','a:1:{s:17:\"show_whats_new_16\";b:0;}'),
(2,15,'guest','en_UK','false','false','false',7,'modus','2026-04-26 21:43:36','true',0,NULL,NULL,NULL,'false','2026-04-26 21:43:36',NULL),
(3,15,'normal','en_UK','false','false','false',7,'modus','2026-04-26 21:46:37','true',0,NULL,NULL,NULL,'false','2026-04-26 21:43:36',NULL),
(4,15,'normal','en_UK','false','false','false',7,'modus','2026-04-26 21:46:37','true',0,NULL,NULL,NULL,'false','2026-04-26 21:43:36',NULL);
/*!40000 ALTER TABLE `piwigo_user_infos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_mail_notification`
--

DROP TABLE IF EXISTS `piwigo_user_mail_notification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_mail_notification` (
  `user_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `check_key` varchar(16) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `enabled` enum('true','false') NOT NULL DEFAULT 'false',
  `last_send` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_mail_notification_ui1` (`check_key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_users` (
  `id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL DEFAULT '',
  `password` varchar(255) DEFAULT NULL,
  `mail_address` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_ui1` (`username`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_users`
--

LOCK TABLES `piwigo_users` WRITE;
/*!40000 ALTER TABLE `piwigo_users` DISABLE KEYS */;
INSERT INTO `piwigo_users` VALUES
(1,'fixture_admin','$P$Gyo4RV7t79FOjFGTdl4EEErP73ABkX/','fixture@example.test'),
(2,'guest',NULL,NULL),
(3,'viewer_alice','$P$Godh6xuNBNZp5TnIUrHuGFxdt4bbhv.','alice@example.test'),
(4,'uploader_bob','$P$GDQb8Fd1MzrFmU1mbLS4QCBO3mBzzC0','bob@example.test');
/*!40000 ALTER TABLE `piwigo_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'piwigo'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-26 21:49:19
