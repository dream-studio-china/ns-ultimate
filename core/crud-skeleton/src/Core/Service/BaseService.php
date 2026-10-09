<?php
declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Service\Concern\BaseServiceInfrastructureTrait;
use App\Core\Service\Concern\BaseServiceMutationTrait;
use App\Core\Service\Concern\BaseServiceReadListTrait;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializerInterface;

/**
 * @template TEntity of object
 * @implements BaseServiceInterface<TEntity>
 */
abstract class BaseService implements BaseServiceInterface
{
    use BaseServiceInfrastructureTrait;
    /** @use BaseServiceReadListTrait<TEntity> */
    use BaseServiceReadListTrait;
    /** @use BaseServiceMutationTrait<TEntity> */
    use BaseServiceMutationTrait;

    /** @var ContainerInterface */
    protected $container;
    /** @var \Doctrine\ORM\EntityManagerInterface */
    protected $em;
    /** @var \Doctrine\Persistence\ObjectRepository<TEntity> */
    protected $rep;
    /** @var class-string<TEntity> */
    protected $entityClass;
    /** @var LoggerInterface */
    protected $logger;
    /** @var UserInterface|null */
    protected $user;
    /** @var QueryBuilderFactory|null */
    protected $qbFactory;
    /** @var ExpressionServiceInterface|null */
    protected $expressionService;
    /** @var LegacyEvaluator|null */
    protected $legacyEvaluator;
    /** @var SymfonySerializerInterface|null */
    protected $serializerService;
    /** @var ServiceLocatorInterface|null */
    protected $serviceLocator;

    /**
     * @param class-string<TEntity> $entityClass
     * @param ServiceLocatorInterface|null $locator
     * @param ExpressionServiceInterface|null $expressionService
     * @param LegacyEvaluator|null $legacyEvaluator
     */
    public function __construct(
        ContainerInterface $container,
        string $entityClass,
        ?ServiceLocatorInterface $locator = null,
        ?ExpressionServiceInterface $expressionService = null,
        ?LegacyEvaluator $legacyEvaluator = null
    ) {
        $this->container = $container;
        $this->entityClass = $entityClass;

        if ($locator === null) {
            $locator = new DefaultServiceLocator($container);
        }

        $this->serviceLocator = $locator;
        $this->em = $locator->getEntityManager();
        $this->rep = $this->em->getRepository($entityClass);
        $this->logger = $locator->getLogger();

        $tokenStorage = $locator->getTokenStorage();
        $token = $tokenStorage ? $tokenStorage->getToken() : null;
        $this->user = $token ? $token->getUser() : null;

        if ($expressionService !== null) {
            $this->expressionService = $expressionService;
        }

        if ($legacyEvaluator !== null) {
            $this->legacyEvaluator = $legacyEvaluator;
        }
    }
}
