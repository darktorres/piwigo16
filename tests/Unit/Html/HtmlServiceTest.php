<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Html;

use Piwigo\Core\Paths;
use LogicException;
use Piwigo\Url\RootPathOverride;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Error;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use SplFileInfo;
use Piwigo\Users\User;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\UserStatus;
use Piwigo\Http\ResponseReadyException;
use RuntimeException;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\ProcessCache;
use Piwigo\Event\Picture\GetElementUrl;
use Piwigo\Event\Picture\GetThumbnailTitle;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Template\RenderCategoryLiteralDescription;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Event\Template\SetStatusHeader;
use Piwigo\Image\Event\GetSrcImageUrl;
use Piwigo\Html\HtmlService;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\Event\BlockManagerRegisterBlocks;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Tests\Support\CurrentUserTestFactory;

beforeEach(function (): void {
    // ProcessCache/ErrorCollector are both real required constructor
    // collaborators on HtmlService -- HtmlServiceTestFactory::build()
    // resolves both from the real container when one is booted, which
    // needs a real Paths too, not just any booted Kernel: ErrorCollector's
    // own container factory resolves a DeploymentPolicy-dependent
    // instance, whose own factory itself needs Paths bound to autowire.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3)));
    $processCache = Kernel::container()->get(ProcessCache::class);
    if ($processCache instanceof ProcessCache) {
        $processCache->reset();
    }
    CurrentUserTestFactory::get()->reset();
    CurrentTemplate::current()->reset();
    CurrentConfigTestFactory::get()->reset();
});

afterEach(function (): void {
    Kernel::reset();
    CurrentUserTestFactory::get()->reset();
    CurrentTemplate::current()->reset();
    CurrentConfigTestFactory::get()->reset();
});

function htmlServiceTestRenderCommentContent(HtmlService $service, string $content): string
{
    return $service->renderCommentContent(new RenderCommentContent($content))->commentContent;
}

/**
 * Resolves the same container-shared ProcessCache instance
 * HtmlServiceTestFactory::build() injects into HtmlService, so
 * seeding/reading it here observes the same object.
 */
function htmlServiceTestProcessCache(): ProcessCache
{
    $processCache = Kernel::container()->get(ProcessCache::class);
    if (! $processCache instanceof ProcessCache) {
        throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
    }

    return $processCache;
}

/**
 * HtmlService::urlService() resolves UrlServiceInterface (and, via its
 * own constructor, RootPathOverride) live from the container on every
 * call. This resolves the one, real, container-shared RootPathOverride
 * every UrlService construction in this booted Kernel shares.
 */
function htmlServiceTestRootPathOverride(): RootPathOverride
{
    $rootPathOverride = Kernel::container()->get(RootPathOverride::class);
    if (! $rootPathOverride instanceof RootPathOverride) {
        throw new LogicException('Container returned an unexpected type for ' . RootPathOverride::class);
    }

    return $rootPathOverride;
}

test('renderCommentContent escapes html and linkifies bare URLs', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect(htmlServiceTestRenderCommentContent($service, '<script>alert(1)</script> see http://example.test/x'))
        ->toBe('&lt;script&gt;alert(1)&lt;/script&gt; see <a href="http://example.test/x" rel="nofollow">http://example.test/x</a>');
});

test('renderCommentContent underlines _word_', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect(htmlServiceTestRenderCommentContent($service, '_hello_'))
        ->toBe('<span style="text-decoration:underline;">hello</span>');
});

test('renderCommentContent bolds *word* only when a word character directly touches the asterisk', function (): void {
    // '\b\*(\S*)\*\b': '*' is not itself a \w character, so \b only fires
    // where a real word character (letter/digit/underscore) directly
    // touches the asterisk on both sides -- a space (or start/end of
    // string) next to '*' is two non-word characters, no boundary, no
    // match (confirmed empirically): "*bold*" surrounded by spaces is
    // verifiably a silent no-op, only "word*bold*word" (no space)
    // actually triggers it.
    $service = HtmlServiceTestFactory::build();

    expect(htmlServiceTestRenderCommentContent($service, 'a *hello* b'))->toBe('a *hello* b')
        ->and(htmlServiceTestRenderCommentContent($service, 'x*hello*y'))
        ->toBe('x<span style="font-weight:bold;">hello</span>y');
});

test('renderCommentContent converts newlines to br tags', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect(htmlServiceTestRenderCommentContent($service, "a\nb"))->toBe('a<br />' . "\n" . 'b');
});

/**
 * Confirmed-equivalent: lines 284/294/299's RemoveStringCast (dropping
 * the `(string)` cast around each successive preg_replace() result
 * before it's fed back into the next preg_replace() call). Every
 * pattern here is a static, compile-time-fixed, valid regex, and the
 * subject is always a real string -- preg_replace() only returns null
 * on a genuine PCRE error, unreachable for these patterns on any real
 * input. Confirmed live: the full suite in this file passes identically
 * with each cast removed.
 */

test('nameCompare sorts case-insensitively', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect($service->nameCompare(['name' => 'banana'], ['name' => 'Apple']))->toBeGreaterThan(0)
        ->and($service->nameCompare(['name' => 'apple'], ['name' => 'apple']))->toBe(0);
});

test('nameCompare lowercases each side independently before comparing', function (): void {
    // Kills line 319's 2 UnwrapStrtolower mutations. The sibling test
    // above can't distinguish either: with $name_a already lowercase,
    // unwrapping strtolower() on the OTHER side happens to land on the
    // same comparison sign by ASCII coincidence. Each case below keeps
    // the untested side already-lowercase so only the side under test
    // can flip the sign.
    $service = HtmlServiceTestFactory::build();

    expect($service->nameCompare(['name' => 'BANANA'], ['name' => 'apple']))->toBeGreaterThan(0);
    expect($service->nameCompare(['name' => 'apple'], ['name' => 'BANANA']))->toBeLessThan(0);
});

test('nameCompare defaults a missing/non-string name to empty string on each side independently', function (): void {
    // Kills line 316/317's EmptyStringToNotEmpty.
    $service = HtmlServiceTestFactory::build();

    expect($service->nameCompare([], ['name' => 'apple']))->toBeLessThan(0);
    expect($service->nameCompare(['name' => 'apple'], []))->toBeGreaterThan(0);
});

test('fatalError uses the given title, falling back to the default only when null or genuinely empty', function (): void {
    // Kills line 465's IfNegated, IdenticalToNotIdentical (x2, one per
    // side of the ||), BooleanOrToBooleanAnd (&& can never be true for
    // both null and '' simultaneously, so neither would ever fall back),
    // and EmptyStringToNotEmpty.
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $withCustomTitle = $caller->call($service, 'msg', 'My Custom Title', false);
    $withNullTitle = $caller->call($service, 'msg', null, false);
    $withEmptyTitle = $caller->call($service, 'msg', '', false);

    expect($withCustomTitle)->toContain('<h1>My Custom Title</h1>')
        ->and($withNullTitle)->toContain('<h1>Piwigo encountered a non recoverable error</h1>')
        ->and($withEmptyTitle)->toContain('<h1>Piwigo encountered a non recoverable error</h1>');
});

test('fatalError omits the backtrace entirely when showTrace is false', function (): void {
    // Kills line 469's EmptyStringToNotEmpty ($btrace_msg's '' initial
    // value) indirectly: with showTrace=false, the whole backtrace-
    // building block is skipped, so $btrace_msg must still read back as
    // the real initial value for the final <pre> block to render with
    // nothing extra after the message.
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $body = $caller->call($service, 'boom', 'T', false);

    expect($body)->toContain("<b>boom</b>\n\n</pre>")
        ->and($body)->not->toContain('#1');
});

test('fatalError formats each real backtrace frame exactly as "#N\\t{class}::{function} {file}({line})\\n"', function (): void {
    // Kills line 473's TernaryNegated/ConcatRemoveLeft/ConcatRemoveRight/
    // EmptyStringToNotEmpty (all corrupt this exact per-frame format). A
    // single named-class call site gives frame #1 (the first frame the
    // loop's own `$i = 1` start includes) a fully predictable
    // class+function -- debug_backtrace() frame N's file/line is the
    // CALL SITE of frame N's own function, so frame #1's file/line
    // point at THIS call, not at HtmlServiceTestFatalErrorCaller.php
    // itself.
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $callLine = __LINE__ + 1;
    $body = $caller->call($service, 'boom', 'T', true);

    $expectedFrame1 = sprintf(
        "#1\t%s::call %s(%d)\n",
        HtmlServiceTestFatalErrorCaller::class,
        __FILE__,
        $callLine,
    );
    expect($body)->toContain($expectedFrame1);
});

test('fatalError\'s backtrace loop covers frames 1 through the real stack depth, and no further', function (): void {
    // Kills line 472's DecrementInteger ($i starts at 0 -- would also
    // include fatalError()'s own self-frame, numbered "#0") and
    // SmallerToSmallerOrEqual ($i <= count($bt) -- would run one
    // iteration past the last valid index, numbered one past the real
    // last frame). $caller->preCallDepth is captured live one function
    // level above fatalError()'s own debug_backtrace() call, so the
    // real last valid frame number is always exactly preCallDepth,
    // regardless of how deep the actual Pest/PHPUnit call stack is.
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $body = $caller->call($service, 'boom', 'T', true);
    $lastValidFrame = $caller->preCallDepth;

    expect($body)->toContain('#' . $lastValidFrame . "\t")
        ->and($body)->not->toContain('#' . ($lastValidFrame + 1) . "\t")
        ->and($body)->not->toContain("#0\t");
});

test('fatalError falls back to an empty class prefix, not a placeholder, for a real frame with no class', function (): void {
    // Kills line 473's EmptyStringToNotEmpty ($class's `? ... : ''`
    // false branch). PHPUnit's own real invocation chain includes a
    // genuine plain-function frame (call_user_func_array, no enclosing
    // class) a few levels above this call -- confirmed live via a
    // throwaway debug_backtrace() dump of this exact call path.
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $body = $caller->call($service, 'boom', 'T', true);

    expect($body)->toContain("\tcall_user_func_array ");
});

test('fatalError renders an empty, not placeholder, file/line for a real frame with neither', function (): void {
    // Kills line 474's two EmptyStringToNotEmpty mutations (the
    // `?? ''` fallbacks for 'file' and 'line'). PHPUnit's own real
    // invocation chain includes a genuine frame with neither key set
    // (a closure invoked through Pest\Factories\TestCaseMethodFactory
    // without file/line context) a few levels above this call --
    // confirmed live via a throwaway debug_backtrace() dump of this
    // exact call path. Real code renders "... ()" (empty parens, no
    // placeholder text before or inside them) for that one frame; no
    // other frame in this exact call chain has both file and line
    // empty, so this pattern can only appear here.
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $body = $caller->call($service, 'boom', 'T', true);

    expect($body)->toMatch('/ \(\)$/m');
});

test('fatalError joins the backtrace immediately after the message, with no stray separator from the concat order', function (): void {
    // Kills line 474's ConcatSwitchSides. Swapping the two concat
    // operands moves each frame's own trailing ")\n" to instead prefix
    // the FOLLOWING frame -- since frames are appended back-to-back,
    // every individual frame's "...)\n" substring still appears
    // (just shifted globally by one frame), so the earlier per-frame
    // exact-match test alone can't tell them apart. What differs is the
    // very first frame: real code has nothing before "#1", while the
    // mutant prepends a stray ")\n" there instead.
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $body = $caller->call($service, 'boom', 'T', true);

    expect($body)->toContain("</b>\n#1\t");
});

test('fatalError trims the trailing newline off the backtrace before it reaches the display template', function (): void {
    // Kills line 476's UnwrapTrim. Without trim(), $btrace_msg keeps its
    // own trailing "\n" (from the last frame's ")\n"), which combines
    // with the display template's own literal "\n" before "</pre>" to
    // produce a double newline -- the same double-newline shape the
    // showTrace=false test already pins down for the *empty*
    // $btrace_msg case, but here with a genuinely non-empty trace.
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $body = $caller->call($service, 'boom', 'T', true);

    expect($body)->not->toContain("\n\n</pre>")
        ->and($body)->toContain(")\n</pre>");
});

test('fatalError pads the display with exactly 300 trailing spaces', function (): void {
    // Kills line 486's ConcatEqualToEqual (`=` would reset $display to
    // just the padding, discarding the whole page built above),
    // DecrementInteger/IncrementInteger (299/301 spaces), and
    // UnwrapStrRepeat (str_repeat() removed, leaving a single space).
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $body = $caller->call($service, 'boom', 'T', false);

    expect($body)->toEndWith(str_repeat(' ', 300))
        ->and(substr_count($body, ' ', strlen($body) - 301))->toBeGreaterThanOrEqual(300);
    // The character immediately before the 300-space run must not
    // itself be a space (pins the count at exactly 300, not "at least").
    expect(substr($body, -301, 1))->not->toBe(' ');
});

test('fatalError actually disables display_errors and raises error_reporting to E_ALL', function (): void {
    // Kills line 488's IfNegated and line 489's FalseToTrue/
    // RemoveFunctionCall (ini_set('display_errors', false) removed or
    // never called), and line 491's RemoveFunctionCall
    // (error_reporting(E_ALL) removed). Both are real global PHP ini
    // state, directly observable via ini_get()/error_reporting()
    // immediately after the call.
    $originalDisplayErrors = ini_get('display_errors');
    $originalErrorReporting = error_reporting();
    ini_set('display_errors', '1');
    error_reporting(0);

    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    try {
        $caller->call($service, 'boom', 'T', false);

        expect(ini_get('display_errors'))->toBe('')
            ->and(error_reporting())->toBe(\E_ALL);
    } finally {
        ini_set('display_errors', $originalDisplayErrors);
        error_reporting($originalErrorReporting);
    }
});

test('fatalError passes trigger_error() the tag-stripped message plus the backtrace, not the raw HTML message', function (): void {
    // Kills line 492's UnwrapStripTags (passing the raw, unstripped
    // $msg through instead).
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $caller->call($service, '<b>boom</b>', 'T', false);

    expect($caller->capturedErrorMessage)->toBe('boom');
});

test('fatalError passes trigger_error() the backtrace too, not just the stripped message, when a trace was built', function (): void {
    // Kills line 492's ConcatRemoveRight (dropping $btrace_msg from the
    // trigger_error() call). Genuinely indistinguishable from the
    // sibling test above with showTrace=false: $btrace_msg is '' in
    // that case, so concatenating it or not is unobservable. Requires
    // showTrace=true to actually exercise a non-empty $btrace_msg.
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $caller->call($service, '<b>boom</b>', 'T', true);

    expect($caller->capturedErrorMessage)->toContain('boom')
        ->and($caller->capturedErrorMessage)->toContain('#1');
});

test('fatalError throws a 500 response, not a neighboring status code', function (): void {
    // Kills line 500's DecrementInteger/IncrementInteger
    // (ResponseFactory::html($display, 499|501) instead of 500).
    $service = HtmlServiceTestFactory::build();
    $caller = new HtmlServiceTestFatalErrorCaller();

    $caller->call($service, 'boom', 'T', false);

    expect($caller->capturedStatusCode)->toBe(500);
});

test('setStatusHeader sends the well-known reason phrase for a known code', function (): void {
    $service = HtmlServiceTestFactory::build();

    $service->setStatusHeader(404);

    expect(true)->toBeTrue(); // real assertion is the absence of a fatal/warning; header() is a no-op under CLI SAPI
});

test('renderCategoryLiteralDescription strips disallowed tag markup but keeps their content and the allow-list', function (): void {
    // strip_tags() removes only the tag markup itself, not the content inside
    // a disallowed tag (it isn't a sanitizer aware of <script>'s special
    // meaning) -- "x" survives, just unwrapped.
    $service = HtmlServiceTestFactory::build();

    expect($service->renderCategoryLiteralDescription(new RenderCategoryLiteralDescription('<script>x</script><p>hello</p><b>world</b>'))->description)
        ->toBe('x<p>hello</p><b>world</b>');
});

test('renderCategoryLiteralDescription treats a null description as empty', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect($service->renderCategoryLiteralDescription(new RenderCategoryLiteralDescription(null))->description)->toBe('');
});

test('pwgNl2br passes through falsy scalars unchanged', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect($service->pwgNl2br(''))->toBe('')
        ->and($service->pwgNl2br(0))->toBe(0)
        ->and($service->pwgNl2br(null))->toBeNull()
        ->and($service->pwgNl2br(false))->toBeFalse();
});

test('pwgNl2br passes through a real float zero unchanged, distinctly from int 0', function (): void {
    // Kills line 863's DecrementFloat/IncrementFloat (`=== -1.0`/`===
    // 1.0` instead of `=== 0.0`) -- the sibling test's `0` is a genuine
    // int, strictly !== 0.0, so it can never reach this specific
    // disjunct at all.
    $service = HtmlServiceTestFactory::build();

    expect($service->pwgNl2br(0.0))->toBe(0.0);
});

test('pwgNl2br casts a real non-zero int/float to string before calling nl2br()', function (): void {
    // Kills line 871's RemoveStringCast -- nl2br() requires a real
    // string parameter under strict_types=1; confirmed live that
    // dropping the cast throws a genuine TypeError for a non-string,
    // non-early-returned value like a real int or float.
    $service = HtmlServiceTestFactory::build();

    expect($service->pwgNl2br(42))->toBe('42');
    expect($service->pwgNl2br(3.14))->toBe('3.14');
});

/**
 * Confirmed-equivalent: line 863's EmptyStringToNotEmpty (the `$string
 * === ''` disjunct). nl2br('') and the identity-passthrough branch both
 * produce '' -- nl2br() is idempotent on an empty string, so mutating
 * which literal this disjunct compares against changes nothing
 * observable: an empty string either matches the mutated disjunct and
 * returns as-is, or falls through to nl2br(''), which returns the exact
 * same ''. Confirmed live: the full suite in this file passes
 * identically with the mutation applied.
 */
test('pwgNl2br passes through arrays unchanged', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect($service->pwgNl2br(['a', 'b']))->toBe(['a', 'b']);
});

test('pwgNl2br converts newlines in a non-empty string', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect($service->pwgNl2br("a\nb"))->toBe('a<br />' . "\n" . 'b');
});

test('renderElementName falls back to the filename when name is not set', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect($service->renderElementName(['file' => 'my-photo.jpg']))->toBe('my-photo');
});

test('renderElementName uses the given name when it is genuinely non-empty', function (): void {
    // Kills line 673's NotIdenticalToIdentical -- the sibling test above
    // only reaches the "name unset" branch, never "name genuinely
    // present and non-empty".
    $service = HtmlServiceTestFactory::build();

    expect($service->renderElementName(['name' => 'My Photo Title', 'file' => 'my-photo.jpg']))->toBe('My Photo Title');
});

test('renderElementName falls back to the filename when name is explicitly an empty string, not just unset', function (): void {
    // Kills line 673's EmptyStringToNotEmpty.
    $service = HtmlServiceTestFactory::build();

    expect($service->renderElementName(['name' => '', 'file' => 'my-photo.jpg']))->toBe('my-photo');
});

test('renderElementName falls back to an empty string when neither name nor file is set', function (): void {
    // Kills line 681's EmptyStringToNotEmpty (the `?? null` -> `is_string()
    // ? ... : ''` fallback passed into getNameFromFile()).
    $service = HtmlServiceTestFactory::build();

    expect($service->renderElementName([]))->toBe('');
});

test('renderElementName throws when a render_element_name handler returns something other than a RenderElementName instance', function (): void {
    // dispatchChange() enforces this itself: the class-string key +
    // instanceof check make returning a non-matching type structurally
    // impossible to pass through silently.
    $service = HtmlServiceTestFactory::build();
    // A real, untyped plugin handler is exactly what addEventHandler()
    // (not addTypedHandler()) accepts -- PHPStan can't see third-party
    // code, so this test registers the same way to genuinely exercise
    // dispatchChange()'s own runtime enforcement, not a static one.
    $handler = static fn (): int => 42;
    EventDispatcherTestFactory::get()->addEventHandler(RenderElementName::class, $handler);

    try {
        expect(static fn () => $service->renderElementName(['name' => 'My Photo Title']))
            ->toThrow(Error::class, 'must return an instance of');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(RenderElementName::class, $handler);
    }
});

test('renderElementDescription returns empty string when comment is not set', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect($service->renderElementDescription([]))->toBe('');
});

test('renderElementDescription uses the given comment when it is genuinely non-empty', function (): void {
    // Kills line 696's NotIdenticalToIdentical.
    $service = HtmlServiceTestFactory::build();

    expect($service->renderElementDescription(['comment' => 'A lovely shot.']))->toBe('A lovely shot.');
});

test('renderElementDescription returns empty string when comment is explicitly an empty string, not just unset', function (): void {
    // Kills line 696's EmptyStringToNotEmpty.
    $service = HtmlServiceTestFactory::build();

    expect($service->renderElementDescription(['comment' => '']))->toBe('');
});

test('renderElementDescription never triggers render_element_description for a genuinely empty comment', function (): void {
    // Kills line 696's second EmptyStringToNotEmpty (`!== 'PEST Mutator
    // was here!'` instead of `!== ''`) -- the sibling test above can't
    // tell the two branches apart: both the real empty-comment
    // short-circuit AND the mutant's wrongly-entered trigger block
    // happen to produce the same '' result when no handler is
    // registered (an unhandled trigger_change() just passes its input
    // straight through, and the input here is already ''). A handler
    // that actually transforms its input is required to expose the
    // difference.
    $service = HtmlServiceTestFactory::build();
    $handler = static fn (RenderElementDescription $e): RenderElementDescription => new RenderElementDescription('MODIFIED', $e->action);
    EventDispatcherTestFactory::get()->addTypedHandler(RenderElementDescription::class, $handler);

    try {
        expect($service->renderElementDescription(['comment' => '']))->toBe('');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(RenderElementDescription::class, $handler);
    }
});

test('renderElementDescription throws when a render_element_description handler returns something other than a RenderElementDescription instance', function (): void {
    // dispatchChange() enforces this itself: the class-string key +
    // instanceof check make returning a non-matching type structurally
    // impossible to pass through silently.
    $service = HtmlServiceTestFactory::build();
    // See RenderElementName's own sibling test above for why this uses
    // addEventHandler(), not addTypedHandler().
    $handler = static fn (): int => 42;
    EventDispatcherTestFactory::get()->addEventHandler(RenderElementDescription::class, $handler);

    try {
        expect(static fn () => $service->renderElementDescription(['comment' => 'A lovely shot.']))
            ->toThrow(Error::class, 'must return an instance of');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(RenderElementDescription::class, $handler);
    }
});

test('tagAlphaCompare sorts by the transliterated tag name', function (): void {
    // Plain ASCII names -- no accent-transliteration ambiguity to worry
    // about (see StringHelperTest's own pwgTransliterate notes).
    $service = HtmlServiceTestFactory::build();

    expect($service->tagAlphaCompare(['name' => 'zebra'], ['name' => 'apple']))->toBeGreaterThan(0);
    expect($service->tagAlphaCompare(['name' => 'apple'], ['name' => 'apple']))->toBe(0);
});

test('tagAlphaCompare defaults a missing/non-string name to empty string on each side independently', function (): void {
    // Kills line 332/333's EmptyStringToNotEmpty.
    $service = HtmlServiceTestFactory::build();

    expect($service->tagAlphaCompare([], ['name' => 'apple']))->toBeLessThan(0);
    expect($service->tagAlphaCompare(['name' => 'apple'], []))->toBeGreaterThan(0);
});

test('tagAlphaCompare reuses an already-cached transliteration instead of recomputing it', function (): void {
    // Kills line 345's IfNegated/CoalesceRemoveLeft/RemoveNot (the
    // cache-hit guard) -- a deliberately WRONG pre-seeded cache entry
    // proves the real (correct) transliteration was never recomputed:
    // if it had been, the comparison would land the other way (real
    // pwgTransliterate('apple') is 'apple', which sorts before 'zebra';
    // the poisoned cache claims 'apple' transliterates to 'zzz-poison',
    // which sorts after).
    htmlServiceTestProcessCache()->set(HtmlService::class . '::tagAlphaCompare', [
        'apple' => 'zzz-poison',
    ]);
    $service = HtmlServiceTestFactory::build();

    expect($service->tagAlphaCompare(['name' => 'apple'], ['name' => 'banana']))->toBeGreaterThan(0);
});

test('tagAlphaCompare treats a non-array cached value as empty and recomputes both names', function (): void {
    // Kills line 338's TernaryNegated ($transliterated stays the
    // non-array raw value instead of falling back to [], which would
    // fatal on the very next array-offset read).
    htmlServiceTestProcessCache()->set(HtmlService::class . '::tagAlphaCompare', 'not-an-array');
    $service = HtmlServiceTestFactory::build();

    expect($service->tagAlphaCompare(['name' => 'apple'], ['name' => 'zebra']))->toBeLessThan(0);
});

test('tagAlphaCompare computes and caches both names, keyed by class name, for a later lookup to reuse', function (): void {
    // Kills line 340's ForeachEmptyIterable/RemoveArrayItem (x2 -- one
    // name silently never gets transliterated/cached) and line 350's
    // RemoveMethodCall/ConcatRemoveLeft/ConcatRemoveRight/
    // ConcatSwitchSides (the cache write itself, and its exact key).
    $service = HtmlServiceTestFactory::build();

    $service->tagAlphaCompare(['name' => 'apple'], ['name' => 'zebra']);

    $cached = htmlServiceTestProcessCache()->get(HtmlService::class . '::tagAlphaCompare');
    expect($cached)->toBe([
        'apple' => 'apple',
        'zebra' => 'zebra',
    ]);
});

/**
 * Confirmed-equivalent: line 353's TernaryNegated (`!is_string(...)`
 * instead of `is_string(...)`) and CoalesceRemoveLeft (`is_string(null)`
 * instead of `is_string($transliterated[$name_b] ?? null)`). By the time
 * $translit_b is computed, the foreach loop just above (lines 340-348)
 * has already unconditionally populated $transliterated[$name_b] with a
 * real string (either an existing cached one or a freshly-computed
 * pwgTransliterate() result) -- so `is_string($transliterated[$name_b]
 * ?? null)` is always true and the ternary's own false branch (the
 * duplicate pwgTransliterate() call) can never run in real execution,
 * regardless of what condition/fallback feeds it. Live sed-verified
 * against the full suite with each mutation applied in turn: both pass
 * identically.
 */
test('getTagsContentTitle pluralizes based on the number of tags', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect($service->getTagsContentTitle([['name' => 'one tag']]))->toContain('Tag')
        ->and($service->getTagsContentTitle([['name' => 'one tag']]))->not->toContain('Tags');
    expect($service->getTagsContentTitle([['name' => 'a'], ['name' => 'b']]))->toContain('Tags');
});

test('getTagsContentTitle builds the exact link markup, prefixed by the real root url', function (): void {
    // Kills line 518's 16 Concat* mutations (5 ConcatRemoveLeft, 5
    // ConcatRemoveRight, 6 ConcatSwitchSides) -- toContain() alone can't
    // tell a reordered/partially-dropped-but-still-present fragment
    // apart from the real one, and the default empty getRootUrl() used
    // by the sibling test above can't distinguish a dropped/reordered
    // getRootUrl() call specifically.
    htmlServiceTestRootPathOverride()->push('/gallery/');

    try {
        $result = HtmlServiceTestFactory::build()->getTagsContentTitle([['name' => 'a']]);

        expect($result)->toBe('<a href="/gallery/tags.php" title="display available tags">Tag</a> ');
    } finally {
        htmlServiceTestRootPathOverride()->reset();
    }
});

test('getThumbnailTitle appends visit/comment counts and a truncated comment, HTML-escaped', function (): void {
    $service = HtmlServiceTestFactory::build();

    $title = $service->getThumbnailTitle(['hit' => 5, 'nb_comments' => 2], 'My Photo', 'A <b>nice</b> shot');

    expect($title)->toContain('My Photo');
    expect($title)->toContain('5 visits');
    expect($title)->toContain('2 comments');
    expect($title)->toContain('A nice shot');
    expect($title)->not->toContain('<b>');
});

test('getThumbnailTitle omits the parenthesized details block when hit/comments are zero or absent', function (): void {
    $service = HtmlServiceTestFactory::build();

    expect($service->getThumbnailTitle([], 'My Photo'))->toBe('My Photo');
});

test('getThumbnailTitle omits hit/nb_comments/rating_score at the exact int/float zero boundary, not just when absent', function (): void {
    // Kills lines 721/729's DecrementInteger (`!== -1` instead of `!==
    // 0`) and 725's DecrementFloat (`!== -1.0`) -- the sibling test
    // above only reaches the "absent" branch, never "present and
    // exactly zero", which these mutations wrongly treat as non-zero.
    $service = HtmlServiceTestFactory::build();

    $title = $service->getThumbnailTitle(['hit' => 0, 'nb_comments' => 0, 'rating_score' => 0.0], 'My Photo');

    expect($title)->toBe('My Photo');
});

test('getThumbnailTitle includes hit/nb_comments/rating_score at exactly 1, not just larger values', function (): void {
    // Kills lines 721/729's IncrementInteger (`!== 1` instead of `!==
    // 0`) and 725's IncrementFloat (`!== 1.0`) -- the sibling "appends
    // visit/comment counts" test uses hit=5/nb_comments=2, both already
    // != 1, so this mutated boundary silently passes there too; and
    // line 725's NotIdenticalToIdentical (`=== 0.0` instead of `!==
    // 0.0`), never exercised by any rating_score value at all before.
    $service = HtmlServiceTestFactory::build();

    $title = $service->getThumbnailTitle(['hit' => 1, 'nb_comments' => 1, 'rating_score' => 1.0], 'My Photo');

    expect($title)->toContain('1 visit')
        ->and($title)->toContain('1 comment')
        ->and($title)->toContain('rating score 1');
});

test('getThumbnailTitle treats a string "0" the same as a real int/float zero, not as non-zero', function (): void {
    // Kills lines 721/729's RemoveIntegerCast and 725's RemoveDoubleCast
    // (dropping the (int)/(float) cast before the !== 0 comparison) --
    // a numeric STRING '0' is never ===/!== identical to an int/float 0
    // without the cast, so an uncast comparison would wrongly include
    // it.
    $service = HtmlServiceTestFactory::build();

    $title = $service->getThumbnailTitle(['hit' => '0', 'nb_comments' => '0', 'rating_score' => '0'], 'My Photo');

    expect($title)->toBe('My Photo');
});

test('getThumbnailTitle casts a numeric-string nb_comments to int before passing it to plural()', function (): void {
    // Kills line 730's RemoveIntegerCast. Translator::plural()'s $n
    // parameter is strictly-typed `int` -- passing the raw numeric
    // STRING '5' through (instead of casting it first) throws a
    // TypeError under this file's declare(strict_types=1), it doesn't
    // silently coerce.
    $service = HtmlServiceTestFactory::build();

    $title = $service->getThumbnailTitle(['nb_comments' => '5'], 'My Photo');

    expect($title)->toBe('My Photo (5 comments)');
});

test('getThumbnailTitle truncates a long comment to 100 characters with an ellipsis', function (): void {
    $service = HtmlServiceTestFactory::build();
    $longComment = str_repeat('x', 150);

    $title = $service->getThumbnailTitle([], 'Title', $longComment);

    expect($title)->toBe('Title ' . str_repeat('x', 100) . '...');
});

test('getThumbnailTitle shows the parenthesized details block for exactly one non-zero metric', function (): void {
    // Kills line 733's IncrementInteger (`count($details) > 1` instead
    // of `> 0`) -- every other test here provides either zero or 3
    // non-zero metrics at once, neither of which distinguishes a
    // boundary shifted by exactly one detail.
    $service = HtmlServiceTestFactory::build();

    $title = $service->getThumbnailTitle(['hit' => 5], 'My Photo');

    expect($title)->toBe('My Photo (5 visits)');
});

test('getThumbnailTitle joins multiple details with ", " inside a single parenthesized block', function (): void {
    // Kills line 734's ConcatRemoveLeft/ConcatRemoveRight/
    // ConcatSwitchSides (x2) -- an exact match on the full parenthesized
    // structure, not just substring containment.
    $service = HtmlServiceTestFactory::build();

    $title = $service->getThumbnailTitle(['hit' => 5, 'nb_comments' => 2], 'My Photo');

    expect($title)->toBe('My Photo (5 visits, 2 comments)');
});

test('getThumbnailTitle strips tags from the comment before counting its length for truncation', function (): void {
    // Kills line 738's UnwrapStripTags. A comment whose RAW length
    // (tags included) is past the 100-char boundary but whose STRIPPED
    // length is not -- real code truncates based on the stripped
    // length, so this must NOT get an ellipsis. Line 742's own final
    // strip_tags() on the whole title would otherwise mask a skipped
    // 738 (stray tag fragments get cleaned up there too), so the tag
    // markup here must be heavy enough (5 wrapping tags, 20 chars) to
    // push the RAW length past 100 while the stripped length (just the
    // 90 x's) stays comfortably under it -- the earlier single-<b>-tag
    // version (97 raw chars) never crossed the boundary either way and
    // couldn't actually distinguish the two.
    $service = HtmlServiceTestFactory::build();
    $comment = str_repeat('<b>', 5) . str_repeat('x', 90) . str_repeat('</b>', 5);

    $title = $service->getThumbnailTitle([], 'Title', $comment);

    expect($title)->toBe('Title ' . str_repeat('x', 90))
        ->and($title)->not->toContain('...');
});

test('getThumbnailTitle does not append an ellipsis at exactly the 100-character boundary, only past it', function (): void {
    // Kills line 739's GreaterToGreaterOrEqual/DecrementInteger/
    // IncrementInteger (`> 100` boundary) and EmptyStringToNotEmpty (the
    // no-ellipsis '' fallback) -- the sibling truncation test uses 150
    // chars, comfortably past any of these shifted boundaries either
    // way.
    $service = HtmlServiceTestFactory::build();
    $exactly100 = str_repeat('x', 100);
    $exactly101 = str_repeat('x', 101);

    expect($service->getThumbnailTitle([], 'Title', $exactly100))->toBe('Title ' . $exactly100);
    expect($service->getThumbnailTitle([], 'Title', $exactly101))->toBe('Title ' . $exactly100 . '...');
});

test('getThumbnailTitle escapes a literal & surviving tag-stripping in the final title', function (): void {
    // Kills line 742's UnwrapHtmlspecialchars -- every other test's
    // title/comment is free of HTML-special characters, so an unwrapped
    // htmlspecialchars() call is otherwise invisible.
    $service = HtmlServiceTestFactory::build();

    $title = $service->getThumbnailTitle([], 'Fish & Chips');

    expect($title)->toBe('Fish &amp; Chips');
});

test('getThumbnailTitle strips real tag markup out of the final title, not just the comment', function (): void {
    // Kills line 742's UnwrapStripTags -- every other test's own
    // $title argument is already tag-free, so an unwrapped strip_tags()
    // there is otherwise invisible (htmlspecialchars() alone would
    // still escape a literal '&', which is what the sibling test above
    // actually exercises).
    $service = HtmlServiceTestFactory::build();

    $title = $service->getThumbnailTitle([], '<b>Bold</b>');

    expect($title)->toBe('Bold');
});

test('getThumbnailTitle throws when a get_thumbnail_title handler returns something other than a GetThumbnailTitle instance', function (): void {
    // dispatchChange() enforces this itself: the class-string key +
    // instanceof check make returning a non-matching type structurally
    // impossible to pass through silently.
    $service = HtmlServiceTestFactory::build();
    // See RenderElementName's own sibling test above for why this uses
    // addEventHandler(), not addTypedHandler().
    $handler = static fn (): int => 42;
    EventDispatcherTestFactory::get()->addEventHandler(GetThumbnailTitle::class, $handler);

    try {
        expect(static fn () => $service->getThumbnailTitle([], 'My Photo'))
            ->toThrow(Error::class, 'must return an instance of');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(GetThumbnailTitle::class, $handler);
    }
});

test('getCatDisplayName throws when a render_category_name handler returns something other than a RenderCategoryName instance', function (): void {
    // See RenderElementName's own sibling test above for why this uses
    // addEventHandler(), not addTypedHandler().
    $service = HtmlServiceTestFactory::build();
    $handler = static fn (): int => 42;
    EventDispatcherTestFactory::get()->addEventHandler(RenderCategoryName::class, $handler);

    try {
        expect(static fn () => $service->getCatDisplayName([['id' => 1, 'name' => 'Nature']], null))
            ->toThrow(Error::class, 'must return an instance of');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(RenderCategoryName::class, $handler);
    }
});

test('getCatDisplayName joins multiple names with the level separator, and only the separator between them', function (): void {
    // Kills line 118's FalseToTrue ($is_first never becomes false, so
    // the separator-inserting branch is never reached at all) and line
    // 124's ConcatEqualToEqual (`=` instead of `.=`, resetting $output
    // to just the current name and losing everything built so far) --
    // both invisible with a single category, which every other test in
    // this file uses.
    $service = HtmlServiceTestFactory::build();

    $result = $service->getCatDisplayName([
        ['id' => 1, 'name' => 'Nature'],
        ['id' => 2, 'name' => 'Portraits'],
        ['id' => 3, 'name' => 'Travel'],
    ], null);

    expect($result)->toBe('Nature / Portraits / Travel');
});

test('getCatDisplayName builds one link per category, in order, when url is an empty string', function (): void {
    // Kills line 126's ConcatEqualToEqual (`=` instead of `.=` on the
    // opening `<a href="...">` fragment, dropping everything built for
    // earlier categories in the same $url === '' branch).
    $service = HtmlServiceTestFactory::build();
    $catA = ['id' => 3, 'name' => 'Nature', 'permalink' => null];
    $catB = ['id' => 7, 'name' => 'Portraits', 'permalink' => null];

    $urlService = UrlServiceTestFactory::build($service);
    $linkA = $urlService->makeIndexUrl(['category' => $catA]);
    $linkB = $urlService->makeIndexUrl(['category' => $catB]);

    $result = $service->getCatDisplayName([$catA, $catB], '');

    expect($result)->toBe(
        '<a href="' . $linkA . '">Nature</a> / <a href="' . $linkB . '">Portraits</a>'
    );
});

test('getCatDisplayNameCache joins multiple names with a <span>-wrapped separator, and only between them', function (): void {
    // Kills line 201's FalseToTrue -- the getCatDisplayNameCache()
    // sibling of getCatDisplayName()'s own line 118 finding above, same
    // "only visible with 2+ categories" gap.
    htmlServiceTestProcessCache()->set('cat_names', [
        '3' => ['id' => 3, 'name' => 'Nature', 'permalink' => null],
        '7' => ['id' => 7, 'name' => 'Portraits', 'permalink' => null],
    ]);
    $service = HtmlServiceTestFactory::build();

    $result = $service->getCatDisplayNameCache('3,7', null);

    expect($result)->toBe('Nature<span> / </span>Portraits');
});

test('getCatDisplayNameCache injects an auth param and wraps the whole chain in a single link with a class when singleLink is set', function (): void {
    htmlServiceTestProcessCache()->set('cat_names', [
        '3' => ['id' => 3, 'name' => 'Nature', 'permalink' => null],
    ]);
    $service = HtmlServiceTestFactory::build();

    $result = $service->getCatDisplayNameCache('3', 'index.php?/category/', true, 'my-link-class', 'SECRETKEY');

    expect($result)->toBe('<a href="index.php?/category/3&amp;auth=SECRETKEY" class="my-link-class">Nature</a>');
});

test('getCatDisplayNameCache\'s singleLink href uses the LAST uppercats id, prefixed by the real root url', function (): void {
    // Kills line 176's ArrayPopToArrayShift (array_pop() takes the last
    // id, not the first -- indistinguishable with the sibling test
    // above's single-id '3' string), ConcatRemoveLeft/ConcatSwitchSides
    // (dropping/reordering getRootUrl() -- indistinguishable when
    // getRootUrl() is '', the default in every other test in this
    // file), and line 177's ConcatEqualToEqual (`=` instead of `.=` on
    // the opening `<a href="..."` fragment).
    htmlServiceTestRootPathOverride()->push('/gallery/');
    htmlServiceTestProcessCache()->set('cat_names', [
        '3' => ['id' => 3, 'name' => 'Nature', 'permalink' => null],
        '7' => ['id' => 7, 'name' => 'Portraits', 'permalink' => null],
    ]);
    $service = HtmlServiceTestFactory::build();

    try {
        $result = $service->getCatDisplayNameCache('3,7', 'index.php?/category/', true);

        expect($result)->toStartWith('<a href="/gallery/index.php?/category/7"');
    } finally {
        htmlServiceTestRootPathOverride()->reset();
    }
});

test('getCatDisplayNameCache throws when a render_category_name handler returns something other than a RenderCategoryName instance', function (): void {
    htmlServiceTestProcessCache()->set('cat_names', [
        '5' => ['id' => 5, 'name' => 'Landscape', 'permalink' => null],
    ]);
    // See RenderElementName's own sibling test above for why this uses
    // addEventHandler(), not addTypedHandler().
    $service = HtmlServiceTestFactory::build();
    $handler = static fn (): int => 7;
    EventDispatcherTestFactory::get()->addEventHandler(RenderCategoryName::class, $handler);

    try {
        expect(static fn () => $service->getCatDisplayNameCache('5', null))
            ->toThrow(Error::class, 'must return an instance of');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(RenderCategoryName::class, $handler);
    }
});

test('getCombinedCategoriesContentTitle renders a single category link without a remove-tag link', function (): void {
    $service = HtmlServiceTestFactory::build();
    $category = ['id' => 3, 'name' => 'Nature', 'permalink' => null];

    // Independently computed from the same real UrlService primitive
    // getCombinedCategoriesContentTitle() itself delegates to (via
    // getCatDisplayName()) -- an exact-value assertion without
    // re-implementing makeSectionInUrl()'s own url-building algorithm here.
    $expectedLink = UrlServiceTestFactory::build($service)->makeIndexUrl(['category' => $category]);

    $title = $service->getCombinedCategoriesContentTitle($category, []);

    expect($title)->toBe('Albums <a href="' . $expectedLink . '">Nature</a>');
});

test('getCombinedCategoriesContentTitle appends a remove-tag link referencing the other category when combining 2', function (): void {
    $service = HtmlServiceTestFactory::build();
    $catA = ['id' => 3, 'name' => 'Nature', 'permalink' => null];
    $catB = ['id' => 7, 'name' => 'Portraits', 'permalink' => null];

    $urlService = UrlServiceTestFactory::build($service);
    $linkA = $urlService->makeIndexUrl(['category' => $catA]);
    $linkB = $urlService->makeIndexUrl(['category' => $catB]);

    $title = $service->getCombinedCategoriesContentTitle($catA, [$catB]);

    // Each category's own remove-link points at the *other* category (the
    // "un-combine" target) -- CurrentTemplate is reset in this file's own
    // beforeEach, so icon_dir resolves to '' via the documented defensive
    // fallback, giving a bare '/remove_s.png' src.
    $removeLinkTemplate = static fn (string $removeUrl): string => '<a id="TagsGroupRemoveTag" href="' . $removeUrl . '" style="border:none;" title="remove this tag from the list">'
        . '<img src="/remove_s.png" alt="x" style="vertical-align:bottom;" >'
        . '<span class="pwg-icon pwg-icon-close" ></span></a>';

    $expected = 'Albums '
        . '<a href="' . $linkA . '">Nature</a>' . $removeLinkTemplate($linkB)
        . ' + '
        . '<a href="' . $linkB . '">Portraits</a>' . $removeLinkTemplate($linkA);

    expect($title)->toBe($expected);
});

test('getCombinedCategoriesContentTitle uses the current template\'s real icon_dir in the remove-link image src', function (): void {
    // Kills line 573's InstanceOfToFalse (the sibling test above can't
    // distinguish this: CurrentTemplate is never initialized there, so
    // $request_template is already null regardless of the instanceof
    // check) and line 576's ConcatRemoveRight/ConcatSwitchSides on the
    // icon_dir fragment specifically (indistinguishable when icon_dir
    // is the sibling test's default ''). Same "point CurrentPaths at a
    // fresh temp root" Template setup as PictureRateRendererTest.php.
    $root = sys_get_temp_dir() . '/pwg-html-service-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);

    // KernelContainerOverride::with() rebinds Paths::class for this test's
    // own scope -- CurrentPaths is a pure shim, reading whatever the live
    // container has, not an independently-settable static. It builds a
    // genuinely new container (Container::build(), not a mutation of the
    // outer one), so CurrentConfig -- itself container-shared -- resolves
    // to a brand-new instance inside the closure too; the
    // setDataLocation()/setDataDirChecked() calls below must therefore run
    // AFTER entering the closure, against that new instance, or
    // Template::__construct()'s own dataDirChecked()===null branch reaches
    // its constructor-injected $this->currentConfigService->get() (never
    // set() in this Unit test) and throws.
    KernelContainerOverride::with([Paths::class => Paths::fromRoot($root)], function () use ($root): void {
        CurrentConfigTestFactory::get()->setDataLocation('data/');
        CurrentConfigTestFactory::get()->setDataDirChecked('1');

        $template = TemplateTestFactory::build();
        $template->assign('themeconf', ['icon_dir' => '/my-theme/icons']);
        CurrentTemplate::current()->set($template);
        // A non-empty root url is required to kill line 576's
        // ConcatRemoveRight (drops getRootUrl() from the src entirely) and
        // ConcatSwitchSides (moves getRootUrl() to prefix the whole `$title
        // .=` expression instead of just the src) -- both are unobservable
        // via toContain() alone when getRootUrl() defaults to ''.
        htmlServiceTestRootPathOverride()->push('/gallery/');

        try {
            $service = HtmlServiceTestFactory::build();
            $catA = ['id' => 3, 'name' => 'Nature', 'permalink' => null];
            $catB = ['id' => 7, 'name' => 'Portraits', 'permalink' => null];

            // The SHARED container-resolved RootPathOverride, not a fresh
            // one -- getCombinedCategoriesContentTitle()'s own internal
            // urlService() (HtmlService's container-live-resolve bridge)
            // reads from that same shared instance, so its <a href> links
            // reflect the pushed '/gallery/' override too, not just the
            // icon src built alongside it.
            $urlService = UrlServiceTestFactory::build($service, htmlServiceTestRootPathOverride());
            $linkA = $urlService->makeIndexUrl(['category' => $catA]);
            $linkB = $urlService->makeIndexUrl(['category' => $catB]);
            $removeLinkTemplate = static fn (string $removeUrl): string => '<a id="TagsGroupRemoveTag" href="' . $removeUrl . '" style="border:none;" title="remove this tag from the list">'
                . '<img src="/gallery//my-theme/icons/remove_s.png" alt="x" style="vertical-align:bottom;" >'
                . '<span class="pwg-icon pwg-icon-close" ></span></a>';

            $title = $service->getCombinedCategoriesContentTitle($catA, [$catB]);

            // Exact match, not toContain(): pins the icon_dir fragment's
            // getRootUrl() prefix to exactly the src attribute (kills
            // ConcatRemoveRight, which drops it entirely) and to exactly
            // that position (kills ConcatSwitchSides, which would instead
            // splice it in as "...</a>/gallery/<a id=..." between the two
            // links).
            expect($title)->toBe(
                'Albums <a href="' . $linkA . '">Nature</a>' . $removeLinkTemplate($linkB)
                . ' + '
                . '<a href="' . $linkB . '">Portraits</a>' . $removeLinkTemplate($linkA),
            );
        } finally {
            htmlServiceTestRootPathOverride()->reset();
            CurrentTemplate::current()->reset();
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $node) {
                assert($node instanceof SplFileInfo);
                $node->isDir() ? rmdir($node->getPathname()) : unlink($node->getPathname());
            }
            rmdir($root);
        }
    });
});

test('getCombinedCategoriesContentTitle folds every other category into combined_categories on the remove-link when combining 3 or more', function (): void {
    // With only 2 total categories (the sibling test above),
    // array_shift($other_cats) empties $other_cats entirely (1 element
    // left after unset(), then shifted out), so `combined_categories`
    // never gets set -- 3 total is the minimum shape where $other_cats
    // still has something left (2 left after unset(), 1 remains after the
    // shift) to reach that branch at all.
    $service = HtmlServiceTestFactory::build();
    $catA = ['id' => 3, 'name' => 'Nature', 'permalink' => null];
    $catB = ['id' => 7, 'name' => 'Portraits', 'permalink' => null];
    $catC = ['id' => 9, 'name' => 'Travel', 'permalink' => null];

    $urlService = UrlServiceTestFactory::build($service);
    // catA's own remove-link: array_shift() takes catB (the lowest-keyed
    // remaining element after unset()), leaving catC to fold into
    // combined_categories.
    $removeLinkA = $urlService->makeIndexUrl(['category' => $catB, 'combined_categories' => [$catC]]);
    // catC's own remove-link: array_shift() takes catA, leaving catB.
    $removeLinkC = $urlService->makeIndexUrl(['category' => $catA, 'combined_categories' => [$catB]]);

    $title = $service->getCombinedCategoriesContentTitle($catA, [$catB, $catC]);

    expect($title)->toContain($removeLinkA)
        ->and($title)->toContain($removeLinkC);
});

/**
 * Confirmed-equivalent: line 558's GreaterToGreaterOrEqual
 * (`count($other_cats) >= 0` instead of `> 0`) and DecrementInteger
 * (`count($other_cats) > -1`). With the "combining 2" shape above,
 * $other_cats is empty (count 0) at this point either way -- real code
 * skips setting 'combined_categories' entirely; both mutants would set
 * it to an explicit empty array instead, for exactly the same reason
 * (count() is never negative, so `> -1` is logically identical to `>=
 * 0` for every real input). Confirmed live that UrlService::makeIndexUrl()
 * renders an identical URL string for ['category' => $cat] and
 * ['category' => $cat, 'combined_categories' => []] -- an explicitly-
 * empty array is indistinguishable from an absent key in the built
 * query string.
 */
test('setStatusHeader accepts every well-known status code and the default fallback without a fatal/warning', function (): void {
    $service = HtmlServiceTestFactory::build();

    foreach ([200, 301, 302, 304, 400, 401, 403, 500, 501, 503] as $code) {
        $service->setStatusHeader($code);
    }
    // Unrecognized code, no explicit $text -- exercises the match's
    // `default => $text` arm (still the already-empty '' at that point).
    $service->setStatusHeader(999);

    expect(true)->toBeTrue(); // header() is a no-op under CLI SAPI; absence of a fatal/warning is the real assertion
});

test('setStatusHeader resolves the exact well-known reason phrase for every known code', function (): void {
    // Kills lines 597-607's 22 DecrementInteger/IncrementInteger
    // mutations (one pair per status code literal) -- header() itself
    // is a genuine no-op under CLI SAPI with no way to inspect what
    // string it received after the fact, but the computed $text is also
    // passed, unmodified, to the real 'set_status_header' trigger_notify
    // event, giving a real side channel to observe it through.
    $captured = [];
    $handler = static function (SetStatusHeader $event) use (&$captured): void {
        $captured[$event->code] = $event->text;
    };
    EventDispatcherTestFactory::get()->addTypedHandler(SetStatusHeader::class, $handler);
    $service = HtmlServiceTestFactory::build();

    try {
        $expected = [
            200 => 'OK',
            301 => 'Moved permanently',
            302 => 'Moved temporarily',
            304 => 'Not modified',
            400 => 'Bad request',
            401 => 'Authorization required',
            403 => 'Forbidden',
            404 => 'Not found',
            500 => 'Server error',
            501 => 'Not implemented',
            503 => 'Service unavailable',
        ];
        foreach ($expected as $code => $text) {
            $service->setStatusHeader($code);
        }

        expect($captured)->toBe($expected);
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(SetStatusHeader::class, $handler);
    }
});

test('setStatusHeader keeps the given text unchanged when it is genuinely non-empty', function (): void {
    // Kills line 595's EmptyStringToNotEmpty/IdenticalToNotIdentical/
    // IfNegated ($text === '' guard) -- without this, a caller-supplied
    // reason phrase for a well-known code would be silently overwritten
    // by the default table lookup instead of being kept as given.
    $captured = null;
    $handler = static function (SetStatusHeader $event) use (&$captured): void {
        $captured = $event->text;
    };
    EventDispatcherTestFactory::get()->addTypedHandler(SetStatusHeader::class, $handler);
    $service = HtmlServiceTestFactory::build();

    try {
        $service->setStatusHeader(200, 'My Custom Reason');

        expect($captured)->toBe('My Custom Reason');
    } finally {
        EventDispatcherTestFactory::get()->removeEventHandler(SetStatusHeader::class, $handler);
    }
});

test('registerDefaultMenubarBlocks does nothing for a BlockManager whose id is not "menubar"', function (): void {
    $service = HtmlServiceTestFactory::build();
    $menu = new BlockManager('sidebar', new EventDispatcher(), CurrentTemplate::current(), CurrentConfigTestFactory::get());

    $service->registerDefaultMenubarBlocks(new BlockManagerRegisterBlocks($menu));

    expect($menu->get_registered_blocks())->toBe([]);
});

function htmlServiceTestSrcImageUrlProtection(HtmlService $service, string $url, SrcImage $srcImage): string
{
    return $service->getSrcImageUrlProtectionHandler(new GetSrcImageUrl($url, $srcImage))->url;
}

/**
 * @param array<string, mixed> $elementInfo
 */
function htmlServiceTestElementUrlProtection(HtmlService $service, string $url, array $elementInfo): string
{
    return $service->getElementUrlProtectionHandler(new GetElementUrl($url, $elementInfo))->url;
}

test('getSrcImageUrlProtectionHandler uses "e" for an original image and "r" for a non-original representative', function (): void {
    $service = HtmlServiceTestFactory::build();
    $original = new SrcImage(['id' => 7, 'path' => 'upload/2026/07/photo.jpg', 'file' => 'photo.jpg']);
    $representative = new SrcImage(['id' => 9, 'path' => 'upload/2026/07/video.mp4', 'file' => 'video.mp4', 'representative_ext' => 'jpg']);

    expect($original->is_original())->toBeTrue()
        ->and($representative->is_original())->toBeFalse();

    expect(htmlServiceTestSrcImageUrlProtection($service, 'ignored-input-url', $original))
        ->toBe('action.php?id=7&amp;part=e')
        ->and(htmlServiceTestSrcImageUrlProtection($service, 'ignored-input-url', $representative))
        ->toBe('action.php?id=9&amp;part=r');
});

test('getElementUrlProtectionHandler passes a non-image extension through unchanged when protection is scoped to images', function (): void {
    CurrentConfigTestFactory::get()->setOriginalUrlProtection('images');
    $service = HtmlServiceTestFactory::build();

    $result = htmlServiceTestElementUrlProtection($service, 'original-url-unchanged', ['id' => 3, 'path' => 'upload/video.mp4']);

    expect($result)->toBe('original-url-unchanged');
});

test('getElementUrlProtectionHandler builds an action url for an image extension when protection is scoped to images', function (): void {
    CurrentConfigTestFactory::get()->setOriginalUrlProtection('images');
    $service = HtmlServiceTestFactory::build();

    $result = htmlServiceTestElementUrlProtection($service, 'ignored', ['id' => 3, 'path' => 'upload/photo.jpg']);

    expect($result)->toBe('action.php?id=3&amp;part=e');
});

test('getElementUrlProtectionHandler builds an action url for any extension when protection is not scoped to images', function (): void {
    // Default originalUrlProtection() is '' -- the images-only guard never
    // triggers, so even a non-image extension reaches the action url.
    $service = HtmlServiceTestFactory::build();

    $result = htmlServiceTestElementUrlProtection($service, 'ignored', ['id' => 5, 'path' => 'upload/video.mp4']);

    expect($result)->toBe('action.php?id=5&amp;part=e');
});

test('getElementUrlProtectionHandler defaults a missing id to an empty string, not a placeholder', function (): void {
    // Kills line 778's EmptyStringToNotEmpty (the `?? ''` coalesce
    // fallback) and line 779's EmptyStringToNotEmpty (the `: ''`
    // ternary fallback, unreachable via the coalesce alone since `''`
    // is already both int|string) -- every other test here always
    // provides a real 'id'.
    $service = HtmlServiceTestFactory::build();

    $result = htmlServiceTestElementUrlProtection($service, 'ignored', ['path' => 'upload/video.mp4']);

    expect($result)->toBe('action.php?id=&amp;part=e');
});

test('getElementUrlProtectionHandler defaults a non-int-non-string id to an empty string too', function (): void {
    // Kills line 779's EmptyStringToNotEmpty ternary false-branch
    // fallback -- unreachable via a missing 'id' (the sibling test
    // above): '' from the `?? ''` coalesce is already a valid string,
    // so is_int($id) || is_string($id) is trivially true and the
    // ternary's own false branch never runs. A value that survives the
    // coalesce (non-null, so it isn't treated as absent) but is neither
    // an int nor a string is required to actually reach it.
    $service = HtmlServiceTestFactory::build();

    $result = htmlServiceTestElementUrlProtection($service, 'ignored', ['id' => 3.5, 'path' => 'upload/video.mp4']);

    expect($result)->toBe('action.php?id=&amp;part=e');
});

test('accessDenied renders a 401 page instead of redirecting when a real (non-guest) user is already authenticated', function (): void {
    // Kills line 366's RemoveNot (`and isAGuest()` instead of `and
    // !isAGuest()`) -- the sibling test below never initializes
    // CurrentUser at all, so isInitialized() alone is already false and
    // can't distinguish the second condition. A real, non-guest
    // CurrentUser is required to reach this branch at all.
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: 'alice',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
    ));
    $service = HtmlServiceTestFactory::build();
    $redirectService = new HtmlServiceTestCapturingRedirectHttpService();

    // accessDenied() is `never`-typed and this branch always throws --
    // if it somehow didn't, PHP's own return-type enforcement would
    // raise a TypeError here, matching the sibling redirectHttp() test's
    // own established precedent (no separate "did it throw" assertion
    // needed).
    // Kills line 367's 11 Concat* mutations (ConcatRemoveLeft/Right x5/
    // ConcatSwitchSides x6) -- toContain() alone can't tell a
    // reordered/partially-dropped-but-still-present fragment apart from
    // the real one, and getRootUrl() defaulting to '' in this file's own
    // beforeEach can't distinguish a dropped/reordered makeIndexUrl()
    // call either.
    $expectedIndexUrl = UrlServiceTestFactory::build($service)->makeIndexUrl();
    $expectedHtml = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
        . "\n" . '<link rel="shortcut icon" type="image/x-icon" href="themes/default/icon/favicon.ico">'
        . "\n" . '<div style="display: flex; justify-content: center;align-items: center;height: 100vh;margin: 0;color: #3C3C3C;font-family: \'Open Sans\', sans-serif;font-size: 20px;font-style: normal;font-weight: 600;line-height: normal;">'
        . "\n" . '  <div style="text-align:center;">'
        . "\n" . '    <img src="themes/default/icon/warning-triangle.svg" alt="warning-triangle" >'
        . "\n" . '    <p style="max-width: 400px; margin-top 20px;">You are not authorized to access the requested page</p>'
        . "\n" . '    <a href="' . $expectedIndexUrl . '" style="display: inline-block;padding: 10px 20px;margin: 10px;margin-top: 50px;border-radius: 7px;cursor: pointer;width: 150px;background-color: #F77000;color: #fff;text-decoration: none;border: 2px solid #F77000;">Home</a>'
        . "\n" . '  </div>'
        . "\n" . '</div>';

    try {
        $service->accessDenied($redirectService);
    } catch (ResponseReadyException $e) {
        expect($e->response()->getStatusCode())->toBe(401);
        expect((string) $e->response()->getBody())->toBe($expectedHtml);
    }
    expect($redirectService->capturedUrl)->toBeNull();
});

test('accessDenied redirects to identification.php with a double urlencoded REQUEST_URI when the user is unauthenticated', function (): void {
    $service = HtmlServiceTestFactory::build();
    $redirectService = new HtmlServiceTestCapturingRedirectHttpService();
    $_SERVER['REQUEST_URI'] = '/gallery/index.php?/category/5';

    try {
        // redirectHttp() is `never`-typed and this fake's implementation
        // unconditionally throws -- if it somehow didn't, PHP's own
        // return-type enforcement would raise a TypeError here, so no
        // separate "did it throw" assertion is needed.
        $service->accessDenied($redirectService);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('HTML_SERVICE_TEST_REDIRECT_HTTP_MARKER');
    } finally {
        unset($_SERVER['REQUEST_URI']);
    }

    expect($redirectService->capturedUrl)->toBe(
        'identification.php?redirect=' . urlencode(urlencode('/gallery/index.php?/category/5'))
    );
});

test('accessDenied falls back to an empty redirect path when REQUEST_URI is missing or not a string', function (): void {
    $service = HtmlServiceTestFactory::build();
    $redirectService = new HtmlServiceTestCapturingRedirectHttpService();
    // A crafted 'REQUEST_URI' can never really be non-scalar over a real
    // web SAPI, but $_SERVER is untyped -- accessDenied() narrows
    // defensively before using it.
    $_SERVER['REQUEST_URI'] = ['not', 'a', 'string'];

    try {
        $service->accessDenied($redirectService);
    } catch (RuntimeException) {
    } finally {
        unset($_SERVER['REQUEST_URI']);
    }

    expect($redirectService->capturedUrl)->toBe('identification.php?redirect=');
});

test('accessDenied falls back to an empty redirect path when REQUEST_URI is genuinely unset, not just non-string', function (): void {
    // Kills line 379's EmptyStringToNotEmpty on the `?? ''` coalesce --
    // the sibling test above sets REQUEST_URI to a real (non-string)
    // array value, so the key always exists and the coalesce's own
    // fallback is never actually exercised; only is_string()'s later
    // check catches that case.
    $service = HtmlServiceTestFactory::build();
    $redirectService = new HtmlServiceTestCapturingRedirectHttpService();
    $hadRequestUri = array_key_exists('REQUEST_URI', $_SERVER);
    $originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    unset($_SERVER['REQUEST_URI']);

    try {
        $service->accessDenied($redirectService);
    } catch (RuntimeException) {
    } finally {
        if ($hadRequestUri) {
            $_SERVER['REQUEST_URI'] = $originalRequestUri;
        }
    }

    expect($redirectService->capturedUrl)->toBe('identification.php?redirect=');
});

test('accessDenied prefixes the identification.php redirect with the real root url', function (): void {
    // Kills line 381's ConcatRemoveLeft (dropping getRootUrl() entirely)
    // and ConcatSwitchSides (reordering it after the redirect param) --
    // indistinguishable with the sibling tests' default empty
    // getRootUrl().
    htmlServiceTestRootPathOverride()->push('/gallery/');
    $service = HtmlServiceTestFactory::build();
    $redirectService = new HtmlServiceTestCapturingRedirectHttpService();
    $_SERVER['REQUEST_URI'] = '/x';

    try {
        $service->accessDenied($redirectService);
    } catch (RuntimeException) {
    } finally {
        unset($_SERVER['REQUEST_URI']);
        htmlServiceTestRootPathOverride()->reset();
    }

    expect($redirectService->capturedUrl)->toBe('/gallery/identification.php?redirect=' . urlencode(urlencode('/x')));
});

test('pageForbidden redirects to the given alternate url with a 403 status, a 5 second refresh, and the forbidden message', function (): void {
    $service = HtmlServiceTestFactory::build();
    $redirectService = new HtmlServiceTestCapturingRedirectHtmlService();

    try {
        $service->pageForbidden($redirectService, 'You lack permission.', '/custom-redirect.php');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('HTML_SERVICE_TEST_REDIRECT_HTML_MARKER');
    }

    // Kills line 398's 7 Concat* mutations (ConcatRemoveLeft/Right/
    // SwitchSides): toContain() alone can't tell a structurally
    // corrupted (reordered, or partially dropped-but-still-present)
    // message apart from the real one -- only an exact match can.
    expect($redirectService->capturedUrl)->toBe('/custom-redirect.php')
        ->and($redirectService->capturedStatus)->toBe(403)
        ->and($redirectService->capturedRefreshTime)->toBe(5)
        ->and($redirectService->capturedMsg)->toBe(
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">'
            . "\n" . '<h1 style="text-align:left; font-size:36px;">Forbidden</h1><br>You lack permission.</div>'
        );
});

test('pageForbidden computes the default alternate url via makeIndexUrl when none is given', function (): void {
    $service = HtmlServiceTestFactory::build();
    $redirectService = new HtmlServiceTestCapturingRedirectHtmlService();
    $expectedUrl = UrlServiceTestFactory::build($service)->makeIndexUrl();

    try {
        $service->pageForbidden($redirectService, 'blocked');
    } catch (RuntimeException) {
    }

    expect($redirectService->capturedUrl)->toBe($expectedUrl);
});

/**
 * Starts a real, disposable PHP built-in server bound to 127.0.0.1
 * serving $docRoot's index.php -- header() is a genuine, unobservable
 * no-op under Pest's own CLI SAPI (confirmed live: headers_list() stays
 * empty after a real header() call), so setStatusHeader()'s
 * SERVER_PROTOCOL fallback/validation logic (lines 611-613) and the
 * header() call itself (line 617) have no in-process side channel to
 * observe. A real HTTP request against a real running server, however,
 * DOES let a raw socket read back the exact status line PHP's SAPI
 * sends -- header() with a leading "HTTP/..." string is specially
 * recognized as a raw status-line override, so this is real,
 * production behavior, not a test-only shortcut.
 *
 * @return array{0: resource, 1: int} the process handle and the bound port
 */
function htmlServiceTestStartStatusHeaderServer(string $docRoot): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20_000, 60_000);
        $proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $docRoot], $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('failed to start local test server');
        }

        set_error_handler(static fn (): bool => true);
        try {
            for ($i = 0; $i < 100; $i++) {
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if (is_resource($sock)) {
                    fclose($sock);

                    return [$proc, $port];
                }
                usleep(20_000);
            }
        } finally {
            restore_error_handler();
        }

        proc_terminate($proc);
        proc_close($proc);
    }

    throw new RuntimeException('local test server never became reachable after 5 attempts');
}

/**
 * @param resource $proc
 */
function htmlServiceTestStopStatusHeaderServer($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

/**
 * Issues a raw HTTP/1.1 request over a fresh socket and returns just the
 * response's own status line (the first "HTTP/x.y NNN Reason" line) --
 * a real client library would normalize/hide the exact protocol version
 * and reason phrase text, which is exactly what's under test here.
 */
function htmlServiceTestRawStatusLine(int $port, string $query): string
{
    $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
    if (! is_resource($sock)) {
        throw new RuntimeException('failed to connect to local test server: ' . $errstr);
    }
    fwrite($sock, "GET /?{$query} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (! feof($sock)) {
        $raw .= fread($sock, 8192);
    }
    fclose($sock);

    return explode("\r\n", $raw, 2)[0];
}

/**
 * The three tests below (and their line-611/612/613/617 kill claims)
 * are individually confirmed live: each mutation was hand-applied to
 * this exact source line, this exact test group was re-run, and it
 * failed as expected, then the source was restored -- because
 * `pest --mutate`'s own scanner can't see failures raised inside a
 * proc_open() subprocess (the real `php -S` server these tests start),
 * the same "sed-mutate-and-run-directly" verification already
 * established for the `pest --mutate`-invisible subprocess tests in
 * HttpClientServiceTest.php. `pest --mutate` will keep reporting these
 * as UNTESTED even though they are, in fact, killed.
 */
test('setStatusHeader sends a real HTTP/1.1 status line, with the given code and reason phrase, for a genuine HTTP/1.1 request', function (): void {
    // Kills line 611's CoalesceRemoveLeft (would discard the real,
    // genuinely-set SERVER_PROTOCOL and always fall through to the
    // '' branch), line 612's TernaryNegated (would discard the real
    // string value instead of keeping it), and line 613's IfNegated /
    // first NotIdenticalToIdentical / BooleanAndToBooleanOr (each would
    // force the validation branch to fire even for this genuinely-valid
    // 'HTTP/1.1' value, overwriting it with 'HTTP/1.0'). A real request
    // sent as HTTP/1.1 makes PHP's own built-in server set
    // $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1' for real -- nothing
    // simulated.
    $docRoot = sys_get_temp_dir() . '/pwg-html-statusheader-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot, 0o777, true);
    file_put_contents(
        $docRoot . '/index.php',
        '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
        . '(new \Piwigo\Html\HtmlService(new \Piwigo\Config\CurrentConfig(), new \Piwigo\PluginConfig\EventDispatcher(), new \Piwigo\Core\ProcessCache(), new \Piwigo\Core\ErrorCollector(new \Piwigo\Config\DeploymentPolicy(), \Piwigo\Core\Paths::fromRoot(sys_get_temp_dir())), new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()), new \Piwigo\Template\CurrentTemplate(), new \Piwigo\Core\PageState(), new \Piwigo\Lang\Translator(new \Piwigo\Config\CurrentConfig())))->setStatusHeader((int) ($_GET[\'code\'] ?? 404), (string) ($_GET[\'text\'] ?? \'\'));',
    );

    [$proc, $port] = htmlServiceTestStartStatusHeaderServer($docRoot);

    try {
        $statusLine = htmlServiceTestRawStatusLine($port, 'code=404');

        expect($statusLine)->toBe('HTTP/1.1 404 Not found');
    } finally {
        htmlServiceTestStopStatusHeaderServer($proc);
        unlink($docRoot . '/index.php');
        rmdir($docRoot);
    }
});

test('setStatusHeader falls back to HTTP/1.0 for a SERVER_PROTOCOL value that is neither well-known version', function (): void {
    // Kills line 613's second NotIdenticalToIdentical
    // (`$protocol === 'HTTP/1.0'` instead of `!==`) -- indistinguishable
    // from the sibling test above using a genuinely-empty/missing
    // SERVER_PROTOCOL (both '' and 'HTTP/1.0' collapse to the same
    // "!== 'HTTP/1.0'" truth value under the mutant too). A third,
    // *non-empty, non-well-known* value (neither 'HTTP/1.1' nor
    // 'HTTP/1.0') is required: real code still normalizes it to
    // 'HTTP/1.0', but the mutant's second clause is now trivially false
    // for this value, short-circuiting the whole condition to false and
    // leaving the raw, invalid 'HTTP/9.9' to reach header() unmodified.
    $docRoot = sys_get_temp_dir() . '/pwg-html-statusheader-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot, 0o777, true);
    file_put_contents(
        $docRoot . '/index.php',
        '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
        . '$_SERVER[\'SERVER_PROTOCOL\'] = \'HTTP/9.9\';'
        . '(new \Piwigo\Html\HtmlService(new \Piwigo\Config\CurrentConfig(), new \Piwigo\PluginConfig\EventDispatcher(), new \Piwigo\Core\ProcessCache(), new \Piwigo\Core\ErrorCollector(new \Piwigo\Config\DeploymentPolicy(), \Piwigo\Core\Paths::fromRoot(sys_get_temp_dir())), new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()), new \Piwigo\Template\CurrentTemplate(), new \Piwigo\Core\PageState(), new \Piwigo\Lang\Translator(new \Piwigo\Config\CurrentConfig())))->setStatusHeader((int) ($_GET[\'code\'] ?? 404), (string) ($_GET[\'text\'] ?? \'\'));',
    );

    [$proc, $port] = htmlServiceTestStartStatusHeaderServer($docRoot);

    try {
        $statusLine = htmlServiceTestRawStatusLine($port, 'code=404');

        expect($statusLine)->toBe('HTTP/1.0 404 Not found');
    } finally {
        htmlServiceTestStopStatusHeaderServer($proc);
        unlink($docRoot . '/index.php');
        rmdir($docRoot);
    }
});

/**
 * Confirmed-equivalent: line 611's EmptyStringToNotEmpty
 * (`$_SERVER['SERVER_PROTOCOL'] ?? 'PEST Mutator was here!'` instead of
 * `?? ''`) and line 612's EmptyStringToNotEmpty (the `is_string()`
 * ternary's own false-branch placeholder). Whatever fallback string
 * either produces, line 613 unconditionally normalizes ANY value that
 * isn't exactly 'HTTP/1.1' or 'HTTP/1.0' to 'HTTP/1.0' -- and
 * "PEST Mutator was here!" is neither, so it collapses to the exact
 * same final 'HTTP/1.0' the real '' fallback also collapses to. Live
 * sed-verified against the "falls back to HTTP/1.0" test above with
 * each mutation applied in turn: both pass identically.
 *
 * Also confirmed-equivalent: line 617's TrueToFalse (`header(...,
 * false, $code)` instead of `true`). Empirically confirmed live (two
 * consecutive real setStatusHeader() calls with the $replace parameter
 * hand-mutated to false, requested through this same real-server
 * technique): PHP treats a raw "HTTP/..." status-line header specially
 * -- the $replace parameter has no effect on it; the most recent such
 * call always wins regardless. A genuinely non-status-line header
 * (X-Test) confirmed the *technique itself* correctly detects a real
 * $replace=false difference (both values appear), ruling out a probe
 * artifact.
 */
test('setStatusHeader actually calls header(), not a no-op, for a real request', function (): void {
    // Kills line 617's RemoveFunctionCall -- without a real header()
    // call, the server's default 200 status line would be sent
    // regardless of the requested code.
    $docRoot = sys_get_temp_dir() . '/pwg-html-statusheader-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot, 0o777, true);
    file_put_contents(
        $docRoot . '/index.php',
        '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
        . '(new \Piwigo\Html\HtmlService(new \Piwigo\Config\CurrentConfig(), new \Piwigo\PluginConfig\EventDispatcher(), new \Piwigo\Core\ProcessCache(), new \Piwigo\Core\ErrorCollector(new \Piwigo\Config\DeploymentPolicy(), \Piwigo\Core\Paths::fromRoot(sys_get_temp_dir())), new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()), new \Piwigo\Template\CurrentTemplate(), new \Piwigo\Core\PageState(), new \Piwigo\Lang\Translator(new \Piwigo\Config\CurrentConfig())))->setStatusHeader((int) ($_GET[\'code\'] ?? 404), (string) ($_GET[\'text\'] ?? \'\'));',
    );

    [$proc, $port] = htmlServiceTestStartStatusHeaderServer($docRoot);

    try {
        $statusLine = htmlServiceTestRawStatusLine($port, 'code=404');

        expect($statusLine)->toContain('404');
    } finally {
        htmlServiceTestStopStatusHeaderServer($proc);
        unlink($docRoot . '/index.php');
        rmdir($docRoot);
    }
});
