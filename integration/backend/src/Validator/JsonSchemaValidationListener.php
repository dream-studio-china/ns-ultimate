<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Validator;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Applies declared JSON Schema constraints before Doctrine writes mapped entities. */
final class JsonSchemaValidationListener
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    public function onFlush(OnFlushEventArgs $eventArgs): void
    {
        $unitOfWork = $eventArgs->getObjectManager()->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            $metadata = $this->validator->getMetadataFor($entity);
            if (!$metadata instanceof ClassMetadataInterface || !$this->hasJsonSchemaConstraint($metadata)) {
                continue;
            }

            $violations = $this->validator->validate($entity);
            if (count($violations) > 0) {
                throw new ValidatorException((string) $violations);
            }
        }
    }

    private function hasJsonSchemaConstraint(ClassMetadataInterface $metadata): bool
    {
        foreach ($metadata->getConstrainedProperties() as $property) {
            foreach ($metadata->getPropertyMetadata($property) as $propertyMetadata) {
                foreach ($propertyMetadata->getConstraints() as $constraint) {
                    if ($constraint instanceof JsonSchema) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
