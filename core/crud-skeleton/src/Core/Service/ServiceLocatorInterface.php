<?php

namespace App\Core\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Return types intentionally omitted from the signature to keep
 * test fakes lightweight. DefaultServiceLocator also preserves the untyped
 * boundary because the test container supplies lightweight fake services.
 */
interface ServiceLocatorInterface
{
    /**
     * @phpstan-return EntityManagerInterface
     */
    public function getEntityManager();

    /**
     * @phpstan-return LoggerInterface
     */
    public function getLogger();

    /**
     * @phpstan-return TokenStorageInterface|null
     */
    public function getTokenStorage();

    /**
     * @phpstan-return RequestStack|null
     */
    public function getRequestStack();

    /**
     * @phpstan-return SerializerInterface|null
     */
    public function getSerializer();

    /**
     * @phpstan-return ValidatorInterface|null
     */
    public function getValidator();
}
