<?php

declare(strict_types=1);

namespace Piwigo\Controller;

/**
 * Bridges a legacy render body (still Smarty, still echoing directly via
 * Template::pparse()/include page_header.php/page_tail.php) into the
 * string a real PSR-7 Response body needs, without retrofitting every
 * P17-20 renderer (PageHeaderRenderer/PageTailRenderer/MenubarRenderer,
 * all still `void` and echoing) to return one instead. Output buffering
 * is the correct bridge here, not a workaround -- none of those renderers
 * call exit()/header() themselves (confirmed for page_header.php/
 * page_tail.php via direct read), so nothing terminates mid-buffer.
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
