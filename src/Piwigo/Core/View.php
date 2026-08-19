<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Marker interface for a typed template view: a `final readonly class
 * FooView implements View` whose public properties are exactly the
 * variables its `#[Template]`-declared `.latte` file renders against.
 * No `toArray()` -- `Renderer`/`Template::renderView()` pass the object
 * straight through `get_object_vars()`, replacing
 * `TemplatePageContext`'s hand-written unwrap step entirely.
 */
interface View {}
