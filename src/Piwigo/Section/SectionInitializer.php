<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;

/**
 * URL token parser: "category/12-name/start-24" -> a structured
 * SectionUrlParse. Ported from the first half of
 * include/section_init.inc.php (root_path/section_url computation,
 * tokenization, the picture-page image-id parsing, and the
 * parse_section_url() call/merge) -- the much larger second half of that
 * file (category/tags/search/favorites/... DB-query building and
 * $page/$template population) stays procedural, P22 (frontend controller
 * migration) scope, same as category_cats.inc.php/search_filters.inc.php.
 *
 * Contains a real bad_request() call (exit-triggering), same established
 * precedent as Html\HtmlService/Page\NoPhotoYetRenderer -- not routed
 * around, since that's the original's real terminal behavior for a
 * malformed picture identifier.
 */
final readonly class SectionInitializer
{
    public function __construct(
        private HtmlRenderingInterface $htmlRenderer,
        private SectionRepository $repo,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
    ) {}

    public function parse(): SectionUrlParse
    {
        // some ISPs set PATH_INFO to empty string or to SCRIPT_FILENAME while in the
        // default apache implementation it is not set
        if (\Piwigo\Config\Config::questionMarkInUrls() === false and
             isset($_SERVER['PATH_INFO']) and $_SERVER['PATH_INFO'] !== '') {
            $rewritten = $_SERVER['PATH_INFO'];
            // $_SERVER values are typed mixed by PHPStan (PATH_INFO is a string in
            // practice, but the superglobal's declared value type doesn't say so)
            $rewritten = is_string($rewritten) ? $rewritten : '';
            $rewritten = str_replace('//', '/', $rewritten);
            $path_count = count(explode('/', $rewritten));
            $root_path = PHPWG_ROOT_PATH . str_repeat('../', $path_count - 1);
        } else {
            $rewritten = '';
            foreach (array_keys($_GET) as $key) {
                // PHP auto-casts a purely-numeric query-string key (e.g.
                // "?1") to a real int array key -- a bare numeric token
                // (no id-name suffix) crashed the original mysqli-based
                // escaping call with a TypeError (?string required), found
                // live via picture.php?1. Cast back to the string this
                // variable was always meant to hold.
                $rewritten = (string) $key;
                break;
            }

            // the $_GET keys are not protected in include/common.inc.php, only the values
            $rewritten = $this->repo->escapeToken($rewritten);
            $root_path = PHPWG_ROOT_PATH;
        }

        if (str_starts_with($root_path, './')) {
            $root_path = substr($root_path, 2);
        }

        $section_url = $rewritten;

        // deleting first "/" if displayed
        $tokens = explode('/', ltrim($rewritten, '/'));
        // $tokens = array(
        //   0 => category,
        //   1 => 12-foo,
        //   2 => start-24
        //   );

        $next_token = 0;

        $image_id = null;
        $image_file = null;

        // +-----------------------------------------------------------------------+
        // |                             picture page                              |
        // +-----------------------------------------------------------------------+
        // the first token must be the identifier for the picture
        if (\Piwigo\Core\PageFilterHelper::scriptBasename() === 'picture') {
            $token = $tokens[$next_token];
            $next_token++;
            if (is_numeric($token)) {
                $image_id = $token;
                if ((int) $image_id === 0) {
                    $this->htmlRenderer->badRequest($this->redirectService, 'invalid picture identifier');
                }
            } else {
                preg_match('/^(\d+-)?(.*)?$/', $token, $matches);
                $match_2 = $matches[2] ?? null;
                if (isset($matches[1]) and is_numeric($matches[1] = rtrim($matches[1], '-'))) {
                    $image_id = $matches[1];
                    if (! self::emptyValue($match_2)) {
                        $image_file = $match_2;
                    }
                } else {
                    $image_id = 0; // more work in picture.php
                    if (! self::emptyValue($match_2)) {
                        $image_file = $match_2;
                    } else {
                        $this->htmlRenderer->badRequest($this->redirectService, 'picture identifier is missing');
                    }
                }
            }
        }

        $parsed = $this->urlService->parseSectionUrl($tokens, $next_token, $this->redirectService);

        return new SectionUrlParse($root_path, $section_url, $tokens, $next_token, $image_id, $image_file, $parsed);
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}
