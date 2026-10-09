<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Controller\App;

use App\Promotion\Controller\App\PromotionController;
use App\Promotion\Entity\Promotion;
use App\Promotion\Service\PromotionServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

final class PromotionControllerTest extends TestCase
{
    private function createController(
        PromotionServiceInterface $service,
        ?EntityManagerInterface $entityManager = null,
        ?Request $request = null,
    ): PromotionController
    {
        $controller = new PromotionController($service, $entityManager);

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $requestStack = new RequestStack();
        $requestStack->push($request ?? Request::create('/', 'GET'));

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('request_stack', $requestStack);
        $container->set('serializer', $serializer);
        $container->set('translator', new Translator('en'));
        $container->set('validator', Validation::createValidator());

        $controller->setContainer($container);
        $controller->setRequestStack($requestStack);
        $controller->setSerializer($serializer);
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    public function testControllerIsInstantiable(): void
    {
        $service = $this->createStub(PromotionServiceInterface::class);
        $controller = $this->createController($service);

        self::assertInstanceOf(PromotionController::class, $controller);
    }

    public function testCommonFilterReturnsEnabledTrue(): void
    {
        $service = $this->createStub(PromotionServiceInterface::class);
        $controller = $this->createController($service);

        $ref = new \ReflectionClass($controller);
        $method = $ref->getMethod('commonFilter');
        $filter = $method->invoke($controller);

        self::assertIsArray($filter);
        self::assertArrayHasKey('enabled', $filter);
        self::assertTrue($filter['enabled']);
    }

    #[Group('low-value')]
    public function testCommonFilterOnlyContainsEnabled(): void
    {
        $service = $this->createStub(PromotionServiceInterface::class);
        $controller = $this->createController($service);

        $ref = new \ReflectionClass($controller);
        $method = $ref->getMethod('commonFilter');
        $filter = $method->invoke($controller);

        self::assertCount(1, $filter);
    }

    public function testCommonFilterBuildsActiveEnabledPromotionQuery(): void
    {
        $service = $this->createStub(PromotionServiceInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $whereClauses = [];
        $parameters = [];

        $entityManager->expects($this->once())->method('createQueryBuilder')->willReturn($queryBuilder);
        $queryBuilder->expects($this->once())->method('select')->with('promotion')->willReturnSelf();
        $queryBuilder->expects($this->once())->method('from')->with(Promotion::class, 'promotion')->willReturnSelf();
        $queryBuilder->expects($this->once())->method('innerJoin')->with('promotion.template', 'template')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnCallback(
            static function (string $clause) use (&$whereClauses, $queryBuilder): QueryBuilder {
                $whereClauses[] = $clause;

                return $queryBuilder;
            },
        );
        $queryBuilder->method('setParameter')->willReturnCallback(
            static function (string $name, mixed $value) use (&$parameters, $queryBuilder): QueryBuilder {
                $parameters[$name] = $value;

                return $queryBuilder;
            },
        );

        $controller = $this->createController($service, $entityManager);
        $filter = $this->invokeCommonFilter($controller);

        self::assertSame($queryBuilder, $filter);
        self::assertSame([
            'promotion.enabled = :enabled',
            'template.enabled = :templateEnabled',
            '(promotion.startTime IS NULL OR promotion.startTime <= :now)',
            '(promotion.endTime IS NULL OR promotion.endTime >= :now)',
        ], $whereClauses);
        self::assertTrue($parameters['enabled']);
        self::assertTrue($parameters['templateEnabled']);
        self::assertInstanceOf(\DateTimeImmutable::class, $parameters['now']);
        self::assertArrayNotHasKey('storeCode', $parameters);
    }

    public function testCommonFilterRestrictsQueryToRequestedStore(): void
    {
        $service = $this->createStub(PromotionServiceInterface::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $whereClauses = [];
        $parameters = [];

        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('innerJoin')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnCallback(
            static function (string $clause) use (&$whereClauses, $queryBuilder): QueryBuilder {
                $whereClauses[] = $clause;

                return $queryBuilder;
            },
        );
        $queryBuilder->method('setParameter')->willReturnCallback(
            static function (string $name, mixed $value) use (&$parameters, $queryBuilder): QueryBuilder {
                $parameters[$name] = $value;

                return $queryBuilder;
            },
        );

        $controller = $this->createController(
            $service,
            $entityManager,
            Request::create('/', 'GET', ['storeCode' => 'downtown']),
        );

        self::assertSame($queryBuilder, $this->invokeCommonFilter($controller));
        self::assertSame('promotion.storeCode = :storeCode', $whereClauses[4]);
        self::assertSame('downtown', $parameters['storeCode']);
    }

    #[Group('low-value')]
    public function testControllerUsesServiceInterface(): void
    {
        $service = $this->createStub(PromotionServiceInterface::class);
        $controller = new PromotionController($service);

        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('service');

        self::assertSame($service, $prop->getValue($controller));
    }

    #[Group('low-value')]
    public function testControllerHasNoWriteMixins(): void
    {
        $service = $this->createStub(PromotionServiceInterface::class);
        $controller = new PromotionController($service);

        $ref = new \ReflectionClass($controller);
        self::assertFalse($ref->hasProperty('acceptedCreateProperties'));
        self::assertFalse($ref->hasProperty('acceptedUpdateProperties'));
        self::assertFalse($ref->hasProperty('requiredCreateProperties'));
    }

    public function testGetServiceReturnsInjectedService(): void
    {
        $service = $this->createStub(PromotionServiceInterface::class);
        $controller = $this->createController($service);

        self::assertSame($service, $controller->getService());
    }

    private function invokeCommonFilter(PromotionController $controller): array|QueryBuilder
    {
        $method = new \ReflectionMethod($controller, 'commonFilter');

        return $method->invoke($controller);
    }
}
