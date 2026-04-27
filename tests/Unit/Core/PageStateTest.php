<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\PageState;

final class PageStateTest extends TestCase
{
    protected function setUp(): void
    {
        PageState::reset();
    }

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
