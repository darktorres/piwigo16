<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\RatingUserPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\GeoIp\GeoIpLookupService;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/rating_user.php (page slug "rating_user") -- the admin
 * "rating by user" report, a sibling top-level page to "rating" sharing the
 * same tabsheet group (not a nested tab of it). Its own data access moved
 * to new Piwigo\Rate\RateRepository report methods, same rationale as
 * RatingSubController.
 */
final readonly class RatingUserSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private ImageStdParams $imageStdParams,
        private CurrentTemplate $currentTemplate,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private CsrfService $csrfService,
        private Renderer $renderer,
        private GeoIpLookupService $geoIpLookupService,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        return new RatingUserPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->imageStdParams, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->entityManager, $this->csrfService, $this->renderer, $this->geoIpLookupService);
    }
}
