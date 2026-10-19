<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Validator;

use App\Core\Validator\JsonSchemaValidator as CoreJsonSchemaValidator;
use App\Core\Validator\JsonSchemaViolationException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class JsonSchemaValidator extends ConstraintValidator
{
    /** @param array<string, string> $schemas */
    public function __construct(
        private readonly CoreJsonSchemaValidator $validator,
        private readonly array $schemas,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof JsonSchema) {
            throw new UnexpectedTypeException($constraint, JsonSchema::class);
        }
        if ($value === null) {
            return;
        }

        $path = $this->schemas[$constraint->schema] ?? null;
        if (!is_string($path) || !is_file($path)) {
            throw new \LogicException(sprintf('Business JSON Schema "%s" was not discovered.', $constraint->schema));
        }

        $schema = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($schema)) {
            throw new \LogicException(sprintf('Business JSON Schema "%s" must be a JSON object.', $constraint->schema));
        }

        try {
            $this->validator->validateInline($value, $schema);
        } catch (JsonSchemaViolationException $exception) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ schema }}', $constraint->schema)
                ->setParameter('{{ detail }}', $exception->getMessage())
                ->addViolation();
        }
    }
}
