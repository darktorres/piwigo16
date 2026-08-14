<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * CurrentConfig's own properties are already an in-process static -- this
 * pool is for cross-*process* reuse (APCu/Redis/filesystem persist between
 * requests; a plain PHP static doesn't). Real consumer: ConfigService::
 * allRowsFromCacheOrDb(), caching the ~280-row param => value map that
 * loadConfFromDb() would otherwise re-hydrate via Doctrine ORM on every
 * request. No TTL -- ConfigService's own confUpdateParam()/
 * confDeleteParam() clear this pool after every real write instead of
 * relying on expiry. Namespace/TTL are supplied by config/container.php's
 * own factory() entry, not this class -- see AbstractNamedCachePool.
 */
final class ConfigCachePool extends AbstractNamedCachePool {}
