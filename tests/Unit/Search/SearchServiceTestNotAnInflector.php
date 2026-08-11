<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

/**
 * A real class that deliberately does NOT implement InflectorInterface --
 * class_alias()'d onto a fake 'Piwigo\Search\Inflector\Inflector_zz' FQCN
 * by the Inflector-guard test below, standing in for exactly the real-world
 * scenario that guard defends against (a 3rd-party language pack shipping
 * a broken Inflector_xx.php for its own 2-letter code).
 */
final class SearchServiceTestNotAnInflector {}
