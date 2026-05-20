<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Config\Config;
use Piwigo\Search\Rules\AllwordsField;
use Piwigo\Search\SearchService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.images.filteredSearch.create` — persist a structured filter set to the search store. */
final readonly class FilteredSearchCreateHandler implements WsAction
{
    public function __construct(
        private SearchService $searchService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $input      = FilteredSearchCreateParams::fromArray($params);
        $searchInfo = null;
        if ($input->searchId !== null) {
            $searchPattern = $this->searchService->getSearchIdPattern($input->searchId);
            if ($searchPattern === null || $searchPattern === '') {
                return new PwgError(WsError::InvalidParam->value, 'Invalid search_id input parameter.');
            }
            $searchInfo = $this->searchService->getSearchInfo($input->searchId);
            if ($searchInfo === null || count($searchInfo) === 0) {
                return new PwgError(WsError::InvalidParam->value, 'This search does not exist.');
            }
        }
        $search = ['mode' => 'AND', 'fields' => []];
        if (isset($params['allwords'])) {
            $search['fields']['allwords'] = [];
            if (!isset($params['allwords_mode'])) {
                $params['allwords_mode'] = 'AND';
            }
            $pAllwordsMode = is_string($params['allwords_mode']) ? $params['allwords_mode'] : '';
            if (!preg_match('/^(OR|AND)$/', $pAllwordsMode)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid parameter allwords_mode');
            }
            $search['fields']['allwords']['mode'] = $pAllwordsMode;
            if (!isset($params['allwords_fields'])) {
                $params['allwords_fields'] = AllwordsField::values();
            }
            $pAllwordsFields = is_array($params['allwords_fields']) ? $params['allwords_fields'] : [];
            foreach ($pAllwordsFields as $field) {
                if (!is_string($field) || AllwordsField::tryFrom($field) === null) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter allwords_fields');
                }
            }
            $search['fields']['allwords']['fields'] = $pAllwordsFields;
            $search['fields']['allwords']['words']  = $this->searchService->splitAllwords(is_string($params['allwords']) ? $params['allwords'] : '');
        }
        if (isset($params['tags'])) {
            $pTags = is_array($params['tags']) ? $params['tags'] : [];
            foreach ($pTags as $tagId) {
                if (!preg_match('/^\d+$/', is_scalar($tagId) ? (string) $tagId : '')) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter tags');
                }
            }
            if (!isset($params['tags_mode'])) {
                $params['tags_mode'] = 'AND';
            }
            $pTagsMode = is_string($params['tags_mode']) ? $params['tags_mode'] : '';
            if (!preg_match('/^(OR|AND)$/', $pTagsMode)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid parameter tags_mode');
            }
            $search['fields']['tags'] = ['words' => $pTags, 'mode' => $pTagsMode];
        }
        if (isset($params['categories'])) {
            $pCategories = is_array($params['categories']) ? $params['categories'] : [];
            foreach ($pCategories as $catId) {
                if (!preg_match('/^\d+$/', is_scalar($catId) ? (string) $catId : '')) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter categories');
                }
            }
            $search['fields']['cat'] = ['words' => $pCategories, 'sub_inc' => $params['categories_withsubs'] ?? false];
        }
        if (isset($params['authors'])) {
            $authors  = [];
            $pAuthors = is_array($params['authors']) ? $params['authors'] : [];
            foreach ($pAuthors as $author) {
                $authors[] = strip_tags(is_scalar($author) ? (string) $author : '');
            }
            $search['fields']['author'] = ['words' => $authors, 'mode' => 'OR'];
        }
        if (isset($params['filetypes'])) {
            $pFiletypes = is_array($params['filetypes']) ? $params['filetypes'] : [];
            foreach ($pFiletypes as $ext) {
                if (!preg_match('/^[a-z0-9]+$/i', is_scalar($ext) ? (string) $ext : '')) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter filetypes');
                }
            }
            $search['fields']['filetypes'] = $pFiletypes;
        }
        if (isset($params['added_by'])) {
            $pAddedBy = is_array($params['added_by']) ? $params['added_by'] : [];
            foreach ($pAddedBy as $userId) {
                if (!preg_match('/^\d+$/', is_scalar($userId) ? (string) $userId : '')) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter added_by');
                }
            }
            $search['fields']['added_by'] = $pAddedBy;
        }
        foreach (['date_posted_preset', 'date_created_preset'] as $presetParam) {
            if (isset($params[$presetParam])) {
                $pPreset   = is_scalar($params[$presetParam]) ? (string) $params[$presetParam] : '';
                $validPres = $presetParam === 'date_posted_preset' ? '/^(24h|7d|30d|3m|6m|custom|)$/' : '/^(7d|30d|3m|6m|12m|custom|)$/';
                if (!preg_match($validPres, $pPreset)) {
                    return new PwgError(WsError::InvalidParam->value, 'Invalid parameter ' . $presetParam);
                }
                $fieldKey                              = $presetParam === 'date_posted_preset' ? 'date_posted' : 'date_created';
                $search['fields'][$fieldKey]['preset'] = $pPreset;
            }
        }
        foreach (['date_posted_custom', 'date_created_custom'] as $customParam) {
            if (isset($params[$customParam])) {
                $fieldKey = $customParam === 'date_posted_custom' ? 'date_posted' : 'date_created';
                $pCustom  = is_array($params[$customParam]) ? $params[$customParam] : [];
                foreach ($pCustom as $date) {
                    $dateStr       = is_scalar($date) ? (string) $date : '';
                    $correctFormat = false;
                    $ymd           = substr($dateStr, 0, 1);
                    if ($ymd === 'y' && preg_match('/^y(\d{4})$/', $dateStr)) {
                        $correctFormat = true;
                    } elseif ($ymd === 'm' && preg_match('/^m(\d{4}-\d{2})$/', $dateStr, $m)) {
                        [$year, $month] = explode('-', $m[1]);
                        if ($month >= 1 && $month <= 12) {
                            $correctFormat = true;
                        }
                    } elseif ($ymd === 'd' && preg_match('/^d(\d{4}-\d{2}-\d{2})$/', $dateStr, $m)) {
                        [$year, $month, $day] = explode('-', $m[1]);
                        if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= cal_days_in_month(CAL_GREGORIAN, (int) $month, (int) $year)) {
                            $correctFormat = true;
                        }
                    }
                    if (!$correctFormat) {
                        return new PwgError(WsError::InvalidParam->value, $customParam . ', invalid option ' . $dateStr);
                    }
                    $search['fields'][$fieldKey]['custom'][] = $dateStr;
                }
            }
        }
        foreach (['ratios', 'ratings', 'filesize_min', 'filesize_max', 'width_min', 'width_max', 'height_min', 'height_max', 'expert'] as $field) {
            $fieldVal = $params[$field] ?? null;
            if ($fieldVal !== null) {
                if ($field === 'ratios') {
                    $pRatios = is_array($fieldVal) ? $fieldVal : [];
                    foreach ($pRatios as $ext) {
                        if (!preg_match('/^[a-z0-9]+$/i', is_scalar($ext) ? (string) $ext : '')) {
                            return new PwgError(WsError::InvalidParam->value, 'Invalid parameter ratios');
                        }
                    }
                    $search['fields']['ratios'] = $pRatios;
                } elseif ($field === 'expert') {
                    $search['fields']['expert'] = ['string' => $fieldVal];
                } elseif ($field === 'ratings' && Config::rateEnabled()) {
                    $search['fields']['ratings'] = $fieldVal;
                } else {
                    $search['fields'][$field] = $fieldVal;
                }
            }
        }
        $forkedFrom = isset($searchInfo['id']) && is_scalar($searchInfo['id']) ? (string) $searchInfo['id'] : null;
        [$searchUuid, $searchUrl] = $this->searchService->saveSearch($search, $forkedFrom);
        return ['search_id' => $searchUuid, 'search_url' => $searchUrl];
    }
}
