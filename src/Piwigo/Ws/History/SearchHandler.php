<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\History;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Auth\CookieService;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Ws\GetHistory;
use Piwigo\History\HistoryImageType;
use Piwigo\History\HistoryService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\Projection\Search;
use Piwigo\Search\SearchRepository;
use Piwigo\Tag\TagService;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Piwigo\Ws\Request\HistorySearchPageRequest;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.history.search` -- admin only. Returns lines of an history search.
 *
 * @since 13
 */
final readonly class SearchHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private TagService $tagService,
        private UserService $userService,
        private HistoryService $historyService,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private Lang $lang,
        private InputValidator $inputValidator,
        private Translator $translator,
        private ImageService $imageService,
        private ImageStdParams $imageStdParams,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     * @return array<string, mixed>
     */
    #[Override]
    public function __invoke(array $params, Server $server): array
    {
        $input = SearchParams::fromArray($params);

        /** @var array<string, mixed> $page */
        $page = [];
        $page['start'] = HistorySearchPageRequest::fromGlobals()->start;

        $types = array_merge(['none'], array_map(
            static fn (HistoryImageType $type): string => $type->value,
            HistoryImageType::cases()
        ));

        $display_thumbnails = [
            'no_display_thumbnail' => $this->lang->t('No display'),
            'display_thumbnail_classic' => $this->lang->t('Classic display'),
            'display_thumbnail_hoverbox' => $this->lang->t('Hoverbox display'),
        ];

        $page['errors'] = [];
        $search = [];
        $search['fields'] = [];

        // date start
        if (! in_array($input->start, [null, ''], true)) {
            $this->inputValidator
                ->validate('start', $params, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-after'] = $input->start;
        }

        // date end
        if (! in_array($input->end, [null, ''], true)) {
            $this->inputValidator
                ->validate('end', $params, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-before'] = $input->end;
        }

        // types
        if ($input->types === []) {
            $search['fields']['types'] = $types;
        } else {
            $this->inputValidator
                ->validate('types', $params, true, '/^(' . implode('|', $types) . ')$/');
            $search['fields']['types'] = $input->types;
        }

        // user
        $search['fields']['user'] = intval($input->userId);

        // image -- Server::checkType() deliberately skips its own
        // int/positive coercion for an empty-string param (its own
        // `elseif ($param !== '')` guard), so a real browser client that
        // always sends every key (history.latte's own `image_id: {if
        // isset($IMAGE_ID)}"{$IMAGE_ID}"{else}""{/if}`, unlike a WS caller
        // that just omits the key) reaches this method with the literal
        // string ''. The old `!== 0` check missed that case (only the
        // sibling filename/ip checks below already excluded '' too),
        // so intval('') = 0 got stored into $search['fields']['image_id']
        // and persisted -- HistoryRepository::search() later reads it back
        // as a real, non-null 0 and calls ImageId::from(0), which throws:
        // a genuine, always-triggered 500 on
        // admin.php?page=history's default, unfiltered search, not a test-only
        // issue.
        if (! in_array($input->imageId, [null, '', 0], true)) {
            $search['fields']['image_id'] = intval($input->imageId);
        }

        // filename
        // filename/ip are read back later via
        // HistoryRepository::findImageIdsByFilename()/the 'ip' rate-limit
        // lookup, which bind the value as a real DBAL parameter
        // (:pattern/:ip), so neither needs escaping here. The '*' -> '%'
        // wildcard conversion below is unrelated to escaping.
        if (! in_array($input->filename, [null, ''], true)) {
            $search['fields']['filename'] = str_replace('*', '%', $input->filename);
        }

        // ip
        if (! in_array($input->ip, [null, ''], true)) {
            $search['fields']['ip'] = str_replace('*', '%', $input->ip);
        }

        // thumbnails
        $this->inputValidator
            ->validate('display_thumbnail', $params, false, '/^(' . implode('|', array_keys($display_thumbnails)) . ')$/');

        $search['fields']['display_thumbnail'] = $input->displayThumbnail;
        // Display choise are also save to one cookie
        if ($input->displayThumbnail !== ''
            and isset($display_thumbnails[$input->displayThumbnail])) {
            $cookie_val = $input->displayThumbnail;
        } else {
            $cookie_val = null;
        }

        new CookieService()
            ->setCookieVar('display_thumbnail', $cookie_val, strtotime('+1 month'));

        // image_id and filename are set independently above (like every
        // other field in this method) and both end up ANDed together in
        // $search['fields'] -- submitting both simultaneously just
        // narrows the search to their intersection, same as any other
        // multi-field combination here, not a special case to resolve.

        // store seach in database
        // register search rules in database, then they will be available on
        // thumbnails page and picture page.
        $searchRepository = new SearchRepository($this->entityManager);
        $search_id = $searchRepository->insertSavedSearch($search);

        // Remove redirect for ajax //
        // redirect(
        //   PHPWG_ROOT_PATH.'admin.php?page=history&search_id='.$search_id
        //   );

        // what are the lines to display in reality ?
        $storedSearch = $searchRepository->findSavedSearchById($search_id);
        // this row is the one we just INSERTed above (via $search_id =
        // Connection::lastInsertId()) with rules we just encoded ourselves,
        // so it's guaranteed to be found with a non-null, decoded array.
        assert($storedSearch instanceof Search && is_array($storedSearch->rules));

        $page['search'] = $storedSearch->rules;

        // Known limitation: the query behind this fetches more rows than
        // the page actually displays instead of a SQL_CALC_FOUND_ROWS-based
        // LIMIT/OFFSET pagination -- a real, non-trivial optimization
        // opportunity on large history tables, not a defect.
        $historyEvent = $this->eventDispatcher->dispatchChange(new GetHistory([], $page['search'], $types));
        // GetHistory::$data is a non-nullable PHP `array` property --
        // dispatchChange()'s own instanceof check already guarantees a real
        // array here, but PHP has no generic array-shape enforcement, so a
        // misbehaving handler at a higher priority than the default
        // GetHistory handler could still populate it with non-row-shaped
        // elements; keep the per-element defensive filter for that.
        /** @var array<int, array<string, mixed>> $data */
        $data = array_values(array_filter($historyEvent->data, is_array(...)));
        usort($data, $this->historyService->historyCompare(...));

        $page['nb_lines'] = count($data);

        // Number of ids of each kind
        $history_lines = [];
        $user_ids = [];
        $username_of = [];
        $category_ids = [];
        $image_ids = [];
        $has_tags = false;
        $search_ids = [];

        // Every field here is really mixed -- the is_string()/is_array()
        // guards throughout this loop (and the rest of this method) are the
        // real narrowing, not just defensive boilerplate.
        foreach ($data as $row) {
            // user_id/category_id/image_id/search_id are int/mediumint/
            // smallint columns -- HistoryRepository::search()'s DBAL
            // fetchAllAssociative() returns these as native PHP int, so the
            // guard below accepts both int and string.
            $row_user_id = $row['user_id'] ?? null;
            if (is_int($row_user_id) || is_string($row_user_id)) {
                $user_ids[(string) $row_user_id] = 1;
            }

            if (isset($row['category_id']) and (is_int($row['category_id']) || is_string($row['category_id']))) {
                array_push($category_ids, (string) $row['category_id']);
            }

            if (isset($row['image_id']) and (is_int($row['image_id']) || is_string($row['image_id']))) {
                $image_ids[(string) $row['image_id']] = 1;
            }

            if (isset($row['tag_ids'])) {
                $has_tags = true;
            }

            if (isset($row['search_id']) and (is_int($row['search_id']) || is_string($row['search_id']))) {
                array_push($search_ids, (string) $row['search_id']);
            }

            $history_lines[] = $row;
        }

        // prepare reference data (users, tags, categories...)
        // Declared unconditionally (not just inside the "if" below) so it is
        // always defined by the time it is read later, even when $search_ids
        // is empty.
        $search_details = [];
        if (count($search_ids) > 0) {
            $rules_by_search_id = $searchRepository->findSavedSearchRulesByIds(array_map(intval(...), $search_ids));
            foreach ($rules_by_search_id as $id_search => $rules_full) {
                if ($rules_full === null) {
                    continue;
                }

                $rules_search = isset($rules_full['fields']) && is_array($rules_full['fields'])
                    ? $rules_full['fields']
                    : [];

                $rules_tags = is_array($rules_search['tags'] ?? null) ? $rules_search['tags'] : [];
                if (! in_array($rules_tags['words'] ?? null, [null, false, 0, '0', '', []], true)) {
                    $has_tags = true;
                }

                $rules_cat = is_array($rules_search['cat'] ?? null) ? $rules_search['cat'] : [];
                if (! in_array($rules_cat['words'] ?? null, [null, false, 0, '0', '', []], true) and is_array($rules_cat['words'])) {
                    foreach ($rules_cat['words'] as $cat_id) {
                        if (is_string($cat_id) || is_int($cat_id)) {
                            $category_ids[] = (string) $cat_id;
                        }
                    }
                }

                $rules_added_by = $rules_search['added_by'] ?? null;
                if (! in_array($rules_added_by, [null, false, 0, '0', '', []], true) and is_array($rules_added_by)) {
                    foreach ($rules_added_by as $key) {
                        if (is_string($key) || is_int($key)) {
                            $user_ids[$key] = 1;
                        }
                    }
                }

                $search_details[$id_search] = $rules_search;
            }
        }

        if (count($user_ids) > 0) {
            $username_of = [];
            foreach ($this->userService->getUsernamesByIds(array_map(strval(...), array_keys($user_ids))) as $id => $username) {
                $username_of[(string) $id] = $username;
            }
        }

        $name_of_category = [];
        // Declared unconditionally (not just inside the "if" below),
        // matching $name_of_category above: both are read later regardless
        // of whether $category_ids is empty here.
        $full_cat_path = [];
        if (count($category_ids) > 0) {
            $uppercats_of = $this->categoryService->getUppercatsById(array_map(intval(...), $category_ids));

            foreach ($uppercats_of as $category_id => $uppercats) {
                $full_cat_path[$category_id] = $this->htmlRenderer->getCatDisplayNameCache(
                    $uppercats,
                    'admin.php?page=album-'
                );

                $uppercats = explode(',', $uppercats);
                $name_of_category[$category_id] = $this->htmlRenderer->getCatDisplayNameCache(
                    end($uppercats),
                    'admin.php?page=album-'
                );
            }
        }

        $image_infos = [];
        if (count($image_ids) > 0) {
            $image_infos = $this->imageService->getHistoryDisplayInfoByIds(array_keys($image_ids));
        }

        // $name_of_tag is a genuinely local variable (not a real global):
        // written, read (including via the closure below), and unset()
        // all within this one function -- the only place in the codebase
        // that touches it.
        $name_of_tag = [];
        if ($has_tags) {
            foreach ($this->tagService->getAll() as $tag) {
                $tag_row = [
                    'id' => $tag->id->value,
                    'name' => $tag->name,
                    'url_name' => $tag->urlName,
                ];
                $tagRowNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName($tag->name, $tag_row));
                $name_of_tag[(string) $tag->id->value] = $tagRowNameEvent->tagName;
            }
        }

        $page_start = $page['start'];

        $nb_logs_page = $this->currentConfig->nbLogsPage;

        $i = 0;
        $first_line = $page_start + 1;
        $last_line = $page_start + $nb_logs_page;

        /** @var array<string, mixed> $summary */
        $summary = [];
        $summary['total_filesize'] = 0;
        $summary['guests_IP'] = [];

        $result = [];
        $sorted_members = [];

        foreach ($history_lines as $line) {
            // every field of $line comes straight from the GetHistory event
            // handler's HistoryRepository::search() rows (DBAL
            // fetchAllAssociative()), so it is really mixed -- the
            // is_string()/is_int() narrowing below is the real guard, not
            // just defensive boilerplate. user_id/category_id/image_id/
            // search_id are int columns -- DBAL returns native int, so
            // these 4 need the wider int|string check the genuinely-string
            // columns below don't.
            $line_image_type = $line['image_type'] ?? null;
            $line_image_type = is_string($line_image_type) ? $line_image_type : null;
            $line_image_id = $line['image_id'] ?? null;
            $line_image_id = (is_int($line_image_id) || is_string($line_image_id)) ? (string) $line_image_id : null;
            $line_user_id = $line['user_id'] ?? null;
            $line_user_id = (is_int($line_user_id) || is_string($line_user_id)) ? (string) $line_user_id : null;
            $line_ip = $line['IP'] ?? null;
            $line_ip = is_string($line_ip) ? $line_ip : null;
            $line_tag_ids = $line['tag_ids'] ?? null;
            $line_tag_ids = is_string($line_tag_ids) ? $line_tag_ids : null;
            $line_search_id = $line['search_id'] ?? null;
            $line_search_id = (is_int($line_search_id) || is_string($line_search_id)) ? (string) $line_search_id : null;
            $line_date = $line['date'] ?? null;
            $line_date = is_string($line_date) ? $line_date : null;
            $line_time = $line['time'] ?? null;
            $line_time = is_string($line_time) ? $line_time : null;
            $line_section = $line['section'] ?? null;
            $line_section = is_string($line_section) ? $line_section : null;
            $line_category_id = $line['category_id'] ?? null;
            $line_category_id = (is_int($line_category_id) || is_string($line_category_id)) ? (string) $line_category_id : null;

            if ($line_image_type === 'high' and $line_image_id !== null) {
                // 'total_filesize' is only ever set to int by this same loop
                // (initialized to 0 above, then always int + int below).
                $running_total_filesize = $summary['total_filesize'];
                $filesize_row = $image_infos[$line_image_id] ?? null;
                $filesize_value = is_array($filesize_row) ? $filesize_row['filesize'] : null;
                $summary['total_filesize'] = $running_total_filesize + (is_scalar($filesize_value) ? intval($filesize_value) : 0);
            }

            if (is_numeric($line_user_id) and (int) $line_user_id === $this->currentConfig->guestId) {
                $ip_key = $line_ip ?? '';
                // 'guests_IP' is only ever set to array by this same loop
                // (initialized to [] above, then always reassigned as array below).
                $guests_ip = $summary['guests_IP'];
                if (! isset($guests_ip[$ip_key])) {
                    $guests_ip[$ip_key] = 0;
                }

                // always int: either the literal 0 just set above, or a
                // value this same loop already wrote as int + 1.
                $guest_ip_count = $guests_ip[$ip_key];
                $guests_ip[$ip_key] = $guest_ip_count + 1;
                $summary['guests_IP'] = $guests_ip;
            }

            $i++;

            if ($i <= $first_line and $i >= $last_line) {
                continue;
            }

            $user_name = '#unknown';
            $user_string = '';
            $user_id_key = $line_user_id ?? '';
            if (isset($username_of[$user_id_key])) {
                $user_name = $username_of[$user_id_key];
                $user_string .= $username_of[$user_id_key];
            } else {
                $user_string .= $user_id_key;
            }
            $user_string .= '&nbsp;<a href="';
            $user_string .= $this->urlService
                ->getRootUrl() . 'admin.php?page=history';
            $user_string .= '&amp;search_id=' . $search_id;
            $user_string .= '&amp;user_id=' . $user_id_key;
            $user_string .= '">+</a>';

            $tag_names = '';
            $tag_ids = '';
            if ($line_tag_ids !== null) {
                $tag_names = preg_replace_callback(
                    '/(\d+)/',
                    /**
                     * @param array<int, string> $m
                     */
                    function (array $m) use ($name_of_tag): string {
                        $tag_id = $m[1];
                        return $name_of_tag[$tag_id] ?? $tag_id;
                    },
                    $line_tag_ids
                );
                $tag_ids = $line_tag_ids;
            }

            $image_string = '';
            $image_title = '';
            $image_edit_string = '';
            $image_id = '';
            $cat_name = '';
            if ($line_image_id !== null) {
                $image_edit_string = $this->urlService
                    ->getRootUrl() . 'admin.php?page=photo-' . $line_image_id;
                $picture_url = $this->urlService
                    ->makePictureUrl(
                        [
                            'image_id' => $line_image_id,
                        ]
                    );

                $element = [];
                if (isset($image_infos[$line_image_id])) {
                    $element = [
                        'id' => $line_image_id,
                        'file' => $image_infos[$line_image_id]['file'],
                        'path' => $image_infos[$line_image_id]['path'],
                        'representative_ext' => $image_infos[$line_image_id]['representative_ext'],
                    ];
                    $page_search = $page['search'];
                    $page_search_fields = $page_search['fields'] ?? null;
                    $thumbnail_display = is_array($page_search_fields) ? ($page_search_fields['display_thumbnail'] ?? 'no_display_thumbnail') : 'no_display_thumbnail';
                } else {
                    $thumbnail_display = 'no_display_thumbnail';
                }

                $image_title = '';

                if (isset($image_infos[$line_image_id]['label'])) {
                    $label = $image_infos[$line_image_id]['label'];
                    $labelEvent = $this->eventDispatcher->dispatchChange(new RenderElementDescription($label));
                    $image_title .= ' ' . $labelEvent->elementDescription;
                } else {
                    $image_edit_string = '';
                    $image_title .= ' unknown filename';
                }

                $image_string = '';
                $image_id = $line_image_id;

                $image_string =
                '<span><img src="' . @DerivativeImage::url($this->imageStdParams->getByType(ImageStdParams::SQUARE), $element)
                . '" alt="' . $image_title . '" title="' . $image_title . '">';
            }

            if ($line_search_id !== null) {
                $search_detail_fields = $search_details[$line_search_id] ?? null;
                $search_detail_fields = is_array($search_detail_fields) ? $search_detail_fields : [];

                $allwords_words = is_array($search_detail_fields['allwords'] ?? null) ? ($search_detail_fields['allwords']['words'] ?? null) : null;

                $tags_words = is_array($search_detail_fields['tags'] ?? null) ? ($search_detail_fields['tags']['words'] ?? null) : null;
                $tags_words = is_array($tags_words) ? array_values(array_filter($tags_words, is_string(...))) : null;

                $date_posted = $search_detail_fields['date_posted'] ?? null;

                $cat_words = is_array($search_detail_fields['cat'] ?? null) ? ($search_detail_fields['cat']['words'] ?? null) : null;
                $cat_words = is_array($cat_words) ? array_values(array_filter($cat_words, is_string(...))) : null;

                $author_words = is_array($search_detail_fields['author'] ?? null) ? ($search_detail_fields['author']['words'] ?? null) : null;

                $added_by = $search_detail_fields['added_by'] ?? null;
                $added_by = is_array($added_by) ? array_values(array_filter($added_by, is_string(...))) : null;

                $filetypes = $search_detail_fields['filetypes'] ?? null;

                $is_falsy = static fn (mixed $v): bool => in_array($v, [null, false, 0, 0.0, '0', '', []], true);
                $search_detail = [
                    'allwords' => ! $is_falsy($allwords_words) ? $allwords_words : null,
                    'tags' => $tags_words !== null && $tags_words !== [] ? array_intersect_key($name_of_tag, array_flip($tags_words)) : null,
                    'date_posted' => ! $is_falsy($date_posted) ? $date_posted : null,
                    'cat' => $cat_words !== null && $cat_words !== [] ? array_intersect_key($name_of_category, array_flip($cat_words)) : null,
                    'author' => ! $is_falsy($author_words) ? $author_words : null,
                    'added_by' => $added_by !== null && $added_by !== [] ? array_intersect_key($username_of, array_flip($added_by)) : null,
                    'filetypes' => ! $is_falsy($filetypes) ? $filetypes : null,
                ];
            } else {
                $search_detail = null;
            }

            @++$sorted_members[$user_name];

            array_push(
                $result,
                [
                    'DATE' => DateHelper::formatDate($line_date ?? ''),
                    'TIME' => $line_time,
                    'USER' => $user_string,
                    'USERNAME' => $user_name,
                    'USERID' => $line_user_id,
                    'IP' => $line_ip,
                    'IMAGE' => $image_string,
                    'IMAGENAME' => $image_title,
                    'IMAGEID' => $image_id,
                    'EDIT_IMAGE' => $image_edit_string,
                    'TYPE' => $line_image_type,
                    'SECTION' => $line_section,
                    'FULL_CATEGORY_PATH' => $line_category_id !== null && isset($full_cat_path[$line_category_id]) ? strip_tags($full_cat_path[$line_category_id]) : $this->lang->t('Root') . $line_category_id,
                    'CATEGORY' => $line_category_id !== null && isset($name_of_category[$line_category_id]) ? $name_of_category[$line_category_id] : $this->lang->t('Root') . $line_category_id,
                    'SEARCH_ID' => $line_search_id,
                    'TAGS' => explode(',', (string) $tag_names),
                    'TAGIDS' => explode(',', $tag_ids),
                    'SEARCH_DETAILS' => $search_detail,
                ]
            );
        }

        $max_page = ceil(count($result) / 300);
        $result = array_reverse($result, true);
        $result = array_slice($result, ($input->pageNumber ?? 0) * 300, 300);

        // always array: see the loop-invariant comment on 'guests_IP' above.
        $guests_ip_final = $summary['guests_IP'];

        $summary['nb_guests'] = 0;
        if (count(array_keys($guests_ip_final)) > 0) {
            $summary['nb_guests'] = count(array_keys($guests_ip_final));

            // we delete the "guest" from the $username_of hash so that it is
            // avoided in next steps
            // guestId() is SCHEMA-typed 'int' only.
            $guest_id_key = (string) $this->currentConfig->guestId;
            $username_of = array_diff_key($username_of, [
                $guest_id_key => true,
            ]);
        }

        $summary['nb_members'] = count($username_of);

        $member_strings = [];
        foreach ($username_of as $user_id => $user_name) {
            $member_string = $user_name;
            $member_strings[] = [
                $member_string => $user_id,
            ];
        }

        arsort($sorted_members);
        unset($sorted_members['guest']);

        // 'total_filesize'/'nb_members'/'nb_guests' are only ever set to int by
        // this same function (see the loop-invariant comments above, plus the
        // count()-based assignments for nb_members/nb_guests); 'nb_lines' is set
        // once above from count($data).
        $summary_total_filesize = $summary['total_filesize'];
        $summary_nb_members = $summary['nb_members'];
        $summary_nb_guests = $summary['nb_guests'];
        $page_nb_lines = $page['nb_lines'];

        $search_summary =
        [
            'NB_LINES' => $this->translator->plural(
                '%d line filtered',
                '%d lines filtered',
                $page_nb_lines
            ),
            'FILESIZE' => $summary_total_filesize !== 0 ? ceil($summary_total_filesize / 1024) : 0,
            'USERS' => $this->translator->plural(
                '%d user',
                '%d users',
                $summary_nb_members + $summary_nb_guests
            ),
            'MEMBERS' => $member_strings,
            'SORTED_MEMBERS' => $sorted_members,
            'GUESTS' => $this->translator->plural(
                '%d guest',
                '%d guests',
                $summary_nb_guests
            ),
        ];

        unset($name_of_tag);

        return [
            'lines' => $result,
            'params' => $params,
            'maxPage' => ($max_page === 0.0) ? 1 : $max_page,
            'summary' => $search_summary,
        ];
    }
}
