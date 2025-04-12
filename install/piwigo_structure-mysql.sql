--
-- Table structure for table activity
--

DROP TABLE IF EXISTS activity;
CREATE TABLE activity
(
    activity_id  INT(11) UNSIGNED      NOT NULL AUTO_INCREMENT,
    object       VARCHAR(255)          NOT NULL,
    object_id    INT(11) UNSIGNED      NOT NULL,
    action       VARCHAR(255)          NOT NULL,
    performed_by MEDIUMINT(8) UNSIGNED NOT NULL,
    session_idx  VARCHAR(255)          NOT NULL,
    ip_address   VARCHAR(50)  DEFAULT NULL,
    occurred_on  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    details      VARCHAR(255) DEFAULT NULL,
    user_agent   VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (activity_id)
) ENGINE = MYISAM;

--
-- Table structure for table caddie
--

DROP TABLE IF EXISTS caddie;
CREATE TABLE caddie
(
    user_id    MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    element_id MEDIUMINT(8)          NOT NULL DEFAULT '0',
    PRIMARY KEY (user_id, element_id)
) ENGINE = MYISAM;

--
-- Table structure for table categories
--

DROP TABLE IF EXISTS categories;
CREATE TABLE categories
(
    id                        SMALLINT(5) UNSIGNED       NOT NULL AUTO_INCREMENT,
    name                      VARCHAR(255)               NOT NULL DEFAULT '',
    id_uppercat               SMALLINT(5) UNSIGNED                DEFAULT NULL,
    comment                   TEXT,
    dir                       VARCHAR(255)                        DEFAULT NULL,
    sort_rank                 SMALLINT(5) UNSIGNED                DEFAULT NULL,
    status                    ENUM ('public', 'private') NOT NULL DEFAULT 'public',
    site_id                   TINYINT(4) UNSIGNED                 DEFAULT NULL,
    visible                   ENUM ('true', 'false')     NOT NULL DEFAULT 'true',
    representative_picture_id MEDIUMINT(8) UNSIGNED               DEFAULT NULL,
    uppercats                 VARCHAR(255)               NOT NULL DEFAULT '',
    commentable               ENUM ('true', 'false')     NOT NULL DEFAULT 'true',
    global_rank               VARCHAR(255)                        DEFAULT NULL,
    image_order               VARCHAR(128)                        DEFAULT NULL,
    permalink                 VARCHAR(64) BINARY                  DEFAULT NULL,
    lastmodified              TIMESTAMP                           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY categories_i2 (permalink),
    KEY categories_i3 (id_uppercat),
    KEY lastmodified (lastmodified)
) ENGINE = MYISAM;

--
-- Table structure for table comments
--

DROP TABLE IF EXISTS comments;
CREATE TABLE comments
(
    id              INT(11) UNSIGNED       NOT NULL AUTO_INCREMENT,
    image_id        MEDIUMINT(8) UNSIGNED  NOT NULL DEFAULT '0',
    date            DATETIME               NOT NULL DEFAULT '1970-01-01 00:00:00',
    author          VARCHAR(255)                    DEFAULT NULL,
    email           VARCHAR(255)                    DEFAULT NULL,
    author_id       MEDIUMINT(8) UNSIGNED           DEFAULT NULL,
    anonymous_id    VARCHAR(45)            NOT NULL,
    website_url     VARCHAR(255)                    DEFAULT NULL,
    content         LONGTEXT,
    validated       ENUM ('true', 'false') NOT NULL DEFAULT 'false',
    validation_date DATETIME                        DEFAULT NULL,
    PRIMARY KEY (id),
    KEY comments_i1 (image_id),
    KEY comments_i2 (validation_date)
) ENGINE = MYISAM;

--
-- Table structure for table config
--

DROP TABLE IF EXISTS config;
CREATE TABLE config
(
    param   VARCHAR(40) NOT NULL DEFAULT '',
    value   TEXT,
    comment VARCHAR(255)         DEFAULT NULL,
    PRIMARY KEY (param)
) ENGINE = MYISAM COMMENT = 'configuration table';

--
-- Table structure for table favorites
--

DROP TABLE IF EXISTS favorites;
CREATE TABLE favorites
(
    user_id  MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    image_id MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    PRIMARY KEY (user_id, image_id)
) ENGINE = MYISAM;

--
-- Table structure for table group_access
--

DROP TABLE IF EXISTS group_access;
CREATE TABLE group_access
(
    group_id SMALLINT(5) UNSIGNED NOT NULL DEFAULT '0',
    cat_id   SMALLINT(5) UNSIGNED NOT NULL DEFAULT '0',
    PRIMARY KEY (group_id, cat_id)
) ENGINE = MYISAM;

--
-- Table structure for table user_groups
--

DROP TABLE IF EXISTS groups;
CREATE TABLE user_groups
(
    id           SMALLINT(5) UNSIGNED   NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255)           NOT NULL DEFAULT '',
    is_default   ENUM ('true', 'false') NOT NULL DEFAULT 'false',
    lastmodified TIMESTAMP                       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY groups_ui1 (name),
    KEY lastmodified (lastmodified)
) ENGINE = MYISAM;

--
-- Table structure for table history
--

DROP TABLE IF EXISTS history;
CREATE TABLE history
(
    id          INT(10) UNSIGNED      NOT NULL AUTO_INCREMENT,
    date        DATE                  NOT NULL                                                                                         DEFAULT '1970-01-01',
    time        TIME                  NOT NULL                                                                                         DEFAULT '00:00:00',
    user_id     MEDIUMINT(8) UNSIGNED NOT NULL                                                                                         DEFAULT '0',
    IP          VARCHAR(15)           NOT NULL                                                                                         DEFAULT '',
    section     ENUM ('categories', 'tags', 'search', 'list', 'favorites', 'most_visited', 'best_rated', 'recent_pics', 'recent_cats') DEFAULT NULL,
    category_id SMALLINT(5)                                                                                                            DEFAULT NULL,
    search_id   INT(10) UNSIGNED                                                                                                       DEFAULT NULL,
    tag_ids     VARCHAR(50)                                                                                                            DEFAULT NULL,
    image_id    MEDIUMINT(8)                                                                                                           DEFAULT NULL,
    image_type  ENUM ('picture', 'high', 'other')                                                                                      DEFAULT NULL,
    format_id   INT(11) UNSIGNED                                                                                                       DEFAULT NULL,
    auth_key_id INT(11) UNSIGNED                                                                                                       DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = MYISAM;

--
-- Table structure for table history_summary
--

DROP TABLE IF EXISTS history_summary;
CREATE TABLE history_summary
(
    year            SMALLINT(4) NOT NULL DEFAULT '0',
    month           TINYINT(2)           DEFAULT NULL,
    day             TINYINT(2)           DEFAULT NULL,
    hour            TINYINT(2)           DEFAULT NULL,
    nb_pages        INT(11)              DEFAULT NULL,
    history_id_from INT(10) UNSIGNED     DEFAULT NULL,
    history_id_to   INT(10) UNSIGNED     DEFAULT NULL,
    UNIQUE KEY history_summary_ymdh (year, month, day, hour)
) ENGINE = MYISAM;

--
-- Table structure for table image_category
--

DROP TABLE IF EXISTS image_category;
CREATE TABLE image_category
(
    image_id    MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    category_id SMALLINT(5) UNSIGNED  NOT NULL DEFAULT '0',
    sort_rank   MEDIUMINT(8) UNSIGNED          DEFAULT NULL,
    PRIMARY KEY (image_id, category_id),
    KEY image_category_i1 (category_id)
) ENGINE = MYISAM;

--
-- Table structure for table image_format
--

DROP TABLE IF EXISTS image_format;
CREATE TABLE image_format
(
    format_id INT(11) UNSIGNED      NOT NULL AUTO_INCREMENT,
    image_id  MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    ext       VARCHAR(255)          NOT NULL,
    filesize  MEDIUMINT(9) UNSIGNED          DEFAULT NULL,
    PRIMARY KEY (format_id)
) ENGINE = MYISAM;

--
-- Table structure for table image_tag
--

DROP TABLE IF EXISTS image_tag;
CREATE TABLE image_tag
(
    image_id MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    tag_id   SMALLINT(5) UNSIGNED  NOT NULL DEFAULT '0',
    PRIMARY KEY (image_id, tag_id),
    KEY image_tag_i1 (tag_id)
) ENGINE = MYISAM;

--
-- Table structure for table images
--

DROP TABLE IF EXISTS images;
CREATE TABLE images
(
    id                   MEDIUMINT(8) UNSIGNED NOT NULL AUTO_INCREMENT,
    file                 VARCHAR(255) BINARY   NOT NULL DEFAULT '',
    date_available       DATETIME              NOT NULL DEFAULT '1970-01-01 00:00:00',
    date_creation        DATETIME                       DEFAULT NULL,
    name                 VARCHAR(255)                   DEFAULT NULL,
    comment              TEXT,
    author               VARCHAR(255)                   DEFAULT NULL,
    hit                  INT(10) UNSIGNED      NOT NULL DEFAULT '0',
    filesize             MEDIUMINT(9) UNSIGNED          DEFAULT NULL,
    width                SMALLINT(9) UNSIGNED           DEFAULT NULL,
    height               SMALLINT(9) UNSIGNED           DEFAULT NULL,
    coi                  CHAR(4)                        DEFAULT NULL COMMENT 'center of interest',
    representative_ext   VARCHAR(4)                     DEFAULT NULL,
    date_metadata_update DATE                           DEFAULT NULL,
    rating_score         FLOAT(5, 2) UNSIGNED           DEFAULT NULL,
    path                 VARCHAR(255)          NOT NULL DEFAULT '',
    storage_category_id  SMALLINT(5) UNSIGNED           DEFAULT NULL,
    level                TINYINT UNSIGNED      NOT NULL DEFAULT '0',
    md5sum               CHAR(32)                       DEFAULT NULL,
    added_by             MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    rotation             TINYINT UNSIGNED               DEFAULT NULL,
    latitude             DOUBLE(8, 6)                   DEFAULT NULL,
    longitude            DOUBLE(9, 6)                   DEFAULT NULL,
    lastmodified         TIMESTAMP                      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY images_i1 (storage_category_id),
    KEY images_i2 (date_available),
    KEY images_i3 (rating_score),
    KEY images_i4 (hit),
    KEY images_i5 (date_creation),
    KEY images_i6 (latitude),
    KEY images_i7 (path),
    KEY lastmodified (lastmodified)
) ENGINE = MYISAM;

--
-- Table structure for table languages
--

DROP TABLE IF EXISTS languages;
CREATE TABLE languages
(
    id      VARCHAR(64) NOT NULL DEFAULT '',
    version VARCHAR(64) NOT NULL DEFAULT '0',
    name    VARCHAR(64)          DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = MYISAM;

--
-- Table structure for table lounge
--

DROP TABLE IF EXISTS lounge;
CREATE TABLE lounge
(
    image_id    MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    category_id SMALLINT(5) UNSIGNED  NOT NULL DEFAULT '0',
    PRIMARY KEY (image_id, category_id)
) ENGINE = MYISAM;

--
-- Table structure for table old_permalinks
--

DROP TABLE IF EXISTS old_permalinks;
CREATE TABLE old_permalinks
(
    cat_id       SMALLINT(5) UNSIGNED NOT NULL DEFAULT '0',
    permalink    VARCHAR(64) BINARY   NOT NULL DEFAULT '',
    date_deleted DATETIME             NOT NULL DEFAULT '1970-01-01 00:00:00',
    last_hit     DATETIME                      DEFAULT NULL,
    hit          INT(10) UNSIGNED     NOT NULL DEFAULT '0',
    PRIMARY KEY (permalink)
) ENGINE = MYISAM;

--
-- Table structure for table plugins
--

DROP TABLE IF EXISTS plugins;
CREATE TABLE plugins
(
    id      VARCHAR(64) BINARY          NOT NULL DEFAULT '',
    state   ENUM ('inactive', 'active') NOT NULL DEFAULT 'inactive',
    version VARCHAR(64)                 NOT NULL DEFAULT '0',
    PRIMARY KEY (id)
) ENGINE = MYISAM;

--
-- Table structure for table rate
--

DROP TABLE IF EXISTS rate;
CREATE TABLE rate
(
    user_id      MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    element_id   MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    anonymous_id VARCHAR(45)           NOT NULL DEFAULT '',
    rate         TINYINT(2) UNSIGNED   NOT NULL DEFAULT '0',
    date         DATE                  NOT NULL DEFAULT '1970-01-01',
    PRIMARY KEY (element_id, user_id, anonymous_id)
) ENGINE = MYISAM;

--
-- Table structure for table search
--

DROP TABLE IF EXISTS search;
CREATE TABLE search
(
    id          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    search_uuid CHAR(23) DEFAULT NULL,
    created_on  DATETIME DEFAULT NULL,
    created_by  MEDIUMINT(8) UNSIGNED,
    forked_from INT(10) UNSIGNED,
    rules       TEXT,
    PRIMARY KEY (id)
) ENGINE = MYISAM;

--
-- Table structure for table sessions
--

DROP TABLE IF EXISTS sessions;
CREATE TABLE sessions
(
    id         VARCHAR(255) BINARY NOT NULL DEFAULT '',
    data       MEDIUMTEXT          NOT NULL,
    expiration DATETIME            NOT NULL DEFAULT '1970-01-01 00:00:00',
    PRIMARY KEY (id)
) ENGINE = MYISAM;

--
-- Table structure for table sites
--

DROP TABLE IF EXISTS sites;
CREATE TABLE sites
(
    id            TINYINT(4)   NOT NULL AUTO_INCREMENT,
    galleries_url VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY sites_ui1 (galleries_url)
) ENGINE = MYISAM;

--
-- Table structure for table tags
--

DROP TABLE IF EXISTS tags;
CREATE TABLE tags
(
    id           SMALLINT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255)         NOT NULL DEFAULT '',
    url_name     VARCHAR(255) BINARY  NOT NULL DEFAULT '',
    lastmodified TIMESTAMP                     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY tags_i1 (url_name),
    KEY lastmodified (lastmodified)
) ENGINE = MYISAM;

--
-- Table structure for table themes
--

DROP TABLE IF EXISTS themes;
CREATE TABLE themes
(
    id      VARCHAR(64) NOT NULL DEFAULT '',
    version VARCHAR(64) NOT NULL DEFAULT '0',
    name    VARCHAR(64)          DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = MYISAM;

--
-- Table structure for table upgrade
--

DROP TABLE IF EXISTS upgrade;
CREATE TABLE upgrade
(
    id          VARCHAR(20) NOT NULL DEFAULT '',
    applied     DATETIME    NOT NULL DEFAULT '1970-01-01 00:00:00',
    description VARCHAR(255)         DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = MYISAM;

--
-- Table structure for table user_access
--

DROP TABLE IF EXISTS user_access;
CREATE TABLE user_access
(
    user_id MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    cat_id  SMALLINT(5) UNSIGNED  NOT NULL DEFAULT '0',
    PRIMARY KEY (user_id, cat_id)
) ENGINE = MYISAM;

--
-- Table structure for table user_auth_keys
--

DROP TABLE IF EXISTS user_auth_keys;
CREATE TABLE user_auth_keys
(
    auth_key_id INT(11) UNSIGNED      NOT NULL AUTO_INCREMENT,
    auth_key    VARCHAR(255)          NOT NULL,
    user_id     MEDIUMINT(8) UNSIGNED NOT NULL,
    created_on  DATETIME              NOT NULL,
    duration    INT(11) UNSIGNED DEFAULT NULL,
    expired_on  DATETIME              NOT NULL,
    PRIMARY KEY (auth_key_id)
) ENGINE = MYISAM;

--
-- Table structure for table user_cache
--

DROP TABLE IF EXISTS user_cache;
CREATE TABLE user_cache
(
    user_id               MEDIUMINT(8) UNSIGNED  NOT NULL DEFAULT '0',
    need_update           ENUM ('true', 'false') NOT NULL DEFAULT 'true',
    cache_update_time     INTEGER UNSIGNED       NOT NULL DEFAULT 0,
    forbidden_categories  MEDIUMTEXT,
    nb_total_images       MEDIUMINT(8) UNSIGNED           DEFAULT NULL,
    last_photo_date       DATETIME                        DEFAULT NULL,
    nb_available_tags     INT(5)                          DEFAULT NULL,
    nb_available_comments INT(5)                          DEFAULT NULL,
    image_access_type     ENUM ('NOT IN', 'IN')  NOT NULL DEFAULT 'NOT IN',
    image_access_list     MEDIUMTEXT                      DEFAULT NULL,
    PRIMARY KEY (user_id)
) ENGINE = MYISAM;

--
-- Table structure for table user_cache_categories
--

DROP TABLE IF EXISTS user_cache_categories;
CREATE TABLE user_cache_categories
(
    user_id                        MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    cat_id                         SMALLINT(5) UNSIGNED  NOT NULL DEFAULT '0',
    date_last                      DATETIME                       DEFAULT NULL,
    max_date_last                  DATETIME                       DEFAULT NULL,
    nb_images                      MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    count_images                   MEDIUMINT(8) UNSIGNED          DEFAULT '0',
    nb_categories                  MEDIUMINT(8) UNSIGNED          DEFAULT '0',
    count_categories               MEDIUMINT(8) UNSIGNED          DEFAULT '0',
    user_representative_picture_id MEDIUMINT(8) UNSIGNED          DEFAULT NULL,
    PRIMARY KEY (user_id, cat_id)
) ENGINE = MYISAM;

--
-- Table structure for table user_feed
--

DROP TABLE IF EXISTS user_feed;
CREATE TABLE user_feed
(
    id         VARCHAR(50) BINARY    NOT NULL DEFAULT '',
    user_id    MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    last_check DATETIME                       DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = MYISAM;

--
-- Table structure for table user_group
--

DROP TABLE IF EXISTS user_group;
CREATE TABLE user_group
(
    user_id  MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT '0',
    group_id SMALLINT(5) UNSIGNED  NOT NULL DEFAULT '0',
    PRIMARY KEY (group_id, user_id)
) ENGINE = MYISAM;

--
-- Table structure for table user_infos
--

DROP TABLE IF EXISTS user_infos;
CREATE TABLE user_infos
(
    user_id                 MEDIUMINT(8) UNSIGNED                                     NOT NULL DEFAULT '0',
    nb_image_page           SMALLINT(3) UNSIGNED                                      NOT NULL DEFAULT '15',
    status                  ENUM ('webmaster', 'admin', 'normal', 'generic', 'guest') NOT NULL DEFAULT 'guest',
    language                VARCHAR(50)                                               NOT NULL DEFAULT 'en_UK',
    expand                  ENUM ('true', 'false')                                    NOT NULL DEFAULT 'false',
    show_nb_comments        ENUM ('true', 'false')                                    NOT NULL DEFAULT 'false',
    show_nb_hits            ENUM ('true', 'false')                                    NOT NULL DEFAULT 'false',
    recent_period           TINYINT(3) UNSIGNED                                       NOT NULL DEFAULT '7',
    theme                   VARCHAR(255)                                              NOT NULL DEFAULT 'modus',
    registration_date       DATETIME                                                  NOT NULL DEFAULT '1970-01-01 00:00:00',
    enabled_high            ENUM ('true', 'false')                                    NOT NULL DEFAULT 'true',
    level                   TINYINT UNSIGNED                                          NOT NULL DEFAULT '0',
    activation_key          VARCHAR(255)                                                       DEFAULT NULL,
    activation_key_expire   DATETIME                                                           DEFAULT NULL,
    last_visit              DATETIME                                                           DEFAULT NULL,
    last_visit_from_history ENUM ('true', 'false')                                    NOT NULL DEFAULT 'false',
    lastmodified            TIMESTAMP                                                          DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    preferences             TEXT                                                               DEFAULT NULL,
    PRIMARY KEY (user_id),
    KEY lastmodified (lastmodified)
) ENGINE = MYISAM;

--
-- Table structure for table user_mail_notification
--

DROP TABLE IF EXISTS user_mail_notification;
CREATE TABLE user_mail_notification
(
    user_id   MEDIUMINT(8) UNSIGNED  NOT NULL DEFAULT '0',
    check_key VARCHAR(16) BINARY     NOT NULL DEFAULT '',
    enabled   ENUM ('true', 'false') NOT NULL DEFAULT 'false',
    last_send DATETIME                        DEFAULT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY user_mail_notification_ui1 (check_key)
) ENGINE = MYISAM;

--
-- Table structure for table users
--

DROP TABLE IF EXISTS users;
CREATE TABLE users
(
    id           MEDIUMINT(8) UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(100) BINARY   NOT NULL DEFAULT '',
    password     VARCHAR(255)                   DEFAULT NULL,
    mail_address VARCHAR(255)                   DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY users_ui1 (username)
) ENGINE = MYISAM;
