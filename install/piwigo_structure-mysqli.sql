--
-- Table structure for table activity
--

CREATE TABLE activity
(
    activity_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    object       VARCHAR(255) NOT NULL,
    object_id    INT UNSIGNED NOT NULL,
    action       VARCHAR(255) NOT NULL,
    performed_by INT UNSIGNED NOT NULL,
    session_idx  VARCHAR(255) NOT NULL,
    ip_address   VARCHAR(50)  DEFAULT NULL,
    occurred_on  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    details      VARCHAR(255) DEFAULT NULL,
    user_agent   VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (activity_id)
) ENGINE = InnoDB;

--
-- Table structure for table caddie
--

CREATE TABLE caddie
(
    user_id    INT UNSIGNED NOT NULL,
    element_id INT          NOT NULL,
    PRIMARY KEY (user_id, element_id)
) ENGINE = InnoDB;

--
-- Table structure for table categories
--

CREATE TABLE categories
(
    id                        INT UNSIGNED               NOT NULL AUTO_INCREMENT,
    name                      VARCHAR(255)               NOT NULL,
    id_uppercat               INT UNSIGNED                        DEFAULT NULL,
    comment                   TEXT,
    dir                       VARCHAR(255)                        DEFAULT NULL,
    sort_rank                 INT UNSIGNED                        DEFAULT NULL,
    status                    ENUM ('public', 'private') NOT NULL DEFAULT 'public',
    site_id                   INT UNSIGNED                        DEFAULT NULL,
    visible                   ENUM ('true', 'false')     NOT NULL DEFAULT 'true',
    representative_picture_id INT UNSIGNED                        DEFAULT NULL,
    uppercats                 VARCHAR(255),
    commentable               ENUM ('true', 'false')     NOT NULL DEFAULT 'true',
    global_rank               VARCHAR(255)                        DEFAULT NULL,
    image_order               VARCHAR(128)                        DEFAULT NULL,
    permalink                 VARCHAR(64)                         DEFAULT NULL,
    lastmodified              TIMESTAMP                           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY categories_i2 (permalink),
    KEY categories_i3 (id_uppercat),
    KEY lastmodified (lastmodified),
    FULLTEXT KEY category_ft (name, comment)
) ENGINE = InnoDB;

--
-- Table structure for table comments
--

CREATE TABLE comments
(
    id              INT UNSIGNED           NOT NULL AUTO_INCREMENT,
    image_id        INT UNSIGNED           NOT NULL,
    date            TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    author          VARCHAR(255)                    DEFAULT NULL,
    email           VARCHAR(255)                    DEFAULT NULL,
    author_id       INT UNSIGNED                    DEFAULT NULL,
    anonymous_id    VARCHAR(45)            NOT NULL,
    website_url     VARCHAR(255)                    DEFAULT NULL,
    content         LONGTEXT,
    validated       ENUM ('true', 'false') NOT NULL DEFAULT 'false',
    validation_date TIMESTAMP                       DEFAULT NULL,
    PRIMARY KEY (id),
    KEY comments_i1 (image_id),
    KEY comments_i2 (validation_date)
) ENGINE = InnoDB;

--
-- Table structure for table config
--

CREATE TABLE config
(
    param   VARCHAR(40) NOT NULL,
    value   TEXT,
    comment VARCHAR(255)         DEFAULT NULL,
    PRIMARY KEY (param)
) ENGINE = InnoDB;

--
-- Table structure for table favorites
--

CREATE TABLE favorites
(
    user_id  INT UNSIGNED NOT NULL,
    image_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, image_id)
) ENGINE = InnoDB;

--
-- Table structure for table group_access
--

CREATE TABLE group_access
(
    group_id INT UNSIGNED NOT NULL,
    cat_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, cat_id)
) ENGINE = InnoDB;

--
-- Table structure for table user_groups
--

CREATE TABLE user_groups
(
    id           INT UNSIGNED           NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255)           NOT NULL,
    is_default   ENUM ('true', 'false') NOT NULL DEFAULT 'false',
    lastmodified TIMESTAMP                       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY groups_ui1 (name),
    KEY lastmodified (lastmodified)
) ENGINE = InnoDB;

--
-- Table structure for table history
--

CREATE TABLE history
(
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    date        DATE         NOT NULL,
    time        TIME         NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    IP          VARCHAR(45)  NOT NULL,
    section     ENUM ('categories', 'tags', 'search', 'list', 'favorites', 'most_visited', 'best_rated', 'recent_pics', 'recent_cats') DEFAULT NULL,
    category_id INT                                                                                                                    DEFAULT NULL,
    search_id   INT UNSIGNED                                                                                                           DEFAULT NULL,
    tag_ids     VARCHAR(50)                                                                                                            DEFAULT NULL,
    image_id    INT                                                                                                                    DEFAULT NULL,
    image_type  ENUM ('picture', 'high', 'other')                                                                                      DEFAULT NULL,
    format_id   INT UNSIGNED                                                                                                           DEFAULT NULL,
    auth_key_id INT UNSIGNED                                                                                                           DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

--
-- Table structure for table history_summary
--

CREATE TABLE history_summary
(
    year            INT NOT NULL,
    month           INT          DEFAULT NULL,
    day             INT          DEFAULT NULL,
    hour            INT          DEFAULT NULL,
    nb_pages        INT          DEFAULT NULL,
    history_id_from INT UNSIGNED DEFAULT NULL,
    history_id_to   INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY history_summary_ymdh (year, month, day, hour)
) ENGINE = InnoDB;

--
-- Table structure for table image_category
--

CREATE TABLE image_category
(
    image_id    INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    sort_rank   INT UNSIGNED          DEFAULT NULL,
    PRIMARY KEY (image_id, category_id),
    KEY image_category_i1 (category_id)
) ENGINE = InnoDB;

--
-- Table structure for table image_format
--

CREATE TABLE image_format
(
    format_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    image_id  INT UNSIGNED NOT NULL,
    ext       VARCHAR(255) NOT NULL,
    filesize  INT UNSIGNED          DEFAULT NULL,
    PRIMARY KEY (format_id)
) ENGINE = InnoDB;

--
-- Table structure for table image_tag
--

CREATE TABLE image_tag
(
    image_id INT UNSIGNED NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (image_id, tag_id),
    KEY image_tag_i1 (tag_id)
) ENGINE = InnoDB;

--
-- Table structure for table images
--

CREATE TABLE images
(
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    file                 VARCHAR(255) NOT NULL,
    date_available       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_creation        TIMESTAMP             DEFAULT NULL,
    name                 VARCHAR(255)          DEFAULT NULL,
    comment              TEXT,
    author               VARCHAR(255)          DEFAULT NULL,
    hit                  INT UNSIGNED NOT NULL DEFAULT 0,
    filesize             INT UNSIGNED          DEFAULT NULL,
    width                INT UNSIGNED          DEFAULT NULL,
    height               INT UNSIGNED          DEFAULT NULL,
    coi                  CHAR(4)               DEFAULT NULL COMMENT 'center of interest',
    representative_ext   VARCHAR(4)            DEFAULT NULL,
    date_metadata_update DATE                  DEFAULT NULL,
    rating_score         FLOAT                 DEFAULT NULL,
    path                 VARCHAR(600) NOT NULL,
    storage_category_id  INT UNSIGNED          DEFAULT NULL,
    level                INT UNSIGNED NOT NULL DEFAULT 0,
    md5sum               CHAR(32)              DEFAULT NULL,
    added_by             INT UNSIGNED NOT NULL,
    rotation             INT UNSIGNED          DEFAULT NULL,
    latitude             DOUBLE                DEFAULT NULL,
    longitude            DOUBLE                DEFAULT NULL,
    lastmodified         TIMESTAMP             DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY images_i1 (storage_category_id),
    KEY images_i2 (date_available),
    KEY images_i3 (rating_score),
    KEY images_i4 (hit),
    KEY images_i5 (date_creation),
    KEY images_i6 (latitude),
    KEY images_i7 (path),
    KEY lastmodified (lastmodified),
    FULLTEXT KEY image_ft (name, comment)
) ENGINE = InnoDB;

--
-- Table structure for table languages
--

CREATE TABLE languages
(
    id      VARCHAR(64) NOT NULL,
    version VARCHAR(64) NOT NULL,
    name    VARCHAR(64)          DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

--
-- Table structure for table lounge
--

CREATE TABLE lounge
(
    image_id    INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (image_id, category_id)
) ENGINE = InnoDB;

--
-- Table structure for table old_permalinks
--

CREATE TABLE old_permalinks
(
    cat_id       INT UNSIGNED NOT NULL,
    permalink    VARCHAR(64)  NOT NULL,
    date_deleted TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_hit     TIMESTAMP             DEFAULT NULL,
    hit          INT UNSIGNED NOT NULL,
    PRIMARY KEY (permalink)
) ENGINE = InnoDB;

--
-- Table structure for table plugins
--

CREATE TABLE plugins
(
    id      VARCHAR(64)                 NOT NULL,
    state   ENUM ('inactive', 'active') NOT NULL DEFAULT 'inactive',
    version VARCHAR(64)                 NOT NULL,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

--
-- Table structure for table rate
--

CREATE TABLE rate
(
    user_id      INT UNSIGNED NOT NULL,
    element_id   INT UNSIGNED NOT NULL,
    anonymous_id VARCHAR(45)  NOT NULL,
    rate         INT UNSIGNED NOT NULL,
    date         DATE         NOT NULL,
    PRIMARY KEY (element_id, user_id, anonymous_id)
) ENGINE = InnoDB;

--
-- Table structure for table search
--

CREATE TABLE search
(
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    search_uuid CHAR(23)  DEFAULT NULL,
    created_on  TIMESTAMP DEFAULT NULL,
    created_by  INT UNSIGNED,
    forked_from INT UNSIGNED,
    rules       TEXT,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

--
-- Table structure for table sessions
--

CREATE TABLE sessions
(
    id         VARCHAR(255) NOT NULL,
    data       MEDIUMTEXT   NOT NULL,
    expiration TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

--
-- Table structure for table sites
--

CREATE TABLE sites
(
    id            INT          NOT NULL AUTO_INCREMENT,
    galleries_url VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY sites_ui1 (galleries_url)
) ENGINE = InnoDB;

--
-- Table structure for table tags
--

CREATE TABLE tags
(
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255) NOT NULL,
    url_name     VARCHAR(255) NOT NULL,
    lastmodified TIMESTAMP             DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY tags_i1 (url_name),
    KEY lastmodified (lastmodified),
    FULLTEXT KEY tag_name_ft (name)
) ENGINE = InnoDB;

--
-- Table structure for table themes
--

CREATE TABLE themes
(
    id      VARCHAR(64) NOT NULL,
    version VARCHAR(64) NOT NULL,
    name    VARCHAR(64)          DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

--
-- Table structure for table upgrade
--

CREATE TABLE upgrade
(
    id          VARCHAR(20) NOT NULL,
    applied     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255)         DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

--
-- Table structure for table user_access
--

CREATE TABLE user_access
(
    user_id INT UNSIGNED NOT NULL,
    cat_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, cat_id)
) ENGINE = InnoDB;

--
-- Table structure for table user_auth_keys
--

CREATE TABLE user_auth_keys
(
    auth_key_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    auth_key    VARCHAR(255) NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    created_on  TIMESTAMP    NOT NULL,
    duration    INT UNSIGNED DEFAULT NULL,
    expired_on  TIMESTAMP    NOT NULL,
    PRIMARY KEY (auth_key_id)
) ENGINE = InnoDB;

--
-- Table structure for table user_cache
--

CREATE TABLE user_cache
(
    user_id               INT UNSIGNED           NOT NULL,
    need_update           ENUM ('true', 'false') NOT NULL DEFAULT 'true',
    cache_update_time     INT UNSIGNED           NOT NULL,
    forbidden_categories  MEDIUMTEXT,
    nb_total_images       INT UNSIGNED                    DEFAULT NULL,
    last_photo_date       TIMESTAMP                       DEFAULT NULL,
    nb_available_tags     INT                             DEFAULT NULL,
    nb_available_comments INT                             DEFAULT NULL,
    image_access_type     ENUM ('NOT IN', 'IN')  NOT NULL DEFAULT 'NOT IN',
    image_access_list     MEDIUMTEXT                      DEFAULT NULL,
    PRIMARY KEY (user_id)
) ENGINE = InnoDB;

--
-- Table structure for table user_cache_categories
--

CREATE TABLE user_cache_categories
(
    user_id                        INT UNSIGNED NOT NULL,
    cat_id                         INT UNSIGNED NOT NULL,
    date_last                      TIMESTAMP             DEFAULT NULL,
    max_date_last                  TIMESTAMP             DEFAULT NULL,
    nb_images                      INT UNSIGNED NOT NULL,
    count_images                   INT UNSIGNED          DEFAULT 0,
    nb_categories                  INT UNSIGNED          DEFAULT 0,
    count_categories               INT UNSIGNED          DEFAULT 0,
    user_representative_picture_id INT UNSIGNED          DEFAULT NULL,
    PRIMARY KEY (user_id, cat_id)
) ENGINE = InnoDB;

--
-- Table structure for table user_feed
--

CREATE TABLE user_feed
(
    id         VARCHAR(50)  NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    last_check TIMESTAMP             DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE = InnoDB;

--
-- Table structure for table user_group
--

CREATE TABLE user_group
(
    user_id  INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, user_id)
) ENGINE = InnoDB;

--
-- Table structure for table user_infos
--

CREATE TABLE user_infos
(
    user_id                 INT UNSIGNED                                              NOT NULL,
    nb_image_page           INT UNSIGNED                                              NOT NULL DEFAULT 80,
    status                  ENUM ('webmaster', 'admin', 'normal', 'generic', 'guest') NOT NULL DEFAULT 'guest',
    language                VARCHAR(50)                                               NOT NULL,
    expand                  ENUM ('true', 'false')                                    NOT NULL DEFAULT 'false',
    show_nb_comments        ENUM ('true', 'false')                                    NOT NULL DEFAULT 'false',
    show_nb_hits            ENUM ('true', 'false')                                    NOT NULL DEFAULT 'false',
    recent_period           INT UNSIGNED                                              NOT NULL DEFAULT 7,
    theme                   VARCHAR(255)                                              NOT NULL DEFAULT 'modus',
    registration_date       TIMESTAMP                                                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enabled_high            ENUM ('true', 'false')                                    NOT NULL DEFAULT 'true',
    level                   INT UNSIGNED                                              NOT NULL,
    activation_key          VARCHAR(255)                                                       DEFAULT NULL,
    activation_key_expire   TIMESTAMP                                                          DEFAULT NULL,
    last_visit              TIMESTAMP                                                          DEFAULT NULL,
    last_visit_from_history ENUM ('true', 'false')                                    NOT NULL DEFAULT 'false',
    lastmodified            TIMESTAMP                                                          DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    preferences             TEXT                                                               DEFAULT NULL,
    PRIMARY KEY (user_id),
    KEY lastmodified (lastmodified)
) ENGINE = InnoDB;

--
-- Table structure for table user_mail_notification
--

CREATE TABLE user_mail_notification
(
    user_id   INT UNSIGNED           NOT NULL,
    check_key VARCHAR(16)            NOT NULL,
    enabled   ENUM ('true', 'false') NOT NULL DEFAULT 'false',
    last_send TIMESTAMP                       DEFAULT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY user_mail_notification_ui1 (check_key)
) ENGINE = InnoDB;

--
-- Table structure for table users
--

CREATE TABLE users
(
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(100) NOT NULL,
    password     VARCHAR(255)          DEFAULT NULL,
    mail_address VARCHAR(255)          DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY users_ui1 (username)
) ENGINE = InnoDB;
