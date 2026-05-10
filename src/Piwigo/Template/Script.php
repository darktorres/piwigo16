<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * Implementation of Combinable for JS files.
 */
final class Script extends Combinable
{
    public ?int $order = null;

    /**
     * @param int $load_mode 0, 1, or 2
     * @param string $path
     * @param string[] $precedents
     */
    public function __construct(
        public int $load_mode,
        string $id,
        ?string $path,
        string|int $version = 0,
        /** @var string[] */
        public array $precedents = []
    ) {
        parent::__construct($id, $path, $version);
    }
}
