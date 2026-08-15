<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsError;
use Piwigo\Search\Projection\Search;
use Piwigo\Search\SearchService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.filteredSearch.create` -- registers a new search.
 *
 * Every registered param here is `WsParamFlag::OPTIONAL` with no
 * 'default' key, so `Server::invoke()` leaves any not provided by the
 * caller entirely absent from `$params` -- hence the `isset($params[...])`
 * checks throughout, unchanged from the god-class method this replaces.
 * `expert` is deliberately NOT a registered param at all (reachable only
 * via an unregistered extra GET/POST key, same as e.g.
 * `Users\GetListHandler`'s own `max_level`), so it's read straight off
 * the raw `$params`. This method's whole shape -- ~20 independently
 * `isset()`-checked optional fields, several mutating `$params` in place
 * before a later branch re-reads the same key (`allwords_mode`/
 * `allwords_fields` auto-filled when absent) -- doesn't benefit from a
 * dedicated Params DTO the way a fixed-shape method would, so this reads
 * the raw array directly throughout, same as `Users\SetInfoParams`'s own
 * documented rationale for skipping one.
 */
final readonly class FilteredSearchCreateHandler implements WsAction
{
    public function __construct(
        private SearchService $searchService,
        private CurrentConfig $currentConfig,
        private UrlServiceInterface $urlService,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{search_id: string, search_url: string}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        // MethodDefinition's own registration for this method guarantees
        // this exact shape before __invoke() ever runs -- WsAction::
        // __invoke()'s own $params type can't express that (every handler
        // shares the same loose array<mixed> contract), so it's asserted
        // locally at this one call site instead. 'expert' is deliberately
        // absent from this shape (see this class's own docblock) -- the
        // open tail still allows reading it as mixed.
        /** @var array{search_id?: string, allwords?: string, allwords_mode?: string, allwords_fields?: array<int, string>, tags?: array<int, int>, tags_mode?: string, categories?: array<int, int>, categories_withsubs?: bool, authors?: array<int, string>, added_by?: array<int, int>, filetypes?: array<int, string>, date_posted_preset?: string, date_posted_custom?: array<int, string>, date_created_preset?: string, date_created_custom?: array<int, string>, ratios?: array<int, string>, ratings?: array<int, string>, filesize_min?: int, filesize_max?: int, height_min?: int, height_max?: int, width_min?: int, width_max?: int, ...} $filterParams */
        $filterParams = $params;

        $searchService = $this->searchService;

        // * check the search exists
        $search_info = null;
        if (isset($filterParams['search_id'])) {
            if (in_array(SearchService::getSearchIdPattern($filterParams['search_id']), [null, ''], true)) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid search_id input parameter.');
            }

            $search_info = $searchService->getValidatedSearchInfo($filterParams['search_id'], null);
            if (! $search_info instanceof Search) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'This search does not exist.');
            }
        }

        // 'fields' (and its 'date_posted'/'date_created' sub-arrays) are
        // predeclared so PHPStan can track $search's shape as it's built up
        // below -- this changes nothing at runtime (PHP would auto-vivify the
        // same nested arrays via the assignments further down anyway).
        $search = [
            'mode' => 'AND',
            'fields' => [
                'date_posted' => [],
                'date_created' => [],
            ],
        ];

        // * check all parameters
        if (isset($filterParams['allwords'])) {
            $search['fields']['allwords'] = [];

            if (! isset($filterParams['allwords_mode'])) {
                $filterParams['allwords_mode'] = 'AND';
            }
            if (! (bool) preg_match('/^(OR|AND)$/', $filterParams['allwords_mode'])) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter allwords_mode');
            }
            $search['fields']['allwords']['mode'] = $filterParams['allwords_mode'];

            $allwords_fields_available = ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'];
            if (! isset($filterParams['allwords_fields'])) {
                $filterParams['allwords_fields'] = $allwords_fields_available;
            }
            foreach ($filterParams['allwords_fields'] as $field) {
                if (! in_array($field, $allwords_fields_available, true)) {
                    return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter allwords_fields');
                }
            }
            $search['fields']['allwords']['fields'] = $filterParams['allwords_fields'];

            $search['fields']['allwords']['words'] = SearchService::splitAllwords($filterParams['allwords']);
        }

        if (isset($filterParams['tags'])) {
            foreach ($filterParams['tags'] as $tag_id) {
                if (! (bool) preg_match('/^\d+$/', (string) $tag_id)) {
                    return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter tags');
                }
            }

            if (! isset($filterParams['tags_mode'])) {
                $filterParams['tags_mode'] = 'AND';
            }
            if (! (bool) preg_match('/^(OR|AND)$/', $filterParams['tags_mode'])) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter tags_mode');
            }

            $search['fields']['tags'] = [
                'words' => $filterParams['tags'],
                'mode' => $filterParams['tags_mode'],
            ];
        }

        if (isset($filterParams['categories'])) {
            foreach ($filterParams['categories'] as $cat_id) {
                if (! (bool) preg_match('/^\d+$/', (string) $cat_id)) {
                    return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter categories');
                }
            }

            $search['fields']['cat'] = [
                'words' => $filterParams['categories'],
                'sub_inc' => $filterParams['categories_withsubs'] ?? false,
            ];
        }

        if (isset($filterParams['authors'])) {
            $authors = [];

            foreach ($filterParams['authors'] as $author) {
                $authors[] = strip_tags($author);
            }

            $search['fields']['author'] = [
                'words' => $authors,
                'mode' => 'OR',
            ];
        }

        if (isset($filterParams['filetypes'])) {
            foreach ($filterParams['filetypes'] as $ext) {
                if (! (bool) preg_match('/^[a-z0-9]+$/i', $ext)) {
                    return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter filetypes');
                }
            }

            $search['fields']['filetypes'] = $filterParams['filetypes'];
        }

        if (isset($filterParams['added_by'])) {
            foreach ($filterParams['added_by'] as $user_id) {
                if (! (bool) preg_match('/^\d+$/', (string) $user_id)) {
                    return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter added_by');
                }
            }

            $search['fields']['added_by'] = $filterParams['added_by'];
        }

        if (isset($filterParams['date_posted_preset'])) {
            if (! (bool) preg_match('/^(24h|7d|30d|3m|6m|custom|)$/', $filterParams['date_posted_preset'])) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter date_posted_preset');
            }

            @$search['fields']['date_posted']['preset'] = $filterParams['date_posted_preset'];

            if ($search['fields']['date_posted']['preset'] === 'custom' and (! isset($filterParams['date_posted_custom']) or $filterParams['date_posted_custom'] === [])) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'date_posted_custom is missing');
            }
        }

        if (isset($filterParams['date_posted_custom'])) {
            if (! isset($search['fields']['date_posted']['preset']) or $search['fields']['date_posted']['preset'] !== 'custom') {
                return new WsErrorResponse(WsError::InvalidParam->value, 'date_posted_custom provided date_posted_preset is not custom');
            }

            foreach ($filterParams['date_posted_custom'] as $date) {
                $correct_format = false;

                $ymd = substr($date, 0, 1);
                if ($ymd === 'y') {
                    if ((bool) preg_match('/^y(\d{4})$/', $date, $matches)) {
                        $correct_format = true;
                    }
                } elseif ($ymd === 'm') {
                    if ((bool) preg_match('/^m(\d{4}-\d{2})$/', $date, $matches)) {
                        [$year, $month] = explode('-', $matches[1]);
                        if ($month >= 1 and $month <= 12) {
                            $correct_format = true;
                        }
                    }
                } elseif ($ymd === 'd') {
                    if ((bool) preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $date, $matches)) {
                        [$year, $month, $day] = explode('-', $matches[1]);
                        if ($month >= 1 and $month <= 12 and $day >= 1 and $day <= cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year)) {
                            $correct_format = true;
                        }
                    }
                }

                if (! $correct_format) {
                    return new WsErrorResponse(WsError::InvalidParam->value, 'date_posted_custom, invalid option ' . $date);
                }

                @$search['fields']['date_posted']['custom'][] = $date;
            }
        }

        if (isset($filterParams['date_created_preset'])) {
            if (! (bool) preg_match('/^(7d|30d|3m|6m|12m|custom|)$/', $filterParams['date_created_preset'])) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter date_created_preset');
            }

            @$search['fields']['date_created']['preset'] = $filterParams['date_created_preset'];

            if ($search['fields']['date_created']['preset'] === 'custom' and (! isset($filterParams['date_created_custom']) or $filterParams['date_created_custom'] === [])) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'date_created_custom is missing');
            }
        }

        if (isset($filterParams['date_created_custom'])) {
            if (! isset($search['fields']['date_created']['preset']) or $search['fields']['date_created']['preset'] !== 'custom') {
                return new WsErrorResponse(WsError::InvalidParam->value, 'date_created_custom provided date_created_preset is not custom');
            }

            foreach ($filterParams['date_created_custom'] as $date) {
                $correct_format = false;

                $ymd = substr($date, 0, 1);
                if ($ymd === 'y') {
                    if ((bool) preg_match('/^y(\d{4})$/', $date, $matches)) {
                        $correct_format = true;
                    }
                } elseif ($ymd === 'm') {
                    if ((bool) preg_match('/^m(\d{4}-\d{2})$/', $date, $matches)) {
                        [$year, $month] = explode('-', $matches[1]);
                        if ($month >= 1 and $month <= 12) {
                            $correct_format = true;
                        }
                    }
                } elseif ($ymd === 'd') {
                    if ((bool) preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $date, $matches)) {
                        [$year, $month, $day] = explode('-', $matches[1]);
                        if ($month >= 1 and $month <= 12 and $day >= 1 and $day <= cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year)) {
                            $correct_format = true;
                        }
                    }
                }

                if (! $correct_format) {
                    return new WsErrorResponse(WsError::InvalidParam->value, 'date_created_custom, invalid option ' . $date);
                }

                @$search['fields']['date_created']['custom'][] = $date;
            }
        }

        if (isset($filterParams['ratios'])) {
            foreach ($filterParams['ratios'] as $ext) {
                if (! (bool) preg_match('/^[a-z0-9]+$/i', $ext)) {
                    return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid parameter ratios');
                }
            }

            $search['fields']['ratios'] = $filterParams['ratios'];
        }

        if (isset($filterParams['expert'])) {
            $search['fields']['expert'] = [
                'string' => $filterParams['expert'],
            ];
        }

        if ($this->currentConfig->rateEnabled and isset($filterParams['ratings'])) {
            $search['fields']['ratings'] = $filterParams['ratings'];
        }

        if (isset($filterParams['filesize_min'])) {
            $search['fields']['filesize_min'] = $filterParams['filesize_min'];
        }

        if (isset($filterParams['filesize_max'])) {
            $search['fields']['filesize_max'] = $filterParams['filesize_max'];
        }

        if (isset($filterParams['width_min'])) {
            $search['fields']['width_min'] = $filterParams['width_min'];
        }

        if (isset($filterParams['width_max'])) {
            $search['fields']['width_max'] = $filterParams['width_max'];
        }

        if (isset($filterParams['height_min'])) {
            $search['fields']['height_min'] = $filterParams['height_min'];
        }

        if (isset($filterParams['height_max'])) {
            $search['fields']['height_max'] = $filterParams['height_max'];
        }

        $forked_from = $search_info?->id;
        [$search_uuid, $search_url] = $searchService->saveSearch($search, $this->urlService, $forked_from);

        return [
            'search_id' => $search_uuid,
            'search_url' => $search_url,
        ];
    }
}
