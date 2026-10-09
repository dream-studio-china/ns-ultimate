<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Wallet\Service;


use PHPUnit\Framework\Attributes\Group;
use App\Wallet\Entity\Wallet;
use App\Wallet\Repository\TransactionRepository;
use App\Wallet\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class WalletServiceCoverageTest extends TestCase
{
    private function buildService(EntityManagerInterface $em): WalletService
    {
        $txRepo = $this->createMock(TransactionRepository::class);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturnCallback(
            fn(string $data, string $class, string $format, array $context) => $context['object_to_populate'] ?? null
        );

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new \Symfony\Component\Validator\ConstraintViolationList());

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn(string $id) => match ($id) {
                'doctrine.orm.entity_manager' => $em,
                'logger' => $this->createMock(LoggerInterface::class),
                'security.token_storage' => $this->createMock(TokenStorageInterface::class),
                'validator' => $validator,
                'serializer' => $serializer,
                default => null,
            }
        );
        $container->method('has')->willReturn(true);

        return new WalletService($container, $txRepo);
    }

    public function testGetWalletRepositoryThrowsWhenRepositoryIsNotWalletRepository(): void
    {
        // The EM resolves a repository for Wallet that is NOT a WalletRepository
        // (a plain Doctrine EntityRepository mock — satisfies getRepository()'s declared
        // return type but fails the instanceof check): getWalletRepository() must throw
        // a LogicException (line 148).
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Wallet::class)->willReturn(
            $this->createMock(\Doctrine\ORM\EntityRepository::class)
        );

        $service = $this->buildService($em);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Wallet repository is not available.');

        $service->verifyBalance();
    }

    public function testGetWalletRepositoryThrowsForUnrelatedRepository(): void
    {
        // Same guard for the reconcile() entry point (still reaches getWalletRepository()
        // first) with a different non-Wallet repository instance.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Wallet::class)->willReturn(
            $this->createMock(\Doctrine\ORM\EntityRepository::class)
        );

        $service = $this->buildService($em);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Wallet repository is not available.');

        $service->reconcile();
    }
}
