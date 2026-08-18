<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Search\Projection\Search;
use Piwigo\Search\SearchService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/searches` -- `pwg.images.filteredSearch.create`'s
 * real replacement. Public, no `AdminGuard` -- the front-end's own
 * advanced-search page calls this, not just admin.
 *
 * Every field here is genuinely optional and independently validated
 * (~20 of them, see ImageFilteredSearchCreateInput's own docblock for
 * why most of them stay `mixed`/loosely-typed there rather than fully
 * narrowed). `expert` (a raw search-string escape hatch, deliberately
 * undocumented) is dropped -- not carried over to this surface.
 */
final readonly class ImageFilteredSearchCreateController implements ControllerInterface
{
    public function __construct(
        private SearchService $searchService,
        private CurrentConfig $currentConfig,
        private UrlServiceInterface $urlService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = ImageFilteredSearchCreateInput::fromArray(JsonBody::decode($request));

        $searchInfo = null;
        if ($input->searchId !== null) {
            $searchId = $input->searchId;
            if (! is_int($searchId) && ! is_string($searchId)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid searchId input parameter.');
            }
            if (in_array(SearchService::getSearchIdPattern($searchId), [null, ''], true)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid searchId input parameter.');
            }

            $searchInfo = $this->searchService->getValidatedSearchInfo($searchId, null);
            if (! $searchInfo instanceof Search) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'This search does not exist.');
            }
        }

        $search = [
            'mode' => 'AND',
            'fields' => [
                'date_posted' => [],
                'date_created' => [],
            ],
        ];

        if ($input->allwords !== null) {
            $allwordsMode = $input->allwordsMode ?? 'AND';
            if (! in_array($allwordsMode, ['OR', 'AND'], true)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter allwordsMode.');
            }

            $allwordsFieldsAvailable = ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'];
            $allwordsFields = $input->allwordsFields !== null ? array_values(array_filter($input->allwordsFields, is_string(...))) : $allwordsFieldsAvailable;
            foreach ($allwordsFields as $field) {
                if (! in_array($field, $allwordsFieldsAvailable, true)) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter allwordsFields.');
                }
            }

            $search['fields']['allwords'] = [
                'mode' => $allwordsMode,
                'fields' => $allwordsFields,
                'words' => SearchService::splitAllwords($input->allwords),
            ];
        }

        if ($input->tags !== null) {
            foreach ($input->tags as $tagId) {
                if (! is_scalar($tagId) || preg_match('/^\d+$/', (string) $tagId) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter tags.');
                }
            }

            $tagsMode = $input->tagsMode ?? 'AND';
            if (! in_array($tagsMode, ['OR', 'AND'], true)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter tagsMode.');
            }

            $search['fields']['tags'] = [
                'words' => $input->tags,
                'mode' => $tagsMode,
            ];
        }

        if ($input->categories !== null) {
            foreach ($input->categories as $catId) {
                if (! is_scalar($catId) || preg_match('/^\d+$/', (string) $catId) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter categories.');
                }
            }

            $search['fields']['cat'] = [
                'words' => $input->categories,
                'sub_inc' => $input->categoriesWithsubs,
            ];
        }

        if ($input->authors !== null) {
            $authors = [];
            foreach ($input->authors as $author) {
                $authors[] = strip_tags(is_string($author) ? $author : '');
            }

            $search['fields']['author'] = [
                'words' => $authors,
                'mode' => 'OR',
            ];
        }

        if ($input->filetypes !== null) {
            foreach ($input->filetypes as $ext) {
                if (! is_string($ext) || preg_match('/^[a-z0-9]+$/i', $ext) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter filetypes.');
                }
            }

            $search['fields']['filetypes'] = $input->filetypes;
        }

        if ($input->addedBy !== null) {
            foreach ($input->addedBy as $userId) {
                if (! is_scalar($userId) || preg_match('/^\d+$/', (string) $userId) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter addedBy.');
                }
            }

            $search['fields']['added_by'] = $input->addedBy;
        }

        foreach ([['datePostedPreset', 'datePostedCustom', 'date_posted', '24h|7d|30d|3m|6m|custom'], ['dateCreatedPreset', 'dateCreatedCustom', 'date_created', '7d|30d|3m|6m|12m|custom']] as [$presetKey, $customKey, $fieldsKey, $presetPattern]) {
            $preset = $presetKey === 'datePostedPreset' ? $input->datePostedPreset : $input->dateCreatedPreset;
            $custom = $customKey === 'datePostedCustom' ? $input->datePostedCustom : $input->dateCreatedCustom;

            if ($preset !== null) {
                if (! is_string($preset) || preg_match('/^(' . $presetPattern . '|)$/', $preset) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ' . $presetKey . '.');
                }

                $search['fields'][$fieldsKey]['preset'] = $preset;

                if ($preset === 'custom' && ($custom === null || $custom === [])) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, $customKey . ' is missing.');
                }
            }

            if ($custom !== null) {
                if (($search['fields'][$fieldsKey]['preset'] ?? null) !== 'custom') {
                    return ResponseFactory::problem('Unprocessable Entity', 422, $customKey . ' provided but ' . $presetKey . ' is not custom.');
                }

                if (! is_array($custom)) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ' . $customKey . '.');
                }

                foreach ($custom as $date) {
                    if (! is_string($date) || ! self::isValidCustomDate($date)) {
                        return ResponseFactory::problem('Unprocessable Entity', 422, $customKey . ', invalid option ' . (is_string($date) ? $date : '') . '.');
                    }

                    $search['fields'][$fieldsKey]['custom'][] = $date;
                }
            }
        }

        if ($input->ratios !== null) {
            foreach ($input->ratios as $ratio) {
                if (! is_string($ratio) || preg_match('/^[a-z0-9]+$/i', $ratio) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ratios.');
                }
            }

            $search['fields']['ratios'] = $input->ratios;
        }

        if ($this->currentConfig->rateEnabled && $input->ratings !== null) {
            // Same array-of-strings contract SearchController.php's own
            // legacy `ratings` field defaults to ([] when the filter is
            // active) and SearchService::applyFilters() itself requires
            // downstream (is_array($ratingsField) ? array_filter(...,
            // is_string(...)) : []) -- an unvalidated scalar here silently
            // dropped the ratings filter from the search entirely (no
            // error, just wrong results) and crashed the search-results
            // page's own filter-panel JS, which unconditionally calls
            // .forEach()/.length/.includes() on this same field. Same
            // validation shape as `ratios` just above.
            if (! is_array($input->ratings)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ratings.');
            }

            foreach ($input->ratings as $rating) {
                if (! is_string($rating)) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ratings.');
                }
            }

            $search['fields']['ratings'] = $input->ratings;
        }

        foreach ([
            'filesize_min' => $input->filesizeMin,
            'filesize_max' => $input->filesizeMax,
            'width_min' => $input->widthMin,
            'width_max' => $input->widthMax,
            'height_min' => $input->heightMin,
            'height_max' => $input->heightMax,
        ] as $fieldKey => $value) {
            if ($value !== null) {
                $search['fields'][$fieldKey] = $value;
            }
        }

        $forkedFrom = $searchInfo?->id;
        // saveSearch()'s own makeIndexUrl() call builds an HTML-embeddable
        // URL (relative to whatever page it's rendered on) by default --
        // correct for its other real caller, Controller\SearchController's
        // own redirect after a search-form submission, but wrong here: a
        // REST API JSON response has no "current page" to resolve a
        // relative URL against. Same setMakeFullUrl()/unsetMakeFullUrl()
        // pairing AuthService::generatePasswordLink()/MailService's own
        // email-body links already use for the same "no current page"
        // reason.
        $this->urlService->setMakeFullUrl();
        [$searchUuid, $searchUrl] = $this->searchService->saveSearch($search, $this->urlService, $forkedFrom);
        $this->urlService->unsetMakeFullUrl();

        return ResponseFactory::json([
            'searchId' => $searchUuid,
            'searchUrl' => $searchUrl,
        ], 201);
    }

    private static function isValidCustomDate(string $date): bool
    {
        $ymd = substr($date, 0, 1);
        if ($ymd === 'y') {
            return preg_match('/^y(\d{4})$/', $date) === 1;
        }
        if ($ymd === 'm') {
            if (preg_match('/^m(\d{4})-(\d{2})$/', $date, $matches) !== 1) {
                return false;
            }
            $month = (int) $matches[2];
            return $month >= 1 && $month <= 12;
        }
        if ($ymd === 'd') {
            if (preg_match('/^d(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) !== 1) {
                return false;
            }
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return $month >= 1 && $month <= 12 && $day >= 1 && $day <= cal_days_in_month(CAL_GREGORIAN, $month, $year);
        }

        return false;
    }
}
