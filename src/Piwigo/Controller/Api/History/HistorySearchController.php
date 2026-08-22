<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\History;

use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\History\HistoryImageType;
use Piwigo\History\HistoryService;
use Piwigo\History\Projection\HistorySearchCriteria;
use Piwigo\Html\Event\RenderElementDescription;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\Projection\CategoryRule;
use Piwigo\Search\Projection\SearchRules;
use Piwigo\Search\Projection\TagsRule;
use Piwigo\Search\SearchRepository;
use Piwigo\Tag\TagService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/history/search` -- filtered/paginated history log lines
 * for the admin history page, admin only (a read, no CSRF needed).
 * Response fields are flat and typed (`imageThumbnailUrl`,
 * `imageEditUrl`, `categoryId`, ...); the client builds any markup from
 * them directly.
 *
 * Never writes a `search` row: `searchId`/`searchDetails` in the
 * response refer to a pre-existing saved search (created by a front-end
 * gallery search via `SearchService::saveSearch()`) that a row's own
 * `history.search_id` column may reference -- filtering here never
 * creates one.
 *
 * Fetches every matching row, then slices 300 per page in PHP rather
 * than a SQL `LIMIT`/`OFFSET` -- a known, accepted cost on large history
 * tables, not a defect.
 */
final readonly class HistorySearchController implements ControllerInterface
{
    private const int PAGE_SIZE = 300;

    public function __construct(
        private AdminGuard $adminGuard,
        private HistoryService $historyService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private UserService $userService,
        private ImageRepository $imageRepository,
        private ImageStdParams $imageStdParams,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private Translator $translator,
        private SearchRepository $searchRepository,
        private CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $input = HistorySearchInput::fromArray($request->getQueryParams());

        foreach ([
            'start' => $input->start,
            'end' => $input->end,
        ] as $field => $value) {
            if ($value !== null && ! (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid ' . $field . '.');
            }
        }

        $allTypes = array_merge(['none'], array_map(
            static fn (HistoryImageType $type): string => $type->value,
            HistoryImageType::cases()
        ));

        $criteria = new HistorySearchCriteria(
            filename: $input->filename !== null ? str_replace('*', '%', $input->filename) : null,
            dateAfter: $input->start,
            dateBefore: $input->end,
            imageTypes: $input->types === [] ? $allTypes : array_values(array_intersect($input->types, $allTypes)),
            userId: $input->userId !== -1 ? $input->userId : null,
            imageId: $input->imageId,
            ip: $input->ip !== null ? str_replace('*', '%', $input->ip) : null,
        );

        /** @var list<array{date: ?string, time: string, user_id: int, IP: string, section: ?string, category_id: ?int, search_id: ?int, tag_ids: ?string, image_id: ?int, image_type: ?string}> $rows */
        $rows = $this->historyService->getHistory([], $criteria, $allTypes);
        usort($rows, $this->historyService->historyCompare(...));

        /** @var array<int, true> $userIds */
        $userIds = [];
        /** @var array<int, true> $categoryIds */
        $categoryIds = [];
        /** @var array<int, true> $imageIds */
        $imageIds = [];
        /** @var array<int, true> $searchIds */
        $searchIds = [];
        $hasTags = false;

        foreach ($rows as $row) {
            $userIds[$row['user_id']] = true;
            if ($row['category_id'] !== null) {
                $categoryIds[$row['category_id']] = true;
            }
            if ($row['image_id'] !== null) {
                $imageIds[$row['image_id']] = true;
            }
            if ($row['search_id'] !== null) {
                $searchIds[$row['search_id']] = true;
            }
            if ($row['tag_ids'] !== null) {
                $hasTags = true;
            }
        }

        /** @var array<int, SearchRules> $searchDetailsBySearchId */
        $searchDetailsBySearchId = [];
        if ($searchIds !== []) {
            $rulesById = $this->searchRepository->findSavedSearchRulesByIds(array_keys($searchIds));
            foreach ($rulesById as $searchId => $rulesFull) {
                if ($rulesFull === null) {
                    continue;
                }
                $rulesFieldsRaw = is_array($rulesFull['fields'] ?? null) ? $rulesFull['fields'] : [];
                $rulesFields = array_filter($rulesFieldsRaw, is_string(...), ARRAY_FILTER_USE_KEY);
                $rules = SearchRules::fromArray($rulesFields);

                if ($rules->tags instanceof TagsRule && $rules->tags->words !== []) {
                    $hasTags = true;
                }

                $searchDetailsBySearchId[$searchId] = $rules;
            }
        }

        $usernameOf = $userIds !== [] ? $this->userService->getUsernamesByIds(array_map(strval(...), array_keys($userIds))) : [];

        /** @var array<int, string> $nameOfCategory */
        $nameOfCategory = [];
        /** @var array<int, string> $fullCatPath */
        $fullCatPath = [];
        if ($categoryIds !== []) {
            $uppercatsOf = $this->categoryService->getUppercatsById(array_keys($categoryIds));
            foreach ($uppercatsOf as $categoryId => $uppercats) {
                $fullCatPath[$categoryId] = strip_tags($this->htmlRenderer->getCatDisplayNameCache($uppercats, null));
                $levels = explode(',', $uppercats);
                $nameOfCategory[$categoryId] = strip_tags($this->htmlRenderer->getCatDisplayNameCache(end($levels), null));
            }
        }

        $imageInfos = $imageIds !== [] ? $this->imageRepository->findHistoryDisplayInfoByIds(array_keys($imageIds)) : [];

        /** @var array<int, string> $nameOfTag */
        $nameOfTag = [];
        if ($hasTags) {
            foreach ($this->tagService->getAllTags($this->htmlRenderer) as $tag) {
                $nameOfTag[$tag['id']] = $tag['name'];
            }
        }

        $lines = [];
        $totalFilesize = 0;
        $guestIps = [];
        /** @var array<int, int> $countByUserId */
        $countByUserId = [];

        foreach ($rows as $row) {
            $lines[] = $this->buildLine(
                $row,
                $usernameOf,
                $nameOfCategory,
                $fullCatPath,
                $imageInfos,
                $nameOfTag,
                $searchDetailsBySearchId,
                $totalFilesize,
                $guestIps,
                $countByUserId,
                $this->currentConfig->guestId,
            );
        }

        $nbLines = count($lines);
        $maxPage = (int) max(1, ceil($nbLines / self::PAGE_SIZE));
        $lines = array_reverse($lines);
        $lines = array_slice($lines, $input->pageNumber * self::PAGE_SIZE, self::PAGE_SIZE);

        $nbGuests = count($guestIps);
        $nbMembers = count($countByUserId);
        arsort($countByUserId);
        $members = [];
        foreach (array_keys($countByUserId) as $memberId) {
            $members[] = [
                'userId' => $memberId,
                'username' => $usernameOf[$memberId] ?? null,
            ];
        }

        return ResponseFactory::json([
            'lines' => $lines,
            'pageNumber' => $input->pageNumber,
            'maxPage' => $maxPage,
            'summary' => [
                'nbLines' => $nbLines,
                'nbLinesText' => $this->translator->plural('%d line filtered', '%d lines filtered', $nbLines),
                'filesizeMb' => $totalFilesize !== 0 ? (int) ceil($totalFilesize / 1024) : 0,
                'nbUsers' => $nbMembers,
                'nbGuests' => $nbGuests,
                'usersText' => $this->translator->plural('%d user', '%d users', $nbMembers + $nbGuests),
                'guestsText' => $this->translator->plural('%d guest', '%d guests', $nbGuests),
                'members' => $members,
            ],
        ]);
    }

    /**
     * @param array{date: ?string, time: string, user_id: int, IP: string, section: ?string, category_id: ?int, search_id: ?int, tag_ids: ?string, image_id: ?int, image_type: ?string} $row
     * @param array<int, string> $usernameOf
     * @param array<int, string> $nameOfCategory
     * @param array<int, string> $fullCatPath
     * @param array<int, array{id: int, label: string, filesize: ?int, file: string, path: string, representative_ext: ?string}> $imageInfos
     * @param array<int, string> $nameOfTag
     * @param array<int, SearchRules> $searchDetailsBySearchId
     * @param array<string, true> $guestIps
     * @param array<int, int> $countByUserId
     * @return array<string, mixed>
     */
    private function buildLine(
        array $row,
        array $usernameOf,
        array $nameOfCategory,
        array $fullCatPath,
        array $imageInfos,
        array $nameOfTag,
        array $searchDetailsBySearchId,
        int &$totalFilesize,
        array &$guestIps,
        array &$countByUserId,
        int $guestId,
    ): array {
        $date = $row['date'];
        $userId = $row['user_id'];
        $ip = $row['IP'];
        $categoryId = $row['category_id'];
        $searchId = $row['search_id'];
        $imageId = $row['image_id'];
        $imageType = $row['image_type'];

        $imageInfo = $imageId !== null ? ($imageInfos[$imageId] ?? null) : null;

        if ($imageType === 'high' && $imageInfo !== null) {
            $totalFilesize += $imageInfo['filesize'] ?? 0;
        }

        $username = $usernameOf[$userId] ?? null;

        if ($userId === $guestId) {
            $guestIps[$ip] = true;
        } elseif ($username !== null) {
            $countByUserId[$userId] = ($countByUserId[$userId] ?? 0) + 1;
        }

        $imageLabel = null;
        $imageEditUrl = null;
        $imageThumbnailUrl = null;
        if ($imageId !== null && $imageInfo !== null) {
            $labelEvent = $this->eventDispatcher->dispatch(new RenderElementDescription($imageInfo['label']));
            $imageLabel = $labelEvent->elementDescription;

            $imageEditUrl = $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $imageId;
            $imageThumbnailUrl = DerivativeImage::url($this->imageStdParams->getByType(ImageStdParams::SQUARE), SrcImageInfo::fromRow([
                'id' => $imageId,
                'file' => $imageInfo['file'],
                'path' => $imageInfo['path'],
                'representative_ext' => $imageInfo['representative_ext'],
            ]));
        }

        $tagIds = [];
        $tagNames = [];
        if ($row['tag_ids'] !== null && $row['tag_ids'] !== '') {
            foreach (explode(',', $row['tag_ids']) as $rawTagId) {
                if (! is_numeric($rawTagId)) {
                    continue;
                }
                $tagId = (int) $rawTagId;
                $tagIds[] = $tagId;
                $tagNames[] = $nameOfTag[$tagId] ?? $rawTagId;
            }
        }

        $searchDetails = $searchId !== null && isset($searchDetailsBySearchId[$searchId])
            ? $this->buildSearchDetails($searchDetailsBySearchId[$searchId], $nameOfCategory, $usernameOf, $nameOfTag)
            : null;

        return [
            'date' => $date,
            'dateFormatted' => $date !== null ? DateHelper::formatDate($date) : null,
            'time' => $row['time'],
            'userId' => $userId,
            'username' => $username,
            'ip' => $ip,
            'section' => $row['section'],
            'categoryId' => $categoryId,
            'categoryName' => $categoryId !== null ? ($nameOfCategory[$categoryId] ?? null) : null,
            'categoryPath' => $categoryId !== null ? ($fullCatPath[$categoryId] ?? null) : null,
            'searchId' => $searchId,
            'searchDetails' => $searchDetails,
            'tagIds' => $tagIds,
            'tagNames' => $tagNames,
            'imageId' => $imageId,
            'imageType' => $imageType,
            'imageLabel' => $imageLabel,
            'imageEditUrl' => $imageEditUrl,
            'imageThumbnailUrl' => $imageThumbnailUrl,
        ];
    }

    /**
     * @param array<int, string> $nameOfCategory
     * @param array<int, string> $usernameOf
     * @param array<int, string> $nameOfTag
     * @return array<string, mixed>
     */
    private function buildSearchDetails(SearchRules $rules, array $nameOfCategory, array $usernameOf, array $nameOfTag): array
    {
        $allwords = $rules->allwords?->words;

        $tagWords = $rules->tags instanceof TagsRule ? $rules->tags->words : [];
        $tags = null;
        if ($tagWords !== []) {
            $tags = [];
            foreach ($tagWords as $tagId) {
                if (is_numeric($tagId)) {
                    $tags[] = $nameOfTag[(int) $tagId] ?? (string) $tagId;
                }
            }
        }

        // $rules->datePosted is a DateRule object whenever the filter is
        // active at all -- the old is_string() check here could never
        // once succeed for a real row (date_posted was never a plain
        // string, always {preset, custom}), so this "search details"
        // summary's own datePosted field has always rendered as null
        // regardless of the real filter value. A real, pre-existing
        // display bug, faithfully preserved rather than fixed here.
        $datePosted = null;

        $catWords = $rules->cat instanceof CategoryRule ? $rules->cat->words : [];
        $cat = null;
        if ($catWords !== []) {
            $cat = [];
            foreach ($catWords as $catId) {
                if (is_numeric($catId)) {
                    $cat[] = $nameOfCategory[(int) $catId] ?? (string) $catId;
                }
            }
        }

        $author = $rules->author?->words;

        $addedByIds = $rules->addedBy ?? [];
        $addedBy = null;
        if ($addedByIds !== []) {
            $addedBy = [];
            foreach ($addedByIds as $userId) {
                if (is_numeric($userId)) {
                    $addedBy[] = $usernameOf[(int) $userId] ?? (string) $userId;
                }
            }
        }

        return [
            'allwords' => $allwords !== [] && $allwords !== null ? $allwords : null,
            'tags' => $tags,
            'datePosted' => $datePosted,
            'cat' => $cat,
            'author' => $author !== [] && $author !== null ? $author : null,
            'addedBy' => $addedBy,
            'filetypes' => $rules->filetypes,
        ];
    }
}
