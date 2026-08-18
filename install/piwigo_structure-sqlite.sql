-- GENERATED FILE -- do not hand-edit. Regenerate with `bin/piwigo schema:dump` after migrating a blank database to the latest version.
-- Source of truth is src/Piwigo/Migrations/ -- this snapshot is a human-
-- reviewable reference and CI drift guard only; InstallWizard no longer
-- reads this file to create a schema.

CREATE TABLE caddie (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, element_id INTEGER NOT NULL REFERENCES images(id) ON DELETE CASCADE, PRIMARY KEY (user_id, element_id));

CREATE INDEX caddie_element_id_idx ON caddie (element_id);

CREATE TABLE categories (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT '', id_uppercat INTEGER DEFAULT NULL REFERENCES categories(id) ON DELETE SET NULL, comment TEXT DEFAULT NULL, dir VARCHAR(255) DEFAULT NULL, rank INTEGER DEFAULT NULL, status TEXT NOT NULL DEFAULT 'public' CHECK (status IN ('public', 'private')), site_id SMALLINT DEFAULT NULL REFERENCES sites(id) ON DELETE CASCADE, visible INTEGER NOT NULL DEFAULT 1, representative_picture_id INTEGER DEFAULT NULL REFERENCES images(id) ON DELETE SET NULL, uppercats VARCHAR(255) NOT NULL DEFAULT '', commentable INTEGER NOT NULL DEFAULT 1, global_rank VARCHAR(255) DEFAULT NULL, image_order VARCHAR(128) DEFAULT NULL, permalink VARCHAR(64) UNIQUE DEFAULT NULL, lastmodified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);

CREATE INDEX categories_i2 ON categories (id_uppercat);

CREATE INDEX categories_lastmodified_idx ON categories (lastmodified);

CREATE INDEX categories_representative_picture_id_idx ON categories (representative_picture_id);

CREATE INDEX categories_site_id_idx ON categories (site_id);

CREATE VIRTUAL TABLE categories_fts USING fts5(name, comment, content='categories', content_rowid='id', tokenize='trigram');

CREATE TRIGGER categories_fts_ai AFTER INSERT ON categories BEGIN INSERT INTO categories_fts(rowid, name, comment) VALUES (new.id, new.name, new.comment); END;

CREATE TRIGGER categories_fts_ad AFTER DELETE ON categories BEGIN INSERT INTO categories_fts(categories_fts, rowid, name, comment) VALUES('delete', old.id, old.name, old.comment); END;

CREATE TRIGGER categories_fts_au AFTER UPDATE ON categories BEGIN INSERT INTO categories_fts(categories_fts, rowid, name, comment) VALUES('delete', old.id, old.name, old.comment); INSERT INTO categories_fts(rowid, name, comment) VALUES (new.id, new.name, new.comment); END;

CREATE TABLE comments (id INTEGER PRIMARY KEY, image_id INTEGER NOT NULL REFERENCES images(id) ON DELETE CASCADE, date DATETIME DEFAULT NULL, author VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, author_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL, anonymous_id VARCHAR(45) NOT NULL, website_url VARCHAR(255) DEFAULT NULL, content TEXT DEFAULT NULL, validated INTEGER NOT NULL DEFAULT 0, validation_date DATETIME DEFAULT NULL);

CREATE INDEX comments_i2 ON comments (validation_date);

CREATE INDEX comments_i1 ON comments (image_id);

CREATE INDEX comments_author_id_idx ON comments (author_id);

CREATE TABLE favorites (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, image_id INTEGER NOT NULL REFERENCES images(id) ON DELETE CASCADE, PRIMARY KEY (user_id, image_id));

CREATE INDEX favorites_image_id_idx ON favorites (image_id);

CREATE TABLE image_category (image_id INTEGER NOT NULL REFERENCES images(id) ON DELETE CASCADE, category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE, rank INTEGER DEFAULT NULL, PRIMARY KEY (image_id, category_id));

CREATE INDEX image_category_i1 ON image_category (category_id);

CREATE TABLE image_format (format_id INTEGER PRIMARY KEY, image_id INTEGER NOT NULL REFERENCES images(id) ON DELETE CASCADE, ext VARCHAR(255) NOT NULL, filesize INTEGER DEFAULT NULL);

CREATE INDEX image_format_image_id_idx ON image_format (image_id);

CREATE TABLE image_tag (image_id INTEGER NOT NULL REFERENCES images(id) ON DELETE CASCADE, tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE, PRIMARY KEY (image_id, tag_id));

CREATE INDEX image_tag_i1 ON image_tag (tag_id);

CREATE TABLE images (id INTEGER PRIMARY KEY, file VARCHAR(255) NOT NULL DEFAULT '', date_available DATETIME DEFAULT NULL, date_creation DATETIME DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, comment TEXT DEFAULT NULL, author VARCHAR(255) DEFAULT NULL, hit INTEGER NOT NULL DEFAULT 0, filesize INTEGER DEFAULT NULL, width INTEGER DEFAULT NULL, height INTEGER DEFAULT NULL, coi CHAR(4) DEFAULT NULL, representative_ext VARCHAR(4) DEFAULT NULL, date_metadata_update DATE DEFAULT NULL, rating_score REAL DEFAULT NULL, path VARCHAR(255) NOT NULL DEFAULT '', storage_category_id INTEGER DEFAULT NULL REFERENCES categories(id) ON DELETE SET NULL, level SMALLINT NOT NULL DEFAULT 0, md5sum CHAR(32) DEFAULT NULL, added_by INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL, rotation SMALLINT DEFAULT NULL, latitude REAL DEFAULT NULL, longitude REAL DEFAULT NULL, lastmodified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);

CREATE INDEX images_i2 ON images (date_available);

CREATE INDEX images_i3 ON images (rating_score);

CREATE INDEX images_i4 ON images (hit);

CREATE INDEX images_i5 ON images (date_creation);

CREATE INDEX images_i1 ON images (storage_category_id);

CREATE INDEX images_i6 ON images (latitude);

CREATE INDEX images_i7 ON images (path);

CREATE INDEX images_i8 ON images (md5sum);

CREATE INDEX images_i9 ON images (file);

CREATE INDEX images_lastmodified_idx ON images (lastmodified);

CREATE INDEX idx_images_date_desc ON images (date_available DESC, id DESC);

CREATE INDEX images_added_by_idx ON images (added_by);

CREATE VIRTUAL TABLE images_fts USING fts5(name, comment, content='images', content_rowid='id', tokenize='trigram');

CREATE TRIGGER images_fts_ai AFTER INSERT ON images BEGIN INSERT INTO images_fts(rowid, name, comment) VALUES (new.id, new.name, new.comment); END;

CREATE TRIGGER images_fts_ad AFTER DELETE ON images BEGIN INSERT INTO images_fts(images_fts, rowid, name, comment) VALUES('delete', old.id, old.name, old.comment); END;

CREATE TRIGGER images_fts_au AFTER UPDATE ON images BEGIN INSERT INTO images_fts(images_fts, rowid, name, comment) VALUES('delete', old.id, old.name, old.comment); INSERT INTO images_fts(rowid, name, comment) VALUES (new.id, new.name, new.comment); END;

CREATE VIRTUAL TABLE images_fts_author USING fts5(author, content='images', content_rowid='id', tokenize='trigram');

CREATE TRIGGER images_fts_author_ai AFTER INSERT ON images BEGIN INSERT INTO images_fts_author(rowid, author) VALUES (new.id, new.author); END;

CREATE TRIGGER images_fts_author_ad AFTER DELETE ON images BEGIN INSERT INTO images_fts_author(images_fts_author, rowid, author) VALUES('delete', old.id, old.author); END;

CREATE TRIGGER images_fts_author_au AFTER UPDATE ON images BEGIN INSERT INTO images_fts_author(images_fts_author, rowid, author) VALUES('delete', old.id, old.author); INSERT INTO images_fts_author(rowid, author) VALUES (new.id, new.author); END;

CREATE TABLE lounge (image_id INTEGER NOT NULL REFERENCES images(id) ON DELETE CASCADE, category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE, PRIMARY KEY (image_id, category_id));

CREATE INDEX lounge_category_id_idx ON lounge (category_id);

CREATE TABLE old_permalinks (cat_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE, permalink VARCHAR(64) NOT NULL DEFAULT '', date_deleted DATETIME DEFAULT NULL, last_hit DATETIME DEFAULT NULL, hit INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (permalink));

CREATE INDEX old_permalinks_cat_id_idx ON old_permalinks (cat_id);

CREATE TABLE rate (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, element_id INTEGER NOT NULL REFERENCES images(id) ON DELETE CASCADE, anonymous_id VARCHAR(45) NOT NULL DEFAULT '', rate SMALLINT NOT NULL DEFAULT 0, date DATE DEFAULT NULL, PRIMARY KEY (element_id, user_id, anonymous_id));

CREATE INDEX rate_user_id_idx ON rate (user_id);

CREATE TABLE tags (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT '', url_name VARCHAR(255) NOT NULL DEFAULT '', lastmodified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);

CREATE INDEX tags_i1 ON tags (url_name);

CREATE INDEX tags_lastmodified_idx ON tags (lastmodified);

CREATE VIRTUAL TABLE tags_fts USING fts5(name, content='tags', content_rowid='id', tokenize='trigram');

CREATE TRIGGER tags_fts_ai AFTER INSERT ON tags BEGIN INSERT INTO tags_fts(rowid, name) VALUES (new.id, new.name); END;

CREATE TRIGGER tags_fts_ad AFTER DELETE ON tags BEGIN INSERT INTO tags_fts(tags_fts, rowid, name) VALUES('delete', old.id, old.name); END;

CREATE TRIGGER tags_fts_au AFTER UPDATE ON tags BEGIN INSERT INTO tags_fts(tags_fts, rowid, name) VALUES('delete', old.id, old.name); INSERT INTO tags_fts(rowid, name) VALUES (new.id, new.name); END;

CREATE TABLE group_access (group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE, cat_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE, PRIMARY KEY (group_id, cat_id));

CREATE INDEX group_access_cat_id_idx ON group_access (cat_id);

CREATE TABLE groups (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT '' UNIQUE, is_default INTEGER NOT NULL DEFAULT 0, lastmodified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);

CREATE INDEX groups_lastmodified_idx ON groups (lastmodified);

CREATE TABLE sessions (id VARCHAR(50) NOT NULL DEFAULT '', data TEXT NOT NULL, expiration DATETIME DEFAULT NULL, PRIMARY KEY (id));

CREATE TABLE user_access (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, cat_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE, PRIMARY KEY (user_id, cat_id));

CREATE INDEX user_access_cat_id_idx ON user_access (cat_id);

CREATE TABLE user_auth_keys (auth_key_id INTEGER PRIMARY KEY, auth_key VARCHAR(255) NOT NULL, apikey_secret VARCHAR(255) DEFAULT NULL, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, created_on DATETIME NOT NULL, duration INTEGER DEFAULT NULL, expired_on DATETIME NOT NULL, apikey_name VARCHAR(100) DEFAULT NULL, key_type VARCHAR(40) DEFAULT NULL, revoked_on DATETIME DEFAULT NULL, last_used_on DATETIME DEFAULT NULL, last_notified_on DATETIME DEFAULT NULL);

CREATE INDEX user_auth_keys_user_id_idx ON user_auth_keys (user_id);

CREATE TABLE user_feed (id VARCHAR(50) NOT NULL DEFAULT '', user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, last_check DATETIME DEFAULT NULL, PRIMARY KEY (id));

CREATE INDEX user_feed_user_id_idx ON user_feed (user_id);

CREATE TABLE user_group (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE, PRIMARY KEY (group_id, user_id));

CREATE INDEX user_group_user_id_idx ON user_group (user_id);

CREATE TABLE user_infos (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, nb_image_page INTEGER NOT NULL DEFAULT 15, status TEXT NOT NULL DEFAULT 'guest' CHECK (status IN ('webmaster', 'admin', 'normal', 'generic', 'guest')), language VARCHAR(50) NOT NULL DEFAULT 'en_UK', expand INTEGER NOT NULL DEFAULT 0, show_nb_comments INTEGER NOT NULL DEFAULT 0, show_nb_hits INTEGER NOT NULL DEFAULT 0, recent_period INTEGER NOT NULL DEFAULT 7, theme VARCHAR(255) NOT NULL DEFAULT 'modus', registration_date DATETIME DEFAULT NULL, enabled_high INTEGER NOT NULL DEFAULT 1, level SMALLINT NOT NULL DEFAULT 0, activation_key VARCHAR(255) DEFAULT NULL, activation_key_expire DATETIME DEFAULT NULL, last_visit DATETIME DEFAULT NULL, last_visit_from_history INTEGER NOT NULL DEFAULT 0, lastmodified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, preferences TEXT DEFAULT NULL, PRIMARY KEY (user_id));

CREATE INDEX user_infos_lastmodified_idx ON user_infos (lastmodified);

CREATE TABLE user_mail_notification (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, check_key VARCHAR(16) NOT NULL DEFAULT '' UNIQUE, enabled INTEGER NOT NULL DEFAULT 0, last_send DATETIME DEFAULT NULL, PRIMARY KEY (user_id));

CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(100) NOT NULL DEFAULT '' UNIQUE, password VARCHAR(255) DEFAULT NULL, mail_address VARCHAR(255) DEFAULT NULL);

CREATE TABLE user_failed_logins (id INTEGER PRIMARY KEY, user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE CASCADE, ip VARCHAR(45) NOT NULL, attempted_at DATETIME NOT NULL);

CREATE INDEX idx_user_failed_logins_user_time ON user_failed_logins (user_id, attempted_at);

CREATE INDEX idx_user_failed_logins_ip_time ON user_failed_logins (ip, attempted_at);

CREATE TABLE activity (activity_id INTEGER PRIMARY KEY, object VARCHAR(255) NOT NULL, object_id INTEGER NOT NULL, action VARCHAR(255) NOT NULL, performed_by INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL, session_idx VARCHAR(255) NOT NULL, ip_address VARCHAR(50) DEFAULT NULL, occured_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, details TEXT DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL, category_id INTEGER DEFAULT NULL REFERENCES categories(id) ON DELETE SET NULL, image_id INTEGER DEFAULT NULL REFERENCES images(id) ON DELETE SET NULL, tag_id INTEGER DEFAULT NULL REFERENCES tags(id) ON DELETE SET NULL, group_id INTEGER DEFAULT NULL REFERENCES groups(id) ON DELETE SET NULL, system_scope SMALLINT DEFAULT NULL);

CREATE INDEX activity_performed_by_idx ON activity (performed_by);

CREATE INDEX activity_user_id_idx ON activity (user_id);

CREATE INDEX activity_category_id_idx ON activity (category_id);

CREATE INDEX activity_image_id_idx ON activity (image_id);

CREATE INDEX activity_tag_id_idx ON activity (tag_id);

CREATE INDEX activity_group_id_idx ON activity (group_id);

CREATE TABLE config (param VARCHAR(40) NOT NULL DEFAULT '', value TEXT DEFAULT NULL, comment VARCHAR(255) DEFAULT NULL, PRIMARY KEY (param));

CREATE TABLE history (id INTEGER PRIMARY KEY, date DATE DEFAULT NULL, time TEXT NOT NULL DEFAULT '00:00:00', user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, ip CHAR(39) NOT NULL DEFAULT '', section VARCHAR(20) DEFAULT NULL, category_id INTEGER DEFAULT NULL REFERENCES categories(id) ON DELETE SET NULL, search_id INTEGER DEFAULT NULL REFERENCES search(id) ON DELETE SET NULL, tag_ids VARCHAR(50) DEFAULT NULL, image_id INTEGER DEFAULT NULL REFERENCES images(id) ON DELETE SET NULL, image_type TEXT DEFAULT NULL CHECK (image_type IS NULL OR image_type IN ('picture', 'high', 'other')), format_id INTEGER DEFAULT NULL REFERENCES image_format(format_id) ON DELETE SET NULL, auth_key_id INTEGER DEFAULT NULL REFERENCES user_auth_keys(auth_key_id) ON DELETE SET NULL);

CREATE INDEX idx_history_date_desc ON history (date DESC, id DESC);

CREATE INDEX history_image_id_idx ON history (image_id);

CREATE INDEX history_category_id_idx ON history (category_id);

CREATE INDEX history_search_id_idx ON history (search_id);

CREATE INDEX history_format_id_idx ON history (format_id);

CREATE INDEX history_auth_key_id_idx ON history (auth_key_id);

CREATE INDEX history_user_id_idx ON history (user_id);

CREATE TABLE history_summary (summary_id INTEGER PRIMARY KEY, year SMALLINT NOT NULL DEFAULT 0, month SMALLINT DEFAULT NULL, day SMALLINT DEFAULT NULL, hour SMALLINT DEFAULT NULL, nb_pages INTEGER DEFAULT NULL, history_id_from INTEGER DEFAULT NULL, history_id_to INTEGER DEFAULT NULL);

CREATE UNIQUE INDEX history_summary_ymdh ON history_summary (year, month, day, hour);

CREATE TABLE languages (id VARCHAR(64) NOT NULL DEFAULT '', version VARCHAR(64) NOT NULL DEFAULT '0', name VARCHAR(64) DEFAULT NULL, PRIMARY KEY (id));

CREATE TABLE plugins (id VARCHAR(64) NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT 'inactive' CHECK (state IN ('inactive', 'active')), version VARCHAR(64) NOT NULL DEFAULT '0', PRIMARY KEY (id));

CREATE TABLE search (id INTEGER PRIMARY KEY, search_uuid CHAR(23) DEFAULT NULL, created_on DATETIME DEFAULT NULL, created_by INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL, forked_from INTEGER DEFAULT NULL REFERENCES search(id) ON DELETE SET NULL, rules TEXT DEFAULT NULL);

CREATE INDEX search_created_by_idx ON search (created_by);

CREATE INDEX search_forked_from_idx ON search (forked_from);

CREATE TABLE sites (id INTEGER PRIMARY KEY, galleries_url VARCHAR(255) NOT NULL DEFAULT '' UNIQUE);

CREATE TABLE themes (id VARCHAR(64) NOT NULL DEFAULT '', version VARCHAR(64) NOT NULL DEFAULT '0', name VARCHAR(64) DEFAULT NULL, PRIMARY KEY (id));

CREATE TABLE derivative_settings (id SMALLINT NOT NULL, default_quality INTEGER NOT NULL DEFAULT 95, watermark_json TEXT NOT NULL, custom_json TEXT NOT NULL, PRIMARY KEY (id));

CREATE TABLE derivative_size (name VARCHAR(32) NOT NULL, enabled SMALLINT NOT NULL DEFAULT 1, max_width INTEGER NOT NULL DEFAULT 0, max_height INTEGER NOT NULL DEFAULT 0, max_crop NUMERIC(5,4) NOT NULL DEFAULT 0, min_width INTEGER DEFAULT NULL, min_height INTEGER DEFAULT NULL, sharpen NUMERIC(5,4) NOT NULL DEFAULT 0, last_mod_time INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (name));

CREATE TABLE extension_ignored_updates (extension_type VARCHAR(16) NOT NULL, extension_id VARCHAR(64) NOT NULL, ignored_at DATETIME NOT NULL, PRIMARY KEY (extension_type, extension_id));

CREATE TABLE integrity_ignored_anomalies (anomaly_id VARCHAR(64) NOT NULL, piwigo_version VARCHAR(16) NOT NULL, ignored_at DATETIME NOT NULL, PRIMARY KEY (anomaly_id, piwigo_version));

CREATE TABLE plugin_migrations (plugin_id VARCHAR(64) NOT NULL REFERENCES plugins(id) ON DELETE RESTRICT, version VARCHAR(191) NOT NULL, executed_at DATETIME NOT NULL, PRIMARY KEY (plugin_id, version));

CREATE TABLE audit_log (id INTEGER PRIMARY KEY, actor_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL, action VARCHAR(64) NOT NULL, entity_type VARCHAR(64) NOT NULL, entity_id INTEGER DEFAULT NULL, before_json TEXT DEFAULT NULL, after_json TEXT DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, prev_hash VARCHAR(64) DEFAULT NULL, row_hash VARCHAR(64) NOT NULL, group_id INTEGER DEFAULT NULL REFERENCES groups(id) ON DELETE SET NULL);

CREATE INDEX idx_audit_log_entity ON audit_log (entity_type, entity_id);

CREATE INDEX idx_audit_log_actor ON audit_log (actor_id);

CREATE INDEX idx_audit_log_created_at ON audit_log (created_at);

CREATE INDEX audit_log_group_id_idx ON audit_log (group_id);
