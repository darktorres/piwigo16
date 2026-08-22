<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

/**
 * {@see \Piwigo\Controller\ProfileController}'s own `default_user_values`
 * shape -- a fixed 5-field subset of {@see \Piwigo\Users\Projection\
 * DefaultUserInfo}, with the 3 booleans coerced to the JS-literal
 * `'true'`/`'false'` strings `profile.latte`'s own inline JS expects
 * (unquoted interpolation, matching {@see \Piwigo\Controller\
 * ProfileFormHandler::loadIntoTemplate()}'s existing convention for the
 * identical case). Genuinely live on this branch, unlike the reference
 * campaign's own equivalent commit's "entirely dead in the template"
 * finding: `themes/standard_pages/js/profile.js` reads all 5 fields back
 * out of `ProfileView::exposedPageData()`'s own `default_user_values`
 * JS page-data key (`nb_image_page`/`recent_period`/`expand` (as
 * `opt_album`)/`show_nb_comments` (as `opt_comment`)/`show_nb_hits` (as
 * `opt_hits`)) to pre-fill the "reset to default" preferences form.
 */
final readonly class DefaultUserProfileValues
{
    public function __construct(
        public int $nbImagePage,
        public string $expand,
        public string $showNbComments,
        public string $showNbHits,
        public int $recentPeriod,
    ) {}

    /**
     * @return array{nb_image_page: int, expand: string, show_nb_comments: string, show_nb_hits: string, recent_period: int}
     */
    public function toArray(): array
    {
        return [
            'nb_image_page' => $this->nbImagePage,
            'expand' => $this->expand,
            'show_nb_comments' => $this->showNbComments,
            'show_nb_hits' => $this->showNbHits,
            'recent_period' => $this->recentPeriod,
        ];
    }
}
