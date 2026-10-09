<?php

declare(strict_types=1);

namespace App\Storage\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class MediaStorageRegistry
{
    /** @var array<string, MediaStorageInterface> */
    private array $drivers = [];

    /** @param iterable<MediaStorageInterface> $drivers */
    public function __construct(#[AutowireIterator('media.storage')] iterable $drivers)
    {
        foreach ($drivers as $driver) {
            $this->drivers[$driver::getName()] = $driver;
        }
    }

    public function get(string $name): MediaStorageInterface
    {
        if (!isset($this->drivers[$name])) {
            throw new \RuntimeException(sprintf('Unknown media storage driver "%s".', $name));
        }

        return $this->drivers[$name];
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->drivers);
    }
}
