# Extension points: a unified "ranked, multi-contributor template slot" primitive

> **SUPERSEDED (2026-08-15) by `docs/PLAN.md`'s Epoch K, phase P49.**
>
> The **diagnosis below stands**: two mechanisms
> (`addIndexButton`/`parseIndexButtons` and `concat('PLUGIN_INDEX_ACTIONS')`)
> for one need, on the same page, is not a design distinction — it is what
> happens when each need is solved locally. So does the observation that
> Latte needs no two-phase push model.
>
> The **proposed remedy is replaced**. A field survey of the 433 plugins and
> 188 themes in the sibling repos found the real demand is far larger than the
> 29 `add_*_button` uses this document scopes to: **122 of 433 plugins (28%)
> use `set_prefilter`, across 211 distinct callbacks**, and that demand
> resolves into a *finite* list of contribution kinds (admin form field ~32,
> picture info row ~21, profile/register field ~15, auth buttons ~13,
> thumbnail overlay ~9, picture action ~8, menu item ~6, …).
>
> Because the kinds are finite, P49 makes contributions **typed value objects**
> rather than string-keyed slots carrying raw HTML — so **the type determines
> the destination** and there is no point name to pass. That structurally
> eliminates the exact risk this document flags against itself under "What this
> replaces": *"a wrong **string** point name silently creates a disconnected
> point that never renders."* There is no string. A wrong kind is a type error;
> a wrong target is an invalid enum case. The `"theme_row_actions:{$theme->id}"`
> composed-key pattern below becomes a typed `themeId` field.
>
> Kept as-is for its reasoning and its record of the original problem.

Status: proposed, not yet implemented. Written while designing P27.6
(porting the last 6 bundled plugins/themes) after noticing the pattern
that need kept running into wasn't actually served well by anything
currently in `Template.php`.

## The problem

Several P27.6 targets need the same underlying thing: **let more than one
independent extension contribute ranked content to a named spot in a
page it doesn't own, rendered as a collection.** AdminTools needs a slot
in the admin header for its toolbar anchor. LocalFilesEditor needs a slot
per row of the admin themes list for a "Customize CSS" link. TakeATour
needs the same per-row slot for a highlight marker. None of these are
"a controller's own typed page data" (`TemplatePageContext` already
handles that correctly) — they're "arbitrary third parties contribute
here," a different shape of problem.

The codebase already has two mechanisms that both partially serve this
need, independently, for the same page:

- `Template::addIndexButton()`/`addPictureButton()` +
  `parseIndexButtons()`/`parsePictureButtons()` — a ranked collector,
  flushed into a named var (`PLUGIN_INDEX_BUTTONS`/`PLUGIN_PICTURE_BUTTONS`)
  by an explicit controller-side call right before the template renders:

  ```
  GalleryController.php:611   $template->parseIndexButtons();
                               $template->parse('index.latte', false);
  PictureController.php:1265  $template->parsePictureButtons();
                               $template->parse($pictureTemplateFile, false);
  ```

  `addIndexButton()`/`addPictureButton()` themselves have zero real
  callers today — the flush half is wired and used, the contribute half
  isn't yet.

- `Template::concat()` — a plain string-append onto a named var, used by
  the one real shipped example, `language_switch`'s
  `Plugin::onIndexEnd()`, listening to `Event\Location\LocEndIndex`
  (dispatched at `GalleryController.php:608`, immediately before the
  `parseIndexButtons()`/`parse()` pair above) and calling
  `$template->concat('PLUGIN_INDEX_ACTIONS', $html)`.

Two different mechanisms, two different var names (`PLUGIN_INDEX_BUTTONS`
vs `PLUGIN_INDEX_ACTIONS`), on the *same page*, for what is structurally
the same kind of need. That's not a deliberate design distinction — it's
what happens when each need gets solved locally instead of once.

## Why the two-phase push model is unnecessary in Latte

The `addX()`/`parseX()` split exists because Smarty's rendering model is
"assign values into template scope, then render — the template can only
read what was already assigned." A controller has to remember to call
the flush step at exactly the right moment before `parse()`.

Latte doesn't have that constraint. Templates already call live PHP
functions directly, at the exact point they're needed —
`Template.php`/`PiwigoExtension.php` already establish this idiom
throughout (`{=getCombinedScripts()}`, `{do combineScript(...)}`,
`{=once('key')}`). A named-slot registry doesn't need a "flush" step at
all if the template just calls a live getter function directly; whatever
has been contributed by that point in the request is what renders. The
`parseIndexButtons()`/`parsePictureButtons()` calls, and their two
controller call sites, are a Smarty-shaped vestige, not something Latte
requires.

## Proposed design

One small class, composed by `Template` (kept separate from `Template`
itself — a plain collector with no rendering/escaping concerns of its
own, versus `Template`'s already-large surface):

```php
namespace Piwigo\Template;

final class ExtensionPoints
{
    /** @var array<string, array<int, list<string>>> */
    private array $items = [];

    public function add(string $point, string $content, int $rank = 50): void
    {
        $this->items[$point][$rank][] = $content;
    }

    /** @return list<string> */
    public function get(string $point): array
    {
        if (! isset($this->items[$point])) {
            return [];
        }
        $group = $this->items[$point];
        ksort($group);
        return array_merge(...$group);
    }
}
```

Registered as a live Latte function in `PiwigoExtension::getFunctions()`,
same shape as every other stateful function already there:

```php
'extensionPoint' => $this->extensionPoints->get(...),
```

Templates call it directly, keeping full control of per-item wrapping
markup (index.latte wraps each item in `<li>`, picture.latte doesn't —
that stays a template concern, not baked into contributors):

```latte
{foreach extensionPoint('index_buttons') as $button}<li>{$button|noescape}</li>{/foreach}
```

Contributors call `Template::addToExtensionPoint()` (or the
`ExtensionPoints` instance directly) from an event listener — no
`ExtensionContext` wrapper needed, matching how `concat()`/`parse()` are
already called directly via `$context->template()` today:

```php
$context->template()->addToExtensionPoint('index_buttons', $html);
```

A dynamic, string-composed point name covers the per-row case without a
bespoke event class:

```php
foreach ($context->themes()->listBasic() as $theme) {
    if ($theme->id === $myThemeId) {
        $context->template()->addToExtensionPoint("theme_row_actions:{$theme->id}", $link);
    }
}
```

with the template calling it once per row:

```latte
{foreach $themes as $theme}
  ...
  {foreach extensionPoint('theme_row_actions:' . $theme['ID']) as $action}{$action|noescape}{/foreach}
{/foreach}
```

## What this replaces

- `addIndexButton()`/`addPictureButton()`/`parseIndexButtons()`/
  `parsePictureButtons()` (4 methods, 2 duplicated array properties) →
  become thin, named, type-safe wrappers over `addToExtensionPoint()`
  (kept for discoverability/typo-safety at PHP call sites — a wrong
  *method* name is caught by static analysis, a wrong *string* point
  name silently creates a disconnected point that never renders). The
  `parseIndexButtons()`/`parsePictureButtons()` controller-side calls and
  their `PLUGIN_INDEX_BUTTONS`/`PLUGIN_PICTURE_BUTTONS` var assignments
  go away entirely — the template pulls live instead.
- The P27.6-planned `Template::addAdminHeaderItem()` +
  `parseAdminHeaderItems()` → not needed as bespoke methods;
  `addToExtensionPoint('admin_header', ...)` covers it.
- The P27.6-planned bespoke `ThemeRowActions` event → not needed;
  `addToExtensionPoint("theme_row_actions:{$themeId}", ...)` covers it
  with a plain string-keyed point instead of a new event class.

## What this does *not* touch

Three other extension-integration primitives already in the codebase
were checked against the same "would a from-scratch design do this
differently" question and hold up:

- **`Core\TemplatePageContext` + `Template::assignContext()`** — a
  controller's (or an extension's) own typed data, assigned once,
  flattened to Latte's string-keyed shape only at the boundary
  (`toArray()`). Genuinely well-designed already; no change proposed.
- **`Template::concat()`/`parse()`** — "render my own complete blob and
  append it to output." Simple need, already simply solved; no change
  proposed. (`concat()`'s one real current use, language_switch's
  `PLUGIN_INDEX_ACTIONS`, is a candidate to migrate onto the new
  mechanism for consistency — see "Open question" below — but `concat()`
  itself stays as a general string-append primitive regardless.)
- **Mutable `EventDispatcher::dispatchChange()` events**
  (`GetIndexDerivativeParams`, `RenderElementContent`, etc.) — "let a
  listener override a computed value." Already a clean, typed,
  PSR-14-shaped pattern; no change proposed.

## Open question: migrate the existing (shipped) call sites too?

Two already-working, already-shipped things currently do part of this
job the old way:

1. `addIndexButton()`/`addPictureButton()` — unused today (zero real
   callers), so migrating their internals is a same-behavior refactor,
   not a behavior change to any real caller.
2. `language_switch`'s `concat('PLUGIN_INDEX_ACTIONS', ...)` — a real,
   shipped, working call site. Migrating it to
   `addToExtensionPoint('index_actions', ...)` would fully unify the
   index page onto one mechanism instead of two parallel ones, but it's
   churn on working code for consistency's sake, not a bug fix.

Not decided here — see the P27.6 plan
(`~/.claude/plans/lets-design-p27-eager-ripple.md`) for how this applies
to that campaign specifically.
