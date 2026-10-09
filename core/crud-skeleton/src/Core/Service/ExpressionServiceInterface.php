<?php

namespace App\Core\Service;

interface ExpressionServiceInterface
{
    /**
     * Build filter and return ['qb' => Query|QueryBuilder, 'parameters' => array]
     * @param array<string, mixed> $values
     * @param mixed $em
     * @return array{qb: \Doctrine\ORM\QueryBuilder|\Doctrine\ORM\Query<mixed, mixed>, parameters: array<int, \Doctrine\ORM\Query\Parameter>}
     */
    public function buildFilter(string $filter, string $dataClass, array $values, $em): array;
}
