<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Integrity;

class check_integrity
{
    /**
     * @var array<int, mixed>
     */
    public $ignore_list;

    /**
     * @var array<int, array<string, mixed>>
     */
    public $retrieve_list;

    /**
     * @var array<int, mixed>
     */
    public $build_ignore_list;

    public function __construct()
    {
        $this->ignore_list = [];
        $this->retrieve_list = [];
        $this->build_ignore_list = [];
    }

    /**
     * Check integrities
     */
    public function check(): void
    {
        /** @var array<string, mixed> $page */
        global $page;
        /** @var array<string, mixed> $header_notes */
        global $header_notes;
        /** @var array<string, mixed> $conf */
        global $conf;

        // Ignore list
        $conf_c13y_ignore_raw = $conf['c13y_ignore'] ?? null;
        $conf_c13y_ignore = is_string($conf_c13y_ignore_raw) ? unserialize($conf_c13y_ignore_raw) : false;
        if (
            is_array($conf_c13y_ignore) and
            isset($conf_c13y_ignore['version']) and
            ($conf_c13y_ignore['version'] == PHPWG_VERSION) and
            is_array($conf_c13y_ignore['list'])
        ) {
            $ignore_list_changed = false;
            $this->ignore_list = array_values($conf_c13y_ignore['list']);
        } else {
            $ignore_list_changed = true;
            $this->ignore_list = [];
        }

        // Retrieve list
        $this->retrieve_list = [];
        $this->build_ignore_list = [];

        trigger_notify('list_check_integrity', $this);

        // Information
        if (count($this->retrieve_list) > 0) {
            $header_notes[] = l10n_dec(
                '%d anomaly has been detected.',
                '%d anomalies have been detected.',
                count($this->retrieve_list)
            );
        }

        // Treatments
        if (isset($_POST['c13y_submit_correction']) and isset($_POST['c13y_selection']) and is_array($_POST['c13y_selection'])) {
            $c13y_selection = $_POST['c13y_selection'];
            $corrected_count = 0;
            $not_corrected_count = 0;

            foreach ($this->retrieve_list as $i => $c13y) {
                if (! empty($c13y['correction_fct']) and
                    (bool) $c13y['is_callable'] and
                    in_array($c13y['id'], $c13y_selection)) {
                    if (is_array($c13y['correction_fct_args'])) {
                        $args = $c13y['correction_fct_args'];
                    } elseif ($c13y['correction_fct_args'] !== null) {
                        $args = [$c13y['correction_fct_args']];
                    } else {
                        $args = [];
                    }
                    $correction_fct = $c13y['correction_fct'];
                    if (is_callable($correction_fct)) {
                        $this->retrieve_list[$i]['corrected'] = call_user_func_array($correction_fct, $args);

                        if ((bool) $this->retrieve_list[$i]['corrected']) {
                            ++$corrected_count;
                        } else {
                            ++$not_corrected_count;
                        }
                    }
                }
            }

            if ($corrected_count > 0) {
                if (! is_array($page['infos'] ?? null)) {
                    $page['infos'] = [];
                }
                $page['infos'][] = l10n_dec(
                    '%d anomaly has been corrected.',
                    '%d anomalies have been detected corrected.',
                    $corrected_count
                );
            }
            if ($not_corrected_count > 0) {
                if (! is_array($page['errors'] ?? null)) {
                    $page['errors'] = [];
                }
                $page['errors'][] = l10n_dec(
                    '%d anomaly has not been corrected.',
                    '%d anomalies have not been corrected.',
                    $not_corrected_count
                );
            }
        } else {
            if (isset($_POST['c13y_submit_ignore']) and isset($_POST['c13y_selection']) and is_array($_POST['c13y_selection'])) {
                $c13y_selection = $_POST['c13y_selection'];
                $ignored_count = 0;

                foreach ($this->retrieve_list as $i => $c13y) {
                    if (in_array($c13y['id'], $c13y_selection)) {
                        $this->build_ignore_list[] = $c13y['id'];
                        $this->retrieve_list[$i]['ignored'] = true;
                        ++$ignored_count;
                    }
                }

                if ($ignored_count > 0) {
                    if (! is_array($page['infos'] ?? null)) {
                        $page['infos'] = [];
                    }
                    $page['infos'][] = l10n_dec(
                        '%d anomaly has been ignored.',
                        '%d anomalies have been ignored.',
                        $ignored_count
                    );
                }
            }
        }

        $scalar_ignore_list = array_filter($this->ignore_list, is_scalar(...));
        $scalar_build_ignore_list = array_filter($this->build_ignore_list, is_scalar(...));
        $ignore_list_changed =
          (
              ($ignore_list_changed) or
        (count(array_diff($scalar_ignore_list, $scalar_build_ignore_list)) > 0) or
        (count(array_diff($scalar_build_ignore_list, $scalar_ignore_list)) > 0)
          );

        if ($ignore_list_changed) {
            $this->update_conf($this->build_ignore_list);
        }
    }

    /**
     * Display anomalies list
     */
    public function display(): void
    {
        /** @var \Template $template */
        global $template;

        $check_automatic_correction = false;
        $submit_automatic_correction = false;
        $submit_ignore = false;

        if (count($this->retrieve_list) > 0) {
            $template->set_filenames([
                'check_integrity' => 'check_integrity.tpl',
            ]);

            foreach ($this->retrieve_list as $i => $c13y) {
                $can_select = false;
                $c13y_display = [
                    'id' => $c13y['id'],
                    'anomaly' => $c13y['anomaly'],
                    'show_ignore_msg' => false,
                    'show_correction_success_fct' => false,
                    'correction_error_fct' => '',
                    'show_correction_fct' => false,
                    'correction_error_fct' => '',
                    'show_correction_bad_fct' => false,
                    'correction_msg' => '',
                ];

                if (isset($c13y['ignored'])) {
                    if ((bool) $c13y['ignored']) {
                        $c13y_display['show_ignore_msg'] = true;
                    } else {
                        die('$c13y[\'ignored\'] cannot be false');
                    }
                } else {
                    if (! empty($c13y['correction_fct'])) {
                        if (isset($c13y['corrected'])) {
                            if ((bool) $c13y['corrected']) {
                                $c13y_display['show_correction_success_fct'] = true;
                            } else {
                                $c13y_display['correction_error_fct'] = $this->get_htlm_links_more_info();
                            }
                        } elseif ((bool) $c13y['is_callable']) {
                            $c13y_display['show_correction_fct'] = true;
                            $template->append('c13y_do_check', $c13y['id']);
                            $submit_automatic_correction = true;
                            $can_select = true;
                        } else {
                            $c13y_display['show_correction_bad_fct'] = true;
                            $can_select = true;
                        }
                    } else {
                        $can_select = true;
                    }

                    if (! empty($c13y['correction_msg']) and ! isset($c13y['corrected'])) {
                        $c13y_display['correction_msg'] = $c13y['correction_msg'];
                    }
                }

                $c13y_display['can_select'] = $can_select;
                if ($can_select) {
                    $submit_ignore = true;
                }

                $template->append('c13y_list', $c13y_display);
            }

            $template->assign('c13y_show_submit_automatic_correction', $submit_automatic_correction);
            $template->assign('c13y_show_submit_ignore', $submit_ignore);

            $template->concat('ADMIN_CONTENT', $template->parse('check_integrity', true));

        }
    }

    /**
     * Add anomaly data
     *
     * @param string $anomaly arguments
     * @param ?string $correction_fct
     * @param ?array<string, mixed> $correction_fct_args
     * @param ?string $correction_msg
     */
    public function add_anomaly($anomaly, $correction_fct = null, $correction_fct_args = null, $correction_msg = null): void
    {
        $id = md5($anomaly . $correction_fct . serialize($correction_fct_args) . $correction_msg);

        if (in_array($id, $this->ignore_list)) {
            $this->build_ignore_list[] = $id;
        } else {
            $this->retrieve_list[] =
              [
                  'id' => $id,
                  'anomaly' => $anomaly,
                  'correction_fct' => $correction_fct,
                  'correction_fct_args' => $correction_fct_args,
                  'correction_msg' => $correction_msg,
                  'is_callable' => is_callable($correction_fct),
              ];
        }
    }

    /**
     * Update table config
     *
     * @param array<int, mixed> $conf_ignore_list list array
     */
    public function update_conf($conf_ignore_list = []): void
    {
        $conf_c13y_ignore = [];
        $conf_c13y_ignore['version'] = PHPWG_VERSION;
        $conf_c13y_ignore['list'] = $conf_ignore_list;
        $query = 'update ' . CONFIG_TABLE . ' set value =\'' . serialize($conf_c13y_ignore) . '\'where param = \'c13y_ignore\';';
        pwg_query($query);
    }

    /**
     * Apply maintenance
     */
    public function maintenance(): void
    {
        $this->update_conf();
    }

    /**
     * Returns links more informations
     *
     * @return string links
     */
    public function get_htlm_links_more_info(): string
    {
        $pwg_links = pwg_URL();
        $link_fmt = '<a href="%s" onclick="window.open(this.href, \'\'); return false;">%s</a>';
        return
          sprintf(
              l10n('Go to %s or %s for more informations'),
              sprintf($link_fmt, $pwg_links['FORUM'], l10n('the forum')),
              sprintf($link_fmt, $pwg_links['WIKI'], l10n('the wiki'))
          );
    }
}
