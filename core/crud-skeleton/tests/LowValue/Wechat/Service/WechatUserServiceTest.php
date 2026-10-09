<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Wechat\Service;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Service\BaseService;
use App\Wechat\Entity\WechatUser;
use App\Wechat\Service\WechatUserService;
use App\Wechat\Service\WechatUserServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Covers App\Wechat\Service\WechatUserService (trivial BaseService subclass)
 * by constructing it with a mocked ContainerInterface.
 */
#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class WechatUserServiceTest extends TestCase
{
    private function buildContainer(): ContainerInterface
    {
        $repository = $this->createMock(EntityRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(WechatUser::class)->willReturn($repository);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->with('doctrine.orm.entity_manager')->willReturn($em);

        return $container;
    }

    public function testCanBeInstantiatedWithContainer(): void
    {
        $service = new WechatUserService($this->buildContainer());

        self::assertInstanceOf(WechatUserService::class, $service);
        self::assertInstanceOf(WechatUserServiceInterface::class, $service);
        self::assertInstanceOf(BaseService::class, $service);
    }

    public function testNewCreatesEntityInstance(): void
    {
        $service = new WechatUserService($this->buildContainer());

        self::assertInstanceOf(WechatUser::class, $service->new());
    }
}
