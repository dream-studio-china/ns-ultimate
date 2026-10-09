<?php
declare(strict_types=1);

namespace App\Core\Service\Concern;

use App\Core\Service\ExpressionService;
use App\Core\Service\LegacyEvaluator;
use App\Core\Service\QueryBuilderFactory;
use App\Core\Utils\ArrayCommon;
use App\Core\Utils\FilterDateTime;
use App\Core\Utils\Math;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

trait BaseServiceInfrastructureTrait
{
    /**
     * TODO: List result will HIGHLY REDUCE PERFORMANCE in database, MUST OPTIMIZE
     *
     * @param QueryBuilder|array<int, object>|null $list
     * @return ArrayCollection<int, object>
     */
    public static function listResultToCollection($list): ArrayCollection
    {
        if($list instanceof QueryBuilder) {
            $result = $list->getQuery()->getResult();
            if($result) {
                return new ArrayCollection($result);
            }
        }
        elseif(is_array($list)) {
            return new ArrayCollection($list);
        }

        return new ArrayCollection();
    }

    /**
     * @return array<string, object>
     */
    public function externalExpressionValues(): array
    {
        return [
            'math' => new Math(),
            'datetime' => new FilterDateTime(),
            'Math' => new Math(),
            'Datetime' => new FilterDateTime(),
            'ArrayCommon' => new ArrayCommon(),
        ];
    }

    /**
     * Get EntityManager (resolved from locator or container). Lazily initializes $this->em if needed.
     * Return type intentionally uses PHPDoc only to allow test fakes.
     * @return \Doctrine\ORM\EntityManagerInterface
     */
    protected function getEntityManager()
    {
        if ($this->em) return $this->em;
        if ($this->container && $this->container->has('doctrine.orm.entity_manager')) {
            $this->em = $this->container->get('doctrine.orm.entity_manager');
            return $this->em;
        }
        throw new \RuntimeException('EntityManager not available');
    }

    /**
     * Get repository for given class (or default entityClass).
     * Return type intentionally uses PHPDoc only to allow test fakes.
     * @param class-string<object>|null $class
     * @return \Doctrine\Persistence\ObjectRepository<object>
     */
    protected function getRepository(?string $class = null)
    {
        if ($class === null) $class = $this->entityClass;
        if ($this->rep && $class === $this->entityClass) return $this->rep;
        return $this->getEntityManager()->getRepository($class);
    }

    /**
     * Get logger (from locator or container).
     * Return type intentionally uses PHPDoc only to allow test fakes.
     * @return \Psr\Log\LoggerInterface
     */
    protected function getLogger()
    {
        if ($this->logger) return $this->logger;
        if ($this->container) {
            try {
                if ($this->container->has('logger')) {
                    $this->logger = $this->container->get('logger');
                    return $this->logger;
                }
            } catch (\Exception $e) {
            }
        }
        return new \Psr\Log\NullLogger();
    }

    /**
     * Get serializer if available.
     * Return type intentionally uses PHPDoc only to allow test fakes.
     * @return \Symfony\Component\Serializer\SerializerInterface
     */
    protected function getSerializer()
    {
        if ($this->serializerService !== null) {
            return $this->serializerService;
        }

        if ($this->serviceLocator !== null) {
            try {
                $serializer = $this->serviceLocator->getSerializer();
                if ($serializer !== null) {
                    $this->serializerService = $serializer;
                    return $this->serializerService;
                }
            } catch (\Exception $e) {
            }
        }

        if ($this->container) {
            try {
                if ($this->container->has('serializer')) {
                    $this->serializerService = $this->container->get('serializer');
                    return $this->serializerService;
                }
            } catch (\Exception $e) {
            }
        }

        $this->serializerService = new Serializer(
            [
                new DateTimeNormalizer(),
                new ArrayDenormalizer(),
                new ObjectNormalizer(),
            ],
            [
                new JsonEncoder(),
            ]
        );

        return $this->serializerService;
    }

    /**
     * Get validator if available.
     * Return type intentionally uses PHPDoc only to allow test fakes.
     * @return \Symfony\Component\Validator\Validator\ValidatorInterface|null
     */
    protected function getValidator()
    {
        if ($this->container && $this->container->has('validator')) {
            return $this->container->get('validator');
        }
        return null;
    }

    /**
     * Get request stack if available.
     * Return type intentionally uses PHPDoc only to allow test fakes.
     * @return \Symfony\Component\HttpFoundation\RequestStack|null
     */
    protected function getRequestStack()
    {
        if ($this->container && $this->container->has('request_stack')) {
            return $this->container->get('request_stack');
        }
        return null;
    }

    /**
     * Get current Request if available (wrapper for request_stack access).
     * Centralized so subclasses/tests can override or mock.
     * @return \Symfony\Component\HttpFoundation\Request|null
     */
    protected function getCurrentRequest()
    {
        $requestStack = $this->getRequestStack();
        return $requestStack ? $requestStack->getCurrentRequest() : null;
    }

    /**
     * Lazy access to QueryBuilderFactory. Creates one using current EntityManager.
     * Allows tests to override by extending BaseService and providing custom factory.
     * @return QueryBuilderFactory
     */
    protected function getQueryBuilderFactory()
    {
        if (isset($this->qbFactory) && $this->qbFactory) return $this->qbFactory;
        $em = $this->getEntityManager();
        $this->qbFactory = new QueryBuilderFactory($em);
        return $this->qbFactory;
    }

    /**
     * Lazy access to ExpressionService (for filters, sorts, etc). Creates one using current EntityManager.
     * Allows tests to override by extending BaseService and providing custom service.
     * @return \App\Core\Service\ExpressionServiceInterface
     */
    protected function getExpressionService()
    {
        if (isset($this->expressionService) && $this->expressionService) return $this->expressionService;
        $this->expressionService = new ExpressionService();
        return $this->expressionService;
    }

    /**
     * Lazy access to LegacyEvaluator service.
     * @return LegacyEvaluator
     */
    protected function getLegacyEvaluator()
    {
        if (isset($this->legacyEvaluator) && $this->legacyEvaluator) return $this->legacyEvaluator;
        $this->legacyEvaluator = new LegacyEvaluator();
        return $this->legacyEvaluator;
    }

    /**
     * Execute a callable within a database transaction.
     * The callable receives the EntityManager for convenience.
     * Flushes before commit, rolls back on any Throwable.
     * Falls back to plain execution when the EM is a fake/mock without transaction support.
     *
     * @template T
     * @param callable(\Doctrine\ORM\EntityManagerInterface): T $fn
     * @return T
     */
    public function wrapInTransaction(callable $fn): mixed
    {
        $em = $this->getEntityManager();

        if (!method_exists($em, 'beginTransaction') || !method_exists($em, 'commit')) { // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType
            $result = $fn($em);
            if (method_exists($em, 'flush')) { // @phpstan-ignore function.alreadyNarrowedType
                $em->flush();
            }
            return $result;
        }

        $em->beginTransaction();
        try {
            $result = $fn($em);
            $em->flush();
            $em->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                if ($em->getConnection()->isTransactionActive()) {
                    $em->rollback();
                }
            } catch (\Throwable $ignored) {
                // The connection may be broken; recovery below still applies.
            }
            if (!$em->isOpen()) {
                $this->resetEntityManager();
            }
            throw $e;
        }
    }

    /**
     * Recover from a closed EntityManager (e.g. after a DB connection failure).
     * Resets the manager registry, rebinds a fresh EntityManager and refreshes
     * the repository so the service stays usable after the error.
     */
    protected function resetEntityManager(): void
    {
        $registry = ($this->container && $this->container->has('doctrine'))
            ? $this->container->get('doctrine')
            : null;

        if (!$registry instanceof \Doctrine\Persistence\ManagerRegistry) {
            return;
        }

        $registry->resetManager();
        /** @var \Doctrine\ORM\EntityManagerInterface $newEm */
        $newEm = $registry->getManager();
        $this->em = $newEm;
        if ($this->entityClass !== null) {
            $this->rep = $newEm->getRepository($this->entityClass);
        }
    }
}
