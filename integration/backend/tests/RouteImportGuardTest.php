<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use LogicException;
use NsUltimate\Integration\Backend\RouteImportGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RouteImportGuardTest extends TestCase
{
    public function testRejectsRouteNamesAlreadyRegistered(): void
    {
        $existing = new RouteCollection();
        $existing->add('shared-name', new Route('/existing'));
        $incoming = new RouteCollection();
        $incoming->add('shared-name', new Route('/incoming'));

        $this->expectException(LogicException::class);
        RouteImportGuard::assertNoCollisions($existing, $incoming, 'test module');
    }
}
