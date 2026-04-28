<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * Implementation of Combinable for JS files.
 */
final class Script extends Combinable
{
    /** @var array */
    public $extra;

    /**
     * @param int $load_mode 0, 1, or 2
     * @param string $id
     * @param string $path
     * @param string|int $version
     * @param array $precedents
     */
    public function __construct(
        public $load_mode,
        $id,
        $path,
        $version = 0,
        public $precedents = []
    ) {
        parent::__construct($id, $path, $version);
        $this->extra = [];
    }
}
