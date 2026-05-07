<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\ServiceLocator;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the tags cloud/alphabetic page (/tags/{rest}).
 * Corresponds to the former tags.php entry-point.
 */
final class TagsController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        PermissionService::get()->checkStatus(AccessLevel::Guest);

        EventDispatcher::notify('loc_begin_tags');

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $title = l10n('Tags');
        $page['body_id'] = 'theTagsPage';

        $tpl = TemplateRegistry::current();
        $tpl->setFilenames(['tags' => 'tags.tpl']);

        $page['display_mode'] = Config::tagsDefaultDisplayMode();
        $display_mode         = input_string('display_mode', null, $_GET);
        if ($display_mode !== null && in_array($display_mode, ['cloud', 'letters'])) {
            $page['display_mode'] = $display_mode;
        }

        foreach (['cloud', 'letters'] as $mode) {
            $tpl->assign(
                'U_' . strtoupper($mode),
                ServiceLocator::get(UrlGenerator::class)->tagsPage() . (Config::tagsDefaultDisplayMode() == $mode ? '' : '&amp;display_mode=' . $mode)
            );
        }

        $displayMode = (string) $page['display_mode'];
        $tpl->assign('display_mode', $displayMode);

        $tags = get_available_tags();

        if ($displayMode === 'letters') {
            usort($tags, fn (mixed $a, mixed $b): int => tag_alpha_compare(is_array($a) ? $a : [], is_array($b) ? $b : []));

            $current_letter   = null;
            $nb_tags          = count($tags);
            $current_column   = 1;
            $current_tag_idx  = 0;
            $letter           = ['tags' => []];

            foreach ($tags as $tag) {
                $tagArr      = is_array($tag) ? $tag : [];
                $tagName     = is_string($tagArr['name'] ?? null) ? $tagArr['name'] : '';
                $tag_letter  = mb_strtoupper(mb_substr(pwg_transliterate($tagName), 0, 1, 'utf-8'), 'utf-8');

                if ($current_tag_idx === 0) {
                    $current_letter  = $tag_letter;
                    $letter['TITLE'] = $tag_letter;
                }

                if ($tag_letter !== $current_letter) {
                    if ($current_column < Config::tagLettersColumnNumber()
                        && $current_tag_idx > $current_column * $nb_tags / Config::tagLettersColumnNumber()
                    ) {
                        $letter['CHANGE_COLUMN'] = true;
                        $current_column++;
                    }
                    $letter['TITLE'] = $current_letter;
                    $tpl->append('letters', $letter);
                    $current_letter = $tag_letter;
                    $letter         = ['tags' => []];
                }

                $letter['tags'][] = array_merge($tagArr, ['URL' => UrlService::get()->makeIndexUrl(['tags' => [$tag]])]);
                $current_tag_idx++;
            }

            if (count($letter['tags']) > 0) {
                $letter['TITLE'] = $current_letter;
                $tpl->append('letters', $letter);
            }
        } else {
            usort($tags, fn (mixed $a, mixed $b): int => tags_counter_compare(is_array($a) ? $a : [], is_array($b) ? $b : []));
            $tags = array_slice($tags, 0, Config::fullTagCloudItemsNumber());
            $tags = add_level_to_tags($tags);
            usort($tags, fn (mixed $a, mixed $b): int => tag_alpha_compare(is_array($a) ? $a : [], is_array($b) ? $b : []));

            foreach ($tags as $tag) {
                $tagArr = is_array($tag) ? $tag : [];
                $tpl->append('tags', array_merge($tagArr, ['URL' => UrlService::get()->makeIndexUrl(['tags' => [$tag]])]));
            }
        }

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theTagsPage', $hideMenuOn)) {
            ServiceLocator::get(MenubarRenderer::class)->render();
        }

        PageHeaderRenderer::render($title);
        EventDispatcher::notify('loc_end_tags');
        flush_page_messages();
        $tpl->pparse('tags');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
