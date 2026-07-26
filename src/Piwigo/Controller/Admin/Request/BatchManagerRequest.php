<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST`/`$_REQUEST` shape for
 * BatchManagerSubController::handle()/handleGetActions()/
 * resolveSessionFilter() (page slug "batch_manager") -- P27/SEC-40
 * Request DTO.
 *
 * `start`/`tab` resolve directly to their final, default-applied value:
 * both computations are pure and self-contained (no controller-local
 * data involved), unlike e.g. `page`/`action`/`nbOrphansDeleted`/
 * `nbMd5sumAdded`, which stay as parsed-but-unbranched fields since the
 * real branching (CSRF check, DB delete, session message, redirect)
 * is business logic that belongs in `handleGetActions()`, not here.
 *
 * `start` folds in `resolveSessionFilter()`'s own `unset($_REQUEST['start'])`
 * ("new photo set must reset the page", done only when `submitFilter` is
 * posted) directly into the computation instead of relying on the
 * mutation's side effect: the original computed `$start` from
 * `$_REQUEST['start']` strictly AFTER that unset ran, so a submitted
 * filter form always saw start as absent -- `isSubmitFilter` short-
 * circuits `start` to 0 here for the exact same reason, without ever
 * needing to touch the real superglobal.
 *
 * `post` retains the raw `$_POST` array -- `resolveSessionFilter()`'s own
 * "filters from form" branch reads ~20 individually-named `filter_*`
 * fields (each gating its own session-array assignment), too many for a
 * one-to-one typed property set to be worth it; same "expose the raw
 * bag" shape as `ConfigurationRequest`/`BatchManagerGlobalRequest`.
 * Nothing in this branch mutates `$_POST` itself (only `$_SESSION`), so
 * no local working-copy is needed the way those 2 siblings needed one.
 *
 * `urlFilterTokens` replaces the original's own
 * `if (! is_array($_GET['filter'])) { $_GET['filter'] = explode(',',
 * ...); }` in-place mutation (done so the immediately-following foreach
 * always sees a real array) with an eagerly-normalized `list<string>` --
 * behaviorally identical (`is_scalar($filter) ? (string) $filter : ''`
 * is applied to every element here instead of inside that loop), without
 * touching the real superglobal.
 */
final readonly class BatchManagerRequest
{
    /**
     * @param array<int|string, mixed> $post
     * @param list<string> $urlFilterTokens
     */
    private function __construct(
        public string $page,
        public int $start,
        public string $tab,
        public ?string $action,
        public ?int $nbOrphansDeleted,
        public ?int $nbMd5sumAdded,
        public bool $isSubmitFilter,
        public array $post,
        public bool $urlFilterPresent,
        public array $urlFilterTokens,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST, $_REQUEST);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     * @param array<int|string, mixed> $request
     */
    public static function fromArrays(array $get, array $post, array $request): self
    {
        new InputValidator()
            ->validate('selection', $post, true, ValidationPattern::ID);
        new InputValidator()
            ->validate('display', $request, false, '/^(\d+|all)$/');

        $page_raw = $get['page'] ?? null;
        $page = is_string($page_raw) ? $page_raw : '';

        $is_submit_filter = isset($post['submitFilter']);

        if ($is_submit_filter
            or ! isset($request['start'])
            or ! is_numeric($request['start'])
            or $request['start'] < 0
            or (isset($request['display']) and $request['display'] === 'all')) {
            $start = 0;
        } else {
            $start = (int) $request['start'];
        }

        $tab = 'global';
        if (isset($get['mode'])) {
            new InputValidator()
                ->validate('mode', $get, false, '/^(global|unit)$/');
            $tab = is_string($get['mode']) ? $get['mode'] : 'global';
        }

        $action_raw = $get['action'] ?? null;
        $action = is_string($action_raw) ? $action_raw : null;

        $nb_orphans_deleted = null;
        if ($action === 'delete_orphans' && isset($get['nb_orphans_deleted'])) {
            new InputValidator()
                ->validate('nb_orphans_deleted', $get, false, '/^\d+$/');
            $nb_orphans_deleted_raw = $get['nb_orphans_deleted'] ?? null;
            $nb_orphans_deleted = is_numeric($nb_orphans_deleted_raw) ? (int) $nb_orphans_deleted_raw : 0;
        }

        $nb_md5sum_added = null;
        if ($action === 'sync_md5sum' && isset($get['nb_md5sum_added'])) {
            new InputValidator()
                ->validate('nb_md5sum_added', $get, false, '/^\d+$/');
            $nb_md5sum_added_raw = $get['nb_md5sum_added'] ?? null;
            $nb_md5sum_added = is_numeric($nb_md5sum_added_raw) ? (int) $nb_md5sum_added_raw : 0;
        }

        $url_filter_present = isset($get['filter']);
        $url_filter_raw = $get['filter'] ?? null;
        $url_filter_tokens = [];
        if ($url_filter_present) {
            if (is_array($url_filter_raw)) {
                foreach ($url_filter_raw as $token) {
                    $url_filter_tokens[] = is_scalar($token) ? (string) $token : '';
                }
            } else {
                $url_filter_tokens = explode(',', is_scalar($url_filter_raw) ? (string) $url_filter_raw : '');
            }
        }

        return new self(
            $page,
            $start,
            $tab,
            $action,
            $nb_orphans_deleted,
            $nb_md5sum_added,
            $is_submit_filter,
            $post,
            $url_filter_present,
            $url_filter_tokens,
        );
    }
}
