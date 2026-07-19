<?php

declare(strict_types=1);

namespace Piwigo\Controller;

/**
 * Bridges a legacy render body (still Smarty, still echoing directly via
 * Template::pparse()/include page_header.php/page_tail.php) into the
 * string a real PSR-7 Response body needs, without retrofitting every
 * P17-20 renderer (PageHeaderRenderer/PageTailRenderer/MenubarRenderer,
 * all still `void` and echoing) to return one instead.
 *
 * Several closures passed to capture() (PopuphelpController's and
 * AdminPopuphelpController's `?page=` validation, Picture\
 * PictureCommentRenderer::render()'s two reject paths, confirmed during
 * the Phase 1k die()/exit() audit) deliberately call die()/exit() from
 * *inside* the closure rather than throwing. die()/exit() skip the
 * try/finally below entirely, so ob_end_clean() never runs -- but PHP
 * flushes any still-open output buffer on exit()/die() by default, so
 * the response body still ends up being [partial legacy HTML echoed so
 * far] + [the die() message], reproducing the original bare-script
 * include's own partial-output-then-die() behavior verbatim rather than
 * silently swallowing it. Not a bug: matches each of those controllers'
 * own already-documented reasoning for the same pattern.
 */
final class LegacyRenderCapture
{
    public static function capture(callable $render): string
    {
        ob_start();
        try {
            $render();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
