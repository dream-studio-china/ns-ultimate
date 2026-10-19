<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;

/**
 * Adds integration-owned single-table entity subtypes without changing the
 * mapped core classes. Each mapping is configured as root class => discriminator
 * value => concrete class.
 */
final class InheritanceMappingDriver implements MappingDriver
{
    /**
     * @param array<class-string, array{column: string, map: array<string, class-string>}> $inheritanceMaps
     */
    public function __construct(
        private readonly MappingDriver $inner,
        private readonly array $inheritanceMaps,
    ) {
    }

    public function loadMetadataForClass(string $className, \Doctrine\Persistence\Mapping\ClassMetadata $metadata): void
    {
        $this->inner->loadMetadataForClass($className, $metadata);

        $inheritance = $this->inheritanceMaps[$className] ?? null;
        if ($inheritance === null) {
            return;
        }

        if (!$metadata instanceof ClassMetadata) {
            throw new \LogicException(sprintf('Entity inheritance for "%s" requires Doctrine ORM ClassMetadata.', $className));
        }

        foreach ($inheritance['map'] as $subclass) {
            if (!is_a($subclass, $className, true)) {
                throw new \LogicException(sprintf('Configured entity "%s" must extend "%s".', $subclass, $className));
            }
        }

        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);
        $metadata->setDiscriminatorColumn([
            'name' => $inheritance['column'],
            'type' => 'string',
            'length' => 32,
            'options' => ['default' => (string) array_key_first($inheritance['map'])],
        ]);
        $metadata->setDiscriminatorMap($inheritance['map']);
    }

    /** @return list<class-string> */
    public function getAllClassNames(): array
    {
        return $this->inner->getAllClassNames();
    }

    public function isTransient(string $className): bool
    {
        return $this->inner->isTransient($className);
    }
}
