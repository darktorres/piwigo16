<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * One row of `site_manager.latte`'s `$sites` list, built by
 * {@see \Piwigo\Controller\Admin\SiteManagerSubController::handle()}.
 * `$pluginLinks` stays a raw array -- it's the direct, unconverted
 * output of {@see \Piwigo\Controller\Admin\Event\GetAdminsSiteLinks}, a
 * confirmed plugin-extensible event payload (`array<mixed> $pluginLinks`
 * on that event itself).
 */
final readonly class SiteRow
{
    /**
     * @param array<mixed> $pluginLinks
     */
    public function __construct(
        public string $name,
        public string $type,
        public int $categories,
        public int $images,
        public string $uSynchronize,
        public ?string $uDelete,
        public array $pluginLinks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'NAME' => $this->name,
            'TYPE' => $this->type,
            'CATEGORIES' => $this->categories,
            'IMAGES' => $this->images,
            'U_SYNCHRONIZE' => $this->uSynchronize,
        ];

        if ($this->uDelete !== null) {
            $result['U_DELETE'] = $this->uDelete;
        }

        $result['plugin_links'] = $this->pluginLinks;

        return $result;
    }
}
