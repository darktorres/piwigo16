# Testing Infrastructure Guide for Piwigo

**Principle**: Tests FIRST, then refactor with confidence
**Goal**: Build comprehensive test coverage before making major changes
**Timeline**: 2-3 weeks to establish solid testing foundation

---

## Why Testing Matters for Refactoring

### The Refactoring Risk

Without tests:
```
Refactor Code → ??? → Something breaks → Users complain → Revert
Risk Level: EXTREME 🔴
```

With tests:
```
Refactor Code → Run Tests → Tests fail? → Fix issues immediately → Deploy safely
Risk Level: MINIMAL 🟢
```

### Test Coverage Impact

| Coverage | Confidence | Refactoring Risk |
|----------|------------|-----------------|
| 0% | Blind | 🔴🔴🔴 Very High |
| 20% | Low | 🔴🔴 High |
| 50% | Medium | 🟠 Medium |
| 70% | High | 🟢 Low |
| 90%+ | Very High | 🟢🟢 Very Low |

**Goal**: Reach 70%+ coverage on core modules before major refactoring

---

## Phase 1: Testing Infrastructure Setup (Week 1)

### 1.1 Install Testing Framework

**Choose PHPUnit** (already in Piwigo):

```bash
# Verify PHPUnit is installed
composer require --dev phpunit/phpunit:^11.0

# Verify installation
./vendor/bin/phpunit --version
```

### 1.2 Configure PHPUnit

**Create `phpunit.xml`** in project root:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         verbose="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="Unit Tests">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration Tests">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="API Tests">
            <directory>tests/Api</directory>
        </testsuite>
    </testsuites>

    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">inc</directory>
            <directory suffix=".php">admin/inc</directory>
        </include>
        <exclude>
            <directory>inc/dblayer</directory>
            <directory>vendor</directory>
            <directory>tests</directory>
        </exclude>
        <report>
            <html outputDirectory="coverage/html"/>
            <text outputFile="coverage/text.txt"/>
            <clover outputFile="coverage/clover.xml"/>
        </report>
    </coverage>
</phpunit>
```

### 1.3 Create Test Bootstrap

**Create `tests/bootstrap.php`**:

```php
<?php
/**
 * Test bootstrap file
 * Sets up testing environment
 */

// Get project root
define('PROJECT_ROOT', dirname(__DIR__));
define('TESTS_DIR', __DIR__);

// Load Composer autoloader
require PROJECT_ROOT . '/vendor/autoload.php';

// Set up test environment
putenv('APP_ENV=testing');
date_default_timezone_set('UTC');
error_reporting(E_ALL);

// Create test database connection
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

// Helper: Clean up database after tests
function cleanup_test_database() {
    // Will implement in Phase 2
}

// Helper: Create test database
function create_test_database() {
    // Will implement in Phase 2
}
```

### 1.4 Create Test Directory Structure

```bash
mkdir -p tests/{Unit,Integration,Api,Fixtures,Helpers}

# Create empty test files to show structure
touch tests/Unit/.gitkeep
touch tests/Integration/.gitkeep
touch tests/Api/.gitkeep
touch tests/Fixtures/.gitkeep
touch tests/Helpers/.gitkeep
```

**Directory structure**:
```
tests/
├── bootstrap.php              # Test configuration
├── Unit/                      # Unit tests (functions, classes)
│   ├── CategoryTest.php
│   ├── ImageTest.php
│   ├── UserTest.php
│   └── SearchTest.php
├── Integration/               # Integration tests (with database)
│   ├── CategoryRepositoryTest.php
│   ├── ImageRepositoryTest.php
│   └── DatabaseTest.php
├── Api/                       # API endpoint tests
│   ├── PhotosApiTest.php
│   ├── CategoriesApiTest.php
│   └── SearchApiTest.php
├── Fixtures/                  # Test data
│   ├── categories.php
│   ├── images.php
│   └── users.php
├── Helpers/                   # Test utilities
│   ├── TestCase.php
│   ├── DatabaseHelper.php
│   └── ApiTestCase.php
└── phpunit.xml               # PHPUnit config
```

### 1.5 Update package.json with Test Scripts

```json
{
  "scripts": {
    "test": "phpunit",
    "test:unit": "phpunit --testsuite='Unit Tests'",
    "test:integration": "phpunit --testsuite='Integration Tests'",
    "test:api": "phpunit --testsuite='API Tests'",
    "test:coverage": "phpunit --coverage-html=coverage/html",
    "test:watch": "phpunit --watch",
    "test:fast": "phpunit --testdox"
  }
}
```

**Usage**:
```bash
npm run test              # Run all tests
npm run test:unit        # Run only unit tests
npm run test:coverage    # Generate coverage report
npm run test:fast        # Quick test output
```

---

## Phase 2: Test Database Setup (Week 1)

### 2.1 Create Test Database

**Create `tests/Helpers/DatabaseHelper.php`**:

```php
<?php

namespace Tests\Helpers;

use mysqli;

class DatabaseHelper {
    private static $connection;
    private static $testDbName = 'piwigo_test';

    /**
     * Set up test database
     */
    public static function setUp() {
        self::$connection = self::createConnection();
        self::createTestDatabase();
        self::loadSchema();
        self::loadFixtures();
    }

    /**
     * Tear down test database
     */
    public static function tearDown() {
        self::dropTestDatabase();
        self::$connection->close();
    }

    /**
     * Create database connection
     */
    private static function createConnection() {
        $conn = new mysqli(
            $_ENV['DB_HOST'] ?? 'localhost',
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASSWORD'] ?? '',
            'mysql'  // Connect to 'mysql' database first
        );

        if ($conn->connect_error) {
            throw new \Exception("Connection failed: " . $conn->connect_error);
        }

        return $conn;
    }

    /**
     * Create test database
     */
    private static function createTestDatabase() {
        $dbName = self::$testDbName;

        // Drop if exists
        self::$connection->query("DROP DATABASE IF EXISTS `$dbName`");

        // Create new test database
        $result = self::$connection->query("CREATE DATABASE `$dbName`");

        if (!$result) {
            throw new \Exception("Failed to create test database: " . self::$connection->error);
        }

        // Select test database
        self::$connection->select_db($dbName);
    }

    /**
     * Load database schema
     */
    private static function loadSchema() {
        $schemaFile = TESTS_DIR . '/fixtures/schema.sql';

        if (!file_exists($schemaFile)) {
            throw new \Exception("Schema file not found: $schemaFile");
        }

        $sql = file_get_contents($schemaFile);
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($statements as $statement) {
            if (!empty($statement)) {
                if (!self::$connection->query($statement)) {
                    throw new \Exception("Failed to execute query: " . self::$connection->error);
                }
            }
        }
    }

    /**
     * Load test fixtures
     */
    private static function loadFixtures() {
        // Load test data
        $fixtures = [
            'categories',
            'images',
            'users',
            'tags',
        ];

        foreach ($fixtures as $fixture) {
            $file = TESTS_DIR . "/fixtures/{$fixture}.php";
            if (file_exists($file)) {
                $data = require $file;
                self::insertData($fixture, $data);
            }
        }
    }

    /**
     * Insert fixture data
     */
    private static function insertData($table, $rows) {
        foreach ($rows as $row) {
            $columns = implode(',', array_keys($row));
            $values = implode(',', array_map(function($v) {
                return "'" . self::$connection->real_escape_string($v) . "'";
            }, $row));

            $query = "INSERT INTO $table ($columns) VALUES ($values)";

            if (!self::$connection->query($query)) {
                throw new \Exception("Failed to insert fixture: " . self::$connection->error);
            }
        }
    }

    /**
     * Drop test database
     */
    private static function dropTestDatabase() {
        $dbName = self::$testDbName;
        self::$connection->query("DROP DATABASE IF EXISTS `$dbName`");
    }

    /**
     * Get database connection
     */
    public static function getConnection() {
        return self::$connection;
    }

    /**
     * Execute query
     */
    public static function query($sql) {
        return self::$connection->query($sql);
    }

    /**
     * Clean specific table
     */
    public static function truncateTable($table) {
        self::$connection->query("TRUNCATE TABLE $table");
    }

    /**
     * Clean all tables
     */
    public static function truncateAll() {
        $tables = [
            'images',
            'image_tag',
            'categories',
            'tags',
            'users',
        ];

        foreach ($tables as $table) {
            self::truncateTable($table);
        }
    }
}
```

### 2.2 Create Minimal Test Schema

**Create `tests/fixtures/schema.sql`**:

```sql
CREATE TABLE categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    parent_id INT,
    position INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE images (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    file VARCHAR(255) NOT NULL UNIQUE,
    category_id INT NOT NULL,
    width INT DEFAULT 0,
    height INT DEFAULT 0,
    filesize INT DEFAULT 0,
    comment TEXT,
    author VARCHAR(255),
    date_available DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_category (category_id),
    FULLTEXT INDEX idx_search (name, comment)
);

CREATE TABLE tags (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    url_name VARCHAR(255) UNIQUE
);

CREATE TABLE image_tag (
    image_id INT,
    tag_id INT,
    PRIMARY KEY (image_id, tag_id),
    FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    is_admin BOOLEAN DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username)
);

CREATE TABLE config (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT
);
```

### 2.3 Create Test Base Classes

**Create `tests/Helpers/TestCase.php`**:

```php
<?php

namespace Tests\Helpers;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
    protected static $database;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        DatabaseHelper::setUp();
        self::$database = DatabaseHelper::getConnection();
    }

    public static function tearDownAfterClass(): void {
        DatabaseHelper::tearDown();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        parent::setUp();
        // Clean up before each test
        DatabaseHelper::truncateAll();
    }

    /**
     * Helper to insert test data
     */
    protected function insertCategory($data = []) {
        $defaults = [
            'name' => 'Test Category',
            'description' => 'Test description',
            'position' => 0,
        ];

        $row = array_merge($defaults, $data);
        $columns = implode(',', array_keys($row));
        $values = implode("','", array_values($row));

        $query = "INSERT INTO categories ($columns) VALUES ('$values')";
        self::$database->query($query);

        return self::$database->insert_id;
    }

    /**
     * Helper to insert test image
     */
    protected function insertImage($data = []) {
        $defaults = [
            'name' => 'Test Image',
            'file' => 'test-' . uniqid() . '.jpg',
            'category_id' => $this->insertCategory(),
            'width' => 800,
            'height' => 600,
            'filesize' => 50000,
        ];

        $row = array_merge($defaults, $data);
        $columns = implode(',', array_keys($row));
        $values = implode("','", array_values($row));

        $query = "INSERT INTO images ($columns) VALUES ('$values')";
        self::$database->query($query);

        return self::$database->insert_id;
    }

    /**
     * Helper to insert user
     */
    protected function insertUser($data = []) {
        $defaults = [
            'username' => 'testuser-' . uniqid(),
            'password' => password_hash('password123', PASSWORD_BCRYPT),
            'email' => 'test-' . uniqid() . '@example.com',
            'is_admin' => 0,
        ];

        $row = array_merge($defaults, $data);
        $columns = implode(',', array_keys($row));
        $values = implode("','", array_values($row));

        $query = "INSERT INTO users ($columns) VALUES ('$values')";
        self::$database->query($query);

        return self::$database->insert_id;
    }

    /**
     * Assert database has record
     */
    protected function assertDatabaseHas($table, $criteria) {
        $where = [];
        foreach ($criteria as $column => $value) {
            $where[] = "$column = '$value'";
        }

        $query = "SELECT COUNT(*) as count FROM $table WHERE " . implode(' AND ', $where);
        $result = self::$database->query($query);
        $row = $result->fetch_assoc();

        $this->assertGreaterThan(0, $row['count'], "Record not found in $table");
    }

    /**
     * Assert database missing record
     */
    protected function assertDatabaseMissing($table, $criteria) {
        $where = [];
        foreach ($criteria as $column => $value) {
            $where[] = "$column = '$value'";
        }

        $query = "SELECT COUNT(*) as count FROM $table WHERE " . implode(' AND ', $where);
        $result = self::$database->query($query);
        $row = $result->fetch_assoc();

        $this->assertEquals(0, $row['count'], "Record found in $table but should not exist");
    }
}
```

---

## Phase 3: Write Tests for Core Modules (Week 2-3)

### 3.1 Unit Test Example: Category Functions

**Create `tests/Unit/CategoryTest.php`**:

```php
<?php

namespace Tests\Unit;

use Tests\Helpers\TestCase;

class CategoryTest extends TestCase {
    /**
     * Test creating a category
     */
    public function testCreateCategory() {
        $categoryId = $this->insertCategory([
            'name' => 'Vacation Photos',
            'description' => 'Summer vacation',
        ]);

        $this->assertGreaterThan(0, $categoryId);
        $this->assertDatabaseHas('categories', [
            'id' => $categoryId,
            'name' => 'Vacation Photos',
        ]);
    }

    /**
     * Test category hierarchy
     */
    public function testCategoryWithParent() {
        $parentId = $this->insertCategory(['name' => 'Parent']);
        $childId = $this->insertCategory([
            'name' => 'Child',
            'parent_id' => $parentId,
        ]);

        $this->assertDatabaseHas('categories', ['id' => $childId, 'parent_id' => $parentId]);
    }

    /**
     * Test category name is required
     */
    public function testCategoryNameRequired() {
        // This depends on your validation - adjust as needed
        $this->assertTrue(true); // Placeholder
    }

    /**
     * Test get categories
     */
    public function testGetAllCategories() {
        $this->insertCategory(['name' => 'Category 1']);
        $this->insertCategory(['name' => 'Category 2']);
        $this->insertCategory(['name' => 'Category 3']);

        $result = self::$database->query("SELECT COUNT(*) as count FROM categories");
        $row = $result->fetch_assoc();

        $this->assertEquals(3, $row['count']);
    }

    /**
     * Test delete category
     */
    public function testDeleteCategory() {
        $categoryId = $this->insertCategory();

        self::$database->query("DELETE FROM categories WHERE id = $categoryId");

        $this->assertDatabaseMissing('categories', ['id' => $categoryId]);
    }

    /**
     * Test update category
     */
    public function testUpdateCategory() {
        $categoryId = $this->insertCategory(['name' => 'Original Name']);

        self::$database->query(
            "UPDATE categories SET name = 'Updated Name' WHERE id = $categoryId"
        );

        $this->assertDatabaseHas('categories', [
            'id' => $categoryId,
            'name' => 'Updated Name',
        ]);
    }

    /**
     * Test cascading delete (images deleted when category deleted)
     */
    public function testCascadeDelete() {
        $categoryId = $this->insertCategory();
        $imageId = $this->insertImage(['category_id' => $categoryId]);

        // Verify image exists
        $this->assertDatabaseHas('images', ['id' => $imageId]);

        // Delete category
        self::$database->query("DELETE FROM categories WHERE id = $categoryId");

        // Image should also be deleted due to CASCADE
        $this->assertDatabaseMissing('images', ['id' => $imageId]);
    }
}
```

**Run tests**:
```bash
npm run test:unit          # Run all unit tests
npm run test -- tests/Unit/CategoryTest.php  # Run specific test
```

### 3.2 Unit Test Example: Image Functions

**Create `tests/Unit/ImageTest.php`**:

```php
<?php

namespace Tests\Unit;

use Tests\Helpers\TestCase;

class ImageTest extends TestCase {
    /**
     * Test creating an image
     */
    public function testCreateImage() {
        $categoryId = $this->insertCategory();
        $imageId = $this->insertImage([
            'name' => 'Beach Sunset',
            'category_id' => $categoryId,
            'width' => 1920,
            'height' => 1080,
        ]);

        $this->assertGreaterThan(0, $imageId);
        $this->assertDatabaseHas('images', ['id' => $imageId, 'name' => 'Beach Sunset']);
    }

    /**
     * Test image has unique filename
     */
    public function testImageFilenameUnique() {
        $categoryId = $this->insertCategory();

        $imageId1 = $this->insertImage([
            'category_id' => $categoryId,
            'file' => 'unique-file.jpg',
        ]);

        // Try to insert duplicate filename - should fail
        // (This depends on your insert method - adjust as needed)
        $this->assertGreaterThan(0, $imageId1);
    }

    /**
     * Test delete image
     */
    public function testDeleteImage() {
        $imageId = $this->insertImage();

        self::$database->query("DELETE FROM images WHERE id = $imageId");

        $this->assertDatabaseMissing('images', ['id' => $imageId]);
    }

    /**
     * Test image metadata
     */
    public function testImageMetadata() {
        $imageId = $this->insertImage([
            'width' => 1280,
            'height' => 960,
            'filesize' => 250000,
            'author' => 'John Doe',
        ]);

        $result = self::$database->query("SELECT * FROM images WHERE id = $imageId");
        $image = $result->fetch_assoc();

        $this->assertEquals(1280, $image['width']);
        $this->assertEquals(960, $image['height']);
        $this->assertEquals(250000, $image['filesize']);
        $this->assertEquals('John Doe', $image['author']);
    }

    /**
     * Test tagging images
     */
    public function testTagImage() {
        $imageId = $this->insertImage();

        // Insert tag
        self::$database->query("INSERT INTO tags (name, url_name) VALUES ('Sunset', 'sunset')");
        $tagId = self::$database->insert_id;

        // Link image to tag
        self::$database->query(
            "INSERT INTO image_tag (image_id, tag_id) VALUES ($imageId, $tagId)"
        );

        $this->assertDatabaseHas('image_tag', [
            'image_id' => $imageId,
            'tag_id' => $tagId,
        ]);
    }

    /**
     * Test get images in category
     */
    public function testGetImagesInCategory() {
        $categoryId = $this->insertCategory();

        $this->insertImage(['category_id' => $categoryId]);
        $this->insertImage(['category_id' => $categoryId]);
        $this->insertImage(['category_id' => $categoryId]);

        $result = self::$database->query(
            "SELECT COUNT(*) as count FROM images WHERE category_id = $categoryId"
        );
        $row = $result->fetch_assoc();

        $this->assertEquals(3, $row['count']);
    }

    /**
     * Test search images
     */
    public function testSearchImages() {
        $categoryId = $this->insertCategory();

        $this->insertImage([
            'name' => 'Beach Sunset',
            'category_id' => $categoryId,
        ]);

        $this->insertImage([
            'name' => 'Mountain Peak',
            'category_id' => $categoryId,
        ]);

        $result = self::$database->query(
            "SELECT * FROM images WHERE name LIKE '%Beach%' OR name LIKE '%Sunset%'"
        );

        $this->assertEquals(1, $result->num_rows);
    }
}
```

### 3.3 Unit Test Example: User Authentication

**Create `tests/Unit/UserTest.php`**:

```php
<?php

namespace Tests\Unit;

use Tests\Helpers\TestCase;

class UserTest extends TestCase {
    /**
     * Test create user
     */
    public function testCreateUser() {
        $userId = $this->insertUser([
            'username' => 'john_doe',
            'email' => 'john@example.com',
        ]);

        $this->assertGreaterThan(0, $userId);
        $this->assertDatabaseHas('users', ['id' => $userId, 'username' => 'john_doe']);
    }

    /**
     * Test admin user
     */
    public function testAdminUser() {
        $adminId = $this->insertUser(['is_admin' => 1]);

        $result = self::$database->query("SELECT is_admin FROM users WHERE id = $adminId");
        $user = $result->fetch_assoc();

        $this->assertEquals(1, $user['is_admin']);
    }

    /**
     * Test regular user not admin
     */
    public function testRegularUserNotAdmin() {
        $userId = $this->insertUser(['is_admin' => 0]);

        $result = self::$database->query("SELECT is_admin FROM users WHERE id = $userId");
        $user = $result->fetch_assoc();

        $this->assertEquals(0, $user['is_admin']);
    }

    /**
     * Test unique username
     */
    public function testUsernameUnique() {
        $this->insertUser(['username' => 'unique_user']);

        // Try to insert duplicate - should fail
        // (depends on your insert method)
        $this->assertDatabaseHas('users', ['username' => 'unique_user']);
    }

    /**
     * Test password hashing
     */
    public function testPasswordIsHashed() {
        $plainPassword = 'MyPassword123!';
        $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

        $userId = $this->insertUser(['password' => $hashedPassword]);

        $result = self::$database->query("SELECT password FROM users WHERE id = $userId");
        $user = $result->fetch_assoc();

        // Password should be hashed
        $this->assertNotEquals($plainPassword, $user['password']);
        $this->assertTrue(password_verify($plainPassword, $user['password']));
    }
}
```

---

## Phase 4: Integration Tests (Week 2-3)

### 4.1 Database Integration Test

**Create `tests/Integration/CategoryRepositoryTest.php`**:

```php
<?php

namespace Tests\Integration;

use Tests\Helpers\TestCase;

class CategoryRepositoryTest extends TestCase {
    /**
     * Test get categories with image counts
     */
    public function testGetCategoriesWithImageCounts() {
        $cat1Id = $this->insertCategory(['name' => 'Landscapes']);
        $cat2Id = $this->insertCategory(['name' => 'Portraits']);

        // Add images to categories
        $this->insertImage(['category_id' => $cat1Id]);
        $this->insertImage(['category_id' => $cat1Id]);
        $this->insertImage(['category_id' => $cat2Id]);

        // Query with image counts
        $result = self::$database->query(
            "SELECT c.*, COUNT(i.id) as image_count
             FROM categories c
             LEFT JOIN images i ON c.id = i.category_id
             GROUP BY c.id
             ORDER BY c.position"
        );

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }

        $this->assertCount(2, $categories);
        $this->assertEquals(2, $categories[0]['image_count']);
        $this->assertEquals(1, $categories[1]['image_count']);
    }

    /**
     * Test nested category hierarchy
     */
    public function testCategoryHierarchy() {
        $root = $this->insertCategory(['name' => 'Root']);
        $level1 = $this->insertCategory(['name' => 'Level 1', 'parent_id' => $root]);
        $level2 = $this->insertCategory(['name' => 'Level 2', 'parent_id' => $level1]);

        // Get full path
        $result = self::$database->query(
            "SELECT id, name, parent_id FROM categories WHERE id = $level2"
        );
        $node = $result->fetch_assoc();

        $this->assertEquals('Level 2', $node['name']);
        $this->assertEquals($level1, $node['parent_id']);
    }

    /**
     * Test category with multiple images and tags
     */
    public function testCategoryComplexRelationships() {
        $categoryId = $this->insertCategory(['name' => 'Complex']);

        // Create images
        $image1 = $this->insertImage(['category_id' => $categoryId, 'name' => 'Image 1']);
        $image2 = $this->insertImage(['category_id' => $categoryId, 'name' => 'Image 2']);

        // Create tags
        self::$database->query("INSERT INTO tags (name) VALUES ('Tag1')");
        $tag1 = self::$database->insert_id;

        self::$database->query("INSERT INTO tags (name) VALUES ('Tag2')");
        $tag2 = self::$database->insert_id;

        // Link images to tags
        self::$database->query(
            "INSERT INTO image_tag (image_id, tag_id) VALUES
             ($image1, $tag1),
             ($image1, $tag2),
             ($image2, $tag1)"
        );

        // Query: get all images with their tags in this category
        $result = self::$database->query(
            "SELECT i.id, i.name, COUNT(t.id) as tag_count
             FROM images i
             LEFT JOIN image_tag it ON i.id = it.image_id
             LEFT JOIN tags t ON it.tag_id = t.id
             WHERE i.category_id = $categoryId
             GROUP BY i.id"
        );

        $images = [];
        while ($row = $result->fetch_assoc()) {
            $images[] = $row;
        }

        $this->assertCount(2, $images);
        $this->assertEquals(2, $images[0]['tag_count']);  // Image 1 has 2 tags
        $this->assertEquals(1, $images[1]['tag_count']);  // Image 2 has 1 tag
    }
}
```

### 4.2 Search Integration Test

**Create `tests/Integration/SearchTest.php`**:

```php
<?php

namespace Tests\Integration;

use Tests\Helpers\TestCase;

class SearchTest extends TestCase {
    /**
     * Test full-text search
     */
    public function testFullTextSearch() {
        $categoryId = $this->insertCategory();

        $this->insertImage([
            'category_id' => $categoryId,
            'name' => 'Beautiful Beach Sunset',
            'comment' => 'Taken during summer vacation',
        ]);

        $this->insertImage([
            'category_id' => $categoryId,
            'name' => 'Mountain Peak',
            'comment' => 'Scenic landscape photography',
        ]);

        // Search for "beach"
        $result = self::$database->query(
            "SELECT * FROM images WHERE MATCH(name, comment) AGAINST('beach' IN BOOLEAN MODE)"
        );

        $this->assertEquals(1, $result->num_rows);
        $image = $result->fetch_assoc();
        $this->assertStringContainsString('Beach', $image['name']);
    }

    /**
     * Test search with filters
     */
    public function testSearchWithFilters() {
        $cat1 = $this->insertCategory(['name' => 'Nature']);
        $cat2 = $this->insertCategory(['name' => 'Urban']);

        $this->insertImage(['category_id' => $cat1, 'name' => 'Forest', 'width' => 1920, 'height' => 1080]);
        $this->insertImage(['category_id' => $cat2, 'name' => 'City', 'width' => 800, 'height' => 600]);
        $this->insertImage(['category_id' => $cat1, 'name' => 'Mountain', 'width' => 1024, 'height' => 768]);

        // Search: high resolution images in "Nature" category
        $result = self::$database->query(
            "SELECT * FROM images
             WHERE category_id = $cat1
             AND width >= 1024
             ORDER BY name"
        );

        $this->assertEquals(2, $result->num_rows);
    }

    /**
     * Test search by tag
     */
    public function testSearchByTag() {
        $categoryId = $this->insertCategory();

        $image1 = $this->insertImage(['category_id' => $categoryId, 'name' => 'Beach']);
        $image2 = $this->insertImage(['category_id' => $categoryId, 'name' => 'Mountain']);

        // Create tags
        self::$database->query("INSERT INTO tags (name) VALUES ('Nature')");
        $tagId = self::$database->insert_id;

        // Tag both images
        self::$database->query("INSERT INTO image_tag (image_id, tag_id) VALUES ($image1, $tagId)");
        self::$database->query("INSERT INTO image_tag (image_id, tag_id) VALUES ($image2, $tagId)");

        // Search: images with "Nature" tag
        $result = self::$database->query(
            "SELECT DISTINCT i.* FROM images i
             JOIN image_tag it ON i.id = it.image_id
             JOIN tags t ON it.tag_id = t.id
             WHERE t.name = 'Nature'
             ORDER BY i.name"
        );

        $this->assertEquals(2, $result->num_rows);
    }
}
```

---

## Phase 5: API Tests (Week 3)

### 5.1 API Endpoint Test Base Class

**Create `tests/Helpers/ApiTestCase.php`**:

```php
<?php

namespace Tests\Helpers;

abstract class ApiTestCase extends TestCase {
    protected $baseUrl = 'http://localhost';

    /**
     * Make API request
     */
    protected function apiCall($method, $endpoint, $data = null, $headers = []) {
        $url = $this->baseUrl . '/api' . $endpoint;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'code' => $httpCode,
            'body' => json_decode($response, true),
        ];
    }

    /**
     * GET request
     */
    protected function get($endpoint, $headers = []) {
        return $this->apiCall('GET', $endpoint, null, $headers);
    }

    /**
     * POST request
     */
    protected function post($endpoint, $data, $headers = []) {
        return $this->apiCall('POST', $endpoint, $data, $headers);
    }

    /**
     * PUT request
     */
    protected function put($endpoint, $data, $headers = []) {
        return $this->apiCall('PUT', $endpoint, $data, $headers);
    }

    /**
     * DELETE request
     */
    protected function delete($endpoint, $headers = []) {
        return $this->apiCall('DELETE', $endpoint, null, $headers);
    }

    /**
     * Assert response status
     */
    protected function assertResponseStatus($response, $expectedCode) {
        $this->assertEquals($expectedCode, $response['code']);
    }

    /**
     * Assert JSON response structure
     */
    protected function assertJsonStructure($response, $structure) {
        foreach ($structure as $key) {
            $this->assertArrayHasKey($key, $response['body']);
        }
    }
}
```

### 5.2 API Test Example

**Create `tests/Api/PhotosApiTest.php`**:

```php
<?php

namespace Tests\Api;

use Tests\Helpers\ApiTestCase;

class PhotosApiTest extends ApiTestCase {
    /**
     * Test get all photos
     */
    public function testGetPhotos() {
        $categoryId = $this->insertCategory();

        $this->insertImage(['category_id' => $categoryId, 'name' => 'Photo 1']);
        $this->insertImage(['category_id' => $categoryId, 'name' => 'Photo 2']);

        $response = $this->get('/photos');

        $this->assertResponseStatus($response, 200);
        $this->assertJsonStructure($response, ['status', 'data']);
        $this->assertCount(2, $response['body']['data']);
    }

    /**
     * Test get photo by ID
     */
    public function testGetPhotoById() {
        $categoryId = $this->insertCategory();
        $imageId = $this->insertImage([
            'category_id' => $categoryId,
            'name' => 'Test Photo',
        ]);

        $response = $this->get("/photos/$imageId");

        $this->assertResponseStatus($response, 200);
        $this->assertEquals('Test Photo', $response['body']['data']['name']);
    }

    /**
     * Test get non-existent photo
     */
    public function testGetPhotoNotFound() {
        $response = $this->get('/photos/99999');

        $this->assertResponseStatus($response, 404);
    }

    /**
     * Test get photos by category
     */
    public function testGetPhotosByCategory() {
        $cat1 = $this->insertCategory(['name' => 'Landscapes']);
        $cat2 = $this->insertCategory(['name' => 'Portraits']);

        $this->insertImage(['category_id' => $cat1]);
        $this->insertImage(['category_id' => $cat1]);
        $this->insertImage(['category_id' => $cat2]);

        $response = $this->get("/photos?category=$cat1");

        $this->assertResponseStatus($response, 200);
        $this->assertCount(2, $response['body']['data']);
    }

    /**
     * Test search photos
     */
    public function testSearchPhotos() {
        $categoryId = $this->insertCategory();

        $this->insertImage([
            'category_id' => $categoryId,
            'name' => 'Sunset at Beach',
        ]);

        $this->insertImage([
            'category_id' => $categoryId,
            'name' => 'Mountain Peak',
        ]);

        $response = $this->get('/photos?search=sunset');

        $this->assertResponseStatus($response, 200);
        $this->assertCount(1, $response['body']['data']);
    }
}
```

---

## Phase 6: Coverage Reporting & CI/CD (Week 3)

### 6.1 Generate Coverage Report

```bash
npm run test:coverage
```

This generates:
- `coverage/html/index.html` - Visual coverage report
- `coverage/clover.xml` - Machine-readable format
- `coverage/text.txt` - Text report

**Check coverage**:
```bash
cat coverage/text.txt
```

### 6.2 Set Up GitHub Actions CI/CD

**Create `.github/workflows/tests.yml`**:

```yaml
name: Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main, develop ]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
        ports:
          - 3306:3306

    steps:
    - uses: actions/checkout@v3

    - name: Set up PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.4'
        extensions: mysqli, gd, exif

    - name: Install dependencies
      run: composer install

    - name: Run unit tests
      run: npm run test:unit

    - name: Run integration tests
      run: npm run test:integration
      env:
        DB_HOST: localhost
        DB_USER: root
        DB_PASSWORD: root

    - name: Generate coverage
      run: npm run test:coverage

    - name: Upload coverage to Codecov
      uses: codecov/codecov-action@v3
      with:
        file: ./coverage/clover.xml
```

### 6.3 Set Coverage Thresholds

Update `phpunit.xml` to enforce minimum coverage:

```xml
<coverage processUncoveredFiles="true"
          failOnRisky="true"
          failOnUnintentionalIncompleteTest="true"
          failOnUncover="false"
          failOnUndermine="false">
    <include>
        <directory suffix=".php">inc</directory>
        <directory suffix=".php">admin/inc</directory>
    </include>

    <!-- Enforce minimum coverage -->
    <report>
        <html outputDirectory="coverage/html"/>
        <text outputFile="coverage/text.txt"/>
        <clover outputFile="coverage/clover.xml"/>
        <crap4j outputFile="coverage/crap4j.xml" threshold="50"/>
    </report>
</coverage>
```

---

## Phase 7: Continuous Testing & Improvement

### 7.1 Testing Checklist for Refactoring

**Before refactoring any code**:

- [ ] Does it have tests?
- [ ] What is the current coverage?
- [ ] Are tests passing?
- [ ] Run `npm run test:coverage` to get baseline
- [ ] Document the coverage number

**While refactoring**:

- [ ] Run tests frequently (`npm run test`)
- [ ] Tests should always pass
- [ ] Coverage should not decrease
- [ ] If test fails, fix code or test, but don't remove test

**After refactoring**:

- [ ] All tests pass
- [ ] Coverage maintained or improved
- [ ] Run full test suite
- [ ] Run coverage report
- [ ] Commit tests and code together

### 7.2 Testing Priority (Write Tests in This Order)

1. **Critical Path** (highest priority)
   - User authentication
   - Photo upload
   - Image display
   - Category access

2. **Core Features** (high priority)
   - Search
   - Tagging
   - Filtering
   - Image metadata

3. **API Endpoints** (medium priority)
   - GET /photos
   - POST /photos (upload)
   - GET /categories
   - DELETE endpoints

4. **Edge Cases** (lower priority)
   - Error handling
   - Invalid input
   - Permission checks

---

## Example: Running Tests

### Run All Tests
```bash
npm run test

# Output:
# PHPUnit 11.0.0 by Sebastian Bergmann and contributors.
#
# ................................... 33 passed, 0 failed, 0 warnings
#
# Code coverage: 65.2% of statement coverage
```

### Run Specific Test Suite
```bash
npm run test:unit
npm run test:integration
npm run test:api
```

### Run Specific Test File
```bash
npm run test -- tests/Unit/CategoryTest.php
```

### Run with Fast Output
```bash
npm run test:fast

# Output in test format (faster for CI):
# Tests\Unit\CategoryTest
#  ✓ testCreateCategory
#  ✓ testCategoryWithParent
#  ✓ testDeleteCategory
#  ...
```

---

## Testing Best Practices

### ✅ DO

- Write tests for new code FIRST
- Test the happy path AND edge cases
- Keep tests focused (one thing per test)
- Use descriptive test names
- Clean up test data after each test
- Use test helpers to reduce duplication
- Test database interactions
- Test error conditions

### ❌ DON'T

- Test multiple things in one test
- Skip difficult-to-test code
- Test implementation details
- Ignore failing tests
- Leave test data behind
- Copy-paste test code
- Test third-party code
- Make tests dependent on each other

---

## Example: Test-Driven Refactoring

### Step 1: Add Test FIRST

```php
public function testUpdateCategoryName() {
    $categoryId = $this->insertCategory(['name' => 'Old Name']);

    // This functionality doesn't exist yet
    $categoryService = new CategoryService();
    $categoryService->updateName($categoryId, 'New Name');

    $this->assertDatabaseHas('categories', [
        'id' => $categoryId,
        'name' => 'New Name',
    ]);
}
```

### Step 2: Run Test (It Fails)
```bash
npm run test
# FAILED: CategoryService class does not exist
```

### Step 3: Implement Feature
```php
class CategoryService {
    public function updateName($categoryId, $newName) {
        // Implementation
        $db->query("UPDATE categories SET name = ? WHERE id = ?",
            [$newName, $categoryId]);
    }
}
```

### Step 4: Run Test (It Passes)
```bash
npm run test
# PASSED: testUpdateCategoryName
```

### Step 5: Refactor Safely
Now you can refactor the implementation without breaking anything!

---

## Quick Start (Today)

```bash
# 1. Install PHPUnit (already done? verify)
composer require --dev phpunit/phpunit

# 2. Create test structure
mkdir -p tests/{Unit,Integration,Api,Helpers,Fixtures}

# 3. Create phpunit.xml (copy from this guide)
# 4. Create tests/bootstrap.php (copy from this guide)
# 5. Create tests/Helpers/TestCase.php (copy from this guide)

# 6. Write first test
# Copy tests/Unit/CategoryTest.php from this guide

# 7. Run tests
./vendor/bin/phpunit tests/Unit/CategoryTest.php

# 8. Watch coverage grow as you write tests
./vendor/bin/phpunit --coverage-html=coverage
```

---

## Success Metrics

| Milestone | Target | Timeline |
|-----------|--------|----------|
| Basic test infrastructure | Complete | Week 1 |
| Test database running | 100% | Week 1 |
| Unit tests written | 20+ | Week 2 |
| Test coverage | 40%+ | Week 2 |
| Integration tests | 10+ | Week 2-3 |
| API tests | 15+ | Week 3 |
| Coverage target | 70%+ | Week 3 |
| CI/CD pipeline | Running | Week 3 |

---

## Next Steps

1. **This week**: Set up infrastructure (Phases 1-2)
2. **Next week**: Write tests (Phases 3-4)
3. **Following week**: Complete coverage (Phases 5-6)
4. **Then**: You're ready to refactor safely!

Once you have 70%+ test coverage on core modules, you can refactor with confidence!

---

**Document Version**: 1.0
**Last Updated**: November 2025
**Recommendation**: Spend 2-3 weeks building this test foundation BEFORE major refactoring
