<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\PageState;

final class PageStateTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        PageState::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        PageState::reset();
    }

    public function test_current_returns_same_instance(): void
    {
        $a = PageState::current();
        $b = PageState::current();
        self::assertSame($a, $b);
    }

    public function test_addError_appends_in_order(): void
    {
        $ps = PageState::current();
        $ps->addError('first');
        $ps->addError('second');

        self::assertSame(['first', 'second'], $ps->errors);
    }

    public function test_addWarning_appends(): void
    {
        $ps = PageState::current();
        $ps->addWarning('w1');
        self::assertSame(['w1'], $ps->warnings);
    }

    public function test_addMessage_appends(): void
    {
        $ps = PageState::current();
        $ps->addMessage('m1');
        self::assertSame(['m1'], $ps->messages);
    }

    public function test_addInfo_appends(): void
    {
        $ps = PageState::current();
        $ps->addInfo('i1');
        self::assertSame(['i1'], $ps->infos);
    }

    public function test_addBodyClass_appends(): void
    {
        $ps = PageState::current();
        $ps->addBodyClass('page-home');
        self::assertSame(['page-home'], $ps->bodyClasses);
    }

    public function test_hasErrors_false_on_empty(): void
    {
        self::assertFalse(PageState::current()->hasErrors());
    }

    public function test_hasErrors_true_after_addError(): void
    {
        PageState::current()->addError('oops');
        self::assertTrue(PageState::current()->hasErrors());
    }

    public function test_all_buckets_independent(): void
    {
        $ps = PageState::current();
        $ps->addError('e');
        $ps->addWarning('w');
        $ps->addInfo('i');
        $ps->addMessage('m');

        self::assertCount(1, $ps->errors);
        self::assertCount(1, $ps->warnings);
        self::assertCount(1, $ps->infos);
        self::assertCount(1, $ps->messages);
    }

    /** Step 2 exit signal: pushes one of each kind, asserts read accessors return them in order. */
    public function test_push_one_of_each_kind_and_read_values_in_order(): void
    {
        $ps = PageState::current();
        $ps->addError('error1');
        $ps->addWarning('warn1');
        $ps->addInfo('info1');
        $ps->addMessage('msg1');

        self::assertSame(['error1'], $ps->errors);
        self::assertSame(['warn1'], $ps->warnings);
        self::assertSame(['info1'], $ps->infos);
        self::assertSame(['msg1'], $ps->messages);
    }

    public function test_mergeFromConf_appends_notes_to_infos(): void
    {
        $ps = PageState::current();
        $ps->mergeFromConf(['note A', 'note B']);

        self::assertSame(['note A', 'note B'], $ps->infos);
    }

    public function test_mergeFromConf_preserves_existing_infos(): void
    {
        $ps = PageState::current();
        $ps->addInfo('existing');
        $ps->mergeFromConf(['from conf']);

        self::assertSame(['existing', 'from conf'], $ps->infos);
    }

    public function test_reset_clears_singleton(): void
    {
        $first = PageState::current();
        $first->addError('x');
        PageState::reset();
        $second = PageState::current();

        self::assertNotSame($first, $second);
        self::assertSame([], $second->errors);
    }
}
