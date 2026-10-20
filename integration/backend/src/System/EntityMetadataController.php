<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\System;

use App\Core\Controller\System\EntityController as CoreEntityController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Adds explicitly declared business subtype fields to the public entity metadata. */
final class EntityMetadataController extends CoreEntityController
{
    /**
     * @param array<class-string, class-string> $metadataClasses Root entity => mapped metadata class
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $metadataClasses,
    ) {
        parent::__construct($entityManager);
    }

    public function retrieveAction(string $entityName): Response
    {
        $rootClass = str_replace('/', '\\', $entityName);
        $metadataClass = $this->metadataClasses[$rootClass] ?? null;
        $response = parent::retrieveAction($entityName);
        if ($metadataClass === null || $response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $payload = json_decode((string) $response->getContent(), true);
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            return $response;
        }

        $metadata = $this->entityManager->getClassMetadata($metadataClass);
        foreach ($metadata->fieldMappings as $fieldName => $fieldMapping) {
            if (isset($payload['data'][$fieldName])) {
                continue;
            }

            $plainText = $this->toPlainText($fieldName);
            $payload['data'][$fieldName] = [
                'metadata' => [
                    'type' => $fieldMapping->type,
                    'columnName' => $fieldMapping->columnName,
                    'nullable' => $fieldMapping->nullable,
                    'length' => $fieldMapping->length,
                    'precision' => $fieldMapping->precision,
                    'scale' => $fieldMapping->scale,
                    'unique' => $fieldMapping->unique,
                    'options' => $fieldMapping->options,
                ],
                'plantext' => $plainText,
                'translation' => $this->getTranslator()->trans($plainText),
            ];
        }

        return new JsonResponse($payload, $response->getStatusCode(), $response->headers->all());
    }

    private function toPlainText(string $camelCase): string
    {
        $parts = preg_split('/(?=[A-Z])/', $camelCase);
        $plainText = ucwords(implode(' ', is_array($parts) ? $parts : [$camelCase]));

        return ucfirst(strtolower($plainText));
    }

}
