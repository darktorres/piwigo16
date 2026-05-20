<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\History;

use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Auth\CookieService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\Tables;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Search\SearchRepository;
use Piwigo\Tag\TagRepository;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\UserRepository;
use Piwigo\Validation\InputValidator;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.history.search` — render the admin history page (filterable search of visit logs). */
final readonly class SearchHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CookieService $cookieService,
        private DateService $dateService,
        private EventDispatcherInterface $dispatcher,
        private HistoryAdminService $historyAdminService,
        private HtmlService $htmlService,
        private ImageRepository $imageRepository,
        private InputValidator $inputValidator,
        private SearchRepository $searchRepository,
        private TagRepository $tagRepository,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): array
    {
        $param             = $params;
        $types             = array_merge(['none'], SchemaHelper::getEnums(Tables::history(), 'image_type'));
        $displayThumbnails = ['no_display_thumbnail' => Lang::t('No display'), 'display_thumbnail_classic' => Lang::t('Classic display'), 'display_thumbnail_hoverbox' => Lang::t('Hoverbox display')];
        PageState::current()->errors = [];
        $search = ['fields' => []];
        if (!empty($param['start'])) {
            $this->inputValidator->check('start', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-after'] = $param['start'];
        }
        if (!empty($param['end'])) {
            $this->inputValidator->check('end', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-before'] = $param['end'];
        }
        if (empty($param['types'])) {
            $search['fields']['types'] = $types;
        } else {
            $this->inputValidator->check('types', $param, true, '/^(' . implode('|', $types) . ')$/');
            $search['fields']['types'] = $param['types'];
        }
        $search['fields']['user'] = is_numeric($param['user_id']) ? (int) $param['user_id'] : 0;
        if (!empty($param['image_id'])) {
            $search['fields']['image_id'] = is_numeric($param['image_id']) ? (int) $param['image_id'] : 0;
        }
        if (!empty($param['filename'])) {
            $search['fields']['filename'] = str_replace('*', '%', is_string($param['filename']) ? $param['filename'] : '');
        }
        if (!empty($param['ip'])) {
            $search['fields']['ip'] = str_replace('*', '%', is_string($param['ip']) ? $param['ip'] : '');
        }
        $this->inputValidator->check('display_thumbnail', $param, false, '/^(' . implode('|', array_keys($displayThumbnails)) . ')$/');
        $search['fields']['display_thumbnail'] = $param['display_thumbnail'];
        $displayThumbnailRaw                   = $param['display_thumbnail'] ?? null;
        $displayThumbnailStr                   = is_string($displayThumbnailRaw) ? $displayThumbnailRaw : '';
        $cookieVal                             = ($displayThumbnailStr !== '' && isset($displayThumbnails[$displayThumbnailStr])) ? $displayThumbnailStr : null;
        $strtotimeMonth                        = strtotime('+1 month');
        /** @var int|false $strtotimeMonth */
        $this->cookieService->setCookieVar('display_thumbnail', $cookieVal, $strtotimeMonth === false ? null : $strtotimeMonth);
        $searchId     = $this->searchRepository->insertSearch(json_encode($search, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $encodedRules = $this->searchRepository->findRulesById($searchId);
        $searchRules  = json_decode(is_string($encodedRules) ? $encodedRules : '', associative: true);
        $search       = is_array($searchRules) ? $searchRules : [];
        $search       = $this->historyAdminService->prepareSearch($search);

        $pageNumber = is_numeric($param['pageNumber']) ? (int) $param['pageNumber'] : 0;
        $pageSize   = Config::nbLogsPage();

        $nbLines       = $this->historyAdminService->getHistoryCount($search, $types);
        $totalFilesize = $this->historyAdminService->getHistoryTotalFilesizeForHigh($search, $types);
        $guestIpHist   = $this->historyAdminService->getHistoryGuestIpHistogram($search, $types, Config::guestId());
        $userHitCounts = $this->historyAdminService->getHistoryUserHitCounts($search, $types);
        $searchIds     = $this->historyAdminService->getHistoryDistinctSearchIds($search, $types);
        $pageRows      = $this->historyAdminService->getHistoryPage($search, $types, $pageNumber * $pageSize, $pageSize);

        $userIds = [];
        foreach (array_keys($userHitCounts) as $uid) {
            $userIds[(string) $uid] = 1;
        }
        $categoryIds = [];
        $imageIds    = [];
        $hasTags     = false;
        foreach ($pageRows as $row) {
            if (isset($row['category_id'])) {
                $categoryIds[] = $row['category_id'];
            }
            if (isset($row['image_id'])) {
                $rowImageId    = $row['image_id'];
                $rowImageIdKey = is_scalar($rowImageId) ? (string) $rowImageId : '';
                if ($rowImageIdKey !== '') {
                    $imageIds[$rowImageIdKey] = 1;
                }
            }
            if (isset($row['tag_ids'])) {
                $hasTags = true;
            }
        }
        $usernameOf    = [];
        $searchDetails = [];
        if (count($searchIds) > 0) {
            $searchDetails = $this->searchRepository->findRulesByIds(array_map(intval(...), $searchIds));
            foreach ($searchDetails as $rulesSearch) {
                $rulesArrRaw = json_decode($rulesSearch, associative: true);
                /** @var array<string, mixed> $rulesArr */
                $rulesArr    = is_array($rulesArrRaw) ? $rulesArrRaw : [];
                /** @var array<string, mixed> $rulesFields */
                $rulesFields = is_array($rulesArr['fields'] ?? null) ? $rulesArr['fields'] : [];
                /** @var array{words?: list<int|string>} $rfTags */
                $rfTags      = is_array($rulesFields['tags'] ?? null) ? $rulesFields['tags'] : [];
                /** @var array{words?: list<int|string>} $rfCat */
                $rfCat       = is_array($rulesFields['cat'] ?? null) ? $rulesFields['cat'] : [];
                if (isset($rfTags['words']) && count($rfTags['words']) > 0) {
                    $hasTags = true;
                }
                if (isset($rfCat['words']) && count($rfCat['words']) > 0) {
                    $catWords    = $rfCat['words'];
                    $categoryIds = array_merge($categoryIds, $catWords);
                }
                if (!empty($rulesFields['added_by'])) {
                    $addedBy = is_array($rulesFields['added_by']) ? $rulesFields['added_by'] : [];
                    foreach ($addedBy as $key) {
                        $keyStr = is_scalar($key) ? (string) $key : '';
                        if ($keyStr !== '') {
                            $userIds[$keyStr] = 1;
                        }
                    }
                }
            }
        }
        if (count($userIds) > 0) {
            $userFields = Config::userFields();
            $rawMap     = $this->userRepository->findUsernamesByIds($userFields['id'], $userFields['username'], Tables::users(), array_map(intval(...), array_keys($userIds)));
            foreach ($rawMap as $id => $username) {
                $usernameOf[$id] = stripslashes($username);
            }
        }
        $nameOfCategory = [];
        $imageInfos     = [];
        $fullCatPath    = [];
        if (count($categoryIds) > 0) {
            $categoryIdsInt = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $categoryIds);
            $uppercatsOf    = $this->categoryRepository->findUppercatsMapByIds($categoryIdsInt);
            foreach ($uppercatsOf as $categoryId => $uppercats) {
                $uppercatsS                  = is_scalar($uppercats) ? (string) $uppercats : '';
                $albumBase                   = $this->urlGenerator->admin() . '&page=album-';
                $fullCatPath[$categoryId]    = $this->htmlService->getCatDisplayNameCache($uppercatsS, $albumBase);
                $uppercatsParts              = explode(',', $uppercatsS);
                $nameOfCategory[$categoryId] = $this->htmlService->getCatDisplayNameCache(end($uppercatsParts) ?: '', $albumBase);
            }
        }
        if (count($imageIds) > 0) {
            $imageInfos = $this->imageRepository->findActivityFeedSummaryByIds(array_map(intval(...), array_keys($imageIds)));
        }
        $nameOfTag = [];
        if ($hasTags) {
            foreach ($this->tagRepository->findAll() as $tag) {
                $tagRenderEvent = new RenderTagName($tag->name, $tag->toRow());
                $this->dispatcher->dispatch($tagRenderEvent);
                $nameOfTag[(string) $tag->id->value] = $tagRenderEvent->tagName;
            }
        }
        $result = [];
        foreach ($pageRows as $line) {
            $lineImageType   = is_string($line['image_type'] ?? null) ? $line['image_type'] : '';
            $lineImageId     = $line['image_id'] ?? null;
            $lineImageIdInt  = is_numeric($lineImageId) ? (int) $lineImageId : 0;
            $lineImageIdStr  = is_scalar($lineImageId) ? (string) $lineImageId : '';
            $lineUserId      = $line['user_id'] ?? null;
            $lineUserIdStr   = is_scalar($lineUserId) ? (string) $lineUserId : '';
            $lineIP          = is_string($line['IP'] ?? null) ? $line['IP'] : '';
            $lineCatId       = $line['category_id'] ?? null;
            $lineCatIdStr    = is_scalar($lineCatId) ? (string) $lineCatId : '';
            $lineSearchId    = $line['search_id'] ?? null;
            $lineSearchIdStr = is_scalar($lineSearchId) ? (string) $lineSearchId : '';
            $lineSection     = is_string($line['section'] ?? null) ? $line['section'] : '';
            $userName        = '#unknown';
            $userString      = '';
            if ($lineUserIdStr !== '' && isset($usernameOf[$lineUserIdStr])) {
                $userName    = $usernameOf[$lineUserIdStr];
                $userString .= $usernameOf[$lineUserIdStr];
            } else {
                $userString .= $lineUserIdStr;
            }
            $userString .= '&nbsp;<a href="' . $this->urlGenerator->admin('history') . '&amp;search_id=' . $searchId . '&amp;user_id=' . $lineUserIdStr . '">+</a>';
            $tagNames = '';
            $tagIds   = '';
            if (isset($line['tag_ids'])) {
                $lineTagIds = is_string($line['tag_ids']) ? $line['tag_ids'] : '';
                $tagNames   = preg_replace_callback('/(\d+)/', function (array $m) use ($nameOfTag): string {
                    $k = $m[1];
                    return $nameOfTag[$k] ?? $k;
                }, $lineTagIds) ?? $lineTagIds;
                $tagIds     = $lineTagIds;
            }
            $imageString     = '';
            $imageTitle      = '';
            $imageEditString = '';
            $imageId         = '';
            if ($lineImageIdStr !== '') {
                $imageEditString = $this->urlGenerator->admin('photo-' . $lineImageIdStr);
                $pictureUrl      = $this->urlService->makePictureUrl(['image_id' => $lineImageId]);
                $element         = [];
                if (isset($imageInfos[$lineImageIdInt])) {
                    $element = ['id' => $lineImageId, 'file' => $imageInfos[$lineImageIdInt]['file'], 'path' => $imageInfos[$lineImageIdInt]['path'], 'representative_ext' => $imageInfos[$lineImageIdInt]['representative_ext']];
                }
                $imageTitle = '';
                if (isset($imageInfos[$lineImageIdInt]['label'])) {
                    $descEvent = new RenderElementDescription($imageInfos[$lineImageIdInt]['label'], __FUNCTION__);
                    $this->dispatcher->dispatch($descEvent);
                    $imageTitle .= ' ' . $descEvent->elementDescription;
                } else {
                    $imageEditString = '';
                    $imageTitle     .= ' unknown filename';
                }
                $imageId = $lineImageId;
                set_error_handler(static fn (): bool => true);
                try {
                    $imgUrl = DerivativeImage::url(ImageStdParams::getByType(DerivativeSize::Square->value), $element);
                } finally {
                    restore_error_handler();
                }
                $imageString = '<span><img src="' . (is_string($imgUrl) ? $imgUrl : '') . '" alt="' . $imageTitle . '" title="' . $imageTitle . '">';
            }
            $searchDetail = null;
            $lineSearchIdInt = is_numeric($lineSearchIdStr) ? (int) $lineSearchIdStr : 0;
            if ($lineSearchIdInt !== 0 && isset($searchDetails[$lineSearchIdInt])) {
                // findRulesByIds returns id → JSON-string-rules, not a
                // pre-decoded map. Decode here to read the saved-search
                // filter fields.
                $sdDecoded = json_decode($searchDetails[$lineSearchIdInt], associative: true);
                /** @var array<string, mixed> $sd */
                $sd              = is_array($sdDecoded) ? $sdDecoded : [];
                /** @var array<string, mixed> $sdFields */
                $sdFields        = is_array($sd['fields'] ?? null) ? $sd['fields'] : [];
                /** @var array{words?: list<int|string>} $sdTags */
                $sdTags          = is_array($sdFields['tags'] ?? null) ? $sdFields['tags'] : [];
                /** @var array{words?: list<int|string>} $sdCat */
                $sdCat           = is_array($sdFields['cat'] ?? null) ? $sdFields['cat'] : [];
                /** @var array{words?: list<string>|string} $sdAllwords */
                $sdAllwords      = is_array($sdFields['allwords'] ?? null) ? $sdFields['allwords'] : [];
                /** @var array{words?: list<string>} $sdAuthor */
                $sdAuthor        = is_array($sdFields['author'] ?? null) ? $sdFields['author'] : [];
                $sdTagsWords     = $sdTags['words']   ?? [];
                $sdCatWords      = $sdCat['words']    ?? [];
                /** @var list<string>|string $sdAllwordsRaw */
                $sdAllwordsRaw   = $sdAllwords['words'] ?? [];
                $sdAllwordsWords = is_array($sdAllwordsRaw) ? $sdAllwordsRaw : [];
                $sdAuthorWords   = $sdAuthor['words'] ?? [];
                /** @var list<int|string> $sdAddedBy */
                $sdAddedBy       = is_array($sdFields['added_by'] ?? null) ? $sdFields['added_by'] : [];
                $searchDetail    = [
                    'allwords'    => (count($sdAllwordsWords) > 0) ? $sdAllwordsWords : null,
                    'tags'        => (count($sdTagsWords) > 0) ? array_intersect_key($nameOfTag, array_flip(array_map(static fn (int|string $v): string => (string) $v, $sdTagsWords))) : null,
                    'date_posted' => (isset($sdFields['date_posted']) && $sdFields['date_posted'] !== '') ? $sdFields['date_posted'] : null,
                    'cat'         => (count($sdCatWords) > 0) ? array_intersect_key($nameOfCategory, array_flip(array_map(static fn (int|string $v): string => (string) $v, $sdCatWords))) : null,
                    'author'      => (count($sdAuthorWords) > 0) ? $sdAuthorWords : null,
                    'added_by'    => (count($sdAddedBy) > 0) ? array_intersect_key($usernameOf, array_flip(array_map(static fn (int|string $v): string => (string) $v, $sdAddedBy))) : null,
                    'filetypes'   => (isset($sdFields['filetypes']) && $sdFields['filetypes'] !== '') ? $sdFields['filetypes'] : null,
                ];
            }
            $lineDate = is_scalar($line['date'] ?? null) ? $line['date'] : null;
            array_push($result, ['DATE' => $this->dateService->formatDate(is_string($lineDate) || is_int($lineDate) ? $lineDate : null), 'TIME' => $line['time'] ?? null, 'USER' => $userString, 'USERNAME' => $userName, 'USERID' => $lineUserId, 'IP' => $lineIP, 'IMAGE' => $imageString, 'IMAGENAME' => $imageTitle, 'IMAGEID' => $imageId, 'EDIT_IMAGE' => $imageEditString, 'TYPE' => $lineImageType, 'SECTION' => $lineSection, 'FULL_CATEGORY_PATH' => ($lineCatIdStr !== '' && isset($fullCatPath[$lineCatIdStr])) ? strip_tags($fullCatPath[$lineCatIdStr]) : Lang::t('Root') . $lineCatIdStr, 'CATEGORY' => ($lineCatIdStr !== '' && isset($nameOfCategory[$lineCatIdStr])) ? $nameOfCategory[$lineCatIdStr] : Lang::t('Root') . $lineCatIdStr, 'SEARCH_ID' => $lineSearchId ?? null, 'TAGS' => explode(',', $tagNames), 'TAGIDS' => explode(',', $tagIds), 'SEARCH_DETAILS' => $searchDetail]);
        }
        $sortedMembers = [];
        foreach ($userHitCounts as $uid => $hits) {
            $uidStr               = (string) $uid;
            $name                 = $usernameOf[$uidStr] ?? '#unknown';
            $sortedMembers[$name] = ($sortedMembers[$name] ?? 0) + $hits;
        }
        $nbGuests = count($guestIpHist);
        if ($nbGuests > 0) {
            unset($usernameOf[(string) Config::guestId()]);
        }
        $nbMembers     = count($usernameOf);
        $memberStrings = [];
        foreach ($usernameOf as $userId => $userName) {
            $memberStrings[] = [$userName => $userId];
        }
        arsort($sortedMembers);
        unset($sortedMembers['guest']);
        $maxPage       = (int) ceil($nbLines / $pageSize);
        $searchSummary = ['NB_LINES' => Translator::get()->plural('%d line filtered', '%d lines filtered', $nbLines), 'FILESIZE' => $totalFilesize != 0 ? ceil($totalFilesize / 1024) : 0, 'USERS' => Translator::get()->plural('%d user', '%d users', $nbMembers + $nbGuests), 'MEMBERS' => $memberStrings, 'SORTED_MEMBERS' => $sortedMembers, 'GUESTS' => Translator::get()->plural('%d guest', '%d guests', $nbGuests)];
        return ['lines' => $result, 'params' => $param, 'maxPage' => ($maxPage == 0) ? 1 : $maxPage, 'summary' => $searchSummary];
    }
}
