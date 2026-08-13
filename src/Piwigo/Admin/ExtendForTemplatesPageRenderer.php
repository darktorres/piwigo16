<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\ExtendForTemplatesPageContext;
use Piwigo\Admin\Request\ExtendForTemplatesSubmitRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Template\CurrentTemplate;

/**
 * Ported from admin/extend_for_templates.php (page slug
 * "extend_for_templates").
 *
 * Define replacement conditions for each template from template-extension
 * (template called "replacer").
 *
 * "original template" from ./template/yoga (or any other than yoga)
 * will be replaced by a "replacer" if the replacer is linked to this "original template"
 * (and optionally, when the requested URL contains an "optional URL keyword").
 *
 * "Optional URL keywords" are those you can find after the module name in URLs.
 *
 * Therefore "Optional URL keywords" can be an active "permalink"
 * (see permalinks in our documentation for further explanation).
 */
final class ExtendForTemplatesPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, ConfigService $configService, PageState $pageState, CurrentTemplate $currentTemplate, CategoryService $categoryService, CurrentConfig $currentConfig, Paths $paths): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $tpl_extension = [];
        // extentsForTemplates defaults to [] when never configured, so this
        // loop is naturally a no-op then -- no separate presence check
        // needed. Every entry is already a validated TemplateExtension, so
        // no further shape-checking is needed here either.
        foreach ($currentConfig->extentsForTemplates as $tpl_extension_file => $tpl_extension_conditions) {
            $tpl_extension[$tpl_extension_file] = [
                $tpl_extension_conditions->handle,
                $tpl_extension_conditions->param,
                $tpl_extension_conditions->theme,
            ];
        }
        $new_extensions = AdminUiHelper::getExtents($paths);

        /* Selective URLs keyword */
        $relevant_parameters = [
            '----------',
            'category',
            'favorites',
            'most_visited',
            'best_rated',
            'recent_pics',
            'recent_cats',
            'created-monthly-calendar',
            'posted-monthly-calendar',
            'search',
            'flat',
            'list', /* <=> Random */
            'tags',
        ];
        /* Add active permalinks */
        $permalinks = $categoryService->getActivePermalinks();
        $relevant_parameters = array_merge($relevant_parameters, $permalinks);

        /* Link all supported templates to their respective handle.
         *
         * Keys are always the current real .latte basename (shown in the
         * admin dropdown). Values are what Template::$extents actually
         * gets keyed by, and that keying isn't uniform: most templates are
         * reached only via a direct parse('x.latte') call, resolved by
         * Template::resolveLatteTemplatePath()'s own basename-keyed
         * lookup -- for those, value == key. A handful of partials are
         * instead reached via a real, live {include getExtent(file,
         * handle)} call inside an already-converted .latte template
         * (menubar.latte's per-block {include getExtent($block->template,
         * $id)}, and the several navigation_bar.latte/picture_nav_buttons.latte
         * {include getExtent('x.latte', 'handle')} sites) -- those pass a
         * short opaque handle string as Template::getExtent()'s own
         * $handle arg, literally hardcoded in the template source, so
         * $this->extents has to stay keyed by that exact string for an
         * override to actually be found. */
        $eligible_templates = [
            '----------' => 'N/A',
            'about.latte' => 'about.latte',
            'comments.latte' => 'comments.latte',
            'comment_list.latte' => 'comment_list.latte',
            'footer.latte' => 'footer.latte',
            'header.latte' => 'header.latte',
            'identification.latte' => 'identification.latte',
            'index.latte' => 'index.latte',
            'mainpage_categories.latte' => 'mainpage_categories.latte',
            'menubar.latte' => 'menubar.latte',
            'menubar_categories.latte' => 'mbCategories',
            'menubar_identification.latte' => 'mbIdentification',
            'menubar_links.latte' => 'mbLinks',
            'menubar_menu.latte' => 'mbMenu',
            'menubar_specials.latte' => 'mbSpecials',
            'menubar_tags.latte' => 'mbTags',
            'month_calendar.latte' => 'month_calendar.latte',
            'navigation_bar.latte' => 'navbar',
            'nbm.latte' => 'nbm.latte',
            'notification.latte' => 'notification.latte',
            'password.latte' => 'password.latte',
            'picture.latte' => 'picture.latte',
            'picture_content.latte' => 'picture_content.latte',
            'picture_nav_buttons.latte' => 'picture_nav_buttons',
            'popuphelp.latte' => 'popuphelp.latte',
            'profile.latte' => 'profile.latte',
            'profile_content.latte' => 'profile_content.latte',
            'redirect.latte' => 'redirect.latte',
            'register.latte' => 'register.latte',
            'search.latte' => 'search.latte',
            'slideshow.latte' => 'slideshow.latte',
            'tags.latte' => 'tags.latte',
            'thumbnails.latte' => 'thumbnails.latte',
        ];

        $flip_templates = array_flip($eligible_templates);

        $available_templates = array_merge(
            [
                'N/A' => '----------',
            ],
            FilesystemHelper::getDirs($paths->themes)
        );

        $extendSubmit = ExtendForTemplatesSubmitRequest::fromGlobals();
        if ($extendSubmit->isSubmitted) {
            $replacements = [];
            $post_reptpl = $extendSubmit->reptpl;
            $post_original = $extendSubmit->original;
            $post_url = $extendSubmit->url;
            $post_bound = $extendSubmit->bound;
            $i = 0;
            while (isset($post_reptpl[$i])) {
                $newtpl = $post_reptpl[$i];
                $original = $post_original[$i] ?? null;
                $url_keyword = $post_url[$i] ?? null;
                $bound_tpl = $post_bound[$i] ?? null;
                if (! is_string($newtpl) || ! is_string($original) || ! is_string($url_keyword) || ! is_string($bound_tpl)) {
                    $i++;
                    continue;
                }
                $handle = $eligible_templates[$original];
                if ($url_keyword === '----------') {
                    $url_keyword = 'N/A';
                }
                if ($bound_tpl === '----------') {
                    $bound_tpl = 'N/A';
                }
                if ($handle !== 'N/A') {
                    $replacements[$newtpl] = [$handle, $url_keyword, $bound_tpl];
                }
                $i++;
            }
            $currentConfig->extentsForTemplates = $replacements;
            $tpl_extension = $replacements;
            /* ecrire la nouvelle conf */
            $configService->confUpdateParam('extents_for_templates', $replacements);
            $pageState->addInfo($lang->t('Templates configuration has been recorded.'));
        }

        /* Clearing (remove old extents, add new ones) */
        foreach ($tpl_extension as $file => $conditions) {
            if (! in_array($file, $new_extensions, true)) {
                unset($tpl_extension[$file]);
            } else {
                $new_extensions = array_diff($new_extensions, [$file]);
            }
        }
        foreach ($new_extensions as $file) {
            $tpl_extension[$file] = ['N/A', 'N/A', 'N/A'];
        }

        ksort($tpl_extension);
        $extents = null;
        foreach ($tpl_extension as $file => $conditions) {
            $handle = $conditions[0];
            $url_keyword = $conditions[1];
            $bound_tpl = $conditions[2];

            $extents ??= [];
            $extents[] = [
                'replacer' => $file,
                'url_parameter' => $relevant_parameters,
                'original_tpl' => array_keys($eligible_templates),
                'bound_tpl' => $available_templates,
                'selected_tpl' => $flip_templates[$handle],
                'selected_url' => $url_keyword,
                'selected_bound' => $bound_tpl,
            ];
        }

        $template->assignContext(new ExtendForTemplatesPageContext(
            helpUrl: $urlService->getRootUrl() . 'admin/popuphelp.php?page=extend_for_templates',
            adminPageTitle: $lang->t('Extend for templates'),
            extents: $extents,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'extend_for_templates.latte');
    }
}
