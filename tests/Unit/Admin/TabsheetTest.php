<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Admin\Event\TabsheetBeforeSelect;
use Piwigo\Admin\Projection\TabSheetEntry;
use Piwigo\Admin\Tabsheet;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

function tabsheetTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? tabsheetTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

// Template::__construct() unconditionally mkgetdir()s a real "templates_c"
// compile directory under CurrentPathsTestFactory::get()->root -- same
// "point CurrentPaths at a fresh temp root, clean it up after" shape as
// PictureCommentRendererTest's own makePictureCommentTestTemplate().
// Tabsheet::assign()'s own Renderer::render() call actually parses a
// real 'tabsheet.latte' through Latte (theme='' -> template_dir is the
// $root passed to the constructor), so a trivial real file is seeded at
// that same root -- its rendered content is never asserted on except
// where a test overwrites it with its own minimal fixture body.
beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-tabsheet-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    file_put_contents($root . '/tabsheet.latte', '');
    // Captured on $this, not re-read via CurrentPathsTestFactory::get() in
    // afterEach() below -- if Kernel::boot() throws here (a prior test left
    // Kernel booted against a different root without resetting), afterEach()
    // still runs, and re-resolving through the container would delete
    // whatever root that earlier, unrelated test left bound instead of this
    // test's own fixture root.
    $this->root = $root;
    Kernel::boot(Paths::fromRoot($root));
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->dataLocation = 'data/';
    $currentConfig->dataDirChecked = '1';
    CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build($root));
});

afterEach(function (): void {
    tabsheetTestRrmdir($this->root);
    CurrentTemplateTestFactory::get()->reset();
    Kernel::reset();
    CurrentConfigTestFactory::get()->reset();
});

test('the constructor defaults name/titlename and starts with no tabs and nothing selected', function (): void {
    $tabsheet = new Tabsheet();

    expect($tabsheet->name)
        ->toBe('TABSHEET');
    expect($tabsheet->titlename)
        ->toBe('TABSHEET_TITLE');
    expect($tabsheet->sheets)
        ->toBe([]);
    expect($tabsheet->uniqid)
        ->toBeNull();
    expect($tabsheet->selected)
        ->toBe('');
    expect($tabsheet->getSelected())
        ->toBeNull();
});

test('the constructor accepts custom name/titlename', function (): void {
    $tabsheet = new Tabsheet('CUSTOM_SHEET', 'CUSTOM_TITLE');

    expect($tabsheet->name)
        ->toBe('CUSTOM_SHEET');
    expect($tabsheet->titlename)
        ->toBe('CUSTOM_TITLE');
});

test('setId stores the given id', function (): void {
    $tabsheet = new Tabsheet();
    $tabsheet->setId('my-tabsheet');

    expect($tabsheet->uniqid)
        ->toBe('my-tabsheet');
});

test('add succeeds for a new tab name and fails for a duplicate one', function (): void {
    $tabsheet = new Tabsheet();

    expect($tabsheet->add('general', 'General', '/admin.php?page=general'))
        ->toBeTrue();
    expect($tabsheet->sheets)
        ->toEqual([
            'general' => new TabSheetEntry('General', '/admin.php?page=general'),
        ]);

    expect($tabsheet->add('general', 'General Again', '/admin.php?page=general2'))
        ->toBeFalse();
    // The failed add must not have overwritten the original entry.
    expect($tabsheet->sheets['general'])->toEqual(new TabSheetEntry('General', '/admin.php?page=general'));
});

test('add with selected=true marks that tab as the selected one', function (): void {
    $tabsheet = new Tabsheet();
    $tabsheet->add('general', 'General', '/general', false);
    $tabsheet->add('advanced', 'Advanced', '/advanced', true);

    expect($tabsheet->selected)
        ->toBe('advanced');
});

test('delete removes an existing tab and returns true, or returns false for an unknown one', function (): void {
    $tabsheet = new Tabsheet();
    $tabsheet->add('general', 'General', '/general');
    $tabsheet->add('advanced', 'Advanced', '/advanced');

    expect($tabsheet->delete('general'))
        ->toBeTrue();
    expect($tabsheet->sheets)
        ->toEqual([
            'advanced' => new TabSheetEntry('Advanced', '/advanced'),
        ]);

    expect($tabsheet->delete('not-a-real-tab'))
        ->toBeFalse();
});

test('delete clears the selected tab when the deleted tab was the selected one', function (): void {
    $tabsheet = new Tabsheet();
    $tabsheet->add('general', 'General', '/general', true);

    expect($tabsheet->selected)
        ->toBe('general');
    $tabsheet->delete('general');
    expect($tabsheet->selected)
        ->toBe('');
});

test('delete leaves the selected tab untouched when a different tab is deleted', function (): void {
    $tabsheet = new Tabsheet();
    $tabsheet->add('general', 'General', '/general', true);
    $tabsheet->add('advanced', 'Advanced', '/advanced');

    $tabsheet->delete('advanced');
    expect($tabsheet->selected)
        ->toBe('general');
});

test('select picks the requested tab when it exists', function (): void {
    $tabsheet = new Tabsheet();
    $tabsheet->add('general', 'General', '/general');
    $tabsheet->add('advanced', 'Advanced', '/advanced');

    $tabsheet->select('advanced', EventDispatcherTestFactory::get());

    expect($tabsheet->selected)
        ->toBe('advanced');
    expect($tabsheet->getSelected())
        ->toEqual(new TabSheetEntry('Advanced', '/advanced'));
});

test('select falls back to the first remaining tab when the requested name does not exist', function (): void {
    $tabsheet = new Tabsheet();
    $tabsheet->add('general', 'General', '/general');
    $tabsheet->add('advanced', 'Advanced', '/advanced');

    $tabsheet->select('not-a-real-tab', EventDispatcherTestFactory::get());

    expect($tabsheet->selected)
        ->toBe('general');
});

test('select applies a tabsheet_before_select handler that filters and appends tabs', function (): void {
    $handler = function (TabsheetBeforeSelect $event): TabsheetBeforeSelect {
        $sheets = $event->sheets;
        unset($sheets['removed-by-handler']);
        $sheets['added-by-handler'] = [
            'caption' => 'Added',
            'url' => '/added',
        ];
        $event->sheets = $sheets;

        return $event;
    };
    EventDispatcherTestFactory::get()->addTypedHandler(TabsheetBeforeSelect::class, $handler);

    try {
        $tabsheet = new Tabsheet();
        $tabsheet->add('removed-by-handler', 'Removed', '/removed');
        $tabsheet->add('kept', 'Kept', '/kept');

        $tabsheet->select('added-by-handler', EventDispatcherTestFactory::get());

        expect($tabsheet->sheets)
            ->toEqual([
                'kept' => new TabSheetEntry('Kept', '/kept'),
                'added-by-handler' => new TabSheetEntry('Added', '/added'),
            ]);
        expect($tabsheet->selected)
            ->toBe('added-by-handler');
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(TabsheetBeforeSelect::class, $handler);
    }
});

test('select discards a handler-returned entry that does not match the expected shape', function (): void {
    $handler = function (TabsheetBeforeSelect $event): TabsheetBeforeSelect {
        $sheets = $event->sheets;
        $sheets['malformed'] = [
            'caption' => 'Missing url',
        ];
        $sheets[] = 'not-even-an-array-entry'; // int key, non-array value
        $event->sheets = $sheets;

        return $event;
    };
    EventDispatcherTestFactory::get()->addTypedHandler(TabsheetBeforeSelect::class, $handler);

    try {
        $tabsheet = new Tabsheet();
        $tabsheet->add('general', 'General', '/general');

        $tabsheet->select('general', EventDispatcherTestFactory::get());

        expect($tabsheet->sheets)
            ->toEqual([
                'general' => new TabSheetEntry('General', '/general'),
            ]);
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(TabsheetBeforeSelect::class, $handler);
    }
});

test('select discards a well-shaped sheet entry keyed by an int, not just a malformed one', function (): void {
    // Distinct from the malformed-shape test above: here the *value* is a
    // perfectly valid ['caption'=>string, 'url'=>string] array -- only the
    // *key* is wrong (an int, not a string). Both checks (is_string($key)
    // and is_array($value)) must independently hold; this proves the key
    // check alone can't be skipped just because the value looks fine.
    $handler = function (TabsheetBeforeSelect $event): TabsheetBeforeSelect {
        $sheets = $event->sheets;
        $sheets[] = [
            'caption' => 'Int Keyed',
            'url' => '/int-keyed',
        ];
        $event->sheets = $sheets;

        return $event;
    };
    EventDispatcherTestFactory::get()->addTypedHandler(TabsheetBeforeSelect::class, $handler);

    try {
        $tabsheet = new Tabsheet();
        $tabsheet->add('general', 'General', '/general');

        $tabsheet->select('general', EventDispatcherTestFactory::get());

        expect($tabsheet->sheets)
            ->toEqual([
                'general' => new TabSheetEntry('General', '/general'),
            ]);
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(TabsheetBeforeSelect::class, $handler);
    }
});

test('assign makes the sheets array available to the tabsheet.latte template before rendering', function (): void {
    file_put_contents(CurrentPathsTestFactory::get()->root . '/tabsheet.latte', 'CAPTION:{$sheets[\'general\'][\'caption\']}');

    $tabsheet = new Tabsheet('MY_TABSHEET', 'MY_TITLE');
    $tabsheet->add('general', 'General Settings', '/general');

    $tabsheet->assign(CurrentTemplateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get()));

    $template = CurrentTemplateTestFactory::get()->get();
    // Renderer::render() wraps the result in Latte\Runtime\Html.
    $tabsheetVar = $template->getTemplateVars('MY_TABSHEET');
    expect($tabsheetVar)
        ->toBeInstanceOf(Html::class);
    if (! $tabsheetVar instanceof Html) {
        throw new LogicException('unreachable -- asserted above');
    }
    expect((string) $tabsheetVar)
        ->toBe('CAPTION:General Settings');
});

test('setTitlename overwrites titlename and returns the new value', function (): void {
    $tabsheet = new Tabsheet();

    expect($tabsheet->setTitlename('NEW_TITLE'))
        ->toBe('NEW_TITLE');
    expect($tabsheet->titlename)
        ->toBe('NEW_TITLE');
    expect($tabsheet->getTitlename())
        ->toBe('NEW_TITLE');
});

test('assign writes the sheets, the selected key, and the bracketed selected caption into the template', function (): void {
    file_put_contents(CurrentPathsTestFactory::get()->root . '/tabsheet.latte', 'SELECTED:{$selected}');

    $tabsheet = new Tabsheet('MY_TABSHEET', 'MY_TITLE');
    $tabsheet->add('general', 'General Settings', '/general', true);
    $tabsheet->add('advanced', 'Advanced', '/advanced');

    $tabsheet->assign(CurrentTemplateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get()));

    $template = CurrentTemplateTestFactory::get()->get();
    $tabsheetVar = $template->getTemplateVars('MY_TABSHEET');
    expect($tabsheetVar)
        ->toBeInstanceOf(Html::class);
    if (! $tabsheetVar instanceof Html) {
        throw new LogicException('unreachable -- asserted above');
    }
    expect((string) $tabsheetVar)
        ->toBe('SELECTED:general');
    expect($template->getTemplateVars('MY_TITLE'))
        ->toBe('[General Settings]');
});

test('assign does not set the titlename var when nothing is selected', function (): void {
    file_put_contents(CurrentPathsTestFactory::get()->root . '/tabsheet.latte', 'SELECTED:{$selected}');

    $tabsheet = new Tabsheet('MY_TABSHEET', 'MY_TITLE');
    $tabsheet->add('general', 'General', '/general');

    $tabsheet->assign(CurrentTemplateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get()));

    $template = CurrentTemplateTestFactory::get()->get();
    $tabsheetVar = $template->getTemplateVars('MY_TABSHEET');
    expect($tabsheetVar)
        ->toBeInstanceOf(Html::class);
    if (! $tabsheetVar instanceof Html) {
        throw new LogicException('unreachable -- asserted above');
    }
    expect((string) $tabsheetVar)
        ->toBe('SELECTED:');
    expect($template->getTemplateVars('MY_TITLE'))
        ->toBeNull();
});
