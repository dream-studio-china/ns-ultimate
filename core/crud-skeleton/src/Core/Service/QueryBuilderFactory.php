<?php

namespace App\Core\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Simple factory for creating QueryBuilder roots. Keeps creation in one place
 * so BaseService can delegate and tests can mock/replace if needed.
 */
class QueryBuilderFactory
{
    /** @var mixed */
    private $em;

    /**
     * Accept any object that provides createQueryBuilder() (supports test doubles)
     * @param mixed $em
     */
    public function __construct($em)
    {
        $this->em = $em;
    }

    /**
     * Create a QueryBuilder with a root alias and select the alias.
     * @return QueryBuilder|mixed
     */
    public function createRootQueryBuilder(string $dataClass, string $rootAlias = 'entity')
    {
        $qb = $this->em->createQueryBuilder();
        return $qb->select($rootAlias)->from($dataClass, $rootAlias);
    }

    /**
     * Backwards-compatible alias used by BaseService currently.
     * @return QueryBuilder|mixed
     */
    public function create(string $dataClass, string $rootAlias = 'entity')
    {
        return $this->createRootQueryBuilder($dataClass, $rootAlias);
    }
}
