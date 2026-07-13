-- MySQL dump 10.13  Distrib 8.4.10, for Linux (x86_64)
--
-- Host: 127.0.0.1    Database: piwigo_test
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
  `occured_on` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`activity_id`),
  KEY `IDX_BCC601B599EB8EA2` (`performed_by`),
  CONSTRAINT `fk_activity_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_activity`
--

LOCK TABLES `piwigo_activity` WRITE;
/*!40000 ALTER TABLE `piwigo_activity` DISABLE KEYS */;
INSERT INTO `piwigo_activity` VALUES (1,'system',1,'install',NULL,'none','::1','2026-08-01 03:00:00','a:2:{s:7:\"version\";s:6:\"16.3.0\";s:6:\"script\";s:7:\"install\";}',NULL),(2,'user',1,'login',1,'4c2d0619744891e07c17dca05b90a240','::1','2026-08-01 03:00:00','a:1:{s:6:\"script\";s:7:\"install\";}','PiwigoFixtureRegen/1.0'),(3,'user',1,'login',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:17:\"pwg.session.login\";}','PiwigoFixtureRegen/1.0'),(4,'album',1,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:18:\"pwg.categories.add\";}',NULL),(5,'album',2,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:18:\"pwg.categories.add\";}',NULL),(6,'photo',1,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(7,'photo',2,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(8,'photo',3,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(9,'photo',4,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(10,'photo',5,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:2:{s:6:\"method\";s:20:\"pwg.images.addSimple\";s:10:\"added_with\";s:3:\"app\";}',NULL),(11,'tag',1,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),(12,'tag',2,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),(13,'tag',3,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:12:\"pwg.tags.add\";}',NULL),(14,'user',3,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:13:\"pwg.users.add\";}',NULL),(15,'user',4,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:13:\"pwg.users.add\";}',NULL),(16,'group',1,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:14:\"pwg.groups.add\";}',NULL),(17,'group',2,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:14:\"pwg.groups.add\";}',NULL),(18,'group',3,'add',1,'fafb31238f2424c775910e681b336be0','::1','2026-08-01 03:00:00','a:1:{s:6:\"method\";s:14:\"pwg.groups.add\";}',NULL);
/*!40000 ALTER TABLE `piwigo_activity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_audit_log`
--

DROP TABLE IF EXISTS `piwigo_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` mediumint unsigned DEFAULT NULL,
  `action` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` int unsigned DEFAULT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `prev_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `row_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_log_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_log_actor` (`actor_id`),
  KEY `idx_audit_log_created_at` (`created_at`),
  CONSTRAINT `fk_audit_log_actor_id` FOREIGN KEY (`actor_id`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_audit_log`
--

LOCK TABLES `piwigo_audit_log` WRITE;
/*!40000 ALTER TABLE `piwigo_audit_log` DISABLE KEYS */;
INSERT INTO `piwigo_audit_log` VALUES (1,1,'create','group',1,NULL,'{\"name\": \"Editors\"}','::1','2026-08-01 00:00:00',NULL,'fb08569e0a181f3c519a47ed7faed20c576f9aee1d4ba3361bfb5d9bc9ffbbe0'),(2,1,'create','group',2,NULL,'{\"name\": \"Reviewers\"}','::1','2026-08-01 00:00:00','fb08569e0a181f3c519a47ed7faed20c576f9aee1d4ba3361bfb5d9bc9ffbbe0','425622dddcec305339ccef4d45d997d899b77bcd4fc4ead48ff7df01a6dbc53b'),(3,1,'create','group',3,NULL,'{\"name\": \"Guests\"}','::1','2026-08-01 00:00:00','425622dddcec305339ccef4d45d997d899b77bcd4fc4ead48ff7df01a6dbc53b','964f6fe6c7316cb7897260dffb899337c55e0d85c47c386f131533c5b209052f');
/*!40000 ALTER TABLE `piwigo_audit_log` ENABLE KEYS */;
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
  KEY `IDX_468BA6E9A76ED395` (`user_id`),
  KEY `IDX_468BA6E91F1F2A24` (`element_id`),
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
  `comment` mediumtext COLLATE utf8mb4_unicode_ci,
  `dir` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rank` smallint unsigned DEFAULT NULL,
  `status` enum('public','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `site_id` tinyint unsigned DEFAULT NULL,
  `visible` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'true',
  `representative_picture_id` mediumint unsigned DEFAULT NULL,
  `uppercats` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `commentable` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'true',
  `global_rank` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_order` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_i3` (`permalink`),
  KEY `categories_i2` (`id_uppercat`),
  KEY `lastmodified` (`lastmodified`),
  KEY `IDX_EDCE31CB9E69CEC8` (`representative_picture_id`),
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
INSERT INTO `piwigo_categories` VALUES (1,'Sample Album',NULL,NULL,NULL,1,'public',NULL,'true',1,'1','true','1',NULL,NULL,'2026-07-13 22:13:05'),(2,'Nested Sub Album',1,NULL,NULL,1,'public',NULL,'true',4,'1,2','true','1.1',NULL,NULL,'2026-07-13 22:13:06');
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
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author_id` mediumint unsigned DEFAULT NULL,
  `anonymous_id` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `validated` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `validation_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comments_i2` (`validation_date`),
  KEY `comments_i1` (`image_id`),
  KEY `IDX_4F2C9EC5F675F31B` (`author_id`),
  CONSTRAINT `fk_comments_author_id` FOREIGN KEY (`author_id`) REFERENCES `piwigo_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comments_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_comments`
--

LOCK TABLES `piwigo_comments` WRITE;
/*!40000 ALTER TABLE `piwigo_comments` DISABLE KEYS */;
INSERT INTO `piwigo_comments` VALUES (1,1,'2026-08-01 00:00:00','fixture_admin',NULL,1,'127.0.0.1',NULL,'Fixture comment for integration tests.','true','2026-08-01 00:00:00'),(2,2,'2026-08-01 00:00:00','regular_user',NULL,3,'127.0.0.2',NULL,'Another perspective on this photo.','true','2026-08-01 00:00:00'),(3,3,'2026-08-01 00:00:00','power_user',NULL,4,'127.0.0.3',NULL,'Great composition and colors!','true','2026-08-01 00:00:00'),(4,1,'2026-08-01 00:00:00','power_user',NULL,4,'127.0.0.3',NULL,'I keep coming back to this one.','true','2026-08-01 00:00:00'),(5,4,'2026-08-01 00:00:00','fixture_admin',NULL,1,'127.0.0.1',NULL,'Pending comment for moderation.','false',NULL);
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
  `value` mediumtext COLLATE utf8mb4_unicode_ci,
  `comment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='configuration table';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_config`
--

LOCK TABLES `piwigo_config` WRITE;
/*!40000 ALTER TABLE `piwigo_config` DISABLE KEYS */;
INSERT INTO `piwigo_config` VALUES ('activate_comments','true','Global parameter for usage of comments system'),('allow_user_customization','true','allow users to customize their gallery?'),('allow_user_registration','true','allow visitors to register?'),('blk_menubar','','Menubar options'),('c13y_ignore',NULL,'List of ignored anomalies'),('comments_author_mandatory','false','Comment author is mandatory'),('comments_email_mandatory','false','Comment email is mandatory'),('comments_enable_website','true','Enable \"website\" field on add comment form'),('comments_forall','false','even guest not registered can post comments'),('comments_order','ASC','comments order on picture page and cie'),('comments_validation','true','administrators validate users comments before becoming visible'),('dashboard_check_for_updates','false',NULL),('data_dir_checked','1',NULL),('derivatives','a:4:{s:1:\"d\";a:9:{s:6:\"square\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:120;i:1;i:120;}s:8:\"max_crop\";i:1;s:8:\"min_size\";a:2:{i:0;i:120;i:1;i:120;}}s:7:\"sharpen\";i:0;}s:5:\"thumb\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:144;i:1;i:144;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"2small\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:240;i:1;i:240;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"xsmall\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:432;i:1;i:324;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:5:\"small\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:576;i:1;i:432;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"medium\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:792;i:1;i:594;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:5:\"large\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:1008;i:1;i:756;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:6:\"xlarge\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:1224;i:1;i:918;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:7:\"xxlarge\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:1656;i:1;i:1242;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}}s:1:\"q\";i:95;s:1:\"w\";O:28:\"Piwigo\\Image\\WatermarkParams\":7:{s:4:\"file\";s:0:\"\";s:8:\"min_size\";a:2:{i:0;i:500;i:1;i:500;}s:4:\"xpos\";i:50;s:4:\"ypos\";i:50;s:7:\"xrepeat\";i:0;s:7:\"yrepeat\";i:0;s:7:\"opacity\";i:100;}s:1:\"c\";a:0:{}}',NULL),('disabled_derivatives','a:2:{s:7:\"3xlarge\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:2232;i:1;i:1674;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}s:7:\"4xlarge\";O:29:\"Piwigo\\Image\\DerivativeParams\":3:{s:13:\"last_mod_time\";i:1783980784;s:6:\"sizing\";O:25:\"Piwigo\\Image\\SizingParams\":3:{s:10:\"ideal_size\";a:2:{i:0;i:3000;i:1;i:2250;}s:8:\"max_crop\";i:0;s:8:\"min_size\";N;}s:7:\"sharpen\";i:0;}}',NULL),('display_fromto','false',NULL),('email_admin_on_comment','false','Send an email to the administrators when a valid comment is entered'),('email_admin_on_comment_deletion','false','Send an email to the administrators when a comment is deleted'),('email_admin_on_comment_edition','false','Send an email to the administrators when a comment is modified'),('email_admin_on_comment_validation','true','Send an email to the administrators when a comment requires validation'),('email_admin_on_new_user','none','Send an email to theadministrators when a user registers'),('extents_for_templates','a:0:{}','Actived template-extension(s)'),('gallery_locked','false','Lock your gallery temporary for non admin users'),('gallery_title','Fixture Gallery','Title at top of each page and for RSS feed'),('history_admin','false','keep a history of administrator visits on your website'),('history_guest','true','keep a history of guest visits on your website'),('index_caddie_icon','true',NULL),('index_created_date_icon','true','Display calendar by creation date icon'),('index_edit_icon','true',NULL),('index_flat_icon','false','Display flat icon'),('index_new_icon','true','Display new icons next albums and pictures'),('index_posted_date_icon','true','Display calendar by posted date'),('index_search_in_set_action','true',NULL),('index_search_in_set_button','false',NULL),('index_sizes_icon','true',NULL),('index_slideshow_icon','true','Display slideshow icon'),('index_sort_order_input','true','Display image order selection list'),('last_major_update','2026-07-13 19:13:04',NULL),('log','true','keep an history of visits on your website'),('lounge_active','true',NULL),('mail_theme','clear',NULL),('menubar_filter_icon','false','Display filter icon'),('mobile_theme',NULL,NULL),('nb_categories_page','12','Param for categories pagination'),('nb_comment_page','10','number of comments to display on each page'),('nbm_complementary_mail_content','','Complementary mail content for notification by mail'),('nbm_send_detailed_content','true','Send detailed content for notification by mail'),('nbm_send_html_mail','true','Send mail on HTML format for notification by mail'),('nbm_send_mail_as','','Send mail as param value for notification by mail'),('nbm_send_recent_post_dates','true','Send recent post by dates for notification by mail'),('obligatory_user_mail_address','false','Mail address is obligatory for users'),('order_by','ORDER BY date_available DESC, file ASC, id ASC','default photo order'),('order_by_inside_category','ORDER BY date_available DESC, file ASC, id ASC','default photo order inside category'),('original_resize','false',NULL),('original_resize_maxheight','2016',NULL),('original_resize_maxwidth','2016',NULL),('original_resize_quality','95',NULL),('page_banner','<h1>%gallery_title%</h1>\n\n<p>Welcome to my photo gallery</p>','html displayed on the top each page of your gallery'),('picture_caddie_icon','true',NULL),('picture_download_icon','true','Display download icon on picture page'),('picture_edit_icon','true',NULL),('picture_favorite_icon','true','Display favorite icon on picture page'),('picture_informations','a:11:{s:6:\"author\";b:1;s:10:\"created_on\";b:1;s:9:\"posted_on\";b:1;s:10:\"dimensions\";b:0;s:4:\"file\";b:0;s:8:\"filesize\";b:0;s:4:\"tags\";b:1;s:10:\"categories\";b:1;s:6:\"visits\";b:1;s:12:\"rating_score\";b:1;s:13:\"privacy_level\";b:1;}','Information displayed on picture page'),('picture_menu','false','Show menubar on picture page'),('picture_metadata_icon','true','Display metadata icon on picture page'),('picture_navigation_icons','true','Display navigation icons on picture page'),('picture_navigation_thumb','true','Display navigation thumbnails on picture page'),('picture_representative_icon','true',NULL),('picture_sizes_icon','true',NULL),('picture_slideshow_icon','true','Display slideshow icon on picture page'),('piwigo_db_version','16',NULL),('piwigo_installed_version','16.3.0',NULL),('rate','true','Rating pictures feature is enabled'),('rate_anonymous','true','Rating pictures feature is also enabled for visitors'),('secret_key','595acfd517bb6ec586964c44a291a5df74ccbe7a','a secret key specific to the gallery for internal use'),('show_mobile_app_banner_in_admin','true',NULL),('show_mobile_app_banner_in_gallery','false',NULL),('show_piwigo_latest_news','false',NULL),('updates_ignored','a:3:{s:7:\"plugins\";a:0:{}s:6:\"themes\";a:0:{}s:9:\"languages\";a:0:{}}','Extensions ignored for update'),('upload_detect_duplicate','true',NULL),('use_standard_pages','true',NULL),('user_can_delete_comment','false','administrators can allow user delete their own comments'),('user_can_edit_comment','false','administrators can allow user edit their own comments'),('webmaster_id','1',NULL),('week_starts_on','monday','Monday may not be the first day of the week');
/*!40000 ALTER TABLE `piwigo_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_derivative_settings`
--

DROP TABLE IF EXISTS `piwigo_derivative_settings`;
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

--
-- Dumping data for table `piwigo_derivative_settings`
--

LOCK TABLES `piwigo_derivative_settings` WRITE;
/*!40000 ALTER TABLE `piwigo_derivative_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_derivative_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_derivative_size`
--

DROP TABLE IF EXISTS `piwigo_derivative_size`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_derivative_size` (
  `name` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
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

--
-- Dumping data for table `piwigo_derivative_size`
--

LOCK TABLES `piwigo_derivative_size` WRITE;
/*!40000 ALTER TABLE `piwigo_derivative_size` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_derivative_size` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_extension_ignored_updates`
--

DROP TABLE IF EXISTS `piwigo_extension_ignored_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_extension_ignored_updates` (
  `extension_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ignored_at` datetime NOT NULL,
  PRIMARY KEY (`extension_type`,`extension_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  KEY `IDX_D4CC2D143DA5256D` (`image_id`),
  KEY `IDX_D4CC2D14A76ED395` (`user_id`),
  CONSTRAINT `fk_favorites_image_id` FOREIGN KEY (`image_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_favorites_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_favorites`
--

LOCK TABLES `piwigo_favorites` WRITE;
/*!40000 ALTER TABLE `piwigo_favorites` DISABLE KEYS */;
INSERT INTO `piwigo_favorites` VALUES (1,1),(1,3),(1,5);
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
  KEY `IDX_9EA42F0CFE54D947` (`group_id`),
  KEY `IDX_9EA42F0CE6ADA943` (`cat_id`),
  CONSTRAINT `fk_group_access_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_access_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_group_access`
--

LOCK TABLES `piwigo_group_access` WRITE;
/*!40000 ALTER TABLE `piwigo_group_access` DISABLE KEYS */;
INSERT INTO `piwigo_group_access` VALUES (1,1),(1,2),(2,1),(3,1);
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
  `is_default` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groups_ui1` (`name`),
  KEY `lastmodified` (`lastmodified`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_groups`
--

LOCK TABLES `piwigo_groups` WRITE;
/*!40000 ALTER TABLE `piwigo_groups` DISABLE KEYS */;
INSERT INTO `piwigo_groups` VALUES (1,'Editors','false','2026-08-01 03:00:00'),(2,'Reviewers','false','2026-08-01 03:00:00'),(3,'Guests','false','2026-08-01 03:00:00');
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
  KEY `IDX_F583509E3DA5256D` (`image_id`),
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
  KEY `IDX_3AECF09F3DA5256D` (`image_id`),
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
  KEY `IDX_6BC62A313DA5256D` (`image_id`),
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
  `file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `date_available` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `date_creation` datetime DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` mediumtext COLLATE utf8mb4_unicode_ci,
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_images`
--

LOCK TABLES `piwigo_images` WRITE;
/*!40000 ALTER TABLE `piwigo_images` DISABLE KEYS */;
INSERT INTO `piwigo_images` VALUES (1,'fixture-photo-1.jpg','2026-08-01 00:00:00',NULL,'Photo 1',NULL,NULL,0,1,200,150,NULL,NULL,'2026-07-13',4.50,'./upload/2026/08/01/20260801000000-2e7e6a46.jpg',NULL,0,'2e7ee450c4a4cffe42945205029782b9',1,0,NULL,NULL,'2026-07-13 22:13:07'),(2,'fixture-photo-2.jpg','2026-08-01 00:00:00',NULL,'Photo 2',NULL,NULL,0,1,200,150,NULL,NULL,'2026-07-13',3.00,'./upload/2026/08/01/20260801000000-4a017438.jpg',NULL,0,'4a010138f010067cfc713afb6dcf45e1',1,0,NULL,NULL,'2026-07-13 22:13:07'),(3,'fixture-photo-3.jpg','2026-08-01 00:00:00',NULL,'Photo 3',NULL,NULL,0,1,200,150,NULL,NULL,'2026-07-13',5.00,'./upload/2026/08/01/20260801000000-a6a0f451.jpg',NULL,0,'a6a04acded208db63890b74c4252a012',1,0,NULL,NULL,'2026-07-13 22:13:07'),(4,'fixture-photo-4.jpg','2026-08-01 00:00:00',NULL,'Photo 4',NULL,NULL,0,1,200,150,NULL,NULL,'2026-07-13',2.00,'./upload/2026/08/01/20260801000000-3df6245a.jpg',NULL,0,'3df6bd0ebb6f22ea988f2ffb1c3a9566',1,0,NULL,NULL,'2026-07-13 22:13:07'),(5,'fixture-photo-5.jpg','2026-08-01 00:00:00',NULL,'Photo 5',NULL,NULL,0,1,200,150,NULL,NULL,'2026-07-13',NULL,'./upload/2026/08/01/20260801000000-4b01b2ce.jpg',NULL,0,'4b01d21f3d56009c3b1f913fafda86c5',1,0,NULL,NULL,'2026-07-13 22:13:06');
/*!40000 ALTER TABLE `piwigo_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_integrity_ignored_anomalies`
--

DROP TABLE IF EXISTS `piwigo_integrity_ignored_anomalies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_integrity_ignored_anomalies` (
  `anomaly_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `piwigo_version` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ignored_at` datetime NOT NULL,
  PRIMARY KEY (`anomaly_id`,`piwigo_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
INSERT INTO `piwigo_languages` VALUES ('en_UK','16.3.0','English (Great Britain)');
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
  KEY `IDX_735C32FF3DA5256D` (`image_id`),
  KEY `IDX_735C32FF12469DE2` (`category_id`),
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
-- Table structure for table `piwigo_old_permalinks`
--

DROP TABLE IF EXISTS `piwigo_old_permalinks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_old_permalinks` (
  `cat_id` smallint unsigned NOT NULL DEFAULT '0',
  `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `date_deleted` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
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
INSERT INTO `piwigo_old_permalinks` VALUES (1,'old-sample-album','2026-08-01 00:00:00','2026-08-01 00:00:00',42);
/*!40000 ALTER TABLE `piwigo_old_permalinks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_plugin_migrations`
--

DROP TABLE IF EXISTS `piwigo_plugin_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_plugin_migrations` (
  `plugin_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
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
  `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
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
  `date` date NOT NULL DEFAULT '1970-01-01',
  PRIMARY KEY (`element_id`,`user_id`,`anonymous_id`),
  KEY `IDX_1069AEF21F1F2A24` (`element_id`),
  KEY `IDX_1069AEF2A76ED395` (`user_id`),
  CONSTRAINT `fk_rate_element_id` FOREIGN KEY (`element_id`) REFERENCES `piwigo_images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rate_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_rate`
--

LOCK TABLES `piwigo_rate` WRITE;
/*!40000 ALTER TABLE `piwigo_rate` DISABLE KEYS */;
INSERT INTO `piwigo_rate` VALUES (1,1,'',5,'2026-08-01'),(3,1,'',4,'2026-08-01'),(4,2,'',3,'2026-08-01'),(1,3,'',5,'2026-08-01'),(3,4,'',2,'2026-08-01');
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
  `rules` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `IDX_1BF6B975DE12AB56` (`created_by`),
  KEY `IDX_1BF6B975C828EDAC` (`forked_from`),
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
  `name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_json` json NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `data` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_sessions`
--

LOCK TABLES `piwigo_sessions` WRITE;
/*!40000 ALTER TABLE `piwigo_sessions` DISABLE KEYS */;
INSERT INTO `piwigo_sessions` VALUES ('fafb31238f2424c775910e681b336be0','pwg_uid|i:1;connected_with|s:16:\"ws_session_login\";','2026-08-01 00:00:00');
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
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `url_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
INSERT INTO `piwigo_tags` VALUES (1,'nature','nature','2026-08-01 03:00:00'),(2,'travel','travel','2026-08-01 03:00:00'),(3,'family','family','2026-08-01 03:00:00');
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
  `applied` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_upgrade`
--

LOCK TABLES `piwigo_upgrade` WRITE;
/*!40000 ALTER TABLE `piwigo_upgrade` DISABLE KEYS */;
INSERT INTO `piwigo_upgrade` VALUES ('100','2026-07-13 19:12:55','upgrade included in installation'),('101','2026-07-13 19:12:55','upgrade included in installation'),('102','2026-07-13 19:12:55','upgrade included in installation'),('103','2026-07-13 19:12:55','upgrade included in installation'),('104','2026-07-13 19:12:55','upgrade included in installation'),('105','2026-07-13 19:12:55','upgrade included in installation'),('106','2026-07-13 19:12:55','upgrade included in installation'),('107','2026-07-13 19:12:55','upgrade included in installation'),('108','2026-07-13 19:12:55','upgrade included in installation'),('109','2026-07-13 19:12:55','upgrade included in installation'),('110','2026-07-13 19:12:55','upgrade included in installation'),('111','2026-07-13 19:12:55','upgrade included in installation'),('112','2026-07-13 19:12:55','upgrade included in installation'),('113','2026-07-13 19:12:55','upgrade included in installation'),('114','2026-07-13 19:12:55','upgrade included in installation'),('115','2026-07-13 19:12:55','upgrade included in installation'),('116','2026-07-13 19:12:55','upgrade included in installation'),('117','2026-07-13 19:12:55','upgrade included in installation'),('118','2026-07-13 19:12:55','upgrade included in installation'),('119','2026-07-13 19:12:55','upgrade included in installation'),('120','2026-07-13 19:12:55','upgrade included in installation'),('121','2026-07-13 19:12:55','upgrade included in installation'),('122','2026-07-13 19:12:55','upgrade included in installation'),('123','2026-07-13 19:12:55','upgrade included in installation'),('124','2026-07-13 19:12:55','upgrade included in installation'),('125','2026-07-13 19:12:55','upgrade included in installation'),('126','2026-07-13 19:12:55','upgrade included in installation'),('127','2026-07-13 19:12:55','upgrade included in installation'),('128','2026-07-13 19:12:55','upgrade included in installation'),('129','2026-07-13 19:12:55','upgrade included in installation'),('130','2026-07-13 19:12:55','upgrade included in installation'),('131','2026-07-13 19:12:55','upgrade included in installation'),('132','2026-07-13 19:12:55','upgrade included in installation'),('133','2026-07-13 19:12:55','upgrade included in installation'),('134','2026-07-13 19:12:55','upgrade included in installation'),('135','2026-07-13 19:12:55','upgrade included in installation'),('136','2026-07-13 19:12:55','upgrade included in installation'),('137','2026-07-13 19:12:55','upgrade included in installation'),('138','2026-07-13 19:12:55','upgrade included in installation'),('139','2026-07-13 19:12:55','upgrade included in installation'),('140','2026-07-13 19:12:55','upgrade included in installation'),('141','2026-07-13 19:12:55','upgrade included in installation'),('142','2026-07-13 19:12:55','upgrade included in installation'),('143','2026-07-13 19:12:55','upgrade included in installation'),('144','2026-07-13 19:12:55','upgrade included in installation'),('145','2026-07-13 19:12:55','upgrade included in installation'),('146','2026-07-13 19:12:55','upgrade included in installation'),('147','2026-07-13 19:12:55','upgrade included in installation'),('148','2026-07-13 19:12:55','upgrade included in installation'),('149','2026-07-13 19:12:55','upgrade included in installation'),('150','2026-07-13 19:12:55','upgrade included in installation'),('151','2026-07-13 19:12:55','upgrade included in installation'),('152','2026-07-13 19:12:55','upgrade included in installation'),('153','2026-07-13 19:12:55','upgrade included in installation'),('154','2026-07-13 19:12:55','upgrade included in installation'),('155','2026-07-13 19:12:55','upgrade included in installation'),('156','2026-07-13 19:12:55','upgrade included in installation'),('157','2026-07-13 19:12:55','upgrade included in installation'),('158','2026-07-13 19:12:55','upgrade included in installation'),('159','2026-07-13 19:12:55','upgrade included in installation'),('160','2026-07-13 19:12:55','upgrade included in installation'),('161','2026-07-13 19:12:55','upgrade included in installation'),('162','2026-07-13 19:12:55','upgrade included in installation'),('163','2026-07-13 19:12:55','upgrade included in installation'),('164','2026-07-13 19:12:55','upgrade included in installation'),('165','2026-07-13 19:12:55','upgrade included in installation'),('166','2026-07-13 19:12:55','upgrade included in installation'),('167','2026-07-13 19:12:55','upgrade included in installation'),('168','2026-07-13 19:12:55','upgrade included in installation'),('169','2026-07-13 19:12:55','upgrade included in installation'),('170','2026-07-13 19:12:55','upgrade included in installation'),('171','2026-07-13 19:12:55','upgrade included in installation'),('172','2026-07-13 19:12:55','upgrade included in installation'),('173','2026-07-13 19:12:55','upgrade included in installation'),('174','2026-07-13 19:12:55','upgrade included in installation'),('175','2026-07-13 19:12:55','upgrade included in installation'),('176','2026-07-13 19:12:55','upgrade included in installation'),('177','2026-07-13 19:12:55','upgrade included in installation'),('178','2026-07-13 19:12:55','upgrade included in installation'),('179','2026-07-13 19:12:55','upgrade included in installation'),('180','2026-07-13 19:12:55','upgrade included in installation'),('181','2026-07-13 19:12:55','upgrade included in installation'),('61','2026-07-13 19:12:55','upgrade included in installation'),('62','2026-07-13 19:12:55','upgrade included in installation'),('63','2026-07-13 19:12:55','upgrade included in installation'),('64','2026-07-13 19:12:55','upgrade included in installation'),('65','2026-07-13 19:12:55','upgrade included in installation'),('66','2026-07-13 19:12:55','upgrade included in installation'),('67','2026-07-13 19:12:55','upgrade included in installation'),('68','2026-07-13 19:12:55','upgrade included in installation'),('69','2026-07-13 19:12:55','upgrade included in installation'),('70','2026-07-13 19:12:55','upgrade included in installation'),('71','2026-07-13 19:12:55','upgrade included in installation'),('72','2026-07-13 19:12:55','upgrade included in installation'),('73','2026-07-13 19:12:55','upgrade included in installation'),('74','2026-07-13 19:12:55','upgrade included in installation'),('75','2026-07-13 19:12:55','upgrade included in installation'),('76','2026-07-13 19:12:55','upgrade included in installation'),('77','2026-07-13 19:12:55','upgrade included in installation'),('78','2026-07-13 19:12:55','upgrade included in installation'),('79','2026-07-13 19:12:55','upgrade included in installation'),('80','2026-07-13 19:12:55','upgrade included in installation'),('81','2026-07-13 19:12:55','upgrade included in installation'),('82','2026-07-13 19:12:55','upgrade included in installation'),('83','2026-07-13 19:12:55','upgrade included in installation'),('84','2026-07-13 19:12:55','upgrade included in installation'),('85','2026-07-13 19:12:55','upgrade included in installation'),('86','2026-07-13 19:12:55','upgrade included in installation'),('87','2026-07-13 19:12:55','upgrade included in installation'),('88','2026-07-13 19:12:55','upgrade included in installation'),('89','2026-07-13 19:12:55','upgrade included in installation'),('90','2026-07-13 19:12:55','upgrade included in installation'),('91','2026-07-13 19:12:55','upgrade included in installation'),('92','2026-07-13 19:12:55','upgrade included in installation'),('93','2026-07-13 19:12:55','upgrade included in installation'),('94','2026-07-13 19:12:55','upgrade included in installation'),('95','2026-07-13 19:12:55','upgrade included in installation'),('96','2026-07-13 19:12:55','upgrade included in installation'),('97','2026-07-13 19:12:55','upgrade included in installation'),('98','2026-07-13 19:12:55','upgrade included in installation'),('99','2026-07-13 19:12:55','upgrade included in installation');
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
  KEY `IDX_2C33FF4CA76ED395` (`user_id`),
  KEY `IDX_2C33FF4CE6ADA943` (`cat_id`),
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
  KEY `IDX_E677EE4BA76ED395` (`user_id`),
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
  `need_update` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'true',
  `cache_update_time` int unsigned NOT NULL DEFAULT '0',
  `forbidden_categories` longtext COLLATE utf8mb4_unicode_ci,
  `nb_total_images` mediumint unsigned DEFAULT NULL,
  `last_photo_date` datetime DEFAULT NULL,
  `nb_available_tags` int DEFAULT NULL,
  `nb_available_comments` int DEFAULT NULL,
  `image_access_type` enum('NOT IN','IN') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NOT IN',
  `image_access_list` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_cache_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_cache`
--

LOCK TABLES `piwigo_user_cache` WRITE;
/*!40000 ALTER TABLE `piwigo_user_cache` DISABLE KEYS */;
INSERT INTO `piwigo_user_cache` VALUES (1,'false',1783980787,'0',5,'2026-08-01 00:00:00',NULL,NULL,'NOT IN','0');
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
  KEY `IDX_F3CC0BFBA76ED395` (`user_id`),
  KEY `IDX_F3CC0BFBE6ADA943` (`cat_id`),
  CONSTRAINT `fk_user_cache_categories_cat_id` FOREIGN KEY (`cat_id`) REFERENCES `piwigo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_cache_categories_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_cache_categories`
--

LOCK TABLES `piwigo_user_cache_categories` WRITE;
/*!40000 ALTER TABLE `piwigo_user_cache_categories` DISABLE KEYS */;
INSERT INTO `piwigo_user_cache_categories` VALUES (1,1,'2026-08-01 00:00:00','2026-08-01 00:00:00',3,5,1,1,NULL),(1,2,'2026-08-01 00:00:00','2026-08-01 00:00:00',2,2,0,0,NULL);
/*!40000 ALTER TABLE `piwigo_user_cache_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_failed_logins`
--

DROP TABLE IF EXISTS `piwigo_user_failed_logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_failed_logins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint unsigned DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_failed_logins_user_time` (`user_id`,`attempted_at`),
  KEY `idx_user_failed_logins_ip_time` (`ip`,`attempted_at`),
  KEY `IDX_B27CBE76A76ED395` (`user_id`),
  CONSTRAINT `fk_user_failed_logins_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_failed_logins`
--

LOCK TABLES `piwigo_user_failed_logins` WRITE;
/*!40000 ALTER TABLE `piwigo_user_failed_logins` DISABLE KEYS */;
/*!40000 ALTER TABLE `piwigo_user_failed_logins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `piwigo_user_feed`
--

DROP TABLE IF EXISTS `piwigo_user_feed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `piwigo_user_feed` (
  `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `user_id` mediumint unsigned NOT NULL DEFAULT '0',
  `last_check` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_69644583A76ED395` (`user_id`),
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
  KEY `IDX_583FC83EA76ED395` (`user_id`),
  KEY `IDX_583FC83EFE54D947` (`group_id`),
  CONSTRAINT `fk_user_group_group_id` FOREIGN KEY (`group_id`) REFERENCES `piwigo_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_group_user_id` FOREIGN KEY (`user_id`) REFERENCES `piwigo_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_user_group`
--

LOCK TABLES `piwigo_user_group` WRITE;
/*!40000 ALTER TABLE `piwigo_user_group` DISABLE KEYS */;
INSERT INTO `piwigo_user_group` VALUES (1,1),(3,1),(3,2),(4,3);
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
  `expand` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `show_nb_comments` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `show_nb_hits` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `recent_period` tinyint unsigned NOT NULL DEFAULT '7',
  `theme` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'modus',
  `registration_date` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `enabled_high` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'true',
  `level` tinyint unsigned NOT NULL DEFAULT '0',
  `activation_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activation_key_expire` datetime DEFAULT NULL,
  `last_visit` datetime DEFAULT NULL,
  `last_visit_from_history` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
  `lastmodified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `preferences` mediumtext COLLATE utf8mb4_unicode_ci,
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
INSERT INTO `piwigo_user_infos` VALUES (1,15,'webmaster','en_UK','false','false','false',7,'modus','2026-08-01 00:00:00','true',8,NULL,NULL,NULL,'false','2026-07-13 22:12:55','a:1:{s:17:\"show_whats_new_16\";b:0;}'),(2,15,'guest','en_UK','false','false','false',7,'modus','2026-08-01 00:00:00','true',0,NULL,NULL,NULL,'false','2026-08-01 03:00:00',NULL),(3,15,'normal','en_UK','false','false','false',7,'modus','2026-08-01 00:00:00','true',0,NULL,NULL,NULL,'false','2026-08-01 03:00:00',NULL),(4,15,'normal','en_UK','false','false','false',7,'modus','2026-08-01 00:00:00','true',0,NULL,NULL,NULL,'false','2026-08-01 03:00:00',NULL);
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
  `check_key` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `enabled` enum('true','false') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'false',
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
INSERT INTO `piwigo_user_mail_notification` VALUES (1,'abcdef1234567890','true','2026-08-01 00:00:00'),(3,'ghijkl9876543210','false',NULL);
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
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mail_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_ui1` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `piwigo_users`
--

LOCK TABLES `piwigo_users` WRITE;
/*!40000 ALTER TABLE `piwigo_users` DISABLE KEYS */;
INSERT INTO `piwigo_users` VALUES (1,'fixture_admin','$2y$04$lUTfwbNMEETib8rWjDGBbulUklaikKyQ.QimvMeDF0OB4SIw/u5jS','fixture_admin@example.test'),(2,'guest',NULL,NULL),(3,'regular_user','$2y$04$zfd1K1WAhOfI9NvwUUZZHO026gCQnfzsbFMSFEYX7/TVgqNxN0kne',NULL),(4,'power_user','$2y$04$8JxRbv6ItxoDGwIA880L4eLBeku4ABlKoTk0z2iYBOYmrZCPwXvwa',NULL);
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

-- Dump completed on 2026-07-13 19:13:08
