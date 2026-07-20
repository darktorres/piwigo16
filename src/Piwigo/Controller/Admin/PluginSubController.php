<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Html\HtmlService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/plugin.php's own body (page slug "plugin"), folded
 * directly into this controller (P23 sub-batch 6i-3) -- dynamic inclusion
 * of a whitelisted file from within an already-active plugin's own
 * directory (e.g. that plugin's settings page). Doesn't touch the
 * plugins/themes/languages god-classes at all, only $pwg_loaded_plugins (a
 * real, already-established global from Piwigo\Admin\PluginLoader's
 * plugin-loading bootstrap chain, unrelated to this batch's real scope --
 * same usage already exists in BatchManagerUnitPageRenderer). No other real
 * caller of admin/plugin.php exists (confirmed via grep) -- admin.php's own
 * routing already gates this page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the shell's
 * own (redundant) copy of that check is dropped here, same precedent as
 * every prior sub-batch's shell fold.
 *
 * Real bug found and fixed during this port: the original file's
 * empty-segment-filtering loop mutated the array being iterated
 * (`unset($sections[$i]); $i--;`) without ever reindexing it. `unset()`
 * leaves a gap in the integer keys rather than shifting them down, so a
 * `section` value with a middle empty segment (e.g. `foo//bar`, two
 * consecutive slashes) made `$i` permanently point at an already-removed
 * key -- `empty()` on a missing offset returns true without warning, so
 * the loop re-entered the same unset()/$i--/continue branch forever,
 * hanging the PHP worker on every such request. Reproduced in isolation
 * (a 3-line PHP snippet outside the app, `timeout 3 php -r '...'`) before
 * concluding this was a real bug, not a hypothetical one -- confirmed the
 * process never returns. `admin.php?page=plugin&section=x//y` from any
 * Administrator-level (not even webmaster) session would trigger it, so
 * while access-gated, it's still a real, previously-undiscovered
 * self-inflictable denial-of-service. Fixed by filtering empty segments
 * with `array_filter()`/`array_values()` (which reindexes) instead of the
 * manual index-decrement loop -- verified behaviorally equivalent for
 * every non-buggy input (plain segments, a trailing empty segment from a
 * trailing slash, a literal `..` segment) and additionally handles the
 * previously-hanging middle-empty-segment case exactly the way the
 * original code's own intent (skip empty segments) already implied it
 * should.
 */
final class PluginSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $section_param = $_GET['section'] ?? '';
        $sections = array_values(array_filter(
            explode('/', is_string($section_param) ? $section_param : ''),
            static fn (string $segment): bool => $segment !== '',
        ));

        foreach ($sections as $section) {
            if ($section === '..' or ! (bool) preg_match('/^[a-zA-Z0-9_\.-]+$/', $section)) {
                new HtmlService()
                    ->fatalError('invalid section token [' . htmlentities($section) . ']');
            }
        }

        if (count($sections) < 2) {
            new HtmlService()
                ->fatalError('Invalid plugin URL');
        }

        $plugin_id = $sections[0];

        if (! (bool) preg_match('/^[\w-]+$/', $plugin_id)) {
            new HtmlService()
                ->fatalError('Invalid plugin identifier');
        }

        if (! isset(\Piwigo\Admin\LoadedPlugins::get()[$plugin_id])) {
            new HtmlService()
                ->fatalError('Invalid URL - plugin ' . $plugin_id . ' not active');
        }

        $filename = \Piwigo\Admin\PluginLoader::pluginsPath() . implode('/', $sections);
        if (is_file($filename)) {
            include_once $filename;
        } else {
            new HtmlService()
                ->fatalError('Missing file ' . htmlentities($filename));
        }
    }
}
