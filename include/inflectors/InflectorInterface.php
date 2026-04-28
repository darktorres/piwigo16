<?php

declare(strict_types=1);

interface InflectorInterface
{
    /** @return string[] */
    public function get_variants(string $word): array;
}
