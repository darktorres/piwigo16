<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\RatingPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/rating.php (page slug "rating") -- the admin "rated
 * photos" report. Its own data access (rated-image aggregates, per-image
 * rate detail, the user id/username map) moved to new
 * Piwigo\Rate\RateRepository report methods, a natural sibling of that
 * repository's existing rate-domain methods (P18) -- reporting is squarely
 * a Rate-domain concern, same precedent as Activity/History's own admin
 * report methods landing directly on their existing repositories rather
 * than a new Admin\Rating-namespaced class.
 */
final class RatingSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Category\CategoryService $categoryService,
        private readonly \Piwigo\Config\CurrentConfig $currentConfig,
        private readonly \Piwigo\Validation\InputValidator $inputValidator,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new RatingPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->currentTemplate, $this->currentConfig, $this->categoryService, $this->inputValidator, $this->eventDispatcher);
    }
}
