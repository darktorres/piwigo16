<?php

declare(strict_types=1);

namespace Piwigo\Search\Inflector;

interface InflectorInterface
{
    /** @return string[] */
    public function get_variants(string $word): array;
}
