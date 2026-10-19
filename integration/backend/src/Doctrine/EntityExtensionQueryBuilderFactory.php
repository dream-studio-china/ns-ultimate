<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Doctrine;

use App\Core\Service\QueryBuilderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/** Exposes declared subtype fields through left joins without excluding root rows. */
final class EntityExtensionQueryBuilderFactory extends QueryBuilderFactory
{
    /** @param array<class-string, array<string, class-string>> $ordering */
    public function __construct(EntityManagerInterface $entityManager, private readonly array $ordering)
    {
        parent::__construct($entityManager);
    }

    public function createRootQueryBuilder(string $dataClass, string $rootAlias = 'entity'): QueryBuilder
    {
        $queryBuilder = parent::createRootQueryBuilder($dataClass, $rootAlias);
        $this->joinExtensions($queryBuilder);

        return $queryBuilder;
    }

    public function joinExtensions(QueryBuilder $queryBuilder): void
    {
        $rootClass = $queryBuilder->getRootEntities()[0];
        $rootAlias = $queryBuilder->getRootAliases()[0];
        foreach (array_unique($this->ordering[$rootClass] ?? []) as $subtype) {
            $alias = $this->extensionAlias($rootAlias, $subtype);
            $condition = $rootAlias.'.id = '.$alias.'.id';
            if (in_array($alias, $queryBuilder->getAllAliases(), true)) {
                foreach ($queryBuilder->getDQLPart('join') as $joins) {
                    foreach ($joins as $join) {
                        if ($join->getAlias() === $alias && $join->getJoin() === $subtype
                            && $join->getJoinType() === Join::LEFT_JOIN && $join->getCondition() === $condition) {
                            continue 3;
                        }
                    }
                }
                throw new \LogicException(sprintf('Entity extension query alias "%s" is already in use.', $alias));
            }
            $queryBuilder->leftJoin($subtype, $alias, Join::WITH, $condition);
        }
    }

    public function orderExpression(string $expression, string $rootClass, string $rootAlias = 'entity'): string
    {
        foreach ($this->ordering[$rootClass] ?? [] as $field => $subtype) {
            $expression = preg_replace(
                '/(?<![\w.])(?:entity|'.preg_quote($rootAlias, '/').')\.'.preg_quote($field, '/').'\b/',
                $this->extensionAlias($rootAlias, $subtype).'.'.$field,
                $expression,
            ) ?? $expression;
        }

        return $expression;
    }

    private function extensionAlias(string $rootAlias, string $subtype): string
    {
        return 'extension_'.substr(hash('sha256', $rootAlias.'|'.$subtype), 0, 12);
    }
}
