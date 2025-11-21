# Backend Refactoring Plan

**Target**: Piwigo 14.x PHP Codebase
**Focus**: Code modernization, maintainability, performance, scalability
**Timeline**: Phased approach over multiple releases
**Compatibility**: Maintain backward compatibility with plugin/theme APIs

---

## Executive Summary

The Piwigo backend has a solid foundation but shows signs of age with heavy procedural code, mixed OOP patterns, and opportunities for modernization. This plan outlines a strategic approach to modernize the codebase while maintaining stability and backward compatibility.

**Key Goals**:
- Reduce technical debt
- Improve code maintainability and testability
- Enhance type safety
- Better separation of concerns
- Improve performance and scalability
- Maintain plugin/theme ecosystem compatibility

---

## Phase 1: Foundation & Preparation (Releases 14.5 - 14.7)

### 1.1 Establish Testing Infrastructure

**Current State**: PHPUnit exists but coverage is likely low
**Goal**: Create foundation for safe refactoring

**Tasks**:
- [ ] Set up automated test pipeline
- [ ] Create test coverage baseline (current %)
- [ ] Add unit tests for core modules
  - [ ] `functions_category.php` (27.5KB) - Critical path
  - [ ] `functions_user.php` - Authentication
  - [ ] `functions_search.php` (45.6KB) - Complex logic
  - [ ] Database layer functions
- [ ] Add integration tests for API endpoints
- [ ] Set up code coverage reporting (target: 70%+ for core)
- [ ] Create CI/CD pipeline (GitHub Actions, etc.)

**Success Criteria**:
- 60%+ test coverage for core modules
- All API endpoints have integration tests
- CI/CD runs on every PR

### 1.2 Modernize PHP Version Requirements

**Current**: PHP 8.4.6 minimum
**Goal**: Leverage modern PHP features

**Tasks**:
- [ ] Audit code for deprecated PHP features
- [ ] Update minimum version in documentation
- [ ] Use PHP 8.4+ features:
  - [ ] Named arguments consistently
  - [ ] Match expressions where appropriate
  - [ ] Enums for status/type constants
  - [ ] Fibers for async operations (future)
  - [ ] Attributes for metadata
- [ ] Remove compatibility shims for older PHP
- [ ] Use strict_types in all files

**Example Improvements**:
```php
// Before
define('ACCESS_GUEST', 0);
define('ACCESS_ADMIN', 1);

// After
enum AccessLevel: int {
    case Guest = 0;
    case Admin = 1;
}

// Before
function get_category($id, $fields = null, $where = '') {}

// After
function getCategory(
    int $id,
    ?array $fields = null,
    string $where = ''
): Category { }
```

### 1.3 Establish Code Standards & Quality Tools

**Current**: ECS, PHPStan, Psalm exist but may not be enforced
**Goal**: Enforce consistency across codebase

**Tasks**:
- [ ] Enforce ECS standards in CI/CD
- [ ] Set PHPStan to maximum level
- [ ] Configure Psalm with strict settings
- [ ] Add pre-commit hooks
- [ ] Document coding standards in CONTRIBUTING.md
- [ ] Create style guide for:
  - [ ] Naming conventions (consistent)
  - [ ] Error handling patterns
  - [ ] Documentation/comments

**Configuration Updates**:
```bash
# In CI pipeline
./vendor/bin/ecs check --fix  # Enforce on push
./vendor/bin/phpstan analyze --level=9  # Strict analysis
./vendor/bin/psalm --strict  # Type checking
```

### 1.4 Improve Configuration Management

**Current**: `Config.php` (3,500 LOC), config_default.php
**Goal**: Type-safe, validated configuration

**Tasks**:
- [ ] Create configuration validation layer
- [ ] Type all configuration values in Config.php
- [ ] Create separate configuration classes:
  - [ ] `DatabaseConfig`
  - [ ] `ImageConfig`
  - [ ] `SecurityConfig`
  - [ ] `MailConfig`
  - [ ] `FeatureConfig`
- [ ] Move from global array to typed objects
- [ ] Add configuration schema validation
- [ ] Environment variable support (.env files)

**Example**:
```php
// Before
global $conf;
$title = $conf['gallery_title'] ?? 'My Gallery';

// After
class GalleryConfig {
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly int $thumbsPerPage = 20,
    ) {}
}

$config = $galleryConfig = GalleryConfig::fromDatabase();
echo $config->title;
```

---

## Phase 2: Core Module Refactoring (Releases 14.8 - 15.0)

### 2.1 Database Abstraction Enhancement

**Current State**: Functional adapter pattern in `inc/dblayer/`
**Issues**: Query functions are procedural, limited type safety
**Goal**: Modern QueryBuilder + Repository pattern

**Tasks**:
- [ ] Create QueryBuilder class
  - [ ] Fluent interface: `$qb->select()->from()->where()`
  - [ ] Type-safe parameter binding
  - [ ] SQL generation for MySQL and PostgreSQL
  - [ ] Support for complex queries (joins, subqueries)
- [ ] Create Repository pattern for main entities:
  - [ ] `ImageRepository`
  - [ ] `CategoryRepository`
  - [ ] `UserRepository`
  - [ ] `CommentRepository`
  - [ ] `TagRepository`
- [ ] Implement Data Mapper pattern
- [ ] Add query caching layer
- [ ] Create connection pooling for performance

**Example**:
```php
// Before
$query = 'SELECT * FROM ' . CATEGORIES_TABLE . '
          WHERE status = "public" ORDER BY rank ASC';
$results = pwg_db_fetch_all_assoc(pwg_db_query($query));

// After
$categories = $categoryRepository
    ->where('status', '=', 'public')
    ->orderBy('rank', 'ASC')
    ->getAll();

// Or with QueryBuilder
$categories = (new QueryBuilder())
    ->select(['id', 'name', 'comment'])
    ->from('categories')
    ->where('status', '=', 'public')
    ->orderBy('rank')
    ->execute();
```

### 2.2 Entity/Model Layer Creation

**Current State**: Mix of array returns and minimal OOP
**Goal**: Strong-typed entity classes

**Tasks**:
- [ ] Create Entity classes:
  - [ ] `Image` - Photo metadata
  - [ ] `Category` - Album/folder
  - [ ] `User` - User account
  - [ ] `Comment` - Photo comment
  - [ ] `Tag` - Photo tag
  - [ ] `Rate` - Photo rating
  - [ ] `Permission` - Access control
- [ ] Implement Doctrine-like ORM features:
  - [ ] Lazy loading
  - [ ] Relationship mapping
  - [ ] Validation
  - [ ] Persistence
- [ ] Create Value Objects:
  - [ ] `ImageDimensions`
  - [ ] `FileInfo`
  - [ ] `EmailAddress`
  - [ ] `DateRange`
- [ ] Create Enums:
  - [ ] `AccessLevel` (Guest, Classic, Admin, Webmaster)
  - [ ] `ImageStatus` (Public, Private, Deleted)
  - [ ] `CommentStatus` (Pending, Approved, Rejected)

**Example**:
```php
// Entity class
class Image {
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $file,
        public readonly ImageDimensions $dimensions,
        public readonly DateTimeImmutable $dateAvailable,
        public readonly DateTimeImmutable $dateCreated,
        public readonly ?string $comment = null,
        public readonly ?string $author = null,
        public readonly ImageStatus $status = ImageStatus::Public,
    ) {}

    public function getThumbnailUrl(): string {
        // ...
    }

    public function getDerivative(DerivativeType $type): ?Derivative {
        // ...
    }
}

// Usage
$image = $repository->getById($id);
echo $image->dimensions->width;  // Type-safe access
```

### 2.3 User & Authentication Refactoring

**Current State**: `functions_user.php` (5.7KB), session handler exists
**Issues**: Authentication scattered, minimal validation
**Goal**: Centralized, testable authentication

**Tasks**:
- [ ] Create `Authentication` service class
- [ ] Create `Authorization` service class
- [ ] Implement Passport/Token system
  - [ ] JWT support for API
  - [ ] OAuth2 readiness
  - [ ] Session tokens
- [ ] Password management:
  - [ ] Hashing service
  - [ ] Strength validation
  - [ ] Change/reset workflows
- [ ] Create `User` value object with immutability
- [ ] Implement `SecurityContext` for current user
- [ ] Create permission/role checking service

**Example**:
```php
class AuthenticationService {
    public function login(string $username, string $password): AuthToken {
        $user = $this->userRepository->findByUsername($username);
        if (!$user) {
            throw AuthenticationException::userNotFound();
        }

        if (!$this->passwordHasher->verify($password, $user->passwordHash)) {
            throw AuthenticationException::invalidPassword();
        }

        return $this->tokenGenerator->generate($user);
    }

    public function verify(AuthToken $token): User {
        // Validate token, return user
    }
}

class AuthorizationService {
    public function isAllowed(User $user, string $action, Resource $resource): bool {
        // Check permissions
    }

    public function requireRole(User $user, UserRole $role): void {
        if ($user->role !== $role) {
            throw ForbiddenException::insufficientRole();
        }
    }
}

// Usage
$authService = new AuthenticationService($userRepository, $passwordHasher);
$token = $authService->login('admin', 'password123');

$authzService = new AuthorizationService($permissionRepository);
if ($authzService->isAllowed($user, 'delete_photo', $photo)) {
    // Delete photo
}
```

### 2.4 Image Processing & Derivatives Refactoring

**Current State**: `DerivativeImage.php`, `ImageStdParams.php`, multiple processors
**Issues**: Complex interaction, lacks clear abstraction
**Goal**: Clean image pipeline

**Tasks**:
- [ ] Create `ImageProcessor` service interface
  - [ ] Implementations: GD, ImageMagick, libvips
  - [ ] Auto-selection based on availability
  - [ ] Fallback chain
- [ ] Create `DerivativeGenerator` service
  - [ ] Async generation support
  - [ ] Progress tracking
  - [ ] Error handling & recovery
- [ ] Create `ImageMetadata` class
  - [ ] EXIF parsing
  - [ ] Dimension storage
  - [ ] Checksum calculation
- [ ] Create `ImageVariant` class for derivatives
- [ ] Implement image optimization
  - [ ] WebP generation
  - [ ] Progressive JPEG
  - [ ] Responsive image hints (srcset)

**Example**:
```php
interface ImageProcessorInterface {
    public function resize(
        string $source,
        string $destination,
        int $width,
        int $height,
        string $mode = 'cover'
    ): void;

    public function crop(
        string $source,
        string $destination,
        int $x,
        int $y,
        int $width,
        int $height
    ): void;

    public function optimize(
        string $source,
        string $destination,
        int $quality = 85
    ): void;
}

class DerivativeGenerator {
    public function __construct(
        private ImageProcessorInterface $processor,
        private ImageRepository $imageRepository,
    ) {}

    public function generateDerivatives(
        Image $image,
        ?ProgressCallback $callback = null
    ): void {
        foreach (DerivativeType::cases() as $type) {
            $callback?->onProgress($type->name);

            $derivative = new Derivative(
                image: $image,
                type: $type,
                path: $this->getDerivativePath($image, $type),
            );

            $this->processor->resize(
                $image->getPath(),
                $derivative->path,
                $type->width,
                $type->height,
            );

            $callback?->onSuccess($derivative);
        }
    }
}
```

### 2.5 Category/Album System Refactoring

**Current State**: `functions_category.php` (27.5KB) - procedural
**Issues**: Complex relationships, embedded SQL, lacks hierarchy operations
**Goal**: Object-oriented category management

**Tasks**:
- [ ] Create `Category` entity with relationships
- [ ] Create `CategoryRepository` with:
  - [ ] Nested set pattern for hierarchy
  - [ ] Path caching (breadcrumb optimization)
  - [ ] Child/parent queries
  - [ ] Subtree operations
- [ ] Create `CategoryService`:
  - [ ] Move, rename, delete operations
  - [ ] Photo count caching
  - [ ] Permission inheritance
  - [ ] Visibility cascading
- [ ] Implement `CategoryHierarchy` value object
- [ ] Create category tree builder
- [ ] Optimize query patterns for large hierarchies

**Example**:
```php
class Category {
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $parentId,
        public readonly int $rank = 0,
        public readonly CategoryStatus $status = CategoryStatus::Public,
        public readonly ?Category $parent = null,
        public readonly array $children = [],
    ) {}

    public function getAncestors(): array {
        // ...
    }

    public function getDescendants(): array {
        // ...
    }

    public function getPhotoCount(bool $recursive = false): int {
        // ...
    }
}

class CategoryRepository {
    /** @return Category[] */
    public function getHierarchy(?int $parentId = null): array {
        // Use nested set model for efficient querying
        $query = 'SELECT * FROM categories WHERE lft > ? AND rgt < ?';
        // ...
    }

    public function move(Category $category, ?int $newParentId): void {
        // Update nested set values
    }

    public function rebuildNestedSet(): void {
        // Rebuild lft/rgt values after modifications
    }
}

class CategoryService {
    public function deleteCategory(int $categoryId, bool $cascade = true): void {
        $category = $this->repository->getById($categoryId);

        if ($cascade) {
            // Reassign photos and delete subcategories
        } else {
            // Move photos to parent
        }
    }
}
```

---

## Phase 3: Advanced Features (Releases 15.1 - 15.3)

### 3.1 Search System Refactoring

**Current State**: `functions_search.php` (45.6KB), complex query builder
**Issues**: Large single file, complex token parsing, difficult to extend
**Goal**: Modular, extensible search engine

**Tasks**:
- [ ] Create `SearchQueryParser` class
  - [ ] Tokenization
  - [ ] AST building
  - [ ] Validation
  - [ ] Custom syntax support
- [ ] Create `SearchEngine` service
  - [ ] Multi-criteria support
  - [ ] Faceted search
  - [ ] Result ranking/relevance
  - [ ] Pagination
- [ ] Create search filter classes:
  - [ ] `TextSearchFilter`
  - [ ] `DateRangeFilter`
  - [ ] `DimensionFilter`
  - [ ] `RatingFilter`
  - [ ] `CategoryFilter`
  - [ ] `TagFilter`
  - [ ] Custom filters
- [ ] Add search result caching
- [ ] Create search analytics (popular queries, etc.)
- [ ] Implement full-text indexing (future: Elasticsearch support)

**Example**:
```php
class SearchEngine {
    public function search(SearchQuery $query): SearchResults {
        $builder = (new QueryBuilder())
            ->select(['images.*', 'COUNT(*) as relevance'])
            ->from('images');

        foreach ($query->filters as $filter) {
            $filter->apply($builder);
        }

        $builder
            ->orderBy($query->sortBy, $query->sortDirection)
            ->limit($query->limit)
            ->offset($query->offset);

        return $this->execute($builder);
    }
}

// Fluent API
$results = $searchEngine->search(
    SearchQuery::builder()
        ->text('beach')
        ->dateRange('2024-01-01', '2024-12-31')
        ->inCategory($categoryId)
        ->withMinimumRating(4)
        ->sortBy(SortBy::DateDescending)
        ->paginate(page: 1, perPage: 20)
        ->build()
);
```

### 3.2 Notification & Event System Refactoring

**Current State**: `functions_notification.php` (20.9KB), simple trigger system
**Issues**: Tight coupling, no event queue, limited extensibility
**Goal**: Event-driven architecture

**Tasks**:
- [ ] Create `Event` base class hierarchy
  - [ ] `ImageUploadedEvent`
  - [ ] `CommentPostedEvent`
  - [ ] `UserRegisteredEvent`
  - [ ] Custom events
- [ ] Create `EventDispatcher` service
  - [ ] Listener registration
  - [ ] Async execution support
  - [ ] Error handling
- [ ] Create `EventBus` for distributed events
- [ ] Implement `EventQueue` (database or message queue)
  - [ ] Delayed execution
  - [ ] Retry logic
  - [ ] Dead letter queue
- [ ] Create `NotificationService`:
  - [ ] Channel abstraction (Email, Database, SMS, etc.)
  - [ ] Template rendering
  - [ ] User preferences
  - [ ] Rate limiting
- [ ] Implement webhook support for plugins
- [ ] Create audit log from events

**Example**:
```php
class ImageUploadedEvent extends DomainEvent {
    public function __construct(
        public readonly Image $image,
        public readonly User $uploader,
        public readonly DateTime $occurredAt = new DateTime(),
    ) {}
}

class NotifyAdminOnImageUploadListener {
    public function __construct(
        private NotificationService $notificationService,
        private UserRepository $userRepository,
    ) {}

    public function handle(ImageUploadedEvent $event): void {
        $admins = $this->userRepository->findAdmins();

        foreach ($admins as $admin) {
            $this->notificationService->notify(
                $admin,
                'photo_uploaded',
                [
                    'image' => $event->image,
                    'uploader' => $event->uploader,
                ]
            );
        }
    }
}

// Registration
$dispatcher->subscribe(
    ImageUploadedEvent::class,
    new NotifyAdminOnImageUploadListener($notificationService, $userRepository)
);

// Publishing
$dispatcher->dispatch(
    new ImageUploadedEvent($image, $currentUser)
);
```

### 3.3 API Layer Refactoring

**Current State**: 79.8KB in `ws_functions.php` with multiple files
**Issues**: Large files, inconsistent response formats, minimal input validation
**Goal**: Standardized, RESTful API with OpenAPI spec

**Tasks**:
- [ ] Create API Response format standardization
- [ ] Create Input `RequestValidator` class
  - [ ] Schema validation
  - [ ] Automatic error responses
  - [ ] OpenAPI integration
- [ ] Refactor API handlers into services:
  - [ ] Each endpoint becomes a command/query
  - [ ] Use CQRS pattern
  - [ ] Consistent error handling
  - [ ] Rate limiting per endpoint
- [ ] Generate OpenAPI specification
- [ ] Create API versioning strategy
- [ ] Implement pagination standards
- [ ] Create API authentication with scopes
- [ ] Add API documentation auto-generation

**Example**:
```php
// Before: Monolithic function
function pwg_images_getList() {
    $search = $_GET['search'] ?? '';
    $limit = (int)($_GET['limit'] ?? 100);
    // ... 50 lines of code
    return $result;
}

// After: Service + Query/Command
class GetImagesQuery {
    public function __construct(
        public readonly string $search = '',
        public readonly int $limit = 100,
        public readonly int $offset = 0,
        public readonly ?int $categoryId = null,
    ) {}
}

class GetImagesQueryHandler {
    public function __construct(
        private ImageRepository $repository,
        private ValidatorInterface $validator,
    ) {}

    public function handle(GetImagesQuery $query): GetImagesResult {
        $this->validator->validate($query);

        return $this->repository->search(
            $query->search,
            $query->limit,
            $query->offset,
            $query->categoryId,
        );
    }
}

// Controller/Handler
#[Route('GET', '/api/v2/images')]
#[RateLimit(100, 3600)]  // 100 requests per hour
public function getImages(Request $request): JsonResponse {
    $query = GetImagesQuery::fromRequest($request);
    $result = $this->queryBus->handle($query);

    return JsonResponse::success($result);
}
```

### 3.4 Plugin Architecture Enhancement

**Current State**: Hook-based system works but limited standardization
**Goal**: More structured plugin API, backward compatible

**Tasks**:
- [ ] Create `PluginInterface` with strict contracts
  - [ ] Service provider pattern
  - [ ] Configuration schema
  - [ ] Dependency injection
- [ ] Create `EventPluginInterface` for event listeners
- [ ] Create `CommandPluginInterface` for custom commands
- [ ] Create `ApiPluginInterface` for API extensions
- [ ] Implement plugin dependency management
- [ ] Create plugin marketplace metadata standard
- [ ] Plugin auto-loading from namespace
- [ ] Plugin configuration validation
- [ ] Plugin sandboxing/capability system

**Example**:
```php
interface PluginInterface {
    public static function getMetadata(): PluginMetadata;
    public function registerServices(Container $container): void;
    public function boot(Application $app): void;
}

class MyPluginMetadata extends PluginMetadata {
    public function __construct() {
        parent::__construct(
            name: 'My Plugin',
            version: '1.0.0',
            description: 'Does something cool',
            author: 'Your Name',
            requiredVersion: '15.0.0',
            dependencies: ['some-other-plugin'],
            capabilities: ['api_extension', 'event_listener'],
        );
    }
}

class MyPlugin implements PluginInterface {
    public static function getMetadata(): PluginMetadata {
        return new MyPluginMetadata();
    }

    public function registerServices(Container $container): void {
        $container->register(MyService::class, fn() => new MyService());
    }

    public function boot(Application $app): void {
        $app->listen(ImageUploadedEvent::class, fn($e) => $this->onImageUploaded($e));
    }
}
```

---

## Phase 4: Infrastructure & DevOps (Parallel to Phases 2-3)

### 4.1 Dependency Injection Container

**Current State**: Mostly manual dependency management
**Goal**: Centralized DI container

**Tasks**:
- [ ] Implement/integrate DI container (Symfony DependencyInjection or similar)
- [ ] Convert service instantiation to container
- [ ] Auto-wiring support
- [ ] Service provider pattern
- [ ] Configuration management
- [ ] Lazy loading

**Example**:
```php
$container = new Container();

// Service registration
$container->register(
    ImageRepository::class,
    fn(Connection $db) => new ImageRepository($db),
);

$container->register(
    ImageService::class,
    fn(ImageRepository $repo, ImageProcessor $proc) =>
        new ImageService($repo, $proc),
);

// Usage
$imageService = $container->get(ImageService::class);
```

### 4.2 Database Migrations System

**Current State**: Manual SQL scripts in `install/`
**Goal**: Automated migrations with version control

**Tasks**:
- [ ] Implement migration system (Doctrine Migrations or Phinx)
- [ ] Convert existing schema to migrations
- [ ] Auto-generate migrations from entity changes
- [ ] Test migrations (up and down)
- [ ] Version tracking
- [ ] Rollback capability
- [ ] Migration hooks for data transformation

**Example**:
```php
class Version20250101000000 extends Migration {
    public function getDescription(): string {
        return 'Add watermark settings to config';
    }

    public function up(Schema $schema): void {
        $this->addSql(
            'ALTER TABLE piwigo_config ADD watermark_enabled BOOLEAN DEFAULT 0'
        );
    }

    public function down(Schema $schema): void {
        $this->addSql('ALTER TABLE piwigo_config DROP COLUMN watermark_enabled');
    }
}
```

### 4.3 Error Handling & Logging

**Current State**: Minimal structured logging
**Goal**: Comprehensive logging with monitoring

**Tasks**:
- [ ] Create exception hierarchy
  - [ ] `ApplicationException`
  - [ ] `ValidationException`
  - [ ] `AuthenticationException`
  - [ ] `AuthorizationException`
  - [ ] `NotFoundException`
  - [ ] `ConflictException`
- [ ] Implement structured logging (Monolog)
  - [ ] Multiple handlers (file, syslog, cloud)
  - [ ] Log levels (DEBUG, INFO, WARNING, ERROR, CRITICAL)
  - [ ] Context/correlation IDs
  - [ ] Performance metrics
- [ ] Create error response standardization
- [ ] Implement error reporting service
- [ ] Add exception handler middleware
- [ ] Create debug/production mode handling

**Example**:
```php
class ImageUploadException extends ApplicationException {
    public function __construct(
        string $reason,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: "Image upload failed: $reason",
            code: $code,
            previous: $previous,
        );
    }
}

// Usage
try {
    $image = $imageService->upload($file);
} catch (ValidationException $e) {
    $logger->warning('Invalid image provided', ['error' => $e->getMessage()]);
    throw ImageUploadException::invalidFormat();
} catch (DiskException $e) {
    $logger->critical('Disk full during upload', ['error' => $e->getMessage()]);
    throw ImageUploadException::diskFull();
}
```

### 4.4 Performance Optimization

**Current State**: Some caching, room for improvement
**Goal**: Production-grade performance

**Tasks**:
- [ ] Implement multi-tier caching
  - [ ] Query result caching (Redis)
  - [ ] Object caching (APCu)
  - [ ] HTTP caching headers
  - [ ] Cache invalidation strategy
- [ ] Database query optimization
  - [ ] Identify N+1 queries
  - [ ] Lazy loading vs. eager loading
  - [ ] Query indexing recommendations
  - [ ] Query analysis and optimization
- [ ] Implement queue system
  - [ ] Async image processing
  - [ ] Batch operations
  - [ ] Background jobs
  - [ ] Scheduled tasks
- [ ] API response compression
- [ ] Connection pooling
- [ ] Memory profiling

**Example**:
```php
class CachedImageRepository implements ImageRepository {
    public function __construct(
        private ImageRepository $delegate,
        private Cache $cache,
    ) {}

    public function getById(int $id): Image {
        $cacheKey = "image.$id";

        return $this->cache->remember(
            $cacheKey,
            ttl: 3600,
            callback: fn() => $this->delegate->getById($id),
        );
    }

    public function invalidate(int $id): void {
        $this->cache->delete("image.$id");
    }
}
```

---

## Phase 5: Documentation & Community (Ongoing)

### 5.1 Developer Documentation

**Tasks**:
- [ ] Create architecture decision records (ADRs)
- [ ] Document refactoring process and rationale
- [ ] Create contribution guidelines
- [ ] API documentation (OpenAPI/Swagger)
- [ ] Create plugin development guide
- [ ] Create video tutorials
- [ ] Maintain changelog

### 5.2 Backward Compatibility Layer

**Tasks**:
- [ ] Maintain legacy API alongside new API
- [ ] Create deprecation notices
- [ ] Provide migration guides
- [ ] Support grace period for plugins
- [ ] Version policy (SemVer)

---

## Breaking Changes & Migration Strategy

### Managed Deprecations

**For Plugins**:
1. Version N: Introduce new API
2. Version N: Deprecate old API with warnings
3. Version N+2: Remove old API
4. Timeline: Minimum 12 months notice

**Example**:
```php
// Old way (deprecated)
function get_categories() {
    trigger_error(
        'get_categories() is deprecated, use CategoryRepository instead',
        E_USER_DEPRECATED
    );
    return /* ... */;
}

// New way
$categories = $categoryRepository->getAll();
```

### Migration Guides

- Provide automated migration tools
- Create before/after code examples
- Document compatibility layer usage
- Supply example plugin upgrades

---

## Rollout Timeline

| Phase | Version | Timeline | Focus |
|-------|---------|----------|-------|
| 1 | 14.5-14.7 | 3 months | Foundation, testing, standards |
| 2 | 14.8-15.0 | 6 months | Core refactoring, entities, repos |
| 3 | 15.1-15.3 | 6 months | Advanced features, APIs |
| 4 | Parallel | Ongoing | Infrastructure, DevOps |
| 5 | Ongoing | Continuous | Docs, community |

---

## Risk Mitigation

### High-Risk Areas
1. **Database changes**: Migration testing, rollback capability
2. **Plugin compatibility**: Backward compatibility layer
3. **User experience**: Gradual rollout, monitoring
4. **Performance**: Benchmarking before/after

### Monitoring & Metrics
- Test coverage tracking
- Performance metrics (response time, memory)
- Error rate monitoring
- Plugin compatibility status
- Community feedback

---

## Success Criteria

- [ ] Test coverage ≥70% for core modules
- [ ] PHPStan level 9 compliance
- [ ] Psalm strict mode passing
- [ ] Zero deprecation warnings in new code
- [ ] Average query time improved by 30%
- [ ] Memory usage reduced by 20%
- [ ] Plugin ecosystem updated to 90%+
- [ ] Community satisfaction rating ≥4.5/5
- [ ] Release cycle shortened to 6 weeks

---

## Maintenance & Future Roadmap

### Post-Refactoring
- Quarterly architecture reviews
- Performance audits
- Security assessments
- Community feedback integration

### Future Enhancements
- GraphQL API support
- Machine learning features (image recognition, auto-tagging)
- Real-time collaboration features
- Advanced analytics and reporting
- Mobile app API improvements
- Kubernetes-ready deployment

---

**Document Version**: 1.0
**Last Updated**: November 2025
**Next Review**: Q2 2025
