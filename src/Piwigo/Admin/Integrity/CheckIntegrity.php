<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Integrity;

use Closure;
use LogicException;
use Piwigo\Admin\AdminUiHelper;
use Piwigo\Admin\Integrity\Event\ListCheckIntegrity;
use Piwigo\Admin\Integrity\Projection\AnomalyDisplayRow;
use Piwigo\Admin\Integrity\Projection\AnomalyRow;
use Piwigo\Admin\Integrity\Projection\CheckIntegrityResult;
use Piwigo\Admin\Integrity\Request\C13yTreatmentRequest;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\PageState;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use ReflectionFunction;

final class CheckIntegrity
{
    /**
     * Now backed by a real typed column (see $repo) -- no more risk of an
     * unknown/corrupted blob shape, always a real list<string> of
     * addAnomaly()-generated md5 ids for the current AppInfo::VERSION.
     *
     * @var list<string>
     */
    public array $ignore_list;

    /**
     * Every element is built exclusively by addAnomaly() below; check()
     * mutates an already-built row's own $corrected/$ignored in place.
     *
     * @var list<AnomalyRow>
     */
    public array $retrieve_list;

    /**
     * @var list<string> addAnomaly()-generated md5 ids
     */
    public array $build_ignore_list;

    /**
     * @param IntegrityIgnoredAnomalyRepository $repo persists per-(anomaly,
     *   version) ignore rows -- replaces the former `c13y_ignore` config
     *   blob entirely
     */
    public function __construct(
        private readonly Lang $lang,
        private readonly IntegrityIgnoredAnomalyRepository $repo,
        private readonly Translator $translator,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly LayoutState $layoutState,
    ) {
        $this->ignore_list = [];
        $this->retrieve_list = [];
        $this->build_ignore_list = [];
    }

    /**
     * Check integrities
     */
    public function check(): void
    {
        // Ignore list -- always the real, current-version ignore set now
        // (no more all-or-nothing blob-version gate: a version bump simply
        // means no rows exist yet for that version, same observable
        // "everything comes back un-ignored" behavior as before).
        $this->ignore_list = $this->repo->findIgnoredAnomalyIdsForVersion(AppInfo::VERSION);

        // Retrieve list
        $this->retrieve_list = [];
        $this->build_ignore_list = [];

        $this->dispatchListCheckIntegrity();

        // Information
        if (count($this->retrieve_list) > 0) {
            /**
             * @psalm-suppress NoValue dispatchListCheckIntegrity()'s own
             *   docblock already documents the root cause for PHPStan: a
             *   registered event handler may call back into this same
             *   instance and mutate $retrieve_list via addAnomaly(),
             *   invisible to static analysis through the dispatch() call.
             *   Psalm's flow analysis is stricter here than PHPStan's and
             *   still collapses this count() (already inside the
             *   `if (count(...) > 0)` body above) to an impossible/never
             *   type -- not dead code, this line executes on every real
             *   anomaly-detected request.
             */
            $this->layoutState->addHeaderNote($this->translator->plural(
                '%d anomaly has been detected.',
                '%d anomalies have been detected.',
                count($this->retrieve_list)
            ));
        }

        // Treatments
        $c13yTreatment = C13yTreatmentRequest::fromGlobals();
        if ($c13yTreatment->mode === 'correction') {
            $c13y_selection = $c13yTreatment->selection;
            $corrected_count = 0;
            $not_corrected_count = 0;

            foreach ($this->retrieve_list as $c13y) {
                if ($c13y->correctionFct !== null and
                    $c13y->isCallable and
                    in_array($c13y->id, $c13y_selection, true)) {
                    $args = $c13y->correctionFctArgs ?? [];
                    $correction_fct = $c13y->correctionFct;
                    if (is_callable($correction_fct)) {
                        $c13y->corrected = (bool) call_user_func_array($correction_fct, $args);

                        if ($c13y->corrected) {
                            ++$corrected_count;
                        } else {
                            ++$not_corrected_count;
                        }
                    }
                }
            }

            if ($corrected_count > 0) {
                $this->pageState->addInfo($this->translator->plural(
                    '%d anomaly has been corrected.',
                    '%d anomalies have been detected corrected.',
                    $corrected_count
                ));
            }
            if ($not_corrected_count > 0) {
                $this->pageState->addError($this->translator->plural(
                    '%d anomaly has not been corrected.',
                    '%d anomalies have not been corrected.',
                    $not_corrected_count
                ));
            }
        } else {
            if ($c13yTreatment->mode === 'ignore') {
                $c13y_selection = $c13yTreatment->selection;
                $ignored_count = 0;

                foreach ($this->retrieve_list as $c13y) {
                    if (in_array($c13y->id, $c13y_selection, true)) {
                        $this->build_ignore_list[] = $c13y->id;
                        $c13y->ignored = true;
                        ++$ignored_count;
                    }
                }

                if ($ignored_count > 0) {
                    $this->pageState->addInfo($this->translator->plural(
                        '%d anomaly has been ignored.',
                        '%d anomalies have been ignored.',
                        $ignored_count
                    ));
                }
            }
        }

        // Both sides are already real list<string> (repo-loaded/
        // addAnomaly()-built respectively) -- no more untyped-blob
        // filtering needed before diffing them. Parenthesized explicitly:
        // `$x = a or b;` would assign only `a` (`=` binds tighter than
        // `or`), silently discarding the `b` side -- a real, easy-to-miss
        // precedence trap for exactly this "= ... or ..." shape.
        $ignore_list_changed = (
            array_diff($this->ignore_list, $this->build_ignore_list) !== []
            or array_diff($this->build_ignore_list, $this->ignore_list) !== []
        );

        if ($ignore_list_changed) {
            $this->updateConf($this->build_ignore_list);
        }
    }

    /**
     * Dispatched as its own method (rather than inline in check()) so
     * PHPStan sees a call on $this and re-widens $retrieve_list /
     * $build_ignore_list afterwards -- it can't otherwise tell that a
     * registered handler may call $event->value->addAnomaly() back into
     * this same instance through the dispatched event.
     */
    private function dispatchListCheckIntegrity(): void
    {
        $this->eventDispatcher->dispatch(new ListCheckIntegrity($this));
    }

    /**
     * Display anomalies list
     */
    public function display(): ?CheckIntegrityResult
    {
        $check_automatic_correction = false;
        $submit_automatic_correction = false;
        $submit_ignore = false;

        if (count($this->retrieve_list) > 0) {
            $c13y_do_check = null;
            $c13y_list = [];
            foreach ($this->retrieve_list as $c13y) {
                $can_select = false;
                $show_ignore_msg = false;
                $show_correction_success_fct = false;
                $correction_error_fct = '';
                $show_correction_fct = false;
                $show_correction_bad_fct = false;
                $correction_msg = '';

                if ($c13y->ignored !== null) {
                    if ($c13y->ignored) {
                        $show_ignore_msg = true;
                    } else {
                        throw new LogicException('$c13y->ignored cannot be false');
                    }
                } else {
                    if ($c13y->correctionFct !== null) {
                        if ($c13y->corrected !== null) {
                            if ($c13y->corrected) {
                                $show_correction_success_fct = true;
                            } else {
                                $correction_error_fct = $this->getHtlmLinksMoreInfo();
                            }
                        } elseif ($c13y->isCallable) {
                            $show_correction_fct = true;
                            $c13y_do_check ??= [];
                            $c13y_do_check[] = $c13y->id;
                            $submit_automatic_correction = true;
                            $can_select = true;
                        } else {
                            $show_correction_bad_fct = true;
                            $can_select = true;
                        }
                    } else {
                        $can_select = true;
                    }

                    if (! in_array($c13y->correctionMsg, [null, '0', ''], true) and $c13y->corrected === null) {
                        $correction_msg = $c13y->correctionMsg;
                    }
                }

                if ($can_select) {
                    $submit_ignore = true;
                }

                $c13y_list[] = new AnomalyDisplayRow(
                    id: $c13y->id,
                    anomaly: $c13y->anomaly,
                    showIgnoreMsg: $show_ignore_msg,
                    showCorrectionSuccessFct: $show_correction_success_fct,
                    correctionErrorFct: $correction_error_fct,
                    showCorrectionFct: $show_correction_fct,
                    showCorrectionBadFct: $show_correction_bad_fct,
                    correctionMsg: $correction_msg,
                    canSelect: $can_select,
                );
            }

            return new CheckIntegrityResult(
                showSubmitAutomaticCorrection: $submit_automatic_correction,
                showSubmitIgnore: $submit_ignore,
                c13yList: array_map(static fn (AnomalyDisplayRow $row): array => $row->toArray(), $c13y_list),
                c13yDoCheck: $c13y_do_check,
            );
        }

        return null;
    }

    /**
     * Add anomaly data.
     *
     * $correction_fct accepts either a bare global function name (e.g.
     * tests pass 'strlen', or a deliberately non-existent name to prove
     * is_callable is false -- a plain string type never gets validated
     * against real function existence, unlike PHP's own `callable` type,
     * which would reject a bad name outright instead of gracefully
     * recording an anomaly with a broken correction reference), or a
     * bound instance method via first-class-callable syntax (e.g.
     * C13yInternal::c13y_user() passes $this->c13y_correction_user(...)).
     * The id hash is derived from a stable label (the plain string as-is,
     * or the Closure's bare method name via Reflection) rather than the
     * value itself, since a Closure can't be string-concatenated -- for
     * every value already in use, the label equals the exact string that
     * used to be concatenated directly, so existing ignore-list ids are
     * unaffected.
     *
     * @param ?array<string, mixed> $correction_fct_args
     */
    public function addAnomaly(string $anomaly, string|Closure|null $correction_fct = null, ?array $correction_fct_args = null, ?string $correction_msg = null): void
    {
        $correctionFctLabel = match (true) {
            $correction_fct === null => null,
            is_string($correction_fct) => $correction_fct,
            default => new ReflectionFunction($correction_fct)
                ->getName(),
        };
        $id = md5($anomaly . ($correctionFctLabel ?? '') . serialize($correction_fct_args) . ($correction_msg ?? ''));

        if (in_array($id, array_map(strval(...), array_filter($this->ignore_list, is_scalar(...))), true)) {
            $this->build_ignore_list[] = $id;
        } else {
            $this->retrieve_list[] = new AnomalyRow(
                id: $id,
                anomaly: $anomaly,
                correctionFct: $correction_fct,
                correctionFctArgs: $correction_fct_args,
                correctionMsg: $correction_msg,
                isCallable: is_callable($correction_fct),
            );
        }
    }

    /**
     * Update table config
     *
     * @param list<string> $conf_ignore_list addAnomaly()-generated md5 ids
     */
    /**
     * @param list<string> $conf_ignore_list
     */
    public function updateConf(array $conf_ignore_list = []): void
    {
        $this->repo->syncForVersion(
            AppInfo::VERSION,
            $conf_ignore_list,
            Env::now()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Apply maintenance
     */
    public function maintenance(): void
    {
        $this->updateConf();
    }

    /**
     * Returns links more informations
     *
     * @return string links
     */
    public function getHtlmLinksMoreInfo(): string
    {
        $pwg_links = AdminUiHelper::pwgUrl();
        $link_fmt = <<<'HTML'
        <a href="%s" onclick="window.open(this.href, ''); return false;">%s</a>
        HTML;
        return
          sprintf(
              $this->lang->t('Go to %s or %s for more informations'),
              sprintf($link_fmt, $pwg_links['FORUM'], $this->lang->t('the forum')),
              sprintf($link_fmt, $pwg_links['WIKI'], $this->lang->t('the wiki'))
          );
    }
}
