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
     * @param int 0,1,2
     * @param string $id
     * @param string $path
     * @param string $version
     * @param array $precedents
     * @param int $load_mode
     */
    public function __construct(/** @var int 0,1,2 */
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
