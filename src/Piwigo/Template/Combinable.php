<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Url\UrlService;

/**
 * A Combinable represents a JS or CSS file ready for cobination and minification.
 */
class Combinable
{
    public string $path = '';

    /**
     * @param string $id
     * @param string|int $version
     */
    public function __construct(public $id, ?string $path, public $version = 0)
    {
        if ($path !== null && $path !== '') {
            $this->path = $path;
        }
    }

    public function isRemote(): bool
    {
        return UrlService::urlIsRemote($this->path) || str_starts_with($this->path, '//');
    }
}
