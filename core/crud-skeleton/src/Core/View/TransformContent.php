<?php

namespace App\Core\View;

use App\Core\Utils\ArrayCommon;
use App\Core\Utils\Math;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Validator\Exception\ValidatorException;

/**
 * @example
 *   POST /api/contents
 *   data: {"title": "Title here", "category": "Test"}
 *   parameter: {"category": "Service.get({'name': ':value'}).getId()"}
 */
trait TransformContent
{
    /**
     * @param array<string, mixed> $content
     * @param array<string, string> $transformer
     * @return array<string, mixed>
     */
    protected function transformContent(array $content, array $transformer, object $entity): array
    {
        $expressionLanguage = new ExpressionLanguage();

        foreach ($transformer as $field => $expression) {

            if(array_key_exists($field, $content)) {
                $value = $content[$field];
            }
            else {
                continue;
            }

            $reflect = new \ReflectionClass($entity);

            if (!$reflect->hasProperty($field)) {
                throw new ValidatorException(ApiViewMessages::INVALID_CONTENT_FIELD);
            }
            $property = $reflect->getProperty($field);
            $service = null;

            foreach ($property->getAttributes() as $attribute) {
                $annotation = $attribute->newInstance();
                if (
                    $annotation instanceof ManyToOne ||
                    $annotation instanceof OneToOne ||
                    $annotation instanceof ManyToMany ||
                    $annotation instanceof OneToMany
                ) {
                    $dataClass = $annotation->targetEntity;

                    // Not accuracy
                    $serviceClass = str_replace('Entity', 'Service', (string) $dataClass) . 'Service';
                    $service = $this->resolveService($serviceClass);
                }
            }

            $serviceGateway = new class($service) {
                public function __construct(private readonly ?object $service)
                {
                }

                public function get(mixed $criteria): mixed
                {
                    if ($this->service === null || !method_exists($this->service, 'get')) {
                        throw new \RuntimeException('Related service does not support get().');
                    }

                    return $this->identity($this->service->get($this->validateCriteria($criteria)));
                }

                public function list(mixed $criteria = null): mixed
                {
                    if ($this->service === null || !method_exists($this->service, 'list')) {
                        throw new \RuntimeException('Related service does not support list().');
                    }

                    $results = $this->service->list($this->validateCriteria($criteria));
                    if (!is_iterable($results)) {
                        throw new \RuntimeException('Related service list() must return an iterable result.');
                    }

                    $identities = [];
                    foreach ($results as $result) {
                        if (!is_object($result)) {
                            throw new \RuntimeException('Related service list() returned a non-object result.');
                        }
                        $identities[] = $this->identity($result);
                    }

                    return $identities;
                }

                private function validateCriteria(mixed $criteria): mixed
                {
                    if (is_scalar($criteria)) {
                        return $criteria;
                    }

                    if (!is_array($criteria) || $criteria === []) {
                        throw new \InvalidArgumentException('Service criteria must be a non-empty scalar value or array of scalar values.');
                    }

                    foreach ($criteria as $value) {
                        if (!is_scalar($value) && $value !== null) {
                            throw new \InvalidArgumentException('Service criteria must not contain objects or nested arrays.');
                        }
                    }

                    return $criteria;
                }

                private function identity(?object $entity): object
                {
                    return new class($entity) {
                        public function __construct(private readonly ?object $entity)
                        {
                        }

                        public function getId(): mixed
                        {
                            if ($this->entity === null || !method_exists($this->entity, 'getId')) {
                                return null;
                            }

                            return $this->entity->getId();
                        }
                    };
                }
            };

            $entityGateway = new class($entity) {
                public function __construct(private readonly object $entity)
                {
                }

                public function getId(): mixed
                {
                    if (!method_exists($this->entity, 'getId')) {
                        return null;
                    }

                    return $this->entity->getId();
                }
            };


            $expression = str_replace(':value', (string) $value, $expression);
            try {
                $content[$field] = $expressionLanguage->evaluate(
                    $expression, [
                        'Service' => $serviceGateway,
                        'entity' => $entityGateway,
                        'Math' => new Math(),
                        'ArrayCommon' => new ArrayCommon()
                    ]
                );
            }
            catch (\Exception $exception) {}
        }

        return $content;
    }

}
