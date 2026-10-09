<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class IntegrationKernelTestCase extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        if (!class_exists(\App\Kernel::class, false)) {
            require_once dirname(__DIR__, 2) . '/src/Kernel.php';
        }

        return \App\Kernel::class;
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        $class = static::getKernelClass();
        $env = $options['environment'] ?? $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'test';
        $debug = $options['debug'] ?? $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? true;

        return new $class($env, (bool) $debug);
    }
}

