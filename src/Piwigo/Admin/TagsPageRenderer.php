<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Request\TagsActionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Tag\GetTagAltNames;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;

/**
 * Ported from admin/tags.php (page slug "tags").
 */
final class TagsPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentTemplate $currentTemplate,
        private readonly TagService $tagService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        // Legacy Coupling Retirement Phase 8, 8g: real, previously-unfixed
        // bug -- nothing had ever called CoreTabs::setContext() with
        // myBaseUrl for this page (same class of gap as
        // ConfigurationSubController's own $conf_link fix), so this page's
        // own tab strip has always rendered a broken relative href.
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('tags');
        $tabsheet->select('', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $tagService = $this->tagService;

        if (TagsActionRequest::fromGlobals()->isDeleteOrphans) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $tagService->deleteOrphanTags();
            $_SESSION['message_tags'] = $this->lang->t('Orphan tags deleted');
            $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=tags');
        }

        $template->set_filenames([
            'tags' => 'tags.tpl',
        ]);

        $template->assign(
            [
                'F_ACTION' => $this->urlService->getRootUrl() . 'admin.php?page=tags',
                'PWG_TOKEN' => new CsrfService($this->currentConfig)
                    ->getToken(),
            ]
        );

        $warning_tags = '';

        $orphan_tags = $tagService->getOrphanTags();

        $orphan_tag_names_array = '[]';
        $orphan_tag_names = [];
        foreach ($orphan_tags as $tag) {
            $orphanNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName($tag->name, $tag->toArray()));
            $orphan_tag_names[] = $orphanNameEvent->tagName;
        }

        if (count($orphan_tag_names) > 0) {
            $warning_tags = sprintf(
                $this->lang->t('You have %d orphan tags %s'),
                count($orphan_tag_names),
                '<a
      class="icon-eye"
      data-url="' . $this->urlService->getRootUrl() . 'admin.php?page=tags&amp;action=delete_orphans&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken() . '">'
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

        $template->assign(
            [
                'orphan_tag_names_array' => $orphan_tag_names_array,
                'warning_tags' => $warning_tags,
            ]
        );

        $message_tags = '';
        if (isset($_SESSION['message_tags'])) {
            $message_tags = $_SESSION['message_tags'];
            unset($_SESSION['message_tags']);
        }
        $template->assign('message_tags', $message_tags);

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
            $tagNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName($raw_name, $tag));
            $rendered_name = $tagNameEvent->tagName;
            $tag['name'] = $rendered_name;

            $tag_id = $tag_obj->id->value;
            $counter = $tag_counters[$tag_id] ?? 0;
            if ($counter > 0) {
                $tag['counter'] = $counter;
            }

            $altNamesEvent = $this->eventDispatcher->dispatchChange(new GetTagAltNames([], $raw_name));
            $alt_names = array_filter($altNamesEvent->value, is_string(...));
            $alt_names = array_diff(array_unique($alt_names), [$rendered_name]);
            if (count($alt_names) > 0) {
                $tag['alt_names'] = implode(', ', $alt_names);
            }
            $all_tags[] = $tag;
        }
        usort($all_tags, $this->htmlRenderer->tagAlphaCompare(...));

        $template->assign(
            [
                'first_tags' => array_slice($all_tags, 0, $per_page),
                'data' => $all_tags,
                'total' => count($all_tags),
                'per_page' => $per_page,
                'ADMIN_PAGE_TITLE' => $this->lang->t('Tags'),
            ]
        );

        $template->assign_var_from_handle('ADMIN_CONTENT', 'tags');
    }
}
