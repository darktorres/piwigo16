# Piwigo Codebase Analysis & Documentation

**Project**: Piwigo Photo Gallery (Fork - Version 14.x)
**Date**: November 2025
**Branch**: 14.x
**Platform**: PHP-based web application with modern JavaScript frontend

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Project Structure](#project-structure)
3. [Technology Stack](#technology-stack)
4. [Architecture & Design Patterns](#architecture--design-patterns)
5. [Core Modules](#core-modules)
6. [Entry Points](#entry-points)
7. [Configuration Management](#configuration-management)
8. [Database Layer](#database-layer)
9. [Plugin & Theme System](#plugin--theme-system)
10. [Security Features](#security-features)
11. [Development & Build](#development--build)
12. [Quick Reference](#quick-reference)

---

## Project Overview

Piwigo is a mature, open-source photo gallery application that enables users to easily manage, organize, and share photo collections through a web interface. This fork is actively maintained on the 14.x branch with modern PHP 8.4+ standards, responsive design updates, and enhanced image processing capabilities.

### Key Characteristics
- **Type**: Web-based photo gallery application
- **Language**: Primary PHP (backend), JavaScript (frontend)
- **License**: Open source (contributions welcome)
- **Deployment**: Traditional web hosting (Apache/Nginx + PHP + MySQL/PostgreSQL)
- **Scalability**: Supports thousands of photos with efficient caching and image derivatives

### Version Information
- **Current Version**: 14.5.0
- **Minimum PHP**: 8.4.6
- **Minimum MySQL**: 8.4.4
- **Minimum MariaDB**: Equivalent to MySQL 8.4.4
- **PostgreSQL**: Full support as alternative to MySQL

---

## Project Structure

### Directory Hierarchy

```
piwigo-fork/
│
├── inc/                          # Core framework (79 PHP files, ~26,456 LOC)
│   ├── dblayer/                 # Database abstraction layer
│   │   ├── functions_mysqli.php # MySQL/MariaDB adapter
│   │   └── functions_pgsql.php  # PostgreSQL adapter
│   │
│   ├── ws_functions/            # Web service API endpoints
│   │   ├── pwg.php              # Core API methods (39.2KB)
│   │   ├── pwg_categories.php   # Category endpoints
│   │   ├── pwg_images.php       # Image endpoints (86.8KB - largest)
│   │   ├── pwg_users.php        # User management endpoints
│   │   ├── pwg_tags.php         # Tag management
│   │   ├── pwg_groups.php       # Group management
│   │   ├── pwg_permissions.php  # Permission system
│   │   └── pwg_extensions.php   # Extension/plugin info
│   │
│   ├── inflectors/              # Text inflection rules
│   │
│   ├── Common Core Modules
│   ├── config_default.php       # Default configuration values
│   ├── constants.php            # Global constants (v14.5.0)
│   ├── Config.php               # Central configuration object
│   ├── common.php               # Request init, sanitization, security
│   ├── section_init.php         # URL routing and page initialization
│   ├── Template.php             # Smarty wrapper with custom plugins
│   ├── functions.php            # 100+ utility functions (110.7KB)
│   │
│   ├── User Management
│   ├── functions_user.php       # Authentication, authorization
│   ├── functions_session.php    # Session handling
│   ├── functions_cookie.php     # Cookie management
│   ├── PwgSessionHandler.php    # Custom session handler
│   │
│   ├── Image & Photo Management
│   ├── DerivativeImage.php      # Thumbnail/medium/large handling
│   ├── DerivativeParams.php     # Derivative parameters
│   ├── ImageStdParams.php       # Standard image sizing
│   ├── SrcImage.php             # Source image representation
│   ├── SizingParams.php         # Image sizing logic
│   ├── WatermarkParams.php      # Watermarking configuration
│   ├── functions_picture.php    # Picture-specific functions
│   │
│   ├── Category/Album Management
│   ├── functions_category.php   # Category operations (27.5KB)
│   ├── category_cats.php        # Category tree rendering
│   ├── category_default.php     # Default category display
│   │
│   ├── Advanced Features
│   ├── functions_search.php     # Advanced search (45.6KB)
│   ├── QSearchScope.php         # Search implementation
│   ├── CalendarBase.php         # Calendar base (11.4KB)
│   ├── CalendarMonthly.php      # Monthly calendar (16.7KB)
│   ├── CalendarWeekly.php       # Weekly calendar
│   ├── functions_tag.php        # Tag management
│   ├── functions_comment.php    # Comments (18.6KB)
│   ├── functions_rate.php       # Photo ratings
│   ├── functions_notification.php # Notifications (20.9KB)
│   ├── functions_mail.php       # Email system (30.8KB)
│   │
│   ├── HTML & Template
│   ├── functions_html.php       # HTML generation (23.4KB)
│   ├── functions_url.php        # URL building (27.9KB)
│   ├── BlockManager.php         # Content blocks
│   ├── DisplayBlock.php         # Block rendering
│   ├── RegisteredBlock.php      # Block registration
│   ├── menubar.php              # Sidebar menu
│   │
│   ├── Web Services
│   ├── PwgServer.php            # REST/XML-RPC server
│   ├── PwgRequestHandler.php    # Request parsing
│   ├── PwgResponseEncoder.php   # Response encoding
│   ├── PwgError.php             # Error responses
│   ├── ws_core.php              # Constants
│   ├── ws_init.php              # Initialization
│   │
│   ├── Assets & Performance
│   ├── ScriptLoader.php         # JavaScript asset management
│   ├── CssLoader.php            # CSS asset management
│   ├── FileCombiner.php         # Asset combining/minification
│   ├── PersistentCache.php      # Cache interface
│   ├── PersistentFileCache.php  # File-based cache
│   │
│   ├── Metadata & Processing
│   ├── functions_metadata.php   # EXIF/IPTC handling
│   ├── functions_metadata_admin.php
│   ├── picture_metadata.php     # Metadata display
│   │
│   ├── Plugin System
│   ├── functions_plugins.php    # Plugin hooks/filters
│   ├── PluginMaintain.php       # Plugin lifecycle
│   ├── ThemeMaintain.php        # Theme lifecycle
│   │
│   ├── Miscellaneous
│   ├── filter.php               # Result filtering
│   ├── ImageRect.php            # Image rectangle
│   ├── no_photo_yet.php         # Placeholder
│   └── page_header.php, page_tail.php
│
├── admin/                        # Administrative interface
│   ├── inc/                     # Admin-specific functions
│   │   ├── functions_admin.php  # Core admin functions (149.5KB)
│   │   ├── functions_install.php
│   │   ├── functions_upload.php # File upload (34.4KB)
│   │   ├── functions_upgrade.php
│   │   ├── image_imagick.php    # ImageMagick processor
│   │   ├── image_vips.php       # libvips processor
│   │   ├── image_gd.php         # GD processor
│   │   ├── pwg_image.php        # Image abstraction
│   │   ├── ExtensionFunctionUpdater.php (62.9KB)
│   │   ├── languages.php        # Language management
│   │   ├── themes.php           # Theme management
│   │   ├── plugins.php          # Plugin management
│   │   ├── updates.php          # Update system
│   │   └── LocalSiteReader.php
│   │
│   ├── templates/               # Admin theme templates
│   │
│   └── Main Admin Pages
│       ├── albums.php           # Album management
│       ├── album.php            # Single album edit
│       ├── batch_manager*.php   # Bulk operations
│       ├── photos_add*.php      # Photo upload (direct/FTP)
│       ├── picture_modify.php   # Photo editing
│       ├── picture_coi.php      # Center of interest
│       ├── picture_formats.php  # Format management
│       ├── comments.php         # Moderation
│       ├── configuration.php    # Settings
│       ├── users.php            # User management
│       ├── groups.php           # Group management
│       ├── permissions.php      # Permissions
│       ├── plugins*.php         # Plugin management
│       ├── themes.php           # Theme selection
│       ├── languages*.php       # Language management
│       ├── maintenance*.php     # System maintenance
│       ├── history.php          # Activity log
│       └── admin.php            # Main dashboard
│
├── themes/                       # Theme templates
│   ├── bootstrap_darkroom/      # Modern Bootstrap-based theme
│   │   ├── package.json         # Theme's own build system
│   │   ├── template/            # Smarty templates
│   │   ├── css/                 # Stylesheets
│   │   └── js/                  # Scripts
│   │
│   ├── modus/                   # Default theme
│   ├── default/                 # Classic theme
│   ├── elegant/                 # Elegant design
│   └── smartpocket/             # Mobile-optimized
│
├── plugins/                      # Built-in plugins
│   ├── AdminTools/              # Admin utilities
│   ├── GDThumb/                 # GD image processor
│   ├── language_switch/         # Language switcher
│   ├── LocalFilesEditor/        # Template editor
│   └── TakeATour/               # Tutorial system
│
├── install/                      # Installation
│   ├── piwigo_structure-*.sql   # Database schemas
│   ├── config.sql               # Initial config
│   ├── test_config.php          # Config validator
│   └── index.php                # Install wizard
│
├── language/                     # Internationalization
│   ├── en_UK/                   # English strings
│   ├── fr_FR/                   # French
│   ├── de_DE/                   # German
│   └── ... (50+ languages)
│
├── local/                        # User-specific (git-ignored)
│   └── config/
│       ├── config.php           # Custom configuration
│       └── database.php         # Database credentials
│
├── _data/                        # Application data
│   ├── i/                       # Derivative image cache
│   ├── templates/               # SQL templates
│   └── themes/                  # Theme metadata
│
├── galleries/                    # User uploaded content (git-ignored)
├── upload/                       # Temporary uploads (git-ignored)
├── cache/                        # Generated cache (git-ignored)
├── coverage/                     # Code coverage reports
│
├── tests/                        # Test suite
│   ├── package.json             # Test dependencies
│   └── *.test.js
│
├── docker/                       # Docker configuration
│   ├── docker-compose.yaml
│   └── ... (container files)
│
├── vendor/                       # Composer dependencies (git-ignored)
├── node_modules/                # NPM dependencies (git-ignored)
│
├── Root Entry Points
├── index.php                    # Main gallery
├── picture.php                  # Photo viewer
├── i.php                        # Image server (derivatives)
├── comments.php                 # Comments
├── feed.php                     # RSS/Atom feeds
├── ws.php                       # Web service API
├── action.php                   # AJAX handler
├── identification.php           # Login
├── password.php                 # Password reset
├── notification.php             # Notifications
├── admin.php                    # Admin panel
├── install.php                  # Installation
├── about.php                    # About page
│
├── Configuration Files
├── composer.json                # PHP dependencies
├── composer.lock                # PHP lock file
├── package.json                 # JS dependencies
├── bun.lock                     # Bun lock file
├── .eslintrc.json              # ESLint config
├── .ecs.php                    # ECS config (PHP standards)
├── phpstan.neon.dist           # PHPStan config
├── psalm.xml.dist              # Psalm config
└── rector.php                   # Rector config
```

---

## Technology Stack

### Backend

#### Language & Core
- **PHP**: 8.4.x (strict types, modern type hints, attributes)
- **Database**: MySQL 8.4+ or PostgreSQL (with abstraction layer)

#### Key Dependencies
| Package | Version | Purpose |
|---------|---------|---------|
| Smarty | 5.5.1+ | Template engine |
| PHPMailer | 6.10.0+ | Email sending |
| jcupitt/vips | 2.5.0+ | Image processing (libvips) |
| Emogrifier | 7.3+ | CSS inlining for emails |
| JShrink | 1.7+ | JavaScript minification |
| CSSMin | 4.1.1+ | CSS minification |
| PCLZip | 2.8.2+ | ZIP operations |
| UniversalFeedCreator | - | Feed generation |

#### Optional Processors
- **ImageMagick**: Recommended for advanced image processing
- **libvips**: Modern, efficient image library (via jcupitt/vips)
- **GD Library**: Built-in PHP image processor (fallback)

#### Code Quality Tools (Development)
- **PHPStan 2.1.17**: Static analysis
- **Psalm 6.12.0**: Type checking
- **Rector 2.0.17**: Automated refactoring
- **Easy Coding Standard**: PHP code standards enforcement
- **PHPUnit**: Unit testing

### Frontend

#### JavaScript/Build
- **Package Manager**: Bun (modern npm alternative)
- **Runtime**: Node.js 18+

#### Core Libraries
| Package | Version | Purpose |
|---------|---------|---------|
| jQuery | 1.11.3+ | DOM manipulation (legacy) |
| jQuery Colorbox | 1.5.15 | Lightbox functionality |
| Plupload | 2.2.0 | Advanced file uploads |
| PhotoSwipe | 5.x | Image gallery/carousel |
| Moment.js | - | Date/time handling |
| Underscore.js | - | Utility functions |
| Mousetrap | - | Keyboard shortcuts |

#### UI Components
- **Bootstrap Tour**: Step-by-step tours
- **Chart.js 2.9.3**: Data visualization
- **DataTables 1.10.11**: Table sorting/pagination
- **Chosen & Selectize**: Enhanced select menus
- **CodeMirror**: Code editor
- **jQuery Color Picker**: Color selection

#### Code Quality Tools
- **ESLint 9.28.0**: JavaScript linting
- **Biome 1.9.4**: Fast code formatter and linter
- **PostCSS**: CSS processing

### Databases

#### MySQL/MariaDB
```sql
-- Key tables:
piwigo_images          -- Photo metadata
piwigo_categories      -- Albums/folders
piwigo_image_category  -- Photo-album relationships
piwigo_users           -- User accounts
piwigo_user_access     -- User permissions
piwigo_comments        -- Photo comments
piwigo_favorites       -- User favorites
piwigo_rates           -- Photo ratings
piwigo_history         -- Activity log
piwigo_tags            -- Photo tags
piwigo_image_tag       -- Photo-tag relationships
piwigo_config          -- Runtime settings
piwigo_plugins         -- Plugin metadata
```

#### PostgreSQL
- Full feature parity with MySQL via `functions_pgsql.php`
- Same table structure
- Optimized for PostgreSQL queries

---

## Architecture & Design Patterns

### Overall Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Browser / Client                      │
└────────────────────┬────────────────────────────────────┘
                     │
        ┌────────────┼────────────┐
        │            │            │
    ┌───▼──┐   ┌────▼───┐   ┌───▼───┐
    │index.│   │picture.│   │ws.php │
    │php   │   │php     │   │(API)  │
    └───┬──┘   └────┬───┘   └───┬───┘
        │           │           │
        └───────────┼───────────┘
                    │
        ┌───────────▼──────────────┐
        │   common.php             │
        │  (Initialization Layer)  │
        │  • Config loading        │
        │  • Security checks       │
        │  • Sanitization          │
        └───────────┬──────────────┘
                    │
        ┌───────────▼──────────────┐
        │ section_init.php         │
        │  (Routing Layer)         │
        │  • URL parsing           │
        │  • Page section init     │
        └───────────┬──────────────┘
                    │
        ┌───────────▼──────────────┐
        │  functions_*.php         │
        │ (Business Logic Layer)   │
        │  • Category ops          │
        │  • Image ops             │
        │  • User mgmt             │
        │  • Search, tags, etc     │
        └───────────┬──────────────┘
                    │
        ┌───────────▼──────────────┐
        │ Database Abstraction     │
        │ dblayer/functions_*.php  │
        │  • MySQL adapter         │
        │  • PostgreSQL adapter    │
        └───────────┬──────────────┘
                    │
        ┌───────────▼──────────────┐
        │  Database Server         │
        │ (MySQL/MariaDB/PgSQL)    │
        └──────────────────────────┘
```

### Design Patterns

#### 1. **Singleton Pattern**
Classes that maintain a single global instance:
- `Config` class - Configuration object
- `Template` class - Smarty template wrapper
- `PwgServer` - Web service handler

```php
// Usage
$config = Config::getInstance();
$template = new Template();  // Re-instantiation reuses same object
```

#### 2. **MVC-Like Architecture**
- **Model**: Database layer (`inc/dblayer/`), data classes
- **View**: Smarty templates (`inc/template/`, `themes/`, `admin/templates/`)
- **Controller**: Entry points and functions (`index.php`, `admin.php`, `functions_*.php`)

#### 3. **Plugin Hook System**
Extensibility without core modification:
```php
// In core code
trigger_notify('action_after_photo_upload', $photo_id);

// In plugins
add_event_listener('action_after_photo_upload', function($photo_id) {
    // Custom processing
});
```

#### 4. **Observer/Event-Driven Pattern**
- `functions_plugins.php`: `trigger_notify()` and `trigger_change()`
- Allows plugins to subscribe to core events
- Enables loose coupling

#### 5. **Template Method Pattern**
- `PluginMaintain.php` and `ThemeMaintain.php` define lifecycle hooks
- Plugins implement methods: `install()`, `activate()`, `deactivate()`, `uninstall()`

#### 6. **Strategy Pattern**
Image processing abstraction:
- `image_gd.php` - GD Library implementation
- `image_imagick.php` - ImageMagick implementation
- `image_vips.php` - libvips implementation
- `pwg_image.php` - Factory/abstraction layer

#### 7. **Adapter Pattern**
- Database abstraction: `functions_mysqli.php`, `functions_pgsql.php`
- Different databases implement same interface
- Query methods remain consistent

#### 8. **Middleware/Pipeline Pattern**
Request processing flow:
```
Request → common.php → config loading → sanitization → DB init
  → section_init.php → routing → handler → response
```

#### 9. **Decorator Pattern**
- `DerivativeImage.php` - Wraps source images with derivative versions
- Adds functionality without modifying source image class

#### 10. **Factory Pattern**
- `DerivativeImage::getRootPath()` - Creates derivative paths
- Image processor selection based on configuration

### Code Organization Principles

1. **Domain-Driven**: Functions grouped by feature/domain
   - `functions_category.php` - All category operations
   - `functions_image.php` (admin) - Image processing
   - `functions_user.php` - User authentication

2. **Separation of Concerns**: Clear boundaries
   - Presentation logic → `functions_html.php`, Templates
   - Business logic → `functions_*.php` in `inc/`
   - Data access → `inc/dblayer/`
   - Admin logic → `admin/inc/`, admin pages

3. **Namespace Organization**:
   - `Piwigo\inc\` - Core framework
   - `Piwigo\admin\inc\` - Admin functions
   - `Piwigo\admin\themes\` - Admin templates

4. **Consistent Naming**:
   - Functions: `{domain}_{operation}()` - e.g., `category_get_all()`
   - Files: `functions_{domain}.php` - e.g., `functions_category.php`
   - Classes: PascalCase - e.g., `DerivativeImage`

---

## Core Modules

### 1. Configuration Module (`inc/Config.php`)

**Size**: ~3,500 lines
**Responsibility**: Centralized configuration management

**Key Features**:
- Runtime configuration loading from database
- Configuration overrides from `local/config/config.php`
- 400+ configurable settings
- Type-safe property access

**Main Configuration Areas**:
```php
// File uploads
$conf['upload_dir']
$conf['file_ext']  // Allowed extensions: jpg, jpeg, png, gif, etc.

// Image sizing
$conf['derivative_url_style']  // /i/ URL style
$conf['thumb_maxwidth']        // Thumbnail dimensions
$conf['thumb_maxheight']
$conf['medium_maxwidth']       // Medium version
$conf['medium_maxheight']
$conf['large_maxwidth']        // Large version
$conf['large_maxheight']

// Database
$conf['db_base']     // Database name
$conf['table_prefix']

// Security
$conf['session_length']        // Minutes
$conf['session_cookie_secure'] // HTTPS only
$conf['session_cookie_samesite']

// Features
$conf['allow_comments']
$conf['allow_ratings']
$conf['allow_user_registration']
$conf['activate_comments']  // Require approval

// Notifications
$conf['send_mail']
$conf['email_admin_on_new_user']
$conf['email_admin_on_comment']
```

### 2. User Management (`inc/functions_user.php`)

**Size**: 5.7KB
**Key Functions**:
- `is_a_guest()` - Check if user is guest
- `is_admin()` - Check if user is admin
- `is_webmaster()` - Check if user is webmaster
- `is_generic()` - Check if user has generic permissions
- `pwg_password_hash()` - Secure password hashing
- `pwg_password_verify()` - Verify password
- `get_userid()` - Get current user ID

**Access Levels**:
```php
define('GUEST', 0);           // Anonymous visitor
define('CLASSIC', 1);         // Registered user
define('ADMIN', 2);           // Site administrator
define('WEBMASTER', 3);       // Full control
```

### 3. Session Management (`inc/PwgSessionHandler.php`)

**Type**: `SessionHandlerInterface` implementation
**Purpose**: Custom session handling

**Features**:
- Database-backed sessions for scalability
- Cookie management
- Session timeout enforcement
- IP address validation (optional)

### 4. Category/Album Management (`inc/functions_category.php`)

**Size**: 27.5KB (largest in functions_*.php set)
**Key Functions**:
- `get_categories()` - Retrieve category tree with parameters
- `get_cat_display_name()` - Format category names
- `get_cat_info()` - Detailed category information
- `move_categories_before()` - Reorder categories
- `delete_category()` - Remove category
- `set_cat_visible()` - Toggle visibility
- `sort_categories()` - Change sorting

**Category Features**:
- Hierarchical organization (parent-child relationships)
- Visibility control (public/private)
- Photo count per category
- Subcategory counts
- Thumbnail image (representative)
- Description/comments
- Metadata (date created, date modified)

### 5. Image/Photo Management

#### `inc/DerivativeImage.php`
Handles image variants (thumbnails, medium, large versions).

**Components**:
- **Source Image**: Original uploaded photo
- **Derivatives**: Auto-generated versions
  - Thumbnail (for galleries)
  - Medium (for browsing)
  - Large (for viewing)

**File Structure**:
```
_data/i/
├── 1/        // Photo ID
│  ├── 1.jpg  // Thumbnail
│  ├── 2.jpg  // Medium
│  └── 3.jpg  // Large
└── 2/
   ├── 1.jpg
   ├── 2.jpg
   └── 3.jpg
```

#### `inc/SrcImage.php`
Represents source (original) image with metadata.

#### `inc/ImageStdParams.php`
Standard image sizing parameters and configurations.

**Standard Sizes**:
```php
DERIVATIVE_THUMB = 1    // Thumbnails (default 120x120)
DERIVATIVE_SMALL = 2    // Small (default 240x240)
DERIVATIVE_MEDIUM = 3   // Medium (default 432x432)
DERIVATIVE_LARGE = 4    // Large (default 850x850)
DERIVATIVE_XLARGE = 5   // Extra large (default 1280x1280)
```

### 6. Search Module (`inc/functions_search.php`)

**Size**: 45.6KB
**Purpose**: Advanced photo search with multiple criteria

**Search Types**:
1. **Simple search**: Keyword in title/description
2. **Advanced search**: Multiple criteria
3. **Date range**: Photos by date
4. **Dimension range**: Photos by width/height
5. **Rating range**: Photos by rating
6. **Category filter**: By album
7. **Tag filter**: By tags
8. **Author filter**: By user

**Query Classes**:
- `QExpression` - Search query builder
- `QSearchScope` - Scope definitions
- `QSingleToken`, `QMultiToken` - Token types
- `QResults` - Result set

**Usage**:
```php
$search_results = get_search_results_from_query(
    [
        'q' => 'beach',           // Keyword
        'sort_by' => 'date-desc', // Sorting
        'items_number' => 20      // Pagination
    ]
);
```

### 7. Calendar Module

#### `inc/CalendarBase.php` (11.4KB)
Abstract base for calendar views.

**Structure**:
```php
abstract class CalendarBase {
    protected $date_field;      // Which date field (created, modified, etc)
    protected $items_number;    // Photos per page

    abstract public function generate_calendar();
    abstract public function get_sql_filter();
}
```

#### `inc/CalendarMonthly.php` (16.7KB)
Monthly calendar view with photo counts.

**Features**:
- Display months with photo counts
- Navigation between months
- SQL filtering for specific date ranges
- Integration with category filtering

#### `inc/CalendarWeekly.php`
Weekly calendar view (similar structure).

### 8. Comments System (`inc/functions_comment.php`)

**Size**: 18.6KB
**Key Functions**:
- `get_comment_list()` - Retrieve comments
- `get_user_comments()` - Comments by user
- `insert_user_comment()` - Add new comment
- `delete_user_comment()` - Remove comment
- `get_comments_nb()` - Count comments
- `insert_comment_spam_data()` - Anti-spam tracking
- `check_comment_validity()` - Validate comment

**Comment Features**:
- Author name/email
- Moderation queue
- Spam detection
- Date tracking
- Rich text support (configurable)
- Category-level moderation

### 9. Tagging System (`inc/functions_tag.php`)

**Purpose**: Photo categorization with flexible tags

**Key Functions**:
- `get_available_tags()` - List all tags
- `get_tag_name()` - Retrieve tag
- `add_tags()` - Create tags
- `delete_tag()` - Remove tag
- Tag-image associations
- Tag-based filtering

### 10. Rating System (`inc/functions_rate.php`)

**Purpose**: Allow users to rate photos (1-5 stars)

**Features**:
- Per-user, per-photo ratings
- Average rating calculation
- Rating statistics
- User/admin configuration

### 11. Notification System (`inc/functions_notification.php`)

**Size**: 20.9KB
**Purpose**: Email notifications for events

**Events**:
- New comment on photo
- New user registration
- Admin alerts
- Custom plugin events

**Configuration**:
- Email recipients per event
- HTML/plain text templates
- Scheduling (immediate vs. digest)

### 12. Email System (`inc/functions_mail.php`)

**Size**: 30.8KB (largest utility module)
**Dependencies**: PHPMailer 6.10.0

**Features**:
- HTML and plain-text emails
- SMTP support
- CSS inlining (for HTML emails)
- Template rendering
- Attachment support
- Batch sending

**Key Functions**:
- `pwg_mail()` - Send mail
- `pwg_mail_notification_string_to_sql()` - Format notifications
- Email template system

### 13. Web Service API (`inc/ws_*.php`)

**Entry Point**: `ws.php`
**Types**: REST JSON-RPC, XML-RPC

#### Core API Methods (`inc/ws_functions/pwg.php`)
- `pwg.getVersion()` - API version
- `pwg.test.echo()` - Connectivity test
- `pwg.getUserInfo()` - Current user info
- `pwg.getStatus()` - System status
- `pwg.Session.getStatus()` - Session info
- `pwg.Session.login()` - Authenticate
- `pwg.Session.logout()` - End session

#### Category API (`inc/ws_functions/pwg_categories.php`)
- `pwg.categories.getList()` - List categories
- `pwg.categories.getInfo()` - Category details
- `pwg.categories.add()` - Create category
- `pwg.categories.delete()` - Remove category
- `pwg.categories.move()` - Reorder

#### Image API (`inc/ws_functions/pwg_images.php`) - **86.8KB**
- `pwg.images.getList()` - Photo listing
- `pwg.images.getInfo()` - Photo metadata
- `pwg.images.setInfo()` - Update photo
- `pwg.images.delete()` - Remove photo
- `pwg.images.add()` - Upload photo
- `pwg.images.addSimple()` - Simple upload
- `pwg.images.addFile()` - File upload
- `pwg.images.getCheckUpload()` - Verify upload
- `pwg.images.setPrivacyLevel()` - Permission control

#### User API (`inc/ws_functions/pwg_users.php`)
- `pwg.users.getList()` - User list
- `pwg.users.getInfo()` - User details
- `pwg.users.add()` - Create user
- `pwg.users.delete()` - Remove user
- `pwg.users.setInfo()` - Update profile

#### Tags API (`inc/ws_functions/pwg_tags.php`)
- `pwg.tags.getList()` - Tag listing
- `pwg.tags.add()` - Create tag
- `pwg.tags.getImages()` - Photos with tag

#### Groups API (`inc/ws_functions/pwg_groups.php`)
- `pwg.groups.getList()` - Group listing
- `pwg.groups.getInfo()` - Group details
- `pwg.groups.add()` - Create group
- `pwg.groups.delete()` - Remove group
- `pwg.groups.addUser()` - Add member
- `pwg.groups.deleteUser()` - Remove member

#### Permissions API (`inc/ws_functions/pwg_permissions.php`)
- `pwg.permissions.getCategories()` - Category access
- `pwg.permissions.add()` - Grant permission
- `pwg.permissions.delete()` - Revoke permission

---

## Entry Points

### Frontend (User-Facing)

| File | Purpose | Parameters |
|------|---------|-----------|
| **index.php** | Main gallery view | `?/category/{id}`, `?/search` |
| **picture.php** | Individual photo view | `?/picture/{id}` |
| **i.php** | Image server (derivatives) | Serves cached thumbnails/medium/large |
| **comments.php** | Comment listing | `?/comment/{id}` |
| **feed.php** | RSS/Atom feeds | `?/feed/atom/` or `?/feed/rss/` |
| **identification.php** | Login page | `?/identification` |
| **password.php** | Password reset | `?/password` |
| **notification.php** | Notification preferences | `?/notification` |
| **action.php** | AJAX actions | POST with JSON data |

### Administrative

| File | Purpose |
|------|---------|
| **admin.php** | Main admin dashboard |
| **admin/albums.php** | Album/category management |
| **admin/photos_add.php** | Upload interface |
| **admin/picture_modify.php** | Photo editing |
| **admin/comments.php** | Comment moderation |
| **admin/users.php** | User management |
| **admin/configuration.php** | Settings panel |
| **admin/plugins_installed.php** | Plugin management |
| **admin/themes.php** | Theme selection |
| **admin/maintenance.php** | System maintenance |

### System

| File | Purpose |
|------|---------|
| **install.php** | Installation wizard (first run) |
| **ws.php** | Web service API entry point |
| **about.php** | About/version page |

### Request Flow

```
HTTP Request
    ↓
[Entry Point] (index.php, admin.php, etc.)
    ↓
common.php
  • Load configuration
  • Initialize database
  • Start session
  • Sanitize input
  • Load language
    ↓
section_init.php
  • Parse URL
  • Initialize section
  • Set up page globals
    ↓
Handler Code
  • functions_*.php calls
  • Business logic
  • Data retrieval
    ↓
Template Rendering
  • Assign variables
  • Render Smarty templates
    ↓
HTTP Response
    ↓
Browser
```

---

## Configuration Management

### Configuration Loading Order (Priority)

1. **Default Values** (`inc/config_default.php`)
   - Built-in, read-only defaults
   - Applied first

2. **User Configuration** (`local/config/config.php`)
   - Local overrides
   - Takes priority over defaults
   - Not in version control

3. **Database Configuration** (`piwigo_config` table)
   - Runtime settings modified through admin
   - Takes highest priority
   - Persistent across requests

4. **Environment** (PHP INI, server settings)
   - Lowest priority for PHP settings

### Configuration Access

```php
// Global array access (deprecated but still used)
global $conf;
echo $conf['gallery_title'];

// Class-based access (modern)
$config = Config::getInstance();
$title = $config->get('gallery_title');
```

### Key Configuration Settings

#### File/Upload Settings
```php
$conf['upload_dir']                    // Upload directory path
$conf['file_ext']                      // Allowed file extensions
$conf['file_upload_dir_pattern']       // Directory naming pattern
```

#### Image Processing
```php
$conf['derivative_url_style']          // URL format (rewrite vs. params)
$conf['image_quality']                 // JPEG quality (1-95)
$conf['upload_form_chunk_size']        // Chunk size for large uploads
$conf['enable_synchronization']        // Auto-sync file system
```

#### Display/Theme
```php
$conf['gallery_title']
$conf['gallery_description']
$conf['thumbs_per_page']
$conf['thumb_maxwidth']
$conf['thumb_maxheight']
$conf['page_banner']
```

#### Security
```php
$conf['session_length']                // Session timeout (minutes)
$conf['session_cookie_secure']         // HTTPS only
$conf['session_cookie_httponly']       // No JavaScript access
$conf['session_cookie_samesite']       // SameSite setting
$conf['allow_user_registration']
$conf['require_email_verification']
```

#### Features
```php
$conf['allow_comments']                // Enable comments
$conf['allow_ratings']                 // Enable ratings
$conf['activate_comments']             // Require approval
$conf['rate_anonymous']                // Allow guest ratings
```

#### Database
```php
$conf['db_base']                       // Database name
$conf['table_prefix']                  // Table prefix (usually 'piwigo_')
$conf['db_port']                       // Port (3306 for MySQL)
```

---

## Database Layer

### Database Abstraction (`inc/dblayer/`)

#### MySQL/MariaDB Adapter (`functions_mysqli.php`)
- 21.5KB of adapter code
- Uses `mysqli` extension
- Supports prepared statements
- Connection pooling

**Key Functions**:
```php
pwg_db_connect()           // Establish connection
pwg_db_query()             // Execute query
pwg_db_fetch_assoc()       // Fetch row as array
pwg_db_fetch_all_assoc()   // Fetch all rows
pwg_db_num_rows()          // Count results
pwg_db_insert_id()         // Last insert ID
```

#### PostgreSQL Adapter (`functions_pgsql.php`)
- 22.7KB of adapter code
- Uses `pgsql` extension
- Same function signatures as MySQL adapter
- SQL translated to PostgreSQL syntax

**Adapter Pattern**:
```php
// In code, use generic functions:
pwg_db_query($sql);

// At runtime, dispatcher calls appropriate adapter:
if ($conf['db_type'] === 'mysqli') {
    // MySQL/MariaDB
} elseif ($conf['db_type'] === 'pgsql') {
    // PostgreSQL
}
```

### Database Schema

#### Core Tables

**piwigo_images** (Photos)
```
- id, name, file, path
- date_available (upload date)
- date_creation (photo date from EXIF)
- comment (description)
- author, width, height
- filesize, representative_ext
- average_rate, hit (view count)
- lastmodified
```

**piwigo_categories** (Albums)
```
- id, name, comment, dir
- parent_id (hierarchical)
- rank (sort order)
- status (public/private)
- visible, uppercats (breadcrumb)
- representative_picture_id
- nb_images (cached count)
- date_last, max_date_last
- comment_count, nb_comments
```

**piwigo_image_category** (Photo-Album Mapping)
```
- image_id, category_id
- rank (ordering within album)
```

**piwigo_users** (Accounts)
```
- id, username, password, email
- status (active/inactive)
- theme, language, user_tz (timezone)
- registration_date, last_visit
- nb_comment_page, autoupload_repeat
- autoupload_recurse, activation_key
- groups, forbidden_categories
```

**piwigo_comments** (Photo Comments)
```
- id, image_id, date, author
- email, content, validated
- user_id
```

**piwigo_favorites** (User Favorites)
```
- user_id, image_id
```

**piwigo_rates** (Photo Ratings)
```
- user_id, image_id
- rate (1-5), date
```

**piwigo_tags** (Photo Tags)
```
- id, name, url_name
```

**piwigo_image_tag** (Photo-Tag Mapping)
```
- image_id, tag_id
```

**piwigo_plugins** (Plugin Metadata)
```
- id, name, state (active/inactive)
- version, md5
```

**piwigo_themes** (Theme Metadata)
```
- id, name, active
- version
```

**piwigo_config** (Runtime Settings)
```
- param (setting name)
- value (setting value)
```

### Query Patterns

#### Prepared Statements
```php
$query = 'SELECT * FROM ' . CATEGORIES_TABLE . '
          WHERE id = ? AND status = ?';
$results = pwg_db_fetch_all_assoc(
    pwg_db_query($query, [$category_id, 'public'])
);
```

#### Generic Queries
```php
$query = 'SELECT * FROM ' . IMAGES_TABLE . '
          WHERE status = "private" AND user_id = ' . $user_id;
$result = pwg_db_query($query);
```

#### Insert Pattern
```php
pwg_db_insert(IMAGES_TABLE, [
    'name' => $name,
    'file' => $file,
    'date_available' => date('Y-m-d H:i:s'),
]);
```

#### Update Pattern
```php
pwg_db_update(IMAGES_TABLE,
    ['comment' => $new_comment],
    ['id' => $image_id]
);
```

---

## Plugin & Theme System

### Plugin Architecture

#### Plugin Structure
```
plugins/{PluginName}/
├── index.php              # Plugin loader
├── {PluginName}_maintain.php  # Lifecycle
├── main.php               # Initialization
├── admin.php              # Admin page (optional)
├── include/               # Helper classes
├── language/              # Translations
│  ├── en_UK/
│  ├── fr_FR/
│  └── ...
├── template/              # Template overrides
│  ├── admin/
│  └── ...
└── pem_metadata.txt       # Plugin metadata
```

#### Plugin Lifecycle (`{PluginName}_maintain.php`)
```php
class {PluginName}Maintain extends PluginMaintain {
    // Called during installation
    public function install($plugin_version, &$errors) {}

    // Called when plugin is activated
    public function activate($plugin_version, &$errors) {}

    // Called when plugin is deactivated
    public function deactivate() {}

    // Called before removal
    public function uninstall() {}

    // Called during version update
    public function update($from, $to, &$errors) {}
}
```

#### Plugin Hooks (in `main.php`)
```php
// Example: Add admin menu item
add_event_listener('loc_begin_admin_page', function() {
    global $template;
    $template->assign([
        'plugin_menu_items' => [
            'your_plugin' => PHPWG_PLUGINS_PATH . 'your_plugin/admin.php'
        ]
    ]);
});

// Example: Modify image list
add_event_listener('pwg_images_getList_filter', function(&$result) {
    // Modify result before returning
    $result['images'][0]['name'] = 'Modified: ' . $result['images'][0]['name'];
});

// Example: Add custom action
add_event_listener('loc_begin_action', function() {
    if (isset($_GET['custom_action'])) {
        // Handle custom action
    }
});
```

#### Built-in Plugins

1. **AdminTools**
   - Multi-site management
   - Custom fonts (Fontello)
   - Admin utilities

2. **GDThumb**
   - Alternative image processor using GD
   - Fallback for ImageMagick/libvips failures

3. **language_switch**
   - Language selector widget
   - Multi-language support

4. **LocalFilesEditor**
   - Edit template files via admin
   - Code editor integration

5. **TakeATour**
   - Interactive tutorials
   - Onboarding system

### Theme System

#### Theme Structure
```
themes/{ThemeName}/
├── template/              # Smarty templates
│  ├── index.tpl          # Main gallery
│  ├── picture.tpl        # Photo viewer
│  ├── admin/             # Admin theme (optional)
│  └── ...
├── css/                  # Stylesheets
│  ├── style.css
│  └── ...
├── js/                   # JavaScript
│  ├── script.js
│  └── ...
├── admin/                # Admin customization
│  └── theme_admin.php
├── language/             # Translations
│  ├── en_UK/
│  └── ...
├── themedef.xml         # Theme metadata
└── screenshot.png        # Preview image
```

#### Theme Metadata (`themedef.xml`)
```xml
<?xml version="1.0" encoding="UTF-8"?>
<theme>
  <extension name="{ThemeName}">
    <version>{version}</version>
    <uri>{url}</uri>
    <description>{description}</description>
    <requires_version>{min_version}</requires_version>
    <icon>{icon_url}</icon>
  </extension>
</theme>
```

#### Built-in Themes

1. **bootstrap_darkroom** (Modern default)
   - Bootstrap-based responsive design
   - Dark mode support
   - Modern UI components
   - Node.js build system

2. **modus** (Clean default)
   - Light, minimal design
   - Fast loading

3. **default** (Classic)
   - Traditional layout
   - Backward compatible

4. **elegant**
   - Premium design
   - Enhanced styling

5. **smartpocket**
   - Mobile-optimized
   - Touch-friendly

#### Theme Customization

**Template Override**:
```
plugins/{PluginName}/template/index.tpl  # Overrides default
themes/{ThemeName}/template/index.tpl    # Overrides core
```

**Smarty Variable Assignment**:
```php
$template->assign([
    'gallery_title' => 'My Gallery',
    'theme_name' => 'bootstrap_darkroom',
    'photos' => $photos_array,
]);
```

---

## Security Features

### Input Validation & Sanitization

#### In `common.php`:
```php
// SLASHES_ON: Adds slashes to all GET/POST/COOKIE vars
define('SLASHES_ON', 1);

// Security checks:
// - SQL injection prevention
// - XSS prevention
// - CSRF token validation
// - File upload validation
```

#### Specific Patterns:
```php
// Integer parameter validation
$cat_id = (int)$_GET['cid'];  // Force integer

// String sanitization
$search = strip_tags($_GET['q']);  // Remove HTML tags

// SQL parameter binding (prepared statements)
pwg_db_query('SELECT * FROM ' . IMAGES_TABLE . ' WHERE id = ?', [$image_id]);
```

### Access Control

#### Role-Based Access Control (RBAC)
```php
// Check access level
if (!is_admin()) {
    exit('Access denied');
}

// Check category access
if (!is_user_allowed($category_id)) {
    exit('Private category');
}

// Check image access
$image = get_image_metadata($image_id);
if (!is_allowed_access($image)) {
    exit('Image not accessible');
}
```

#### Access Levels
- **GUEST (0)**: Anonymous user
- **CLASSIC (1)**: Registered user
- **ADMIN (2)**: Site administrator
- **WEBMASTER (3)**: Full system access

### Password Security

#### Hashing
```php
// Uses PHP password_hash() with bcrypt
$hash = pwg_password_hash($password);

// Verification
if (pwg_password_verify($submitted_password, $hash)) {
    // Correct password
}
```

### Session Management

#### Secure Session Configuration
```php
// HTTPS only (if configured)
ini_set('session.cookie_secure', true);

// No JavaScript access
ini_set('session.cookie_httponly', true);

// SameSite protection
ini_set('session.cookie_samesite', 'Lax');

// Custom session handler for database storage
session_set_save_handler(new PwgSessionHandler());
```

### CSRF Protection

#### Token Generation & Validation
```php
// Generate form token
$token = pwg_get_session_var('form_token');
if (!$token) {
    $token = md5(uniqid());
    pwg_set_session_var('form_token', $token);
}

// Validate on submission
if ($_POST['token'] !== $token) {
    exit('CSRF token invalid');
}
```

### XSS Prevention

#### Template Escaping
```smarty
{* Smarty auto-escapes by default in v5.x *}
{$user_comment}  {* Escaped *}
{$html_content nofilter}  {* Unescaped (use carefully) *}
```

#### PHP Escaping
```php
// For HTML output
echo htmlspecialchars($user_input, ENT_QUOTES, 'UTF-8');

// For URL output
echo urlencode($parameter);

// For JSON output
echo json_encode($data);
```

### File Upload Security

#### Validation in `admin/inc/functions_upload.php`:
```php
// Check MIME type
$file_info = getimagesize($temp_file);
if (!in_array($file_info['mime'], ['image/jpeg', 'image/png', 'image/gif'])) {
    exit('Invalid image type');
}

// Check file extension against whitelist
$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $conf['file_ext'])) {
    exit('File extension not allowed');
}

// Check file size
$max_size = $conf['upload_form_chunk_size'];
if ($_FILES['file']['size'] > $max_size) {
    exit('File too large');
}

// Use unique filename
$new_filename = md5(date('Y-m-d H:i:s') . $user_id . mt_rand());
```

---

## Development & Build

### Code Quality Tools

#### PHP Static Analysis
```bash
# PHPStan - Find type-related bugs
./vendor/bin/phpstan analyze inc/

# Psalm - Type checking with IDE integration
./vendor/bin/psalm

# Rector - Automated code refactoring
./vendor/bin/rector process inc/
```

#### PHP Code Standards
```bash
# Easy Coding Standard - PSR-12 compliance
./vendor/bin/ecs check inc/
./vendor/bin/ecs fix inc/
```

#### JavaScript/CSS Quality
```bash
# ESLint - JavaScript linting
bun run lint

# Biome - Fast formatter
biome format themes/
```

### Testing

#### Unit Tests with PHPUnit
```bash
cd /tests
./vendor/bin/phpunit
```

#### Test Structure
```
tests/
├── php/
│  ├── CategoryTest.php
│  ├── ImageTest.php
│  └── ...
├── js/
│  ├── gallery.test.js
│  └── ...
└── package.json
```

### Build System

#### npm/Bun Scripts
```json
{
  "scripts": {
    "lint": "eslint themes/ admin/ --ext .js",
    "format": "biome format themes/ admin/ --write",
    "build": "webpack --mode production",
    "dev": "webpack --mode development --watch"
  }
}
```

#### Theme Build (bootstrap_darkroom)
```bash
cd themes/bootstrap_darkroom
bun install
bun run build  # Compile SASS/PostCSS
```

### Docker Development

#### Quick Start
```bash
# Copy environment file
cp .env.example .env

# Start containers
docker compose up -d

# Install Piwigo
docker compose exec web php install.php

# Access at http://localhost
```

#### Docker Structure
```
docker/
├── docker-compose.yaml
├── docker-compose.override.yaml  # Local overrides
├── Dockerfile                    # Web container
├── php.ini                      # PHP configuration
├── nginx.conf                   # Nginx config
└── postgres.conf               # PostgreSQL config
```

---

## Quick Reference

### Common File Locations

| Task | File Location |
|------|---------------|
| Add theme | `themes/{new-theme}/` |
| Create plugin | `plugins/{PluginName}/` |
| Add language strings | `language/{lang}/` |
| Modify admin | `admin/` |
| Core functions | `inc/` |
| Database schemas | `install/` |

### Common Functions to Use

#### Category Operations
```php
// Get all categories
$categories = get_categories([
    'sort_by' => ['rank'],
    'viewable' => true,
]);

// Get category details
$cat = get_cat_info($category_id);

// Create category
$cat_id = add_category($name, $parent_id);

// Delete category
delete_category($category_id);
```

#### Image Operations
```php
// Get image info
$img = get_image_metadata($image_id);

// Get image list with filters
$images = get_images(
    $where = ['status = "public"'],
    $order_by = 'date DESC',
    $limit = 20
);

// Add image
$img_id = add_images([$image_data]);

// Delete image
delete_elements([$image_id]);
```

#### User Operations
```php
// Create user
$user_id = register_user($username, $password, $email);

// Get user info
$user = get_user_info($user_id);

// Update user
update_user($user_id, $data);

// Check admin
$is_admin = is_admin();
```

#### Search
```php
// Simple search
$results = get_search_results_from_query([
    'q' => 'beach',
    'sort_by' => 'date-desc',
]);

// Advanced search (dates, ratings, dimensions, etc)
$results = get_search_results_from_query([
    'date_range' => ['2023-01-01', '2024-12-31'],
    'rating_range' => [4, 5],
]);
```

### Common Template Variables

#### Gallery Templates
```smarty
{$gallery_title}          {* Site title *}
{$PAGE_TITLE}            {* Current page title *}
{$categories}            {* Category list *}
{$images}                {* Photo list *}
{$navbar}                {* Navigation bar *}
{$THEME}                 {* Theme name *}
{$USER}                  {* Current user *}
{$is_admin}              {* Admin flag *}
{$BODY_ID}               {* Page identifier *}
```

#### Admin Templates
```smarty
{$admin_menu}            {* Admin menu *}
{$F_ACTION}              {* Form action URL *}
{$F_ERRORS}              {* Form errors *}
{$F_INFOS}               {* Form messages *}
{$ADMIN_CONTENT}         {* Main content *}
```

### Common Configuration Values

```php
// Display
$conf['gallery_title']           // Site name
$conf['gallery_description']     // Tagline
$conf['thumbs_per_page']         // Items per page
$conf['thumb_maxwidth']          // Thumbnail width
$conf['thumb_maxheight']         // Thumbnail height

// Features
$conf['allow_comments']          // Enable comments
$conf['allow_ratings']           // Enable ratings
$conf['allow_user_registration'] // Self-registration

// Security
$conf['session_length']          // Timeout (minutes)
$conf['session_cookie_secure']   // HTTPS only
$conf['session_cookie_httponly'] // No JS access

// Images
$conf['image_quality']           // JPEG quality
$conf['upload_dir']              // Upload directory
$conf['file_ext']                // Allowed extensions

// Database
$conf['db_base']                 // Database name
$conf['table_prefix']            // Table prefix
```

### Database Table Constants

```php
CATEGORIES_TABLE          // piwigo_categories
IMAGES_TABLE             // piwigo_images
IMAGE_CATEGORY_TABLE     // piwigo_image_category
USERS_TABLE              // piwigo_users
COMMENTS_TABLE           // piwigo_comments
FAVORITES_TABLE          // piwigo_favorites
RATES_TABLE              // piwigo_rates
TAGS_TABLE               // piwigo_tags
IMAGE_TAG_TABLE          // piwigo_image_tag
PLUGINS_TABLE            // piwigo_plugins
THEMES_TABLE             // piwigo_themes
CONFIG_TABLE             // piwigo_config
```

---

## Summary

Piwigo is a well-engineered, production-ready photo gallery application with:

1. **Clear Architecture**: MVC-like with plugin/theme extensibility
2. **Code Quality**: Type-safe PHP 8.4+, static analysis, automated formatting
3. **Database Flexibility**: MySQL/MariaDB/PostgreSQL support
4. **Rich Features**: Search, calendar, comments, ratings, notifications, bulk operations
5. **API-First**: Full REST/XML-RPC web service
6. **Security-Focused**: Input validation, RBAC, secure sessions
7. **Performance-Conscious**: Caching, image optimization, asset combining
8. **Community-Driven**: Extensible plugin system, translation support
9. **Modern Frontend**: Responsive design, multiple themes, PhotoSwipe 5.x
10. **Developer-Friendly**: Clear module organization, comprehensive hooks, good documentation

The codebase demonstrates professional PHP development practices suitable for production environments with thousands of users and hundreds of thousands of photos.

---

**Document Generated**: November 2025
**Piwigo Version**: 14.x
**PHP Requirement**: 8.4.6+
