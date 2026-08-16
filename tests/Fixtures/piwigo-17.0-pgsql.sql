--
-- PostgreSQL database dump
--


-- Dumped from database version 18.4 (Ubuntu 18.4-0ubuntu0.26.04.1)
-- Dumped by pg_dump version 18.4 (Ubuntu 18.4-0ubuntu0.26.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

ALTER TABLE IF EXISTS ONLY public.user_mail_notification DROP CONSTRAINT IF EXISTS fk_user_mail_notification_user_id;
ALTER TABLE IF EXISTS ONLY public.user_infos DROP CONSTRAINT IF EXISTS fk_user_infos_user_id;
ALTER TABLE IF EXISTS ONLY public.user_group DROP CONSTRAINT IF EXISTS fk_user_group_user_id;
ALTER TABLE IF EXISTS ONLY public.user_group DROP CONSTRAINT IF EXISTS fk_user_group_group_id;
ALTER TABLE IF EXISTS ONLY public.user_feed DROP CONSTRAINT IF EXISTS fk_user_feed_user_id;
ALTER TABLE IF EXISTS ONLY public.user_failed_logins DROP CONSTRAINT IF EXISTS fk_user_failed_logins_user_id;
ALTER TABLE IF EXISTS ONLY public.user_auth_keys DROP CONSTRAINT IF EXISTS fk_user_auth_keys_user_id;
ALTER TABLE IF EXISTS ONLY public.user_access DROP CONSTRAINT IF EXISTS fk_user_access_user_id;
ALTER TABLE IF EXISTS ONLY public.user_access DROP CONSTRAINT IF EXISTS fk_user_access_cat_id;
ALTER TABLE IF EXISTS ONLY public.search DROP CONSTRAINT IF EXISTS fk_search_forked_from;
ALTER TABLE IF EXISTS ONLY public.search DROP CONSTRAINT IF EXISTS fk_search_created_by;
ALTER TABLE IF EXISTS ONLY public.rate DROP CONSTRAINT IF EXISTS fk_rate_user_id;
ALTER TABLE IF EXISTS ONLY public.rate DROP CONSTRAINT IF EXISTS fk_rate_element_id;
ALTER TABLE IF EXISTS ONLY public.lounge DROP CONSTRAINT IF EXISTS fk_lounge_image_id;
ALTER TABLE IF EXISTS ONLY public.lounge DROP CONSTRAINT IF EXISTS fk_lounge_category_id;
ALTER TABLE IF EXISTS ONLY public.images DROP CONSTRAINT IF EXISTS fk_images_storage_category_id;
ALTER TABLE IF EXISTS ONLY public.images DROP CONSTRAINT IF EXISTS fk_images_added_by;
ALTER TABLE IF EXISTS ONLY public.image_tag DROP CONSTRAINT IF EXISTS fk_image_tag_tag_id;
ALTER TABLE IF EXISTS ONLY public.image_tag DROP CONSTRAINT IF EXISTS fk_image_tag_image_id;
ALTER TABLE IF EXISTS ONLY public.image_format DROP CONSTRAINT IF EXISTS fk_image_format_image_id;
ALTER TABLE IF EXISTS ONLY public.image_category DROP CONSTRAINT IF EXISTS fk_image_category_image_id;
ALTER TABLE IF EXISTS ONLY public.image_category DROP CONSTRAINT IF EXISTS fk_image_category_category_id;
ALTER TABLE IF EXISTS ONLY public.history DROP CONSTRAINT IF EXISTS fk_history_user_id;
ALTER TABLE IF EXISTS ONLY public.history DROP CONSTRAINT IF EXISTS fk_history_search_id;
ALTER TABLE IF EXISTS ONLY public.history DROP CONSTRAINT IF EXISTS fk_history_image_id;
ALTER TABLE IF EXISTS ONLY public.history DROP CONSTRAINT IF EXISTS fk_history_format_id;
ALTER TABLE IF EXISTS ONLY public.history DROP CONSTRAINT IF EXISTS fk_history_category_id;
ALTER TABLE IF EXISTS ONLY public.history DROP CONSTRAINT IF EXISTS fk_history_auth_key_id;
ALTER TABLE IF EXISTS ONLY public.group_access DROP CONSTRAINT IF EXISTS fk_group_access_group_id;
ALTER TABLE IF EXISTS ONLY public.group_access DROP CONSTRAINT IF EXISTS fk_group_access_cat_id;
ALTER TABLE IF EXISTS ONLY public.favorites DROP CONSTRAINT IF EXISTS fk_favorites_user_id;
ALTER TABLE IF EXISTS ONLY public.favorites DROP CONSTRAINT IF EXISTS fk_favorites_image_id;
ALTER TABLE IF EXISTS ONLY public.comments DROP CONSTRAINT IF EXISTS fk_comments_image_id;
ALTER TABLE IF EXISTS ONLY public.comments DROP CONSTRAINT IF EXISTS fk_comments_author_id;
ALTER TABLE IF EXISTS ONLY public.categories DROP CONSTRAINT IF EXISTS fk_categories_representative_picture_id;
ALTER TABLE IF EXISTS ONLY public.categories DROP CONSTRAINT IF EXISTS fk_categories_id_uppercat;
ALTER TABLE IF EXISTS ONLY public.caddie DROP CONSTRAINT IF EXISTS fk_caddie_user_id;
ALTER TABLE IF EXISTS ONLY public.caddie DROP CONSTRAINT IF EXISTS fk_caddie_element_id;
ALTER TABLE IF EXISTS ONLY public.audit_log DROP CONSTRAINT IF EXISTS fk_audit_log_actor_id;
ALTER TABLE IF EXISTS ONLY public.activity DROP CONSTRAINT IF EXISTS fk_activity_performed_by;
DROP INDEX IF EXISTS public.user_infos_lastmodified_idx;
DROP INDEX IF EXISTS public.tags_lastmodified_idx;
DROP INDEX IF EXISTS public.tags_i1;
DROP INDEX IF EXISTS public.tags_ft_name;
DROP INDEX IF EXISTS public.images_lastmodified_idx;
DROP INDEX IF EXISTS public.images_i9;
DROP INDEX IF EXISTS public.images_i8;
DROP INDEX IF EXISTS public.images_i7;
DROP INDEX IF EXISTS public.images_i6;
DROP INDEX IF EXISTS public.images_i5;
DROP INDEX IF EXISTS public.images_i4;
DROP INDEX IF EXISTS public.images_i3;
DROP INDEX IF EXISTS public.images_i2;
DROP INDEX IF EXISTS public.images_i1;
DROP INDEX IF EXISTS public.images_ft_name_comment;
DROP INDEX IF EXISTS public.images_ft_author;
DROP INDEX IF EXISTS public.image_tag_i1;
DROP INDEX IF EXISTS public.image_category_i1;
DROP INDEX IF EXISTS public.idx_user_failed_logins_user_time;
DROP INDEX IF EXISTS public.idx_user_failed_logins_ip_time;
DROP INDEX IF EXISTS public.idx_images_date_desc;
DROP INDEX IF EXISTS public.idx_history_date_desc;
DROP INDEX IF EXISTS public.idx_audit_log_entity;
DROP INDEX IF EXISTS public.idx_audit_log_created_at;
DROP INDEX IF EXISTS public.idx_audit_log_actor;
DROP INDEX IF EXISTS public.groups_lastmodified_idx;
DROP INDEX IF EXISTS public.comments_i2;
DROP INDEX IF EXISTS public.comments_i1;
DROP INDEX IF EXISTS public.categories_lastmodified_idx;
DROP INDEX IF EXISTS public.categories_i2;
DROP INDEX IF EXISTS public.categories_ft_name_comment;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_ui1_unique;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_pkey;
ALTER TABLE IF EXISTS ONLY public.user_mail_notification DROP CONSTRAINT IF EXISTS user_mail_notification_ui1_unique;
ALTER TABLE IF EXISTS ONLY public.user_mail_notification DROP CONSTRAINT IF EXISTS user_mail_notification_pkey;
ALTER TABLE IF EXISTS ONLY public.user_infos DROP CONSTRAINT IF EXISTS user_infos_pkey;
ALTER TABLE IF EXISTS ONLY public.user_group DROP CONSTRAINT IF EXISTS user_group_pkey;
ALTER TABLE IF EXISTS ONLY public.user_feed DROP CONSTRAINT IF EXISTS user_feed_pkey;
ALTER TABLE IF EXISTS ONLY public.user_failed_logins DROP CONSTRAINT IF EXISTS user_failed_logins_pkey;
ALTER TABLE IF EXISTS ONLY public.user_auth_keys DROP CONSTRAINT IF EXISTS user_auth_keys_pkey;
ALTER TABLE IF EXISTS ONLY public.user_access DROP CONSTRAINT IF EXISTS user_access_pkey;
ALTER TABLE IF EXISTS ONLY public.themes DROP CONSTRAINT IF EXISTS themes_pkey;
ALTER TABLE IF EXISTS ONLY public.tags DROP CONSTRAINT IF EXISTS tags_pkey;
ALTER TABLE IF EXISTS ONLY public.sites DROP CONSTRAINT IF EXISTS sites_ui1_unique;
ALTER TABLE IF EXISTS ONLY public.sites DROP CONSTRAINT IF EXISTS sites_pkey;
ALTER TABLE IF EXISTS ONLY public.sessions DROP CONSTRAINT IF EXISTS sessions_pkey;
ALTER TABLE IF EXISTS ONLY public.search DROP CONSTRAINT IF EXISTS search_pkey;
ALTER TABLE IF EXISTS ONLY public.search_filter_view DROP CONSTRAINT IF EXISTS search_filter_view_pkey;
ALTER TABLE IF EXISTS ONLY public.rate DROP CONSTRAINT IF EXISTS rate_pkey;
ALTER TABLE IF EXISTS ONLY public.plugins DROP CONSTRAINT IF EXISTS plugins_pkey;
ALTER TABLE IF EXISTS ONLY public.plugin_migrations DROP CONSTRAINT IF EXISTS plugin_migrations_pkey;
ALTER TABLE IF EXISTS ONLY public.old_permalinks DROP CONSTRAINT IF EXISTS old_permalinks_pkey;
ALTER TABLE IF EXISTS ONLY public.lounge DROP CONSTRAINT IF EXISTS lounge_pkey;
ALTER TABLE IF EXISTS ONLY public.languages DROP CONSTRAINT IF EXISTS languages_pkey;
ALTER TABLE IF EXISTS ONLY public.integrity_ignored_anomalies DROP CONSTRAINT IF EXISTS integrity_ignored_anomalies_pkey;
ALTER TABLE IF EXISTS ONLY public.images DROP CONSTRAINT IF EXISTS images_pkey;
ALTER TABLE IF EXISTS ONLY public.image_tag DROP CONSTRAINT IF EXISTS image_tag_pkey;
ALTER TABLE IF EXISTS ONLY public.image_format DROP CONSTRAINT IF EXISTS image_format_pkey;
ALTER TABLE IF EXISTS ONLY public.image_category DROP CONSTRAINT IF EXISTS image_category_pkey;
ALTER TABLE IF EXISTS ONLY public.history_summary DROP CONSTRAINT IF EXISTS history_summary_ymdh;
ALTER TABLE IF EXISTS ONLY public.history_summary DROP CONSTRAINT IF EXISTS history_summary_pkey;
ALTER TABLE IF EXISTS ONLY public.history DROP CONSTRAINT IF EXISTS history_pkey;
ALTER TABLE IF EXISTS ONLY public.groups DROP CONSTRAINT IF EXISTS groups_ui1_unique;
ALTER TABLE IF EXISTS ONLY public.groups DROP CONSTRAINT IF EXISTS groups_pkey;
ALTER TABLE IF EXISTS ONLY public.group_access DROP CONSTRAINT IF EXISTS group_access_pkey;
ALTER TABLE IF EXISTS ONLY public.favorites DROP CONSTRAINT IF EXISTS favorites_pkey;
ALTER TABLE IF EXISTS ONLY public.extension_ignored_updates DROP CONSTRAINT IF EXISTS extension_ignored_updates_pkey;
ALTER TABLE IF EXISTS ONLY public.derivative_size DROP CONSTRAINT IF EXISTS derivative_size_pkey;
ALTER TABLE IF EXISTS ONLY public.derivative_settings DROP CONSTRAINT IF EXISTS derivative_settings_pkey;
ALTER TABLE IF EXISTS ONLY public.config DROP CONSTRAINT IF EXISTS config_pkey;
ALTER TABLE IF EXISTS ONLY public.comments DROP CONSTRAINT IF EXISTS comments_pkey;
ALTER TABLE IF EXISTS ONLY public.categories DROP CONSTRAINT IF EXISTS categories_pkey;
ALTER TABLE IF EXISTS ONLY public.categories DROP CONSTRAINT IF EXISTS categories_i3_unique;
ALTER TABLE IF EXISTS ONLY public.caddie DROP CONSTRAINT IF EXISTS caddie_pkey;
ALTER TABLE IF EXISTS ONLY public.audit_log DROP CONSTRAINT IF EXISTS audit_log_pkey;
ALTER TABLE IF EXISTS ONLY public.activity DROP CONSTRAINT IF EXISTS activity_pkey;
DROP TABLE IF EXISTS public.users;
DROP TABLE IF EXISTS public.user_mail_notification;
DROP TABLE IF EXISTS public.user_infos;
DROP TABLE IF EXISTS public.user_group;
DROP TABLE IF EXISTS public.user_feed;
DROP TABLE IF EXISTS public.user_failed_logins;
DROP TABLE IF EXISTS public.user_auth_keys;
DROP TABLE IF EXISTS public.user_access;
DROP TABLE IF EXISTS public.themes;
DROP TABLE IF EXISTS public.tags;
DROP TABLE IF EXISTS public.sites;
DROP TABLE IF EXISTS public.sessions;
DROP TABLE IF EXISTS public.search_filter_view;
DROP TABLE IF EXISTS public.search;
DROP TABLE IF EXISTS public.rate;
DROP TABLE IF EXISTS public.plugins;
DROP TABLE IF EXISTS public.plugin_migrations;
DROP TABLE IF EXISTS public.old_permalinks;
DROP TABLE IF EXISTS public.lounge;
DROP TABLE IF EXISTS public.languages;
DROP TABLE IF EXISTS public.integrity_ignored_anomalies;
DROP TABLE IF EXISTS public.images;
DROP TABLE IF EXISTS public.image_tag;
DROP TABLE IF EXISTS public.image_format;
DROP TABLE IF EXISTS public.image_category;
DROP TABLE IF EXISTS public.history_summary;
DROP TABLE IF EXISTS public.history;
DROP TABLE IF EXISTS public.groups;
DROP TABLE IF EXISTS public.group_access;
DROP TABLE IF EXISTS public.favorites;
DROP TABLE IF EXISTS public.extension_ignored_updates;
DROP TABLE IF EXISTS public.derivative_size;
DROP TABLE IF EXISTS public.derivative_settings;
DROP TABLE IF EXISTS public.config;
DROP TABLE IF EXISTS public.comments;
DROP TABLE IF EXISTS public.categories;
DROP TABLE IF EXISTS public.caddie;
DROP TABLE IF EXISTS public.audit_log;
DROP TABLE IF EXISTS public.activity;
SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: activity; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.activity (
    activity_id integer NOT NULL,
    object character varying(255) NOT NULL,
    object_id integer NOT NULL,
    action character varying(255) NOT NULL,
    performed_by integer,
    session_idx character varying(255) NOT NULL,
    ip_address character varying(50) DEFAULT NULL::character varying,
    occured_on timestamp(0) without time zone DEFAULT now() NOT NULL,
    details jsonb,
    user_agent character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.activity OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE activity; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.activity IS 'general activity log of user and system actions, distinct from the tamper-evident audit_log';


--
-- Name: COLUMN activity.activity_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.activity_id IS 'surrogate primary key';


--
-- Name: COLUMN activity.object; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.object IS 'entity type the action applies to, e.g. user, photo, album, tag, plugin';


--
-- Name: COLUMN activity.object_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.object_id IS 'id of the affected object, or the target user id on a logout action';


--
-- Name: COLUMN activity.action; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.action IS 'action verb, e.g. add, delete, login, logout, autoupdate';


--
-- Name: COLUMN activity.performed_by; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.performed_by IS 'acting user id, null for an unresolved or system actor';


--
-- Name: COLUMN activity.session_idx; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.session_idx IS 'PHP session id active during the request, or none if there was no session';


--
-- Name: COLUMN activity.ip_address; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.ip_address IS 'REMOTE_ADDR of the request that triggered the action';


--
-- Name: COLUMN activity.occured_on; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.occured_on IS 'when the action was recorded';


--
-- Name: COLUMN activity.details; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.details IS 'per-action heterogeneous payload, e.g. config diffs, batch-edit fields, install metadata';


--
-- Name: COLUMN activity.user_agent; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.activity.user_agent IS 'browser user agent string, only captured on login actions';


--
-- Name: activity_activity_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.activity ALTER COLUMN activity_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.activity_activity_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: audit_log; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.audit_log (
    id integer NOT NULL,
    actor_id integer,
    action character varying(64) NOT NULL,
    entity_type character varying(64) NOT NULL,
    entity_id integer,
    before_json jsonb,
    after_json jsonb,
    ip_address character varying(45) DEFAULT NULL::character varying,
    created_at timestamp(0) without time zone NOT NULL,
    prev_hash character varying(64) DEFAULT NULL::character varying,
    row_hash character varying(64) NOT NULL
);


ALTER TABLE public.audit_log OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE audit_log; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.audit_log IS 'SEC-57 append-only, hash-chained audit trail of admin actions and permission changes';


--
-- Name: COLUMN audit_log.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.id IS 'surrogate primary key';


--
-- Name: COLUMN audit_log.actor_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.actor_id IS 'acting user id, null for an unattributed or system action';


--
-- Name: COLUMN audit_log.action; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.action IS 'action verb, e.g. delete, grant, revoke';


--
-- Name: COLUMN audit_log.entity_type; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.entity_type IS 'audited entity type, e.g. group, permission';


--
-- Name: COLUMN audit_log.entity_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.entity_id IS 'id of the audited entity, null when not applicable';


--
-- Name: COLUMN audit_log.before_json; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.before_json IS 'entity-agnostic snapshot before the change, null for a creation, folded into row_hash so must stay exactly what was recorded';


--
-- Name: COLUMN audit_log.after_json; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.after_json IS 'entity-agnostic snapshot after the change, null for a deletion, folded into row_hash so must stay exactly what was recorded';


--
-- Name: COLUMN audit_log.ip_address; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.ip_address IS 'REMOTE_ADDR of the request that performed the action';


--
-- Name: COLUMN audit_log.created_at; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.created_at IS 'when the action was recorded';


--
-- Name: COLUMN audit_log.prev_hash; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.prev_hash IS 'row_hash of the previous row, null for the first row, forms the hash chain';


--
-- Name: COLUMN audit_log.row_hash; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.audit_log.row_hash IS 'sha256 of this row content plus prev_hash, tamper-evidence for the chain, see AuditService::computeHash';


--
-- Name: audit_log_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.audit_log ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.audit_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: caddie; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.caddie (
    user_id integer NOT NULL,
    element_id integer NOT NULL
);


ALTER TABLE public.caddie OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE caddie; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.caddie IS 'per-user temporary photo selection (caddie/basket) used by batch operations';


--
-- Name: COLUMN caddie.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.caddie.user_id IS 'owning user id';


--
-- Name: COLUMN caddie.element_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.caddie.element_id IS 'image id added to the caddie';


--
-- Name: categories; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.categories (
    id integer NOT NULL,
    name character varying(255) DEFAULT ''::character varying NOT NULL,
    id_uppercat integer,
    comment text,
    dir character varying(255) DEFAULT NULL::character varying,
    rank integer,
    status character varying(10) DEFAULT 'public'::character varying NOT NULL,
    site_id smallint,
    visible boolean DEFAULT true NOT NULL,
    representative_picture_id integer,
    uppercats character varying(255) DEFAULT ''::character varying NOT NULL,
    commentable boolean DEFAULT true NOT NULL,
    global_rank character varying(255) DEFAULT NULL::character varying,
    image_order character varying(128) DEFAULT NULL::character varying,
    permalink character varying(64) DEFAULT NULL::character varying COLLATE pg_catalog."C",
    lastmodified timestamp(0) without time zone DEFAULT now() NOT NULL,
    tsv_search tsvector GENERATED ALWAYS AS (to_tsvector('simple'::regconfig, (((COALESCE(name, ''::character varying))::text || ' '::text) || COALESCE(comment, ''::text)))) STORED,
    CONSTRAINT categories_status_check CHECK (((status)::text = ANY ((ARRAY['public'::character varying, 'private'::character varying])::text[])))
);


ALTER TABLE public.categories OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE categories; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.categories IS 'photo albums, both physical filesystem-synced and virtual';


--
-- Name: COLUMN categories.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.id IS 'surrogate primary key';


--
-- Name: COLUMN categories.name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.name IS 'album display name';


--
-- Name: COLUMN categories.id_uppercat; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.id_uppercat IS 'parent album id, null for a root album';


--
-- Name: COLUMN categories.comment; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.comment IS 'album description shown on its page';


--
-- Name: COLUMN categories.dir; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.dir IS 'filesystem subdirectory name for a physical, synchronized album, null for a virtual album';


--
-- Name: COLUMN categories.rank; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.rank IS 'sibling display order within the same parent, distinct from global_rank';


--
-- Name: COLUMN categories.status; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.status IS 'private albums require an explicit user_access or group_access grant to view';


--
-- Name: COLUMN categories.site_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.site_id IS 'owning site id, resolves to sites.galleries_url for a physical album';


--
-- Name: COLUMN categories.visible; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.visible IS 'whether the album is shown in navigation, forced false at creation if its parent is not visible';


--
-- Name: COLUMN categories.representative_picture_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.representative_picture_id IS 'image id used as the album thumbnail';


--
-- Name: COLUMN categories.uppercats; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.uppercats IS 'comma-separated ancestor album id path, from root to this album';


--
-- Name: COLUMN categories.commentable; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.commentable IS 'whether photo comments are allowed for images in this album';


--
-- Name: COLUMN categories.global_rank; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.global_rank IS 'full-tree sort key derived from rank along the ancestor path, used to order albums across different parents';


--
-- Name: COLUMN categories.image_order; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.image_order IS 'preferred ORDER BY expression for images in this album, inheritable to descendant albums';


--
-- Name: COLUMN categories.permalink; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.permalink IS 'unique URL-friendly slug for this album';


--
-- Name: COLUMN categories.lastmodified; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.categories.lastmodified IS 'row last-update timestamp';


--
-- Name: categories_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.categories ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: comments; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.comments (
    id integer NOT NULL,
    image_id integer NOT NULL,
    date timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    author character varying(255) DEFAULT NULL::character varying,
    email character varying(255) DEFAULT NULL::character varying,
    author_id integer,
    anonymous_id character varying(45) NOT NULL,
    website_url character varying(255) DEFAULT NULL::character varying,
    content text,
    validated boolean DEFAULT false NOT NULL,
    validation_date timestamp(0) without time zone DEFAULT NULL::timestamp without time zone
);


ALTER TABLE public.comments OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE comments; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.comments IS 'visitor comments left on photos';


--
-- Name: COLUMN comments.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.id IS 'surrogate primary key';


--
-- Name: COLUMN comments.image_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.image_id IS 'commented image id';


--
-- Name: COLUMN comments.date; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.date IS 'when the comment was submitted';


--
-- Name: COLUMN comments.author; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.author IS 'display name shown with the comment, the account username for a logged-in user or the guest-entered name otherwise';


--
-- Name: COLUMN comments.email; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.email IS 'guest-provided email address';


--
-- Name: COLUMN comments.author_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.author_id IS 'commenting user id, null for a guest comment';


--
-- Name: COLUMN comments.anonymous_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.anonymous_id IS 'full IP address of a guest commenter, used for anti-flood throttling';


--
-- Name: COLUMN comments.website_url; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.website_url IS 'guest-provided homepage link';


--
-- Name: COLUMN comments.content; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.content IS 'comment body';


--
-- Name: COLUMN comments.validated; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.validated IS 'moderation approval flag, gates visibility when comments_validation is enabled';


--
-- Name: COLUMN comments.validation_date; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.comments.validation_date IS 'when the comment was approved';


--
-- Name: comments_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.comments ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.comments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: config; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.config (
    param character varying(40) DEFAULT ''::character varying NOT NULL,
    value jsonb,
    comment character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.config OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE config; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.config IS 'configuration table';


--
-- Name: COLUMN config.param; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.config.param IS 'configuration key';


--
-- Name: COLUMN config.value; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.config.value IS 'JSON-encoded configuration value, see ConfigService::encode()/hydrate()';


--
-- Name: COLUMN config.comment; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.config.comment IS 'human-readable description of the param, seeded for built-in settings by install/config.sql';


--
-- Name: derivative_settings; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.derivative_settings (
    id smallint NOT NULL,
    default_quality integer DEFAULT 95 NOT NULL,
    watermark_json jsonb NOT NULL,
    custom_json jsonb NOT NULL
);


ALTER TABLE public.derivative_settings OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE derivative_settings; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.derivative_settings IS 'global derivative-image generation settings, read and written by ImageStdParams via DerivativeSettingsRepository';


--
-- Name: COLUMN derivative_settings.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_settings.id IS 'settings row identifier';


--
-- Name: COLUMN derivative_settings.default_quality; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_settings.default_quality IS 'default JPEG compression quality, 0 to 100, for generated derivative images';


--
-- Name: COLUMN derivative_settings.watermark_json; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_settings.watermark_json IS 'encoded watermark configuration';


--
-- Name: COLUMN derivative_settings.custom_json; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_settings.custom_json IS 'encoded custom derivative-generation parameters';


--
-- Name: derivative_size; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.derivative_size (
    name character varying(32) NOT NULL,
    enabled smallint DEFAULT 1 NOT NULL,
    max_width integer DEFAULT 0 NOT NULL,
    max_height integer DEFAULT 0 NOT NULL,
    max_crop numeric(5,4) DEFAULT 0 NOT NULL,
    min_width integer,
    min_height integer,
    sharpen numeric(5,4) DEFAULT 0 NOT NULL,
    last_mod_time integer DEFAULT 0 NOT NULL
);


ALTER TABLE public.derivative_size OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE derivative_size; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.derivative_size IS 'per-named derivative size definitions, read and written by ImageStdParams via DerivativeSizeRepository';


--
-- Name: COLUMN derivative_size.name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_size.name IS 'derivative size name, e.g. thumb, medium, xxlarge';


--
-- Name: COLUMN derivative_size.enabled; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_size.enabled IS 'whether this derivative size is generated';


--
-- Name: COLUMN derivative_size.max_width; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_size.max_width IS 'maximum output width in pixels, see SizingParams';


--
-- Name: COLUMN derivative_size.max_height; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_size.max_height IS 'maximum output height in pixels, see SizingParams';


--
-- Name: COLUMN derivative_size.max_crop; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_size.max_crop IS 'cropping ratio from 0, no cropping, to 1, max cropping, see SizingParams::max_crop';


--
-- Name: COLUMN derivative_size.min_width; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_size.min_width IS 'minimum output width required to allow cropping, see SizingParams::min_size';


--
-- Name: COLUMN derivative_size.min_height; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_size.min_height IS 'minimum output height required to allow cropping, see SizingParams::min_size';


--
-- Name: COLUMN derivative_size.sharpen; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_size.sharpen IS 'sharpening amount from 0, none, to 1, max, see DerivativeParams::sharpen';


--
-- Name: COLUMN derivative_size.last_mod_time; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.derivative_size.last_mod_time IS 'unix timestamp of the last parameter change, used to invalidate cached derivatives, see DerivativeParams::last_mod_time';


--
-- Name: extension_ignored_updates; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.extension_ignored_updates (
    extension_type character varying(16) NOT NULL,
    extension_id character varying(64) NOT NULL,
    ignored_at timestamp without time zone NOT NULL
);


ALTER TABLE public.extension_ignored_updates OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE extension_ignored_updates; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.extension_ignored_updates IS 'extension updates an admin dismissed, read and written by ExtensionUpdateChecker via ExtensionIgnoredUpdateRepository';


--
-- Name: COLUMN extension_ignored_updates.extension_type; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.extension_ignored_updates.extension_type IS 'plugin, theme, or language, see ExtensionType';


--
-- Name: COLUMN extension_ignored_updates.extension_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.extension_ignored_updates.extension_id IS 'directory-name identifier of the extension whose update is being ignored';


--
-- Name: COLUMN extension_ignored_updates.ignored_at; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.extension_ignored_updates.ignored_at IS 'when the update was dismissed';


--
-- Name: favorites; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.favorites (
    user_id integer NOT NULL,
    image_id integer NOT NULL
);


ALTER TABLE public.favorites OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE favorites; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.favorites IS 'per-user favorited images';


--
-- Name: COLUMN favorites.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.favorites.user_id IS 'owning user id';


--
-- Name: COLUMN favorites.image_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.favorites.image_id IS 'image the user marked as a favorite';


--
-- Name: group_access; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.group_access (
    group_id integer NOT NULL,
    cat_id integer NOT NULL
);


ALTER TABLE public.group_access OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE group_access; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.group_access IS 'per-group private album permission grants';


--
-- Name: COLUMN group_access.group_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.group_access.group_id IS 'granted group id';


--
-- Name: COLUMN group_access.cat_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.group_access.cat_id IS 'private album the group is granted access to';


--
-- Name: groups; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.groups (
    id integer NOT NULL,
    name character varying(255) DEFAULT ''::character varying NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    lastmodified timestamp(0) without time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.groups OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE groups; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.groups IS 'user groups for bulk permission and membership management';


--
-- Name: COLUMN groups.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.groups.id IS 'surrogate primary key';


--
-- Name: COLUMN groups.name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.groups.name IS 'group display name, unique';


--
-- Name: COLUMN groups.is_default; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.groups.is_default IS 'every newly registered user is automatically added to groups marked default';


--
-- Name: COLUMN groups.lastmodified; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.groups.lastmodified IS 'row last-update timestamp';


--
-- Name: groups_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.groups ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.groups_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: history; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.history (
    id integer NOT NULL,
    date date,
    "time" time without time zone DEFAULT '00:00:00'::time without time zone NOT NULL,
    user_id integer NOT NULL,
    ip character(39) DEFAULT ''::bpchar NOT NULL,
    section character varying(20) DEFAULT NULL::character varying,
    category_id integer,
    search_id integer,
    tag_ids character varying(50) DEFAULT NULL::character varying,
    image_id integer,
    image_type character varying(10) DEFAULT NULL::character varying,
    format_id integer,
    auth_key_id integer,
    CONSTRAINT history_image_type_check CHECK (((image_type IS NULL) OR ((image_type)::text = ANY ((ARRAY['picture'::character varying, 'high'::character varying, 'other'::character varying])::text[]))))
);


ALTER TABLE public.history OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE history; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.history IS 'per-visit page-view log, periodically rolled up into history_summary';


--
-- Name: COLUMN history.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.id IS 'surrogate primary key';


--
-- Name: COLUMN history.date; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.date IS 'calendar date of the visit';


--
-- Name: COLUMN history."time"; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history."time" IS 'time of day of the visit';


--
-- Name: COLUMN history.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.user_id IS 'visiting user id, the guest user id for anonymous visitors';


--
-- Name: COLUMN history.ip; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.ip IS 'REMOTE_ADDR of the request, truncated to fit an IPv6 address';


--
-- Name: COLUMN history.section; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.section IS 'gallery navigation view the visit occurred in, plugin-defined sections are appended to this enum automatically';


--
-- Name: COLUMN history.category_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.category_id IS 'album being viewed, set when section is a category-based view';


--
-- Name: COLUMN history.search_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.search_id IS 'search being viewed, set when section is search';


--
-- Name: COLUMN history.tag_ids; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.tag_ids IS 'comma-separated tag ids being viewed, set when section is tags, truncated to fit';


--
-- Name: COLUMN history.image_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.image_id IS 'viewed image id, null for a listing/section page-view';


--
-- Name: COLUMN history.image_type; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.image_type IS 'size the image was viewed at';


--
-- Name: COLUMN history.format_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.format_id IS 'image_format row downloaded or viewed, when applicable';


--
-- Name: COLUMN history.auth_key_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history.auth_key_id IS 'API auth key the request was authenticated with, if any';


--
-- Name: history_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.history ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: history_summary; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.history_summary (
    summary_id integer NOT NULL,
    year smallint DEFAULT 0 NOT NULL,
    month smallint,
    day smallint,
    hour smallint,
    nb_pages integer,
    history_id_from integer,
    history_id_to integer
);


ALTER TABLE public.history_summary OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE history_summary; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.history_summary IS 'year/month/day/hour rollup of history, one row per granularity level, letting old detail rows be purged';


--
-- Name: COLUMN history_summary.summary_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history_summary.summary_id IS 'surrogate primary key';


--
-- Name: COLUMN history_summary.year; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history_summary.year IS 'rollup year';


--
-- Name: COLUMN history_summary.month; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history_summary.month IS 'rollup month, null for a year-level summary row';


--
-- Name: COLUMN history_summary.day; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history_summary.day IS 'rollup day, null for a year- or month-level summary row';


--
-- Name: COLUMN history_summary.hour; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history_summary.hour IS 'rollup hour, null for a year-, month-, or day-level summary row';


--
-- Name: COLUMN history_summary.nb_pages; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history_summary.nb_pages IS 'number of history page-views folded into this summary row';


--
-- Name: COLUMN history_summary.history_id_from; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history_summary.history_id_from IS 'lowest history.id folded into this summary row';


--
-- Name: COLUMN history_summary.history_id_to; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.history_summary.history_id_to IS 'highest history.id folded into this summary row, the next run resumes past this id';


--
-- Name: history_summary_summary_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.history_summary ALTER COLUMN summary_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.history_summary_summary_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: image_category; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.image_category (
    image_id integer NOT NULL,
    category_id integer NOT NULL,
    rank integer
);


ALTER TABLE public.image_category OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE image_category; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.image_category IS 'image-to-album membership, an image can belong to more than one album';


--
-- Name: COLUMN image_category.image_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.image_category.image_id IS 'member image id';


--
-- Name: COLUMN image_category.category_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.image_category.category_id IS 'album the image belongs to';


--
-- Name: COLUMN image_category.rank; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.image_category.rank IS 'manual sort position of the image within this specific album';


--
-- Name: image_format; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.image_format (
    format_id integer NOT NULL,
    image_id integer NOT NULL,
    ext character varying(255) NOT NULL,
    filesize integer
);


ALTER TABLE public.image_format OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE image_format; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.image_format IS 'alternate format files stored alongside an image (the multiple formats feature)';


--
-- Name: COLUMN image_format.format_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.image_format.format_id IS 'surrogate primary key';


--
-- Name: COLUMN image_format.image_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.image_format.image_id IS 'image this alternate format file belongs to';


--
-- Name: COLUMN image_format.ext; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.image_format.ext IS 'file extension of this alternate format, e.g. a RAW file stored alongside the main JPEG';


--
-- Name: COLUMN image_format.filesize; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.image_format.filesize IS 'file size of this alternate format in KB';


--
-- Name: image_format_format_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.image_format ALTER COLUMN format_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.image_format_format_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: image_tag; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.image_tag (
    image_id integer NOT NULL,
    tag_id integer NOT NULL
);


ALTER TABLE public.image_tag OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE image_tag; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.image_tag IS 'image-to-tag associations';


--
-- Name: COLUMN image_tag.image_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.image_tag.image_id IS 'tagged image id';


--
-- Name: COLUMN image_tag.tag_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.image_tag.tag_id IS 'tag applied to the image';


--
-- Name: images; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.images (
    id integer NOT NULL,
    file character varying(255) DEFAULT ''::character varying NOT NULL COLLATE pg_catalog."C",
    date_available timestamp without time zone,
    date_creation timestamp without time zone,
    name character varying(255) DEFAULT NULL::character varying,
    comment text,
    author character varying(255) DEFAULT NULL::character varying,
    hit integer DEFAULT 0 NOT NULL,
    filesize integer,
    width integer,
    height integer,
    coi character(4) DEFAULT NULL::bpchar,
    representative_ext character varying(4) DEFAULT NULL::character varying,
    date_metadata_update date,
    rating_score real,
    path character varying(255) DEFAULT ''::character varying NOT NULL,
    storage_category_id integer,
    level smallint DEFAULT 0 NOT NULL,
    md5sum character(32) DEFAULT NULL::bpchar,
    added_by integer,
    rotation smallint,
    latitude double precision,
    longitude double precision,
    lastmodified timestamp(0) without time zone DEFAULT now() NOT NULL,
    tsv_search tsvector GENERATED ALWAYS AS (to_tsvector('simple'::regconfig, (((COALESCE(name, ''::character varying))::text || ' '::text) || COALESCE(comment, ''::text)))) STORED,
    tsv_author tsvector GENERATED ALWAYS AS (to_tsvector('simple'::regconfig, (COALESCE(author, ''::character varying))::text)) STORED
);


ALTER TABLE public.images OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE images; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.images IS 'photo/media metadata and file location, one row per uploaded image';


--
-- Name: COLUMN images.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.id IS 'surrogate primary key';


--
-- Name: COLUMN images.file; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.file IS 'base filename of the original file';


--
-- Name: COLUMN images.date_available; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.date_available IS 'date the photo is considered added/visible in the gallery, can be mapped from EXIF/IPTC or admin-edited';


--
-- Name: COLUMN images.date_creation; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.date_creation IS 'date the photo was taken, typically synced from EXIF/IPTC metadata';


--
-- Name: COLUMN images.name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.name IS 'display title, distinct from the filename';


--
-- Name: COLUMN images.comment; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.comment IS 'photo description shown on its page';


--
-- Name: COLUMN images.author; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.author IS 'photographer/author credit';


--
-- Name: COLUMN images.hit; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.hit IS 'view counter';


--
-- Name: COLUMN images.filesize; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.filesize IS 'original file size in KB';


--
-- Name: COLUMN images.width; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.width IS 'original pixel width';


--
-- Name: COLUMN images.height; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.height IS 'original pixel height';


--
-- Name: COLUMN images.coi; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.coi IS 'center of interest';


--
-- Name: COLUMN images.representative_ext; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.representative_ext IS 'file extension of a separate representative thumbnail, for formats that cannot be thumbnailed directly, e.g. PDF/video';


--
-- Name: COLUMN images.date_metadata_update; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.date_metadata_update IS 'date the row was last synced from the file EXIF/IPTC metadata, null if never synced';


--
-- Name: COLUMN images.rating_score; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.rating_score IS 'bayesian average of rate ratings, recomputed by RateService::updateRatingScore';


--
-- Name: COLUMN images.path; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.path IS 'full relative filesystem path to the original file';


--
-- Name: COLUMN images.storage_category_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.storage_category_id IS 'album the file is physically stored under, distinct from possibly multiple image_category memberships';


--
-- Name: COLUMN images.level; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.level IS 'minimum permission level required to view the image, see PwgImages::setPrivacyLevel and available_permission_levels';


--
-- Name: COLUMN images.md5sum; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.md5sum IS 'MD5 checksum of the original file, computed lazily for duplicate detection';


--
-- Name: COLUMN images.added_by; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.added_by IS 'uploading user id';


--
-- Name: COLUMN images.rotation; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.rotation IS 'pending quarter-turn rotation to apply when rendering, 0 to 3';


--
-- Name: COLUMN images.latitude; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.latitude IS 'GPS latitude, from EXIF';


--
-- Name: COLUMN images.longitude; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.longitude IS 'GPS longitude, from EXIF';


--
-- Name: COLUMN images.lastmodified; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.images.lastmodified IS 'row last-update timestamp';


--
-- Name: images_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.images ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.images_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: integrity_ignored_anomalies; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.integrity_ignored_anomalies (
    anomaly_id character varying(64) NOT NULL,
    piwigo_version character varying(16) NOT NULL,
    ignored_at timestamp without time zone NOT NULL
);


ALTER TABLE public.integrity_ignored_anomalies OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE integrity_ignored_anomalies; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.integrity_ignored_anomalies IS 'integrity-check anomalies an admin dismissed, read and written by CheckIntegrity via IntegrityIgnoredAnomalyRepository';


--
-- Name: COLUMN integrity_ignored_anomalies.anomaly_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.integrity_ignored_anomalies.anomaly_id IS 'add_anomaly()-generated md5 id, see CheckIntegrity';


--
-- Name: COLUMN integrity_ignored_anomalies.piwigo_version; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.integrity_ignored_anomalies.piwigo_version IS 'Piwigo version the anomaly was ignored under';


--
-- Name: COLUMN integrity_ignored_anomalies.ignored_at; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.integrity_ignored_anomalies.ignored_at IS 'when the anomaly was dismissed';


--
-- Name: languages; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.languages (
    id character varying(64) DEFAULT ''::character varying NOT NULL,
    version character varying(64) DEFAULT '0'::character varying NOT NULL,
    name character varying(64) DEFAULT NULL::character varying
);


ALTER TABLE public.languages OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE languages; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.languages IS 'installed/active language packs, row deleted outright on deactivation';


--
-- Name: COLUMN languages.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.languages.id IS 'language directory-name identifier, e.g. en_UK, row existence alone means installed and active';


--
-- Name: COLUMN languages.version; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.languages.version IS 'installed language pack version string';


--
-- Name: COLUMN languages.name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.languages.name IS 'human-readable language display name';


--
-- Name: lounge; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.lounge (
    image_id integer NOT NULL,
    category_id integer NOT NULL
);


ALTER TABLE public.lounge OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE lounge; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.lounge IS 'pending image-to-album associations, applied in bulk by ImageService::emptyLounge';


--
-- Name: COLUMN lounge.image_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.lounge.image_id IS 'newly uploaded image pending album association';


--
-- Name: COLUMN lounge.category_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.lounge.category_id IS 'album the image is intended for once the lounge is emptied';


--
-- Name: old_permalinks; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.old_permalinks (
    cat_id integer NOT NULL,
    permalink character varying(64) DEFAULT ''::character varying NOT NULL COLLATE pg_catalog."C",
    date_deleted timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    last_hit timestamp without time zone,
    hit integer DEFAULT 0 NOT NULL
);


ALTER TABLE public.old_permalinks OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE old_permalinks; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.old_permalinks IS 'retired album permalinks, kept to block reuse and shown on the admin permalinks page';


--
-- Name: COLUMN old_permalinks.cat_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.old_permalinks.cat_id IS 'album the removed permalink used to point to';


--
-- Name: COLUMN old_permalinks.permalink; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.old_permalinks.permalink IS 'the retired URL slug, kept so it is not immediately reusable by another album';


--
-- Name: COLUMN old_permalinks.date_deleted; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.old_permalinks.date_deleted IS 'when the permalink was retired';


--
-- Name: COLUMN old_permalinks.last_hit; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.old_permalinks.last_hit IS 'when the dead permalink was last visited';


--
-- Name: COLUMN old_permalinks.hit; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.old_permalinks.hit IS 'visit count against the dead permalink';


--
-- Name: plugin_migrations; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.plugin_migrations (
    plugin_id character varying(64) NOT NULL,
    version character varying(191) NOT NULL,
    executed_at timestamp without time zone NOT NULL
);


ALTER TABLE public.plugin_migrations OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE plugin_migrations; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.plugin_migrations IS 'per-plugin install/update history, read and written by ExtensionLifecycle via PluginMigrationRepository, not a real migration runner';


--
-- Name: COLUMN plugin_migrations.plugin_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.plugin_migrations.plugin_id IS 'directory-name identifier of the plugin that ran this migration';


--
-- Name: COLUMN plugin_migrations.version; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.plugin_migrations.version IS 'plugin-internal migration version identifier';


--
-- Name: COLUMN plugin_migrations.executed_at; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.plugin_migrations.executed_at IS 'when this plugin migration ran';


--
-- Name: plugins; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.plugins (
    id character varying(64) DEFAULT ''::character varying NOT NULL COLLATE pg_catalog."C",
    state character varying(10) DEFAULT 'inactive'::character varying NOT NULL,
    version character varying(64) DEFAULT '0'::character varying NOT NULL,
    CONSTRAINT plugins_state_check CHECK (((state)::text = ANY ((ARRAY['inactive'::character varying, 'active'::character varying])::text[])))
);


ALTER TABLE public.plugins OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE plugins; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.plugins IS 'installed plugins and their active/inactive state';


--
-- Name: COLUMN plugins.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.plugins.id IS 'plugin directory-name identifier, row existence alone means installed, active or not';


--
-- Name: COLUMN plugins.state; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.plugins.state IS 'whether the installed plugin is currently active';


--
-- Name: COLUMN plugins.version; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.plugins.version IS 'installed plugin version string';


--
-- Name: rate; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.rate (
    user_id integer NOT NULL,
    element_id integer NOT NULL,
    anonymous_id character varying(45) DEFAULT ''::character varying NOT NULL,
    rate smallint DEFAULT 0 NOT NULL,
    date date
);


ALTER TABLE public.rate OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE rate; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.rate IS 'per-user or per-anonymous-visitor image ratings, aggregated into images.rating_score';


--
-- Name: COLUMN rate.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.rate.user_id IS 'rating user id, the guest user id for anonymous visitors';


--
-- Name: COLUMN rate.element_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.rate.element_id IS 'rated image id';


--
-- Name: COLUMN rate.anonymous_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.rate.anonymous_id IS 'truncated IP address identifying an anonymous rater, from the anonymous_rater cookie';


--
-- Name: COLUMN rate.rate; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.rate.rate IS 'submitted rating value, restricted to the configured rate_items';


--
-- Name: COLUMN rate.date; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.rate.date IS 'date the rate was submitted';


--
-- Name: search; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.search (
    id integer NOT NULL,
    search_uuid character(23) DEFAULT NULL::bpchar,
    created_on timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    created_by integer,
    forked_from integer,
    rules jsonb
);


ALTER TABLE public.search OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE search; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.search IS 'saved/shareable search queries';


--
-- Name: COLUMN search.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.search.id IS 'surrogate primary key';


--
-- Name: COLUMN search.search_uuid; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.search.search_uuid IS 'public, shareable identifier for this saved search, used in URLs instead of id';


--
-- Name: COLUMN search.created_on; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.search.created_on IS 'when the search was saved';


--
-- Name: COLUMN search.created_by; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.search.created_by IS 'user id who saved the search, null for an anonymous search';


--
-- Name: COLUMN search.forked_from; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.search.forked_from IS 'search this one was refined/derived from, null for an original search';


--
-- Name: COLUMN search.rules; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.search.rules IS 'encoded search criteria (query terms, filters) evaluated by SearchService';


--
-- Name: search_filter_view; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.search_filter_view (
    name character varying(64) NOT NULL,
    config_json jsonb NOT NULL,
    created_at timestamp without time zone NOT NULL
);


ALTER TABLE public.search_filter_view OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE search_filter_view; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.search_filter_view IS 'named, reusable saved search-filter presets, unused: not read or written by any repository or service in this codebase';


--
-- Name: COLUMN search_filter_view.name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.search_filter_view.name IS 'saved filter view name';


--
-- Name: COLUMN search_filter_view.config_json; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.search_filter_view.config_json IS 'encoded search filter configuration';


--
-- Name: COLUMN search_filter_view.created_at; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.search_filter_view.created_at IS 'when the filter view was saved';


--
-- Name: search_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.search ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.search_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.sessions (
    id character varying(50) DEFAULT ''::character varying NOT NULL COLLATE pg_catalog."C",
    data text NOT NULL,
    expiration timestamp without time zone
);


ALTER TABLE public.sessions OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE sessions; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.sessions IS 'PHP session storage backend';


--
-- Name: COLUMN sessions.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.sessions.id IS 'composite PHP session id, IP-hash-prefixed by SessionService';


--
-- Name: COLUMN sessions.data; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.sessions.data IS 'serialized PHP session payload';


--
-- Name: COLUMN sessions.expiration; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.sessions.expiration IS 'when this session becomes invalid';


--
-- Name: sites; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.sites (
    id smallint NOT NULL,
    galleries_url character varying(255) DEFAULT ''::character varying NOT NULL
);


ALTER TABLE public.sites OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE sites; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.sites IS 'multi-site photo sources synchronized into albums';


--
-- Name: COLUMN sites.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.sites.id IS 'surrogate primary key, referenced by categories.site_id';


--
-- Name: COLUMN sites.galleries_url; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.sites.galleries_url IS 'base path or URL this site synchronizes photos from, local or remote (see UrlService::urlIsRemote)';


--
-- Name: sites_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.sites ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.sites_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: tags; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.tags (
    id integer NOT NULL,
    name character varying(255) DEFAULT ''::character varying NOT NULL,
    url_name character varying(255) DEFAULT ''::character varying NOT NULL COLLATE pg_catalog."C",
    lastmodified timestamp(0) without time zone DEFAULT now() NOT NULL,
    tsv_search tsvector GENERATED ALWAYS AS (to_tsvector('simple'::regconfig, (COALESCE(name, ''::character varying))::text)) STORED
);


ALTER TABLE public.tags OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE tags; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.tags IS 'photo tags/keywords';


--
-- Name: COLUMN tags.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.tags.id IS 'surrogate primary key';


--
-- Name: COLUMN tags.name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.tags.name IS 'tag display name';


--
-- Name: COLUMN tags.url_name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.tags.url_name IS 'URL-friendly slug derived from name';


--
-- Name: COLUMN tags.lastmodified; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.tags.lastmodified IS 'row last-update timestamp';


--
-- Name: tags_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.tags ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.tags_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: themes; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.themes (
    id character varying(64) DEFAULT ''::character varying NOT NULL,
    version character varying(64) DEFAULT '0'::character varying NOT NULL,
    name character varying(64) DEFAULT NULL::character varying
);


ALTER TABLE public.themes OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE themes; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.themes IS 'installed/active themes, row deleted outright on deactivation';


--
-- Name: COLUMN themes.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.themes.id IS 'theme directory-name identifier, referenced by user_infos.theme, row existence alone means installed and active';


--
-- Name: COLUMN themes.version; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.themes.version IS 'installed theme version string';


--
-- Name: COLUMN themes.name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.themes.name IS 'human-readable theme display name';


--
-- Name: user_access; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.user_access (
    user_id integer NOT NULL,
    cat_id integer NOT NULL
);


ALTER TABLE public.user_access OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE user_access; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.user_access IS 'per-user private album permission grants';


--
-- Name: COLUMN user_access.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_access.user_id IS 'granted user id';


--
-- Name: COLUMN user_access.cat_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_access.cat_id IS 'private album the user is granted access to';


--
-- Name: user_auth_keys; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.user_auth_keys (
    auth_key_id integer NOT NULL,
    auth_key character varying(255) NOT NULL,
    apikey_secret character varying(255) DEFAULT NULL::character varying,
    user_id integer NOT NULL,
    created_on timestamp(0) without time zone NOT NULL,
    duration integer,
    expired_on timestamp(0) without time zone NOT NULL,
    apikey_name character varying(100) DEFAULT NULL::character varying,
    key_type character varying(40) DEFAULT NULL::character varying,
    revoked_on timestamp without time zone,
    last_used_on timestamp without time zone,
    last_notified_on timestamp without time zone
);


ALTER TABLE public.user_auth_keys OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE user_auth_keys; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.user_auth_keys IS 'persistent-login tokens and personal API keys, two row shapes sharing one table';


--
-- Name: COLUMN user_auth_keys.auth_key_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.auth_key_id IS 'surrogate primary key';


--
-- Name: COLUMN user_auth_keys.auth_key; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.auth_key IS 'the token value: a random persistent-login token for key_type=auth_key, or the public pkid-... identifier for key_type=api_key';


--
-- Name: COLUMN user_auth_keys.apikey_secret; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.apikey_secret IS 'hashed secret half of a key_type=api_key pair, null for auth_key rows';


--
-- Name: COLUMN user_auth_keys.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.user_id IS 'owning user id';


--
-- Name: COLUMN user_auth_keys.created_on; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.created_on IS 'when the key was issued';


--
-- Name: COLUMN user_auth_keys.duration; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.duration IS 'requested key lifetime, seconds for auth_key rows or days for api_key rows, see expired_on for the actual cutoff';


--
-- Name: COLUMN user_auth_keys.expired_on; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.expired_on IS 'when the key stops being valid';


--
-- Name: COLUMN user_auth_keys.apikey_name; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.apikey_name IS 'user-given label for a key_type=api_key row, null for auth_key rows';


--
-- Name: COLUMN user_auth_keys.key_type; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.key_type IS 'auth_key for a persistent-login/URL-login token, api_key for a personal API key';


--
-- Name: COLUMN user_auth_keys.revoked_on; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.revoked_on IS 'when the key was manually revoked, null if still live';


--
-- Name: COLUMN user_auth_keys.last_used_on; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.last_used_on IS 'when the key last authenticated a request';


--
-- Name: COLUMN user_auth_keys.last_notified_on; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_auth_keys.last_notified_on IS 'when the owner was last emailed an expiration notice';


--
-- Name: user_auth_keys_auth_key_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.user_auth_keys ALTER COLUMN auth_key_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.user_auth_keys_auth_key_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: user_failed_logins; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.user_failed_logins (
    id integer NOT NULL,
    user_id integer,
    ip character varying(45) NOT NULL,
    attempted_at timestamp without time zone NOT NULL
);


ALTER TABLE public.user_failed_logins OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE user_failed_logins; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.user_failed_logins IS 'failed login attempts, read and written by AuthService::pwgLogin() via UserFailedLoginRepository to back its dual-scope (username + IP) lockout';


--
-- Name: COLUMN user_failed_logins.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_failed_logins.id IS 'surrogate primary key';


--
-- Name: COLUMN user_failed_logins.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_failed_logins.user_id IS 'targeted user id, if the attempted username resolved to a real account';


--
-- Name: COLUMN user_failed_logins.ip; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_failed_logins.ip IS 'REMOTE_ADDR the failed login attempt came from';


--
-- Name: COLUMN user_failed_logins.attempted_at; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_failed_logins.attempted_at IS 'when the failed attempt occurred';


--
-- Name: user_failed_logins_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.user_failed_logins ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.user_failed_logins_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: user_feed; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.user_feed (
    id character varying(50) DEFAULT ''::character varying NOT NULL COLLATE pg_catalog."C",
    user_id integer NOT NULL,
    last_check timestamp without time zone
);


ALTER TABLE public.user_feed OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE user_feed; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.user_feed IS 'per-user private RSS feed tokens';


--
-- Name: COLUMN user_feed.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_feed.id IS 'private feed token, passed as ?feed= to authenticate as the owning user without a login';


--
-- Name: COLUMN user_feed.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_feed.user_id IS 'user this feed token authenticates as';


--
-- Name: COLUMN user_feed.last_check; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_feed.last_check IS 'when this feed URL was last polled';


--
-- Name: user_group; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.user_group (
    user_id integer NOT NULL,
    group_id integer NOT NULL
);


ALTER TABLE public.user_group OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE user_group; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.user_group IS 'user to group membership';


--
-- Name: COLUMN user_group.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_group.user_id IS 'member user id';


--
-- Name: COLUMN user_group.group_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_group.group_id IS 'group the user belongs to';


--
-- Name: user_infos; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.user_infos (
    user_id integer NOT NULL,
    nb_image_page integer DEFAULT 15 NOT NULL,
    status character varying(10) DEFAULT 'guest'::character varying NOT NULL,
    language character varying(50) DEFAULT 'en_UK'::character varying NOT NULL,
    expand boolean DEFAULT false NOT NULL,
    show_nb_comments boolean DEFAULT false NOT NULL,
    show_nb_hits boolean DEFAULT false NOT NULL,
    recent_period smallint DEFAULT 7 NOT NULL,
    theme character varying(255) DEFAULT 'modus'::character varying NOT NULL,
    registration_date timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    enabled_high boolean DEFAULT true NOT NULL,
    level smallint DEFAULT 0 NOT NULL,
    activation_key character varying(255) DEFAULT NULL::character varying,
    activation_key_expire timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    last_visit timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    last_visit_from_history boolean DEFAULT false NOT NULL,
    lastmodified timestamp(0) without time zone DEFAULT now() NOT NULL,
    preferences jsonb,
    CONSTRAINT user_infos_status_check CHECK (((status)::text = ANY ((ARRAY['webmaster'::character varying, 'admin'::character varying, 'normal'::character varying, 'generic'::character varying, 'guest'::character varying])::text[])))
);


ALTER TABLE public.user_infos OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE user_infos; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.user_infos IS 'per-user profile and preferences, one row per users.id';


--
-- Name: COLUMN user_infos.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.user_id IS 'the owning users.id row, application-assigned, never auto-generated here';


--
-- Name: COLUMN user_infos.nb_image_page; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.nb_image_page IS 'photos per page preference';


--
-- Name: COLUMN user_infos.status; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.status IS 'account role, gates admin access and permission checks';


--
-- Name: COLUMN user_infos.language; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.language IS 'interface language, references languages.id';


--
-- Name: COLUMN user_infos.expand; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.expand IS 'whether the album tree auto-expands in the menu';


--
-- Name: COLUMN user_infos.show_nb_comments; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.show_nb_comments IS 'whether comment counts are shown alongside thumbnails';


--
-- Name: COLUMN user_infos.show_nb_hits; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.show_nb_hits IS 'whether view counts are shown alongside thumbnails';


--
-- Name: COLUMN user_infos.recent_period; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.recent_period IS 'number of days considered recent for the recent photos/albums views';


--
-- Name: COLUMN user_infos.theme; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.theme IS 'interface theme, references themes.id';


--
-- Name: COLUMN user_infos.registration_date; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.registration_date IS 'account creation date';


--
-- Name: COLUMN user_infos.enabled_high; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.enabled_high IS 'whether the user may view/download the original, high-definition photo';


--
-- Name: COLUMN user_infos.level; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.level IS 'effective permission level, gates access to images.level-restricted photos';


--
-- Name: COLUMN user_infos.activation_key; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.activation_key IS 'hashed password-reset token, see AuthService::setActivationKey and password.php';


--
-- Name: COLUMN user_infos.activation_key_expire; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.activation_key_expire IS 'when activation_key stops being valid';


--
-- Name: COLUMN user_infos.last_visit; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.last_visit IS 'when the user was last seen, refreshed once per session length';


--
-- Name: COLUMN user_infos.last_visit_from_history; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.last_visit_from_history IS 'whether last_visit was already backfilled from the history table, avoids repeating that lookup';


--
-- Name: COLUMN user_infos.lastmodified; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.lastmodified IS 'row last-update timestamp';


--
-- Name: COLUMN user_infos.preferences; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_infos.preferences IS 'generic per-user key-value bag for preferences with no dedicated column';


--
-- Name: user_mail_notification; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.user_mail_notification (
    user_id integer NOT NULL,
    check_key character varying(16) DEFAULT ''::character varying NOT NULL COLLATE pg_catalog."C",
    enabled boolean DEFAULT false NOT NULL,
    last_send timestamp without time zone
);


ALTER TABLE public.user_mail_notification OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE user_mail_notification; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.user_mail_notification IS 'per-user new-photo email notification subscriptions';


--
-- Name: COLUMN user_mail_notification.user_id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_mail_notification.user_id IS 'subscribing user id';


--
-- Name: COLUMN user_mail_notification.check_key; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_mail_notification.check_key IS 'private token used in subscribe/unsubscribe confirmation email links';


--
-- Name: COLUMN user_mail_notification.enabled; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_mail_notification.enabled IS 'whether the user currently receives new-photo notification emails';


--
-- Name: COLUMN user_mail_notification.last_send; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.user_mail_notification.last_send IS 'when a notification email was last sent to this user';


--
-- Name: users; Type: TABLE; Schema: public; Owner: piwigo_fixture_regen
--

CREATE TABLE public.users (
    id integer NOT NULL,
    username character varying(100) DEFAULT ''::character varying NOT NULL COLLATE pg_catalog."C",
    password character varying(255) DEFAULT NULL::character varying,
    mail_address character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.users OWNER TO piwigo_fixture_regen;

--
-- Name: TABLE users; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON TABLE public.users IS 'core login accounts, column names configurable via CurrentConfig::userFields for multi-auth integrations';


--
-- Name: COLUMN users.id; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.users.id IS 'surrogate primary key, referenced by user_id everywhere else';


--
-- Name: COLUMN users.username; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.users.username IS 'login name, unique';


--
-- Name: COLUMN users.password; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.users.password IS 'hashed login password';


--
-- Name: COLUMN users.mail_address; Type: COMMENT; Schema: public; Owner: piwigo_fixture_regen
--

COMMENT ON COLUMN public.users.mail_address IS 'account email address';


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE public.users ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Data for Name: activity; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.activity (activity_id, object, object_id, action, performed_by, session_idx, ip_address, occured_on, details, user_agent) FROM stdin;
1	system	3	activate	\N	none	::1	2026-08-01 00:00:00	{"script": "install", "theme_id": "default"}	\N
2	system	1	install	\N	none	::1	2026-08-01 00:00:00	{"script": "install", "version": "16.3.0"}	\N
3	user	1	login	1	1bc781ddd982d9b5d45f376dfc58d7f7	::1	2026-08-01 00:00:00	{"script": "install"}	PiwigoFixtureRegen/1.0
4	user	1	login	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.session.login"}	PiwigoFixtureRegen/1.0
5	album	1	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.categories.add"}	\N
6	album	2	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.categories.add"}	\N
7	photo	1	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.images.addSimple", "added_with": "app"}	\N
8	photo	2	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.images.addSimple", "added_with": "app"}	\N
9	photo	3	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.images.addSimple", "added_with": "app"}	\N
10	photo	4	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.images.addSimple", "added_with": "app"}	\N
11	photo	5	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.images.addSimple", "added_with": "app"}	\N
12	tag	1	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.tags.add"}	\N
13	tag	2	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.tags.add"}	\N
14	tag	3	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.tags.add"}	\N
15	user	3	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.users.add"}	\N
16	user	4	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.users.add"}	\N
17	group	1	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.groups.add"}	\N
18	group	2	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.groups.add"}	\N
19	group	3	add	1	181a4e2d08983eef4ef71dc7e8fa24c1	::1	2026-08-01 00:00:00	{"method": "pwg.groups.add"}	\N
\.


--
-- Data for Name: audit_log; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.audit_log (id, actor_id, action, entity_type, entity_id, before_json, after_json, ip_address, created_at, prev_hash, row_hash) FROM stdin;
1	1	create	group	1	\N	{"name": "Editors"}	::1	2026-08-01 00:00:00	\N	fb08569e0a181f3c519a47ed7faed20c576f9aee1d4ba3361bfb5d9bc9ffbbe0
2	1	create	group	2	\N	{"name": "Reviewers"}	::1	2026-08-01 00:00:00	fb08569e0a181f3c519a47ed7faed20c576f9aee1d4ba3361bfb5d9bc9ffbbe0	425622dddcec305339ccef4d45d997d899b77bcd4fc4ead48ff7df01a6dbc53b
3	1	create	group	3	\N	{"name": "Guests"}	::1	2026-08-01 00:00:00	425622dddcec305339ccef4d45d997d899b77bcd4fc4ead48ff7df01a6dbc53b	964f6fe6c7316cb7897260dffb899337c55e0d85c47c386f131533c5b209052f
\.


--
-- Data for Name: caddie; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.caddie (user_id, element_id) FROM stdin;
\.


--
-- Data for Name: categories; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.categories (id, name, id_uppercat, comment, dir, rank, status, site_id, visible, representative_picture_id, uppercats, commentable, global_rank, image_order, permalink, lastmodified) FROM stdin;
1	Sample Album	\N	\N	\N	1	public	\N	t	1	1	t	1	\N	\N	2026-08-10 14:13:12
2	Nested Sub Album	1	\N	\N	1	public	\N	t	4	1,2	t	1.1	\N	\N	2026-08-10 14:13:15
\.


--
-- Data for Name: comments; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.comments (id, image_id, date, author, email, author_id, anonymous_id, website_url, content, validated, validation_date) FROM stdin;
1	1	2026-08-01 00:00:00	fixture_admin	\N	1	127.0.0.1	\N	Fixture comment for integration tests.	t	2026-08-01 00:00:00
2	2	2026-08-01 00:00:00	regular_user	\N	3	127.0.0.2	\N	Another perspective on this photo.	t	2026-08-01 00:00:00
3	3	2026-08-01 00:00:00	power_user	\N	4	127.0.0.3	\N	Great composition and colors!	t	2026-08-01 00:00:00
4	1	2026-08-01 00:00:00	power_user	\N	4	127.0.0.3	\N	I keep coming back to this one.	t	2026-08-01 00:00:00
5	4	2026-08-01 00:00:00	fixture_admin	\N	1	127.0.0.1	\N	Pending comment for moderation.	f	\N
\.


--
-- Data for Name: config; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.config (param, value, comment) FROM stdin;
nb_comment_page	10	number of comments to display on each page
log	true	keep an history of visits on your website
comments_forall	false	even guest not registered can post comments
comments_order	"ASC"	comments order on picture page and cie
comments_author_mandatory	false	Comment author is mandatory
comments_email_mandatory	false	Comment email is mandatory
comments_enable_website	true	Enable "website" field on add comment form
user_can_delete_comment	false	administrators can allow user delete their own comments
user_can_edit_comment	false	administrators can allow user edit their own comments
email_admin_on_comment_edition	false	Send an email to the administrators when a comment is modified
email_admin_on_comment_deletion	false	Send an email to the administrators when a comment is deleted
gallery_locked	false	Lock your gallery temporary for non admin users
rate_anonymous	true	Rating pictures feature is also enabled for visitors
history_admin	false	keep a history of administrator visits on your website
history_guest	true	keep a history of guest visits on your website
allow_user_registration	true	allow visitors to register?
allow_user_customization	true	allow users to customize their gallery?
nbm_send_html_mail	true	Send mail on HTML format for notification by mail
nbm_send_mail_as	""	Send mail as param value for notification by mail
nbm_send_detailed_content	true	Send detailed content for notification by mail
nbm_complementary_mail_content	""	Complementary mail content for notification by mail
nbm_send_recent_post_dates	true	Send recent post by dates for notification by mail
email_admin_on_new_user	"none"	Send an email to theadministrators when a user registers
email_admin_on_comment	false	Send an email to the administrators when a valid comment is entered
email_admin_on_comment_validation	true	Send an email to the administrators when a comment requires validation
obligatory_user_mail_address	false	Mail address is obligatory for users
extents_for_templates	[]	Actived template-extension(s)
menubar_filter_icon	false	Display filter icon
index_sort_order_input	true	Display image order selection list
index_flat_icon	false	Display flat icon
index_posted_date_icon	true	Display calendar by posted date
index_created_date_icon	true	Display calendar by creation date icon
index_slideshow_icon	true	Display slideshow icon
index_new_icon	true	Display new icons next albums and pictures
picture_metadata_icon	true	Display metadata icon on picture page
picture_slideshow_icon	true	Display slideshow icon on picture page
picture_favorite_icon	true	Display favorite icon on picture page
picture_download_icon	true	Display download icon on picture page
picture_navigation_icons	true	Display navigation icons on picture page
picture_navigation_thumb	true	Display navigation thumbnails on picture page
picture_menu	false	Show menubar on picture page
picture_informations	{"file": false, "tags": true, "author": true, "visits": true, "filesize": false, "posted_on": true, "categories": true, "created_on": true, "dimensions": false, "rating_score": true, "privacy_level": true}	Information displayed on picture page
week_starts_on	"monday"	Monday may not be the first day of the week
order_by	"ORDER BY date_available DESC, file ASC, id ASC"	default photo order
order_by_inside_category	"ORDER BY date_available DESC, file ASC, id ASC"	default photo order inside category
original_resize	false	\N
original_resize_maxwidth	2016	\N
original_resize_maxheight	2016	\N
original_resize_quality	95	\N
mobile_theme	\N	\N
mail_theme	"clear"	\N
picture_sizes_icon	true	\N
index_sizes_icon	true	\N
index_edit_icon	true	\N
index_caddie_icon	true	\N
display_fromto	false	\N
picture_edit_icon	true	\N
picture_caddie_icon	true	\N
picture_representative_icon	true	\N
show_mobile_app_banner_in_admin	true	\N
show_mobile_app_banner_in_gallery	false	\N
index_search_in_set_button	false	\N
index_search_in_set_action	"true"	\N
upload_detect_duplicate	true	\N
webmaster_id	1	\N
use_standard_pages	true	\N
secret_key	"75c9c6fe09cb4cfdae81547823137019bb38fd8e"	a secret key specific to the gallery for internal use
activate_comments	true	Global parameter for usage of comments system
page_banner	"<h1>%gallery_title%</h1>\\n\\n<p>Welcome to my photo gallery</p>"	html displayed on the top each page of your gallery
piwigo_installed_version	"17.0.0"	\N
last_major_update	"2026-08-01 00:00:00"	\N
data_dir_checked	"1"	\N
lounge_active	true	\N
no_photo_yet	"false"	\N
gallery_title	"Fixture Gallery"	Title at top of each page and for RSS feed
comments_validation	true	administrators validate users comments before becoming visible
nb_categories_page	12	Param for categories pagination
rate	true	Rating pictures feature is enabled
show_piwigo_latest_news	false	\N
dashboard_check_for_updates	false	\N
\.


--
-- Data for Name: derivative_settings; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.derivative_settings (id, default_quality, watermark_json, custom_json) FROM stdin;
1	95	{"file": "", "xpos": 50, "ypos": 50, "opacity": 100, "xrepeat": 0, "yrepeat": 0, "min_size": [500, 500]}	[]
\.


--
-- Data for Name: derivative_size; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.derivative_size (name, enabled, max_width, max_height, max_crop, min_width, min_height, sharpen, last_mod_time) FROM stdin;
square	1	120	120	1.0000	120	120	0.0000	1786381991
thumb	1	144	144	0.0000	\N	\N	0.0000	1786381991
2small	1	240	240	0.0000	\N	\N	0.0000	1786381991
xsmall	1	432	324	0.0000	\N	\N	0.0000	1786381991
small	1	576	432	0.0000	\N	\N	0.0000	1786381991
medium	1	792	594	0.0000	\N	\N	0.0000	1786381991
large	1	1008	756	0.0000	\N	\N	0.0000	1786381991
xlarge	1	1224	918	0.0000	\N	\N	0.0000	1786381991
xxlarge	1	1656	1242	0.0000	\N	\N	0.0000	1786381991
3xlarge	0	2232	1674	0.0000	\N	\N	0.0000	1786381991
4xlarge	0	3000	2250	0.0000	\N	\N	0.0000	1786381991
\.


--
-- Data for Name: extension_ignored_updates; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.extension_ignored_updates (extension_type, extension_id, ignored_at) FROM stdin;
\.


--
-- Data for Name: favorites; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.favorites (user_id, image_id) FROM stdin;
1	1
1	3
1	5
\.


--
-- Data for Name: group_access; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.group_access (group_id, cat_id) FROM stdin;
1	1
1	2
2	1
3	1
\.


--
-- Data for Name: groups; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.groups (id, name, is_default, lastmodified) FROM stdin;
1	Editors	f	2026-08-01 00:00:00
2	Reviewers	f	2026-08-01 00:00:00
3	Guests	f	2026-08-01 00:00:00
\.


--
-- Data for Name: history; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.history (id, date, "time", user_id, ip, section, category_id, search_id, tag_ids, image_id, image_type, format_id, auth_key_id) FROM stdin;
\.


--
-- Data for Name: history_summary; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.history_summary (summary_id, year, month, day, hour, nb_pages, history_id_from, history_id_to) FROM stdin;
\.


--
-- Data for Name: image_category; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.image_category (image_id, category_id, rank) FROM stdin;
1	1	1
2	1	2
3	1	3
4	2	1
5	2	2
\.


--
-- Data for Name: image_format; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.image_format (format_id, image_id, ext, filesize) FROM stdin;
\.


--
-- Data for Name: image_tag; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.image_tag (image_id, tag_id) FROM stdin;
1	1
1	2
1	3
2	1
3	1
\.


--
-- Data for Name: images; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.images (id, file, date_available, date_creation, name, comment, author, hit, filesize, width, height, coi, representative_ext, date_metadata_update, rating_score, path, storage_category_id, level, md5sum, added_by, rotation, latitude, longitude, lastmodified) FROM stdin;
4	fixture-photo-4.jpg	2026-08-01 00:00:00	\N	Photo 4	\N	\N	0	1	200	150	\N	\N	2026-08-10	2	upload/2026/08/01/20260801000000-3df6d315.jpg	\N	0	3df6bd0ebb6f22ea988f2ffb1c3a9566	1	0	\N	\N	2026-08-10 14:13:17
5	fixture-photo-5.jpg	2026-08-01 00:00:00	\N	Photo 5	\N	\N	0	1	200	150	\N	\N	2026-08-10	\N	upload/2026/08/01/20260801000000-4b010581.jpg	\N	0	4b01d21f3d56009c3b1f913fafda86c5	1	0	\N	\N	2026-08-10 14:13:15
1	fixture-photo-1.jpg	2026-08-01 00:00:00	\N	Photo 1	\N	\N	0	1	200	150	\N	\N	2026-08-10	4.5	upload/2026/08/01/20260801000000-2e7e2ce3.jpg	\N	0	2e7ee450c4a4cffe42945205029782b9	1	0	\N	\N	2026-08-10 14:13:17
2	fixture-photo-2.jpg	2026-08-01 00:00:00	\N	Photo 2	\N	\N	0	1	200	150	\N	\N	2026-08-10	3	upload/2026/08/01/20260801000000-4a0136e2.jpg	\N	0	4a010138f010067cfc713afb6dcf45e1	1	0	\N	\N	2026-08-10 14:13:17
3	fixture-photo-3.jpg	2026-08-01 00:00:00	\N	Photo 3	\N	\N	0	1	200	150	\N	\N	2026-08-10	5	upload/2026/08/01/20260801000000-a6a01c06.jpg	\N	0	a6a04acded208db63890b74c4252a012	1	0	\N	\N	2026-08-10 14:13:17
\.


--
-- Data for Name: integrity_ignored_anomalies; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.integrity_ignored_anomalies (anomaly_id, piwigo_version, ignored_at) FROM stdin;
\.


--
-- Data for Name: languages; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.languages (id, version, name) FROM stdin;
en_UK	16.3.0	English (Great Britain)
\.


--
-- Data for Name: lounge; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.lounge (image_id, category_id) FROM stdin;
\.


--
-- Data for Name: old_permalinks; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.old_permalinks (cat_id, permalink, date_deleted, last_hit, hit) FROM stdin;
1	old-sample-album	2026-08-01 00:00:00	2026-08-01 00:00:00	42
\.


--
-- Data for Name: plugin_migrations; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.plugin_migrations (plugin_id, version, executed_at) FROM stdin;
\.


--
-- Data for Name: plugins; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.plugins (id, state, version) FROM stdin;
\.


--
-- Data for Name: rate; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.rate (user_id, element_id, anonymous_id, rate, date) FROM stdin;
1	1		5	2026-08-01
3	1		4	2026-08-01
4	2		3	2026-08-01
1	3		5	2026-08-01
3	4		2	2026-08-01
\.


--
-- Data for Name: search; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.search (id, search_uuid, created_on, created_by, forked_from, rules) FROM stdin;
\.


--
-- Data for Name: search_filter_view; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.search_filter_view (name, config_json, created_at) FROM stdin;
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.sessions (id, data, expiration) FROM stdin;
11c7b76e0d4b9ea7413be9088473f1f0		2026-08-01 00:00:00
849b99c5216593bf118155c326116b77		2026-08-01 00:00:00
9d5f43dc0d2f8e73178b2315998c20a3		2026-08-01 00:00:00
f7e0f8df945af550476b59c8c188bbbb		2026-08-01 00:00:00
9a9b30216df4d6ba19b0d1ee99c361ea		2026-08-01 00:00:00
181a4e2d08983eef4ef71dc7e8fa24c1	pwg_uid|i:1;connected_with|s:16:"ws_session_login";	2026-08-01 00:00:00
\.


--
-- Data for Name: sites; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.sites (id, galleries_url) FROM stdin;
1	/home/torres/piwigo17-rewrite-sql/galleries/
\.


--
-- Data for Name: tags; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.tags (id, name, url_name, lastmodified) FROM stdin;
1	nature	nature	2026-08-01 00:00:00
2	travel	travel	2026-08-01 00:00:00
3	family	family	2026-08-01 00:00:00
\.


--
-- Data for Name: themes; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.themes (id, version, name) FROM stdin;
\.


--
-- Data for Name: user_access; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.user_access (user_id, cat_id) FROM stdin;
\.


--
-- Data for Name: user_auth_keys; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.user_auth_keys (auth_key_id, auth_key, apikey_secret, user_id, created_on, duration, expired_on, apikey_name, key_type, revoked_on, last_used_on, last_notified_on) FROM stdin;
\.


--
-- Data for Name: user_failed_logins; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.user_failed_logins (id, user_id, ip, attempted_at) FROM stdin;
\.


--
-- Data for Name: user_feed; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.user_feed (id, user_id, last_check) FROM stdin;
\.


--
-- Data for Name: user_group; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.user_group (user_id, group_id) FROM stdin;
1	1
3	1
3	2
4	3
\.


--
-- Data for Name: user_infos; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.user_infos (user_id, nb_image_page, status, language, expand, show_nb_comments, show_nb_hits, recent_period, theme, registration_date, enabled_high, level, activation_key, activation_key_expire, last_visit, last_visit_from_history, lastmodified, preferences) FROM stdin;
2	15	guest	en_UK	f	f	f	7	default	2026-08-01 00:00:00	t	0	\N	\N	\N	f	2026-08-01 00:00:00	\N
1	15	webmaster	en_UK	f	f	f	7	default	2026-08-01 00:00:00	t	8	\N	\N	\N	f	2026-08-01 00:00:00	{"show_whats_new_16": false}
3	15	normal	en_UK	f	f	f	7	default	2026-08-01 00:00:00	t	0	\N	\N	\N	f	2026-08-01 00:00:00	\N
4	15	normal	en_UK	f	f	f	7	default	2026-08-01 00:00:00	t	0	\N	\N	\N	f	2026-08-01 00:00:00	\N
\.


--
-- Data for Name: user_mail_notification; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.user_mail_notification (user_id, check_key, enabled, last_send) FROM stdin;
1	abcdef1234567890	t	2026-08-01 00:00:00
3	ghijkl9876543210	f	\N
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: piwigo_fixture_regen
--

COPY public.users (id, username, password, mail_address) FROM stdin;
1	fixture_admin	$2y$04$mneAv48AR7mwW0GoBNh4vefxc3598N7uoLGPa83dfKBCp8FVNkQQO	fixture_admin@example.test
2	guest	\N	\N
3	regular_user	$2y$04$To87nw9.k48SazVd75zrbeZMs3dtZZgk3gYQSuBGi8B8esr.YdKBu	\N
4	power_user	$2y$04$FA1vvlBXxvikFw7ngnbKEuyz8hsWefDyafHW65XUcesLXUWy4Fy2a	\N
\.


--
-- Name: activity_activity_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.activity_activity_id_seq', 19, true);


--
-- Name: audit_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.audit_log_id_seq', 3, true);


--
-- Name: categories_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.categories_id_seq', 2, true);


--
-- Name: comments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.comments_id_seq', 5, true);


--
-- Name: groups_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.groups_id_seq', 3, true);


--
-- Name: history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.history_id_seq', 1, false);


--
-- Name: history_summary_summary_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.history_summary_summary_id_seq', 1, false);


--
-- Name: image_format_format_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.image_format_format_id_seq', 1, false);


--
-- Name: images_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.images_id_seq', 5, true);


--
-- Name: search_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.search_id_seq', 1, false);


--
-- Name: sites_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.sites_id_seq', 1, true);


--
-- Name: tags_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.tags_id_seq', 3, true);


--
-- Name: user_auth_keys_auth_key_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.user_auth_keys_auth_key_id_seq', 1, false);


--
-- Name: user_failed_logins_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.user_failed_logins_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: piwigo_fixture_regen
--

SELECT pg_catalog.setval('public.users_id_seq', 4, true);


--
-- Name: activity activity_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.activity
    ADD CONSTRAINT activity_pkey PRIMARY KEY (activity_id);


--
-- Name: audit_log audit_log_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_pkey PRIMARY KEY (id);


--
-- Name: caddie caddie_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.caddie
    ADD CONSTRAINT caddie_pkey PRIMARY KEY (user_id, element_id);


--
-- Name: categories categories_i3_unique; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_i3_unique UNIQUE (permalink);


--
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);


--
-- Name: comments comments_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.comments
    ADD CONSTRAINT comments_pkey PRIMARY KEY (id);


--
-- Name: config config_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.config
    ADD CONSTRAINT config_pkey PRIMARY KEY (param);


--
-- Name: derivative_settings derivative_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.derivative_settings
    ADD CONSTRAINT derivative_settings_pkey PRIMARY KEY (id);


--
-- Name: derivative_size derivative_size_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.derivative_size
    ADD CONSTRAINT derivative_size_pkey PRIMARY KEY (name);


--
-- Name: extension_ignored_updates extension_ignored_updates_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.extension_ignored_updates
    ADD CONSTRAINT extension_ignored_updates_pkey PRIMARY KEY (extension_type, extension_id);


--
-- Name: favorites favorites_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.favorites
    ADD CONSTRAINT favorites_pkey PRIMARY KEY (user_id, image_id);


--
-- Name: group_access group_access_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.group_access
    ADD CONSTRAINT group_access_pkey PRIMARY KEY (group_id, cat_id);


--
-- Name: groups groups_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.groups
    ADD CONSTRAINT groups_pkey PRIMARY KEY (id);


--
-- Name: groups groups_ui1_unique; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.groups
    ADD CONSTRAINT groups_ui1_unique UNIQUE (name);


--
-- Name: history history_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.history
    ADD CONSTRAINT history_pkey PRIMARY KEY (id);


--
-- Name: history_summary history_summary_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.history_summary
    ADD CONSTRAINT history_summary_pkey PRIMARY KEY (summary_id);


--
-- Name: history_summary history_summary_ymdh; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.history_summary
    ADD CONSTRAINT history_summary_ymdh UNIQUE (year, month, day, hour);


--
-- Name: image_category image_category_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.image_category
    ADD CONSTRAINT image_category_pkey PRIMARY KEY (image_id, category_id);


--
-- Name: image_format image_format_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.image_format
    ADD CONSTRAINT image_format_pkey PRIMARY KEY (format_id);


--
-- Name: image_tag image_tag_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.image_tag
    ADD CONSTRAINT image_tag_pkey PRIMARY KEY (image_id, tag_id);


--
-- Name: images images_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.images
    ADD CONSTRAINT images_pkey PRIMARY KEY (id);


--
-- Name: integrity_ignored_anomalies integrity_ignored_anomalies_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.integrity_ignored_anomalies
    ADD CONSTRAINT integrity_ignored_anomalies_pkey PRIMARY KEY (anomaly_id, piwigo_version);


--
-- Name: languages languages_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.languages
    ADD CONSTRAINT languages_pkey PRIMARY KEY (id);


--
-- Name: lounge lounge_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.lounge
    ADD CONSTRAINT lounge_pkey PRIMARY KEY (image_id, category_id);


--
-- Name: old_permalinks old_permalinks_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.old_permalinks
    ADD CONSTRAINT old_permalinks_pkey PRIMARY KEY (permalink);


--
-- Name: plugin_migrations plugin_migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.plugin_migrations
    ADD CONSTRAINT plugin_migrations_pkey PRIMARY KEY (plugin_id, version);


--
-- Name: plugins plugins_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.plugins
    ADD CONSTRAINT plugins_pkey PRIMARY KEY (id);


--
-- Name: rate rate_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.rate
    ADD CONSTRAINT rate_pkey PRIMARY KEY (element_id, user_id, anonymous_id);


--
-- Name: search_filter_view search_filter_view_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.search_filter_view
    ADD CONSTRAINT search_filter_view_pkey PRIMARY KEY (name);


--
-- Name: search search_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.search
    ADD CONSTRAINT search_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: sites sites_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_pkey PRIMARY KEY (id);


--
-- Name: sites sites_ui1_unique; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_ui1_unique UNIQUE (galleries_url);


--
-- Name: tags tags_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.tags
    ADD CONSTRAINT tags_pkey PRIMARY KEY (id);


--
-- Name: themes themes_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.themes
    ADD CONSTRAINT themes_pkey PRIMARY KEY (id);


--
-- Name: user_access user_access_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_access
    ADD CONSTRAINT user_access_pkey PRIMARY KEY (user_id, cat_id);


--
-- Name: user_auth_keys user_auth_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_auth_keys
    ADD CONSTRAINT user_auth_keys_pkey PRIMARY KEY (auth_key_id);


--
-- Name: user_failed_logins user_failed_logins_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_failed_logins
    ADD CONSTRAINT user_failed_logins_pkey PRIMARY KEY (id);


--
-- Name: user_feed user_feed_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_feed
    ADD CONSTRAINT user_feed_pkey PRIMARY KEY (id);


--
-- Name: user_group user_group_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_group
    ADD CONSTRAINT user_group_pkey PRIMARY KEY (group_id, user_id);


--
-- Name: user_infos user_infos_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_infos
    ADD CONSTRAINT user_infos_pkey PRIMARY KEY (user_id);


--
-- Name: user_mail_notification user_mail_notification_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_mail_notification
    ADD CONSTRAINT user_mail_notification_pkey PRIMARY KEY (user_id);


--
-- Name: user_mail_notification user_mail_notification_ui1_unique; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_mail_notification
    ADD CONSTRAINT user_mail_notification_ui1_unique UNIQUE (check_key);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_ui1_unique; Type: CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_ui1_unique UNIQUE (username);


--
-- Name: categories_ft_name_comment; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX categories_ft_name_comment ON public.categories USING gin (tsv_search);


--
-- Name: categories_i2; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX categories_i2 ON public.categories USING btree (id_uppercat);


--
-- Name: categories_lastmodified_idx; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX categories_lastmodified_idx ON public.categories USING btree (lastmodified);


--
-- Name: categories_site_id_idx; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX categories_site_id_idx ON public.categories USING btree (site_id);


--
-- Name: old_permalinks_cat_id_idx; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX old_permalinks_cat_id_idx ON public.old_permalinks USING btree (cat_id);


--
-- Name: comments_i1; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX comments_i1 ON public.comments USING btree (image_id);


--
-- Name: comments_i2; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX comments_i2 ON public.comments USING btree (validation_date);


--
-- Name: groups_lastmodified_idx; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX groups_lastmodified_idx ON public.groups USING btree (lastmodified);


--
-- Name: idx_audit_log_actor; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX idx_audit_log_actor ON public.audit_log USING btree (actor_id);


--
-- Name: idx_audit_log_created_at; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX idx_audit_log_created_at ON public.audit_log USING btree (created_at);


--
-- Name: idx_audit_log_entity; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX idx_audit_log_entity ON public.audit_log USING btree (entity_type, entity_id);


--
-- Name: idx_history_date_desc; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX idx_history_date_desc ON public.history USING btree (date DESC, id DESC);


--
-- Name: idx_images_date_desc; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX idx_images_date_desc ON public.images USING btree (date_available DESC, id DESC);


--
-- Name: idx_user_failed_logins_ip_time; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX idx_user_failed_logins_ip_time ON public.user_failed_logins USING btree (ip, attempted_at);


--
-- Name: idx_user_failed_logins_user_time; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX idx_user_failed_logins_user_time ON public.user_failed_logins USING btree (user_id, attempted_at);


--
-- Name: image_category_i1; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX image_category_i1 ON public.image_category USING btree (category_id);


--
-- Name: image_tag_i1; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX image_tag_i1 ON public.image_tag USING btree (tag_id);


--
-- Name: images_ft_author; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_ft_author ON public.images USING gin (tsv_author);


--
-- Name: images_ft_name_comment; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_ft_name_comment ON public.images USING gin (tsv_search);


--
-- Name: images_i1; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_i1 ON public.images USING btree (storage_category_id);


--
-- Name: images_i2; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_i2 ON public.images USING btree (date_available);


--
-- Name: images_i3; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_i3 ON public.images USING btree (rating_score);


--
-- Name: images_i4; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_i4 ON public.images USING btree (hit);


--
-- Name: images_i5; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_i5 ON public.images USING btree (date_creation);


--
-- Name: images_i6; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_i6 ON public.images USING btree (latitude);


--
-- Name: images_i7; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_i7 ON public.images USING btree (path);


--
-- Name: images_i8; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_i8 ON public.images USING btree (md5sum);


--
-- Name: images_i9; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_i9 ON public.images USING btree (file);


--
-- Name: images_lastmodified_idx; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX images_lastmodified_idx ON public.images USING btree (lastmodified);


--
-- Name: tags_ft_name; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX tags_ft_name ON public.tags USING gin (tsv_search);


--
-- Name: tags_i1; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX tags_i1 ON public.tags USING btree (url_name);


--
-- Name: tags_lastmodified_idx; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX tags_lastmodified_idx ON public.tags USING btree (lastmodified);


--
-- Name: user_infos_lastmodified_idx; Type: INDEX; Schema: public; Owner: piwigo_fixture_regen
--

CREATE INDEX user_infos_lastmodified_idx ON public.user_infos USING btree (lastmodified);












--
-- Name: activity fk_activity_performed_by; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.activity
    ADD CONSTRAINT fk_activity_performed_by FOREIGN KEY (performed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: audit_log fk_audit_log_actor_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT fk_audit_log_actor_id FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: caddie fk_caddie_element_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.caddie
    ADD CONSTRAINT fk_caddie_element_id FOREIGN KEY (element_id) REFERENCES public.images(id) ON DELETE CASCADE;


--
-- Name: caddie fk_caddie_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.caddie
    ADD CONSTRAINT fk_caddie_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: categories fk_categories_id_uppercat; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT fk_categories_id_uppercat FOREIGN KEY (id_uppercat) REFERENCES public.categories(id) ON DELETE SET NULL;


--
-- Name: categories fk_categories_representative_picture_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT fk_categories_representative_picture_id FOREIGN KEY (representative_picture_id) REFERENCES public.images(id) ON DELETE SET NULL;


--
-- Name: categories fk_categories_site_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT fk_categories_site_id FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: old_permalinks fk_old_permalinks_cat_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.old_permalinks
    ADD CONSTRAINT fk_old_permalinks_cat_id FOREIGN KEY (cat_id) REFERENCES public.categories(id) ON DELETE CASCADE;


--
-- Name: comments fk_comments_author_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.comments
    ADD CONSTRAINT fk_comments_author_id FOREIGN KEY (author_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: comments fk_comments_image_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.comments
    ADD CONSTRAINT fk_comments_image_id FOREIGN KEY (image_id) REFERENCES public.images(id) ON DELETE CASCADE;


--
-- Name: favorites fk_favorites_image_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.favorites
    ADD CONSTRAINT fk_favorites_image_id FOREIGN KEY (image_id) REFERENCES public.images(id) ON DELETE CASCADE;


--
-- Name: favorites fk_favorites_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.favorites
    ADD CONSTRAINT fk_favorites_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: group_access fk_group_access_cat_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.group_access
    ADD CONSTRAINT fk_group_access_cat_id FOREIGN KEY (cat_id) REFERENCES public.categories(id) ON DELETE CASCADE;


--
-- Name: group_access fk_group_access_group_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.group_access
    ADD CONSTRAINT fk_group_access_group_id FOREIGN KEY (group_id) REFERENCES public.groups(id) ON DELETE CASCADE;


--
-- Name: history fk_history_auth_key_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.history
    ADD CONSTRAINT fk_history_auth_key_id FOREIGN KEY (auth_key_id) REFERENCES public.user_auth_keys(auth_key_id) ON DELETE SET NULL;


--
-- Name: history fk_history_category_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.history
    ADD CONSTRAINT fk_history_category_id FOREIGN KEY (category_id) REFERENCES public.categories(id) ON DELETE SET NULL;


--
-- Name: history fk_history_format_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.history
    ADD CONSTRAINT fk_history_format_id FOREIGN KEY (format_id) REFERENCES public.image_format(format_id) ON DELETE SET NULL;


--
-- Name: history fk_history_image_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.history
    ADD CONSTRAINT fk_history_image_id FOREIGN KEY (image_id) REFERENCES public.images(id) ON DELETE SET NULL;


--
-- Name: history fk_history_search_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.history
    ADD CONSTRAINT fk_history_search_id FOREIGN KEY (search_id) REFERENCES public.search(id) ON DELETE SET NULL;


--
-- Name: history fk_history_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.history
    ADD CONSTRAINT fk_history_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: image_category fk_image_category_category_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.image_category
    ADD CONSTRAINT fk_image_category_category_id FOREIGN KEY (category_id) REFERENCES public.categories(id) ON DELETE CASCADE;


--
-- Name: image_category fk_image_category_image_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.image_category
    ADD CONSTRAINT fk_image_category_image_id FOREIGN KEY (image_id) REFERENCES public.images(id) ON DELETE CASCADE;


--
-- Name: image_format fk_image_format_image_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.image_format
    ADD CONSTRAINT fk_image_format_image_id FOREIGN KEY (image_id) REFERENCES public.images(id) ON DELETE CASCADE;


--
-- Name: image_tag fk_image_tag_image_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.image_tag
    ADD CONSTRAINT fk_image_tag_image_id FOREIGN KEY (image_id) REFERENCES public.images(id) ON DELETE CASCADE;


--
-- Name: image_tag fk_image_tag_tag_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.image_tag
    ADD CONSTRAINT fk_image_tag_tag_id FOREIGN KEY (tag_id) REFERENCES public.tags(id) ON DELETE CASCADE;


--
-- Name: images fk_images_added_by; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.images
    ADD CONSTRAINT fk_images_added_by FOREIGN KEY (added_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: images fk_images_storage_category_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.images
    ADD CONSTRAINT fk_images_storage_category_id FOREIGN KEY (storage_category_id) REFERENCES public.categories(id) ON DELETE SET NULL;


--
-- Name: lounge fk_lounge_category_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.lounge
    ADD CONSTRAINT fk_lounge_category_id FOREIGN KEY (category_id) REFERENCES public.categories(id) ON DELETE CASCADE;


--
-- Name: lounge fk_lounge_image_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.lounge
    ADD CONSTRAINT fk_lounge_image_id FOREIGN KEY (image_id) REFERENCES public.images(id) ON DELETE CASCADE;


--
-- Name: rate fk_rate_element_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.rate
    ADD CONSTRAINT fk_rate_element_id FOREIGN KEY (element_id) REFERENCES public.images(id) ON DELETE CASCADE;


--
-- Name: rate fk_rate_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.rate
    ADD CONSTRAINT fk_rate_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: search fk_search_created_by; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.search
    ADD CONSTRAINT fk_search_created_by FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: search fk_search_forked_from; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.search
    ADD CONSTRAINT fk_search_forked_from FOREIGN KEY (forked_from) REFERENCES public.search(id) ON DELETE SET NULL;


--
-- Name: user_access fk_user_access_cat_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_access
    ADD CONSTRAINT fk_user_access_cat_id FOREIGN KEY (cat_id) REFERENCES public.categories(id) ON DELETE CASCADE;


--
-- Name: user_access fk_user_access_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_access
    ADD CONSTRAINT fk_user_access_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_auth_keys fk_user_auth_keys_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_auth_keys
    ADD CONSTRAINT fk_user_auth_keys_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_failed_logins fk_user_failed_logins_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_failed_logins
    ADD CONSTRAINT fk_user_failed_logins_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_feed fk_user_feed_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_feed
    ADD CONSTRAINT fk_user_feed_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_group fk_user_group_group_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_group
    ADD CONSTRAINT fk_user_group_group_id FOREIGN KEY (group_id) REFERENCES public.groups(id) ON DELETE CASCADE;


--
-- Name: user_group fk_user_group_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_group
    ADD CONSTRAINT fk_user_group_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_infos fk_user_infos_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_infos
    ADD CONSTRAINT fk_user_infos_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_mail_notification fk_user_mail_notification_user_id; Type: FK CONSTRAINT; Schema: public; Owner: piwigo_fixture_regen
--

ALTER TABLE ONLY public.user_mail_notification
    ADD CONSTRAINT fk_user_mail_notification_user_id FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--


