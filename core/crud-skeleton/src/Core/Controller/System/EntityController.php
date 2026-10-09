<?php

namespace App\Core\Controller\System;

use App\Core\Controller\RestController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/system/entities', name: 'system-entity-')]
class EntityController extends RestController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[OA\Get(
        path: '/system/entities',
        summary: 'List all registered Doctrine entities',
        description: 'Returns FQCNs of all entities managed by Doctrine ORM.',
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'String array of entity FQCNs',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string'), example: ['App\Common\Entity\Category', 'App\Common\Entity\Tag']),
                        new OA\Property(property: 'code', type: 'integer', example: 0),
                        new OA\Property(property: 'message', type: 'string', example: 'SUCCESS'),
                    ]
                )
            ),
        ]
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        $entities = [];
        $metadatas = $this->entityManager->getMetadataFactory()->getAllMetadata();
        foreach ($metadatas as $metadata) {
            $entities[] = $metadata->getName();
        }

        return $this->success($entities);
    }

    #[OA\Get(
        path: '/system/entities/{entityName}',
        summary: 'Get field and association metadata for an entity',
        description: 'Returns field mappings (type, nullable, etc.), association mappings (type, targetEntity), and auto-generated plaintext/translation for each property.',
        tags: ['System'],
        parameters: [
            new OA\Parameter(
                name: 'entityName',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'FQCN with slashes instead of backslashes, e.g. App/Common/Entity/Category',
                example: 'App/Common/Entity/Category'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entity field and association metadata',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', additionalProperties: new OA\AdditionalProperties(
                            properties: [
                                new OA\Property(property: 'metadata', description: 'Doctrine field mapping or association info'),
                                new OA\Property(property: 'plantext', type: 'string', example: 'Created at'),
                                new OA\Property(property: 'translation', type: 'string', example: 'Created at'),
                            ]
                        )),
                        new OA\Property(property: 'code', type: 'integer', example: 0),
                        new OA\Property(property: 'message', type: 'string', example: 'SUCCESS'),
                    ]
                )
            ),
        ]
    )]
    #[Route('/{entityName}', name: 'retrieve', methods: ['GET'], requirements: ['entityName' => '.+'])]
    public function retrieveAction(string $entityName): Response
    {
        $entityName = str_replace('/', '\\', $entityName);

        /** @var class-string<object> $entityName */
        /** @var ClassMetadata<object> $metadata */
        $metadata = $this->entityManager->getClassMetadata($entityName);
        $entityFields = [];

        foreach ($metadata->fieldMappings as $fieldName => $fieldMapping) {
            $entityFields[$fieldName]['metadata'] = [
                'type' => $fieldMapping->type,
                'columnName' => $fieldMapping->columnName,
                'nullable' => $fieldMapping->nullable,
                'length' => $fieldMapping->length,
                'precision' => $fieldMapping->precision,
                'scale' => $fieldMapping->scale,
                'unique' => $fieldMapping->unique,
                'options' => $fieldMapping->options,
            ];
            $entityFields[$fieldName]['plantext'] = $this->toPlainText($fieldName);
            $entityFields[$fieldName]['translation'] = $this->getTranslator()->trans(
                $this->toPlainText($fieldName)
            );
        }

        foreach ($metadata->associationMappings as $fieldName => $assocMapping) {
            $typeMap = [
                ClassMetadata::MANY_TO_ONE => 'ManyToOne',
                ClassMetadata::ONE_TO_ONE => 'OneToOne',
                ClassMetadata::MANY_TO_MANY => 'ManyToMany',
                ClassMetadata::ONE_TO_MANY => 'OneToMany',
            ];
            $type = $typeMap[$assocMapping['type']] ?? (string) $assocMapping['type'];
            $entityFields[$fieldName]['metadata'] = [
                'type' => $type,
                'targetEntity' => $assocMapping['targetEntity'],
            ];
            $entityFields[$fieldName]['plantext'] = $this->toPlainText($fieldName);
            $entityFields[$fieldName]['translation'] = $this->getTranslator()->trans(
                $this->toPlainText($fieldName)
            );
        }

        return $this->success($entityFields);
    }

    private function toPlainText(string $camelCase): string
    {
        $parts = preg_split('/(?=[A-Z])/', $camelCase);
        $plantext = ucwords(implode(' ', is_array($parts) ? $parts : [$camelCase]));
        return ucfirst(strtolower($plantext));
    }
}
