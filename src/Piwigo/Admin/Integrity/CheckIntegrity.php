<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity;

use Piwigo\Core\Kernel;
use Latte\Runtime\Html;
use Piwigo\Admin\AdminService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Lang\Translator;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;

final class CheckIntegrity
{
    /** @var array<string> */
    public array $ignore_list = [];
    /** @var array<int, array<string, mixed>> */
    public array $retrieve_list = [];
    /** @var array<string> */
    public array $build_ignore_list = [];

    public function __construct()
    {
        $this->ignore_list = [];
        $this->retrieve_list = [];
        $this->build_ignore_list = [];
    }

    /**
     * Check integrities
     *

     */
    public function check(): void
    {
        // Ignore list
        $conf_c13y_ignore = unserialize(Config::c13yIgnore() ?? '');
        if (
            is_array($conf_c13y_ignore) and
            isset($conf_c13y_ignore['version']) and
            ($conf_c13y_ignore['version'] == AppInfo::VERSION) and
            is_array($conf_c13y_ignore['list'])
        ) {
            $ignore_list_changed = false;
            $this->ignore_list = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $conf_c13y_ignore['list']);
        } else {
            $ignore_list_changed = true;
            $this->ignore_list = [];
        }

        // Retrieve list
        $this->retrieve_list = [];
        $this->build_ignore_list = [];

        EventDispatcher::notify('list_check_integrity', $this);

        // Information
        /** @var array<mixed> $retrieveList */
        $retrieveList = $this->retrieve_list;
        $anomalyCount = count($retrieveList);
        if ($anomalyCount > 0) {
            if (!isset($GLOBALS['header_notes']) || !is_array($GLOBALS['header_notes'])) {
                $GLOBALS['header_notes'] = [];
            }
            /** @psalm-suppress NoValue -- Psalm over-narrows through $GLOBALS array assignment */
            $GLOBALS['header_notes'][] = Translator::get()->plural(
                '%d anomaly has been detected.',
                '%d anomalies have been detected.',
                $anomalyCount
            );
        }

        // Treatments
        /** @var array<mixed> $c13y_selection_post */
        $c13y_selection_post = is_array($_POST['c13y_selection'] ?? null) ? $_POST['c13y_selection'] : [];
        if (isset($_POST['c13y_submit_correction']) and isset($_POST['c13y_selection'])) {
            $corrected_count = 0;
            $not_corrected_count = 0;

            foreach ($this->retrieve_list as $i => $c13y) {
                if (!empty($c13y['correction_fct']) and
                    $c13y['is_callable'] and
                    in_array($c13y['id'], $c13y_selection_post)) {
                    if (is_array($c13y['correction_fct_args'])) {
                        $args = $c13y['correction_fct_args'];
                    } elseif (!is_null($c13y['correction_fct_args'])) {
                        $args = [$c13y['correction_fct_args']];
                    } else {
                        $args = [];
                    }
                    $fct = $c13y['correction_fct'];
                    $this->retrieve_list[$i]['corrected'] = is_callable($fct) ? call_user_func_array($fct, $args) : false;

                    if ($this->retrieve_list[$i]['corrected'] !== false && $this->retrieve_list[$i]['corrected'] !== null && $this->retrieve_list[$i]['corrected'] !== '' && $this->retrieve_list[$i]['corrected'] !== 0) {
                        $corrected_count += 1;
                    } else {
                        $not_corrected_count += 1;
                    }
                }
            }

            if ($corrected_count > 0) {
                PageState::current()->addInfo(Translator::get()->plural(
                    '%d anomaly has been corrected.',
                    '%d anomalies have been detected corrected.',
                    $corrected_count
                ));
            }
            if ($not_corrected_count > 0) {
                PageState::current()->addError(Translator::get()->plural(
                    '%d anomaly has not been corrected.',
                    '%d anomalies have not been corrected.',
                    $not_corrected_count
                ));
            }
        } else {
            if (isset($_POST['c13y_submit_ignore']) and isset($_POST['c13y_selection'])) {
                $ignored_count = 0;

                foreach ($this->retrieve_list as $i => $c13y) {
                    if (in_array($c13y['id'], $c13y_selection_post)) {
                        $this->build_ignore_list[] = is_string($c13y['id']) ? $c13y['id'] : '';
                        $this->retrieve_list[$i]['ignored'] = true;
                        $ignored_count += 1;
                    }
                }

                if ($ignored_count > 0) {
                    PageState::current()->addInfo(Translator::get()->plural(
                        '%d anomaly has been ignored.',
                        '%d anomalies have been ignored.',
                        $ignored_count
                    ));
                }
            }
        }

        $ignore_list_changed =
          (
              ($ignore_list_changed) or
        (count(array_diff($this->ignore_list, $this->build_ignore_list)) > 0) or
        (count(array_diff($this->build_ignore_list, $this->ignore_list)) > 0)
          );

        if ($ignore_list_changed) {
            $this->updateConf($this->build_ignore_list);
        }
    }

    /**
     * Display anomalies list
     *

     */
    public function display(): void
    {
        $template = TemplateRegistry::current();

        $check_automatic_correction = false;
        $submit_automatic_correction = false;
        $submit_ignore = false;

        if (count($this->retrieve_list) > 0) {
            foreach ($this->retrieve_list as $i => $c13y) {
                $can_select = false;
                $c13y_display = [
                   'id' => $c13y['id'],
                   'anomaly' => $c13y['anomaly'],
                   'show_ignore_msg' => false,
                   'show_correction_success_fct' => false,
                   'correction_error_fct' => '',
                   'show_correction_fct' => false,
                   'show_correction_bad_fct' => false,
                   'correction_msg' => '',
                  ];

                if (isset($c13y['ignored'])) {
                    if ($c13y['ignored']) {
                        $c13y_display['show_ignore_msg'] = true;
                    } else {
                        die('$c13y[\'ignored\'] cannot be false');
                    }
                } else {
                    if (!empty($c13y['correction_fct'])) {
                        if (isset($c13y['corrected'])) {
                            if ($c13y['corrected']) {
                                $c13y_display['show_correction_success_fct'] = true;
                            } else {
                                $c13y_display['correction_error_fct'] = new Html($this->getHtlmLinksMoreInfo());
                            }
                        } elseif ($c13y['is_callable']) {
                            $c13y_display['show_correction_fct'] = true;
                            $rawId = $c13y['id'] ?? null;
                            $template->append('c13y_do_check', is_string($rawId) ? $rawId : (is_int($rawId) ? (string) $rawId : ''));
                            $submit_automatic_correction = true;
                            $can_select = true;
                        } else {
                            $c13y_display['show_correction_bad_fct'] = true;
                            $can_select = true;
                        }
                    } else {
                        $can_select = true;
                    }

                    if (!empty($c13y['correction_msg']) and !isset($c13y['corrected'])) {
                        $msg = $c13y['correction_msg'];
                        $c13y_display['correction_msg'] = is_string($msg) ? new Html($msg) : $msg;
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

            $template->concat('ADMIN_CONTENT', (string) $template->parse('check_integrity.latte', true));

        }
    }

    /**
     * Add anomaly data
     *
     *  array
     */
    /** @param array<mixed>|null $correction_fct_args */
    public function addAnomaly(string $anomaly, ?callable $correction_fct = null, ?array $correction_fct_args = null, ?string $correction_msg = null): void
    {
        $id = md5($anomaly.(is_callable($correction_fct) ? serialize($correction_fct) : '').serialize($correction_fct_args).($correction_msg ?? ''));

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
                'is_callable' => is_callable($correction_fct)];
        }
    }

    /**
     * Update table config
     *
     *  string[]  array
     */
    /** @param array<mixed> $conf_ignore_list */
    public function updateConf(array $conf_ignore_list = []): void
    {
        $conf_c13y_ignore =  [];
        $conf_c13y_ignore['version'] = AppInfo::VERSION;
        $conf_c13y_ignore['list'] = $conf_ignore_list;
        Kernel::service(ConfigService::class)->confUpdateParam('c13y_ignore', serialize($conf_c13y_ignore));
    }

    /**
     * Apply maintenance
     *

     */
    public function maintenance(): void
    {
        $this->updateConf();
    }

    /**
     * Returns links more informations
     *

     *  string
     */
    public function getHtlmLinksMoreInfo(): string
    {
        $pwg_links = Kernel::service(AdminService::class)->pwgURL();
        $link_fmt = '<a href="%s" onclick="window.open(this.href, \'\'); return false;">%s</a>';
        return
          sprintf(
              Lang::t('Go to %s or %s for more informations'),
              sprintf($link_fmt, $pwg_links['FORUM'], Lang::t('the forum')),
              sprintf($link_fmt, $pwg_links['WIKI'], Lang::t('the wiki'))
          );
    }

}
