<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Location\LocBeginTags;
use Piwigo\Event\Location\LocEndTags;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Session\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces tags.php -- the front-end tag cloud/letter-index browsing page
 * (distinct from P21's admin/tags.php, the orphan-tag management page).
 *
 * Legacy Coupling Retirement Workstream D: converted off
 * LegacyRenderCapture's ob_start()/ob_get_contents() capture, same
 * pattern as AboutController -- see that class's own docblock for the
 * accumulator mechanics this relies on.
 */
final class TagsController implements ControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\FilterState $filterState,
        private readonly \Piwigo\Section\SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Config\DeploymentPolicy $deploymentPolicy,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Tag\TagService $tagService,
        private readonly \Piwigo\Html\HtmlService $htmlService,
        private readonly \Piwigo\Config\CurrentConfig $currentConfig,
        private readonly \Piwigo\Lang\Translator $translator,
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Guest);

        $this->eventDispatcher->dispatchNotify(new LocBeginTags());

        $displayModeParam = $request->getQueryParams()['display_mode'] ?? null;
        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        $title = $this->lang->t('Tags');
        $this->pageState->setBodyId('theTagsPage');

        $template->set_filenames([
            'tags' => 'tags.tpl',
        ]);

        $default_display_mode = $this->currentConfig->tagsDefaultDisplayMode();

        $display_mode = $default_display_mode;
        if (is_string($displayModeParam) && in_array($displayModeParam, ['cloud', 'letters'], true)) {
            $display_mode = $displayModeParam;
        }

        foreach (['cloud', 'letters'] as $mode) {
            $template->assign(
                'U_' . strtoupper($mode),
                $urlService->getRootUrl() . 'tags.php' . ($default_display_mode === $mode ? '' : '?display_mode=' . $mode)
            );
        }

        $template->assign('display_mode', $display_mode);

        $conn = DbConnection::build();
        $tagService = $this->tagService;

        // find all tags available for the current user
        $tags = $tagService->getAvailableTags();

        if ($display_mode === 'letters') {
            // we want tags diplayed in alphabetic order
            usort($tags, $this->htmlService->tagAlphaCompare(...));

            $tag_letters_column_number = $this->currentConfig->tagLettersColumnNumber();

            $current_letter = null;
            $nb_tags = count($tags);
            $current_column = 1;
            $current_tag_idx = 0;

            $letter = [
                'tags' => [],
            ];

            foreach ($tags as $tag) {
                $tag_name = $tag['name'];
                $pwgCharset = \Piwigo\Core\CharsetHelper::getPwgCharset();
                $tag_letter = mb_strtoupper(mb_substr(\Piwigo\Core\StringHelper::pwgTransliterate($tag_name), 0, 1, $pwgCharset), $pwgCharset);

                if ($current_tag_idx === 0) {
                    $current_letter = $tag_letter;
                    $letter['TITLE'] = $tag_letter;
                }

                // lettre precedente differente de la lettre suivante
                if ($tag_letter !== $current_letter) {
                    if ($current_column < $tag_letters_column_number
                        and $current_tag_idx > $current_column * $nb_tags / $tag_letters_column_number) {
                        $letter['CHANGE_COLUMN'] = true;
                        $current_column++;
                    }

                    $letter['TITLE'] = $current_letter;

                    $template->append(
                        'letters',
                        $letter
                    );

                    $current_letter = $tag_letter;
                    $letter = [
                        'tags' => [],
                    ];
                }

                $letter['tags'][] = array_merge(
                    $tag,
                    [
                        'URL' => $urlService->makeIndexUrl([
                            'tags' => [$tag],
                        ]),
                    ]
                );

                $current_tag_idx++;
            }

            // flush last letter -- CHANGE_COLUMN is never set on $letter
            // at this point: every place it gets set is immediately
            // followed by an unconditional $letter reset within the same
            // loop iteration, before the loop can exit (confirmed via
            // PHPStan's own unset.offset finding, not assumed), so the
            // legacy file's own defensive unset() here was dead code.
            if (count($letter['tags']) > 0) {
                $letter['TITLE'] = $current_letter;
                $template->append(
                    'letters',
                    $letter
                );
            }
        } else {
            // we want only the first most represented tags, so we sort
            // them by counter and take the first tags
            usort($tags, $tagService->tagsCounterCompare(...));
            $full_tag_cloud_items_number = $this->currentConfig->fullTagCloudItemsNumber();
            $tags = array_slice($tags, 0, $full_tag_cloud_items_number);

            // depending on its counter and the other tags counter,
            // each tag has a level
            $tags = $tagService->addLevelToTags($tags);

            // we want tags diplayed in alphabetic order
            usort($tags, $this->htmlService->tagAlphaCompare(...));

            foreach ($tags as $tag) {
                $template->append(
                    'tags',
                    array_merge(
                        $tag,
                        [
                            'URL' => $urlService->makeIndexUrl(
                                [
                                    'tags' => [$tag],
                                ]
                            ),
                        ]
                    )
                );
            }
        }

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theTagsPage', $themeconf['hide_menu_on'], true)) {
            new MenubarRenderer()
                ->render($this->lang, $this->accessControl, $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger);
        }

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        $this->eventDispatcher->dispatchNotify(new LocEndTags());
        $this->htmlService
            ->flushPageMessages();
        $template->parse('tags', false);
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
