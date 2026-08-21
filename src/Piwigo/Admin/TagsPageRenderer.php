<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\TagsView;
use Piwigo\Admin\Request\TagsActionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
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

            $tagService->deleteOrphanTags($this->entityManager);
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
            $tag = [
                'name' => $tag_obj->name,
                'id' => $tag_obj->id->value,
                'url_name' => $tag_obj->urlName,
            ];
            $raw_name = $tag_obj->name;
            $tag['raw_name'] = $raw_name;
            $tagNameEvent = $this->eventDispatcher->dispatch(new RenderTagName($raw_name, $tag));
            $rendered_name = $tagNameEvent->tagName;
            $tag['name'] = $rendered_name;

            $tag_id = $tag_obj->id->value;
            $counter = $tag_counters[$tag_id] ?? 0;
            if ($counter > 0) {
                $tag['counter'] = $counter;
            }

            $altNamesEvent = $this->eventDispatcher->dispatch(new GetTagAltNames([], $raw_name));
            $alt_names = array_filter($altNamesEvent->value, is_string(...));
            $alt_names = array_diff(array_unique($alt_names), [$rendered_name]);
            if (count($alt_names) > 0) {
                $tag['alt_names'] = implode(', ', $alt_names);
            }
            $all_tags[] = $tag;
        }
        usort($all_tags, $this->htmlRenderer->tagAlphaCompare(...));

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
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Tags'),
        );
    }
}
