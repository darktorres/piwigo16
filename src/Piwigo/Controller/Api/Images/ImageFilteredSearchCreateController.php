<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Search\Projection\AllwordsRule;
use Piwigo\Search\Projection\AuthorRule;
use Piwigo\Search\Projection\CategoryRule;
use Piwigo\Search\Projection\DateRule;
use Piwigo\Search\Projection\Search;
use Piwigo\Search\Projection\SearchRules;
use Piwigo\Search\Projection\TagsRule;
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

        $rules = new SearchRules();
        $datePostedRule = new DateRule();
        $dateCreatedRule = new DateRule();

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

            $rules->allwords = new AllwordsRule(
                words: array_values(SearchService::splitAllwords($input->allwords) ?? []),
                mode: $allwordsMode,
                fields: $allwordsFields,
            );
        }

        if ($input->tags !== null) {
            $tagWords = self::intOrStringList($input->tags);
            if ($tagWords === null) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter tags.');
            }

            $tagsMode = $input->tagsMode ?? 'AND';
            if (! in_array($tagsMode, ['OR', 'AND'], true)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter tagsMode.');
            }

            $rules->tags = new TagsRule(words: $tagWords, mode: $tagsMode);
        }

        if ($input->categories !== null) {
            $catWords = self::intOrStringList($input->categories);
            if ($catWords === null) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter categories.');
            }

            $rules->cat = new CategoryRule(words: $catWords, subInc: $input->categoriesWithsubs);
        }

        if ($input->authors !== null) {
            $authors = [];
            foreach ($input->authors as $author) {
                $authors[] = strip_tags(is_string($author) ? $author : '');
            }

            $rules->author = new AuthorRule(words: $authors);
        }

        if ($input->filetypes !== null) {
            foreach ($input->filetypes as $ext) {
                if (! is_string($ext) || preg_match('/^[a-z0-9]+$/i', $ext) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter filetypes.');
                }
            }

            $rules->filetypes = array_values(array_filter($input->filetypes, is_string(...)));
        }

        if ($input->addedBy !== null) {
            $addedByIds = self::intOrStringList($input->addedBy);
            if ($addedByIds === null) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter addedBy.');
            }

            $rules->addedBy = $addedByIds;
        }

        foreach ([
            ['datePostedPreset', 'datePostedCustom', 'date_posted', '24h|7d|30d|3m|6m|custom'],
            ['dateCreatedPreset', 'dateCreatedCustom', 'date_created', '7d|30d|3m|6m|12m|custom'],
        ] as [$presetKey, $customKey, $fieldsKey, $presetPattern]) {
            $preset = $presetKey === 'datePostedPreset' ? $input->datePostedPreset : $input->dateCreatedPreset;
            $custom = $customKey === 'datePostedCustom' ? $input->datePostedCustom : $input->dateCreatedCustom;
            $dateRule = $fieldsKey === 'date_posted' ? $datePostedRule : $dateCreatedRule;

            if ($preset !== null) {
                if (! is_string($preset) || preg_match('/^(' . $presetPattern . '|)$/', $preset) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ' . $presetKey . '.');
                }

                $dateRule->preset = $preset;

                if ($preset === 'custom' && ($custom === null || $custom === [])) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, $customKey . ' is missing.');
                }
            }

            if ($custom !== null) {
                if ($dateRule->preset !== 'custom') {
                    return ResponseFactory::problem('Unprocessable Entity', 422, $customKey . ' provided but ' . $presetKey . ' is not custom.');
                }

                if (! is_array($custom)) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ' . $customKey . '.');
                }

                foreach ($custom as $date) {
                    if (! is_string($date) || ! self::isValidCustomDate($date)) {
                        return ResponseFactory::problem('Unprocessable Entity', 422, $customKey . ', invalid option ' . (is_string($date) ? $date : '') . '.');
                    }

                    $dateRule->custom[] = $date;
                }
            }
        }

        if ($input->ratios !== null) {
            foreach ($input->ratios as $ratio) {
                if (! is_string($ratio) || preg_match('/^[a-z0-9]+$/i', $ratio) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ratios.');
                }
            }

            $rules->ratios = array_values(array_filter($input->ratios, is_string(...)));
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

            $rules->ratings = array_values(array_filter($input->ratings, is_string(...)));
        }

        $rules->datePosted = $datePostedRule;
        $rules->dateCreated = $dateCreatedRule;

        $rules->filesizeMin = $input->filesizeMin;
        $rules->filesizeMax = $input->filesizeMax;
        $rules->widthMin = $input->widthMin;
        $rules->widthMax = $input->widthMax;
        $rules->heightMin = $input->heightMin;
        $rules->heightMax = $input->heightMax;

        $search = [
            'mode' => 'AND',
            'fields' => $rules->toArray(),
        ];

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

    /**
     * Validates every element is an all-digits scalar (same regex every
     * real caller already used inline) without casting it -- the JSON
     * payload's own int-vs-numeric-string distinction is preserved,
     * matching {@see \Piwigo\Search\Projection\CategoryRule}/
     * {@see \Piwigo\Search\Projection\TagsRule}'s own `int|string` words.
     * Returns null on the first invalid element.
     *
     * @param array<array-key, mixed> $values
     * @return ?list<int|string>
     */
    private static function intOrStringList(array $values): ?array
    {
        $result = [];
        foreach ($values as $v) {
            if (! is_scalar($v) || preg_match('/^\d+$/', (string) $v) !== 1) {
                return null;
            }

            $result[] = is_int($v) ? $v : (string) $v;
        }

        return $result;
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
