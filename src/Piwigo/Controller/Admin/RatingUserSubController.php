<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\RatingUserPageRenderer;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/rating_user.php (page slug "rating_user") -- the admin
 * "rating by user" report, a sibling top-level page to "rating" sharing the
 * same tabsheet group (not a nested tab of it). Its own data access moved
 * to new Piwigo\Rate\RateRepository report methods, same rationale as
 * RatingSubController.
 */
final class RatingUserSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Image\ImageStdParams $imageStdParams,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new RatingUserPageRenderer()
            ->render($this->urlService, $this->imageStdParams);
    }
}
