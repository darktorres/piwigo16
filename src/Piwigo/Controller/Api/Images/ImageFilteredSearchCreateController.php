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
 * (~20 of them) -- this reads the decoded JSON body straight off the raw
 * request array, camelCased, rather than through a dedicated input DTO.
 * `expert` (a raw search-string escape hatch, deliberately undocumented)
 * is dropped -- not carried over to this surface.
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
        $body = JsonBody::decode($request);

        $searchInfo = null;
        if (isset($body['searchId'])) {
            $searchId = $body['searchId'];
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

        if (isset($body['allwords']) && is_string($body['allwords'])) {
            $allwordsMode = is_string($body['allwordsMode'] ?? null) ? $body['allwordsMode'] : 'AND';
            if (! in_array($allwordsMode, ['OR', 'AND'], true)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter allwordsMode.');
            }

            $allwordsFieldsAvailable = ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'];
            $allwordsFields = is_array($body['allwordsFields'] ?? null) ? array_values(array_filter($body['allwordsFields'], is_string(...))) : $allwordsFieldsAvailable;
            foreach ($allwordsFields as $field) {
                if (! in_array($field, $allwordsFieldsAvailable, true)) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter allwordsFields.');
                }
            }

            $search['fields']['allwords'] = [
                'mode' => $allwordsMode,
                'fields' => $allwordsFields,
                'words' => SearchService::splitAllwords($body['allwords']),
            ];
        }

        if (isset($body['tags']) && is_array($body['tags'])) {
            foreach ($body['tags'] as $tagId) {
                if (! is_scalar($tagId) || preg_match('/^\d+$/', (string) $tagId) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter tags.');
                }
            }

            $tagsMode = is_string($body['tagsMode'] ?? null) ? $body['tagsMode'] : 'AND';
            if (! in_array($tagsMode, ['OR', 'AND'], true)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter tagsMode.');
            }

            $search['fields']['tags'] = [
                'words' => $body['tags'],
                'mode' => $tagsMode,
            ];
        }

        if (isset($body['categories']) && is_array($body['categories'])) {
            foreach ($body['categories'] as $catId) {
                if (! is_scalar($catId) || preg_match('/^\d+$/', (string) $catId) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter categories.');
                }
            }

            $search['fields']['cat'] = [
                'words' => $body['categories'],
                'sub_inc' => $body['categoriesWithsubs'] ?? false,
            ];
        }

        if (isset($body['authors']) && is_array($body['authors'])) {
            $authors = [];
            foreach ($body['authors'] as $author) {
                $authors[] = strip_tags(is_string($author) ? $author : '');
            }

            $search['fields']['author'] = [
                'words' => $authors,
                'mode' => 'OR',
            ];
        }

        if (isset($body['filetypes']) && is_array($body['filetypes'])) {
            foreach ($body['filetypes'] as $ext) {
                if (! is_string($ext) || preg_match('/^[a-z0-9]+$/i', $ext) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter filetypes.');
                }
            }

            $search['fields']['filetypes'] = $body['filetypes'];
        }

        if (isset($body['addedBy']) && is_array($body['addedBy'])) {
            foreach ($body['addedBy'] as $userId) {
                if (! is_scalar($userId) || preg_match('/^\d+$/', (string) $userId) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter addedBy.');
                }
            }

            $search['fields']['added_by'] = $body['addedBy'];
        }

        foreach ([['datePostedPreset', 'datePostedCustom', 'date_posted', '24h|7d|30d|3m|6m|custom'], ['dateCreatedPreset', 'dateCreatedCustom', 'date_created', '7d|30d|3m|6m|12m|custom']] as [$presetKey, $customKey, $fieldsKey, $presetPattern]) {
            if (isset($body[$presetKey])) {
                $preset = $body[$presetKey];
                if (! is_string($preset) || preg_match('/^(' . $presetPattern . '|)$/', $preset) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ' . $presetKey . '.');
                }

                $search['fields'][$fieldsKey]['preset'] = $preset;

                if ($preset === 'custom' && (! isset($body[$customKey]) || $body[$customKey] === [])) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, $customKey . ' is missing.');
                }
            }

            if (isset($body[$customKey])) {
                if (($search['fields'][$fieldsKey]['preset'] ?? null) !== 'custom') {
                    return ResponseFactory::problem('Unprocessable Entity', 422, $customKey . ' provided but ' . $presetKey . ' is not custom.');
                }

                if (! is_array($body[$customKey])) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ' . $customKey . '.');
                }

                foreach ($body[$customKey] as $date) {
                    if (! is_string($date) || ! self::isValidCustomDate($date)) {
                        return ResponseFactory::problem('Unprocessable Entity', 422, $customKey . ', invalid option ' . (is_string($date) ? $date : '') . '.');
                    }

                    $search['fields'][$fieldsKey]['custom'][] = $date;
                }
            }
        }

        if (isset($body['ratios']) && is_array($body['ratios'])) {
            foreach ($body['ratios'] as $ratio) {
                if (! is_string($ratio) || preg_match('/^[a-z0-9]+$/i', $ratio) !== 1) {
                    return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameter ratios.');
                }
            }

            $search['fields']['ratios'] = $body['ratios'];
        }

        if ($this->currentConfig->rateEnabled && isset($body['ratings'])) {
            $search['fields']['ratings'] = $body['ratings'];
        }

        foreach ([
            'filesizeMin' => 'filesize_min',
            'filesizeMax' => 'filesize_max',
            'widthMin' => 'width_min',
            'widthMax' => 'width_max',
            'heightMin' => 'height_min',
            'heightMax' => 'height_max',
        ] as $inputKey => $fieldKey) {
            if (isset($body[$inputKey]) && is_int($body[$inputKey])) {
                $search['fields'][$fieldKey] = $body[$inputKey];
            }
        }

        $forkedFrom = $searchInfo?->id;
        [$searchUuid, $searchUrl] = $this->searchService->saveSearch($search, $this->urlService, $forkedFrom);

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
