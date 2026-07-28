<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures;

/**
 * A real, minimal class (not a genuine Doctrine entity) for
 * TablePrefixListenerTest.php to hand to ClassMetadata's constructor,
 * which requires a real class-string -- the listener under test never
 * instantiates or maps this class, only reads/writes the metadata's own
 * table name.
 */
final class FakeEntity {}
