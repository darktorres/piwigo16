<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\RatingPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/rating.php (page slug "rating") -- the admin "rated
 * photos" report. Its own data access (rated-image aggregates, per-image
 * rate detail, the user id/username map) moved to new
 * Piwigo\Rate\RateRepository report methods, a natural sibling of that
 * repository's existing rate-domain methods -- reporting is squarely
 * a Rate-domain concern, same precedent as Activity/History's own admin
 * report methods landing directly on their existing repositories rather
 * than a new Admin\Rating-namespaced class.
 */
final readonly class RatingSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private CurrentTemplate $currentTemplate,
        private CategoryService $categoryService,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private CsrfService $csrfService,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new RatingPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->currentTemplate, $this->currentConfig, $this->categoryService, $this->inputValidator, $this->eventDispatcher, $this->entityManager, $this->csrfService, $this->renderer);
    }
}
