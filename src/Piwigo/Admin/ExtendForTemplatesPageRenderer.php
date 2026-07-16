<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

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
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $conf, $page, $template;

        // $page['infos'] is always initialized to an array by common.inc.php, but
        // that isn't visible across the include() boundary -- narrow it once here
        // so the $page['infos'][] = ... append below type-checks.
        $page['infos'] = is_array($page['infos'] ?? null) ? $page['infos'] : [];

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        $tpl_extension = [];
        if (isset($conf['extents_for_templates']) && is_string($conf['extents_for_templates'])) {
            $unserialized_tpl_extension = unserialize($conf['extents_for_templates']);
            if (is_array($unserialized_tpl_extension)) {
                foreach ($unserialized_tpl_extension as $tpl_extension_file => $tpl_extension_conditions) {
                    if (is_string($tpl_extension_file) && is_array($tpl_extension_conditions)
                        && isset($tpl_extension_conditions[0], $tpl_extension_conditions[1], $tpl_extension_conditions[2])
                        && is_string($tpl_extension_conditions[0]) && is_string($tpl_extension_conditions[1])
                        && is_string($tpl_extension_conditions[2])) {
                        $tpl_extension[$tpl_extension_file] = [
                            $tpl_extension_conditions[0],
                            $tpl_extension_conditions[1],
                            $tpl_extension_conditions[2],
                        ];
                    }
                }
            }
        }
        $new_extensions = get_extents();

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
        $query = '
SELECT permalink
  FROM ' . Tables::categories() . '
  WHERE permalink IS NOT NULL
';

        /* Add active permalinks */
        $permalinks = query2array($query, null, 'permalink');
        $relevant_parameters = array_merge($relevant_parameters, $permalinks);

        /* Link all supported templates to their respective handle */
        $eligible_templates = [
            '----------' => 'N/A',
            'about.tpl' => 'about',
            'comments.tpl' => 'comments',
            'comment_list.tpl' => 'comment_list',
            'footer.tpl' => 'tail',
            'header.tpl' => 'header',
            'identification.tpl' => 'identification',
            'index.tpl' => 'index',
            'mainpage_categories.tpl' => 'index_category_thumbnails',
            'menubar.tpl' => 'menubar',
            'menubar_categories.tpl' => 'mbCategories',
            'menubar_identification.tpl' => 'mbIdentification',
            'menubar_links.tpl' => 'mbLinks',
            'menubar_menu.tpl' => 'mbMenu',
            'menubar_specials.tpl' => 'mbSpecials',
            'menubar_tags.tpl' => 'mbTags',
            'month_calendar.tpl' => 'month_calendar',
            'navigation_bar.tpl' => 'navbar',
            'nbm.tpl' => 'nbm',
            'notification.tpl' => 'notification',
            'password.tpl' => 'password',
            'picture.tpl' => 'picture',
            'picture_content.tpl' => 'default_content',
            'picture_nav_buttons.tpl' => 'picture_nav_buttons',
            'popuphelp.tpl' => 'popuphelp',
            'profile.tpl' => 'profile',
            'profile_content.tpl' => 'profile_content',
            'redirect.tpl' => 'redirect',
            'register.tpl' => 'register',
            'search.tpl' => 'search',
            'slideshow.tpl' => 'slideshow',
            'tags.tpl' => 'tags',
            'thumbnails.tpl' => 'index_thumbnails',
        ];

        $flip_templates = array_flip($eligible_templates);

        $available_templates = array_merge(
            [
                'N/A' => '----------',
            ],
            get_dirs(PHPWG_ROOT_PATH . 'themes')
        );

        if (isset($_POST['submit'])) {
            $replacements = [];
            $post_reptpl = isset($_POST['reptpl']) && is_array($_POST['reptpl']) ? $_POST['reptpl'] : [];
            $post_original = isset($_POST['original']) && is_array($_POST['original']) ? $_POST['original'] : [];
            $post_url = isset($_POST['url']) && is_array($_POST['url']) ? $_POST['url'] : [];
            $post_bound = isset($_POST['bound']) && is_array($_POST['bound']) ? $_POST['bound'] : [];
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
            $serialized_extents = serialize($replacements);
            $conf['extents_for_templates'] = $serialized_extents;
            $tpl_extension = $replacements;
            /* ecrire la nouvelle conf */
            $query = '
UPDATE ' . Tables::config() . '
  SET value = \'' . str_replace("\'", "''", $serialized_extents) . '\'
WHERE param = \'extents_for_templates\';';
            if ((bool) pwg_query($query)) {
                $page['infos'][] = l10n('Templates configuration has been recorded.');
            }
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

        $template->set_filenames([
            'extend_for_templates' => 'extend_for_templates.tpl',
        ]);

        $template->assign(
            [
                'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=extend_for_templates',
            ]
        );
        ksort($tpl_extension);
        foreach ($tpl_extension as $file => $conditions) {
            $handle = $conditions[0];
            $url_keyword = $conditions[1];
            $bound_tpl = $conditions[2];

            $template->append(
                'extents',
                [
                    'replacer' => $file,
                    'url_parameter' => $relevant_parameters,
                    'original_tpl' => array_keys($eligible_templates),
                    'bound_tpl' => $available_templates,
                    'selected_tpl' => $flip_templates[$handle],
                    'selected_url' => $url_keyword,
                    'selected_bound' => $bound_tpl,
                ]
            );
        }
        $template->assign('ADMIN_PAGE_TITLE', l10n('Extend for templates'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'extend_for_templates');
    }
}
