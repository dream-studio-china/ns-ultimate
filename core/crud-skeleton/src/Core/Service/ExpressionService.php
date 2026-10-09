<?php

namespace App\Core\Service;

use App\Core\Parser\ExpressionDqlParser;
use App\Core\Parser\ExpressionQueryBuilderAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\SimpleCache\CacheInterface;

/**
 * ExpressionService: wraps parsing, validation and building of filter QueryBuilder.
 * Keeps ExpressionDqlParser/ExpressionQueryBuilderAssembler usage centralized.
 */
class ExpressionService implements ExpressionServiceInterface
{
    /** @var CacheInterface|null */
    private $cache;

    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
    }

    /**
     * Build a QueryBuilder (or Query) for a filter expression. Returns ['qb' => Query|QueryBuilder, 'parameters' => array]
     * @param array<string, mixed> $values
     * @param EntityManagerInterface $em
     * @return array{qb: \Doctrine\ORM\QueryBuilder|\Doctrine\ORM\Query<mixed, mixed>, parameters: array<int, \Doctrine\ORM\Query\Parameter>}
     * @throws \Exception
     */
    public function buildFilter(string $filter, string $dataClass, array $values, $em): array
    {
        $cacheKey = null;
        if ($this->cache) {
            // Use safe cache key characters only (avoid Symfony reserved characters like ':' etc.)
            $cacheKey = 'expr_' . sha1($dataClass . '|' . $filter);
            $cached = $this->cache->get($cacheKey);
            if ($cached && is_array($cached) && isset($cached['dql']) && isset($cached['parameters'])) {
                // Recreate Query from cached DQL
                $query = $em->createQuery($cached['dql']);

                // Recreate parameter wrapper objects with getName/getValue
                $parameters = array_map(function ($p) {
                    return new \Doctrine\ORM\Query\Parameter($p['n'], $p['v']);
                }, $cached['parameters']);

                return ['qb' => $query, 'parameters' => $parameters];
            }
        }

        // No cache hit: parse and assemble (extracted to allow test overrides)
        $res = $this->parseAndAssemble($filter, $dataClass, $values, $em);
        $filterQb = $res['qb'];
        $parameters = $res['parameters'];

        if ($parameters instanceof \Doctrine\Common\Collections\ArrayCollection) {
            $parameters = $parameters->toArray();
        }

        // Store cacheable representation
        if ($this->cache && $cacheKey) {
            $storeParams = [];
            foreach ($parameters as $p) {
                $storeParams[] = ['n' => $p->getName(), 'v' => $p->getValue()];
            }
            $this->cache->set($cacheKey, ['dql' => $filterQb->getDQL(), 'parameters' => $storeParams]);
        }

        return ['qb' => $filterQb, 'parameters' => $parameters];
    }

    /**
     * Extracted parsing + assembling logic to allow unit tests to override behavior
     * and avoid relying on Doctrine metadata in unit tests.
     * @param string $filter
     * @param string $dataClass
     * @param array<string, mixed> $values
     * @param mixed $em
     * @return array{qb: \Doctrine\ORM\QueryBuilder|\Doctrine\ORM\Query<mixed, mixed>, parameters: \Doctrine\Common\Collections\ArrayCollection<int, \Doctrine\ORM\Query\Parameter>}
     * @throws \Exception
     */
    protected function parseAndAssemble(string $filter, string $dataClass, array $values, $em): array
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass($dataClass)
            ->setExpression($filter)
            ->setValues($values)
            ->compile();

        // Validate structure against metadata
        $parser->validateFragments($em);

        $assembler = new ExpressionQueryBuilderAssembler($em);
        $filterQb = $assembler->buildQueryBuilder($parser);

        $parameters = $parser->getParameters();

        return ['qb' => $filterQb, 'parameters' => $parameters];
    }
}
