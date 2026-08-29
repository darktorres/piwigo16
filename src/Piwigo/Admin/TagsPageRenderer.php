<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\TagRow;
use Piwigo\Admin\Projection\TagsView;
use Piwigo\Admin\Request\TagsActionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\CookieService;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\ImageService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\Event\GetTagAltNames;
use Piwigo\Tag\Event\RenderTagName;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

/**
 * Ported from admin/tags.php (page slug "tags").
 */
final readonly class TagsPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private EventDispatcher $eventDispatcher,
        private CurrentTemplate $currentTemplate,
        private TagService $tagService,
        private HtmlRenderingInterface $htmlRenderer,
        private CsrfService $csrfService,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
        private ImageService $imageService,
    ) {}

    public function render(): AdminPageResult
    {
        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('tags');
        $tabsheet->select('', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate, $this->renderer);

        $tagService = $this->tagService;

        if (TagsActionRequest::fromGlobals()->isDeleteOrphans) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $tagService->deleteOrphanTags($this->entityManager, $this->imageService);
            $_SESSION['message_tags'] = $this->lang->t('Orphan tags deleted');
            $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=tags');
        }

        $warning_tags = '';

        $orphan_tags = $tagService->getOrphanTags();

        $orphan_tag_names_array = '[]';
        $orphan_tag_names = [];
        foreach ($orphan_tags as $tag) {
            $orphanNameEvent = $this->eventDispatcher->dispatch(new RenderTagName($tag->name, $tag->toArray()));
            $orphan_tag_names[] = $orphanNameEvent->tagName;
        }

        if (count($orphan_tag_names) > 0) {
            $warning_tags = sprintf(
                $this->lang->t('You have %d orphan tags %s'),
                count($orphan_tag_names),
                '<a
      class="icon-eye"
      data-url="' . $this->urlService->getRootUrl() . 'admin.php?page=tags&amp;action=delete_orphans&amp;pwg_token=' . $this->csrfService->getToken() . '">'
                . $this->lang->t('Review') . '</a>'
            );

            $orphan_tag_names_array = '["';
            $orphan_tag_names_array .= implode(
                '" ,"',
                array_map(
                    htmlentities(...),
                    $orphan_tag_names,
                    array_fill(0, count($orphan_tag_names), ENT_QUOTES)
                )
            );
            $orphan_tag_names_array .= '"]';
        }

        $message_tags = '';
        if (isset($_SESSION['message_tags'])) {
            $message_tags_raw = $_SESSION['message_tags'];
            $message_tags = is_string($message_tags_raw) ? $message_tags_raw : '';
            unset($_SESSION['message_tags']);
        }

        $per_page = 100;

        // tag counters
        $tag_counters = $tagService->getImageCountsPerTagUnrestricted();

        // all tags
        $all_tags = [];
        foreach ($tagService->getAll() as $tag_obj) {
            $raw_name = $tag_obj->name;
            $tag_id = $tag_obj->id->value;

            // RenderTagName carries the row as context. Its shape is the
            // partial one this loop used to have built by this point:
            // the raw name, before the event's own rendered name lands,
            // and no counter or alt names yet.
            $tagNameEvent = $this->eventDispatcher->dispatch(new RenderTagName($raw_name, [
                'name' => $raw_name,
                'id' => $tag_id,
                'url_name' => $tag_obj->urlName,
                'raw_name' => $raw_name,
            ]));
            $rendered_name = $tagNameEvent->tagName;

            $altNamesEvent = $this->eventDispatcher->dispatch(new GetTagAltNames([], $raw_name));
            $alt_names = array_filter($altNamesEvent->value, is_string(...));
            $alt_names = array_diff(array_unique($alt_names), [$rendered_name]);

            $all_tags[] = new TagRow(
                id: $tag_id,
                name: $rendered_name,
                rawName: $raw_name,
                urlName: $tag_obj->urlName,
                counter: $tag_counters[$tag_id] ?? 0,
                altNames: $alt_names === [] ? null : implode(', ', $alt_names),
            );
        }

        // tagAlphaCompare() is the shared cross-domain row reader and takes
        // arrays; 'name' is the only key it reads, so handing it that one
        // key keeps this list on the same ProcessCache-backed
        // transliteration every other tag listing sorts by.
        usort(
            $all_tags,
            fn (TagRow $a, TagRow $b): int => $this->htmlRenderer->tagAlphaCompare(
                [
                    'name' => $a->name,
                ],
                [
                    'name' => $b->name,
                ],
            )
        );

        $adminContent = $this->renderer->render(new TagsView(
            pwgToken: $this->csrfService
                ->getToken(),
            orphanTagNamesArray: $orphan_tag_names_array,
            warningTags: $warning_tags,
            messageTags: $message_tags,
            firstTags: array_slice($all_tags, 0, $per_page),
            data: $all_tags,
            total: count($all_tags),
            perPage: $per_page,
            tagsPerPageSelected: self::tagsPerPageSelected(new CookieService()->getTagsPerPage()),
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Tags'),
        );
    }

    /**
     * Which page-size link `tags.latte` paints as selected, from the
     * `pwg_tags_per_page` cookie.
     *
     * Absent, empty or `'0'` means the visitor has no preference yet and
     * the first link wins -- `tags.ts` writes `'100'` on its own next
     * tick, and reasserts the whole selection client-side anyway, so this
     * only decides the pre-JS paint. Anything that is not one of the four
     * offered sizes selects nothing, which is what the template's own
     * `== 100`/`== 200`/... comparisons did with a value they did not
     * recognise.
     */
    private static function tagsPerPageSelected(?string $cookie): ?int
    {
        if ($cookie === null || $cookie === '' || $cookie === '0') {
            return 100;
        }

        if (! is_numeric($cookie)) {
            return null;
        }

        $value = (int) $cookie;

        return in_array($value, [100, 200, 500, 1000], true) ? $value : null;
    }
}
