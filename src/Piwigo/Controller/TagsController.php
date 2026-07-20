<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Category\CategoryRepository;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces tags.php -- the front-end tag cloud/letter-index browsing page
 * (distinct from P21's admin/tags.php, the orphan-tag management page).
 * check_status() stays outside the captured closure, same exit()-based-
 * termination limitation as every other controller this phase.
 */
final class TagsController implements ControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_tags');

        $displayModeParam = $request->getQueryParams()['display_mode'] ?? null;
        $urlService = $this->urlService;

        $body = LegacyRenderCapture::capture(static function () use ($displayModeParam, $urlService): void {
            // $title is set and read entirely within this closure (passed
            // straight into PageHeaderRenderer::render() below) -- no
            // other file reads $GLOBALS['title']. Plain local, not global.
            $template = \Piwigo\Template\CurrentTemplate::get();

            $title = l10n('Tags');
            \Piwigo\Core\PageState::current()->setBodyId('theTagsPage');

            $template->set_filenames([
                'tags' => 'tags.tpl',
            ]);

            $default_display_mode = \Piwigo\Config\Config::tagsDefaultDisplayMode();

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
            $tagService = new TagService(new TagRepository($conn), new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn)));

            // find all tags available for the current user
            $tags = $tagService->getAvailableTags();

            if ($display_mode === 'letters') {
                // we want tags diplayed in alphabetic order
                usort($tags, new HtmlService()->tagAlphaCompare(...));

                $tag_letters_column_number = is_numeric(\Piwigo\Config\Config::tagLettersColumnNumber()) ? (int) \Piwigo\Config\Config::tagLettersColumnNumber() : 4;

                $current_letter = null;
                $nb_tags = count($tags);
                $current_column = 1;
                $current_tag_idx = 0;

                $letter = [
                    'tags' => [],
                ];

                foreach ($tags as $tag) {
                    $tag_name = $tag['name'];
                    if (! is_string($tag_name)) {
                        $tag_name = is_string($tag['name_raw'] ?? null) ? $tag['name_raw'] : '';
                    }
                    $tag_letter = mb_strtoupper(mb_substr(\Piwigo\Core\StringHelper::pwgTransliterate($tag_name), 0, 1, PWG_CHARSET), PWG_CHARSET);

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
                $full_tag_cloud_items_number = is_numeric(\Piwigo\Config\Config::fullTagCloudItemsNumber()) ? (int) \Piwigo\Config\Config::fullTagCloudItemsNumber() : 200;
                $tags = array_slice($tags, 0, $full_tag_cloud_items_number);

                // depending on its counter and the other tags counter,
                // each tag has a level
                $tags = $tagService->addLevelToTags($tags);

                // we want tags diplayed in alphabetic order
                usort($tags, new HtmlService()->tagAlphaCompare(...));

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
                    ->render($urlService);
            }

            new \Piwigo\Page\PageHeaderRenderer()
                ->render($title);
            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_tags');
            new HtmlService()
                ->flushPageMessages();
            $template->pparse('tags');
            \Piwigo\Bootstrap\PageTail::render();
        });

        return ResponseFactory::html($body);
    }
}
