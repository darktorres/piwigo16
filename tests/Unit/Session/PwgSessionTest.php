<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Piwigo\Session\PwgSession;

/**
 * Unit tests for PwgSession.
 *
 * PwgSession is a thin SessionHandlerInterface adapter that delegates every
 * method to pwg_session_* free functions defined in functions_sessions.inc.php.
 * These functions require a live DB, so we verify structural contracts only:
 * the class implements the interface correctly and its method signatures match.
 */
final class PwgSessionTest extends TestCase
{
    public function test_implements_session_handler_interface(): void
    {
        $session = new PwgSession();
        self::assertInstanceOf(\SessionHandlerInterface::class, $session);
    }

    public function test_has_all_session_handler_methods(): void
    {
        $required = ['open', 'close', 'read', 'write', 'destroy', 'gc'];
        foreach ($required as $method) {
            self::assertTrue(
                method_exists(PwgSession::class, $method),
                "PwgSession must implement SessionHandlerInterface::$method()"
            );
        }
    }

    public function test_gc_return_type_is_int(): void
    {
        $ref = new \ReflectionMethod(PwgSession::class, 'gc');
        $returnType = $ref->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('int', (string) $returnType);
    }

    public function test_read_return_type_is_string(): void
    {
        $ref = new \ReflectionMethod(PwgSession::class, 'read');
        $returnType = $ref->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('string', (string) $returnType);
    }
}
