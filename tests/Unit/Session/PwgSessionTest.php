<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Piwigo\Session\SessionService;

/**
 * Structural tests for SessionService as a SessionHandlerInterface implementor.
 *
 * SessionService now implements SessionHandlerInterface directly — no adapter needed.
 * DB-dependent behaviour is tested in the integration suite; here we verify only
 * structural contracts: correct interface, correct method signatures.
 */
final class PwgSessionTest extends TestCase
{
    public function test_implements_session_handler_interface(): void
    {
        $interfaces = class_implements(SessionService::class);
        self::assertContains(\SessionHandlerInterface::class, $interfaces !== false ? $interfaces : []);
    }

    public function test_has_all_session_handler_methods(): void
    {
        $required = ['open', 'close', 'read', 'write', 'destroy', 'gc'];
        foreach ($required as $method) {
            self::assertTrue(
                method_exists(SessionService::class, $method),
                "SessionService must implement SessionHandlerInterface::$method()"
            );
        }
    }

    public function test_gc_return_type_is_int(): void
    {
        $ref = new \ReflectionMethod(SessionService::class, 'gc');
        $returnType = $ref->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('int', (string) $returnType);
    }

    public function test_read_return_type_is_string(): void
    {
        $ref = new \ReflectionMethod(SessionService::class, 'read');
        $returnType = $ref->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('string', (string) $returnType);
    }
}
