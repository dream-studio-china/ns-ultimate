<?php

declare(strict_types=1);

namespace App\Tests\Integration;

trait DatabaseBootstrapTrait
{
    protected static bool $dbBootstrapped = false;

    protected function bootTestDatabase(): void
    {
        if (self::$dbBootstrapped) {
            return;
        }

        $kernel = self::bootKernel();
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $application->setAutoExit(false);

        $drop = new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'doctrine:schema:drop',
            '--force' => true,
            '--full-database' => true,
            '--env' => 'test',
            '--quiet' => true,
        ]);
        $application->run($drop, new \Symfony\Component\Console\Output\NullOutput());

        $create = new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'doctrine:schema:create',
            '--env' => 'test',
            '--quiet' => true,
        ]);
        $application->run($create, new \Symfony\Component\Console\Output\NullOutput());

        self::$dbBootstrapped = true;
    }

    /**
     * Ensure KernelTestCase can find the Kernel class in this test suite.
     */
    protected static function getKernelClass(): string
    {
        return 'App\\Kernel';
    }
}
