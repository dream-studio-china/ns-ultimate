<?php

namespace App\Core\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DefaultServiceLocator implements ServiceLocatorInterface
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @return \Doctrine\ORM\EntityManagerInterface
     */
    public function getEntityManager()
    {
        /** @var \Doctrine\ORM\EntityManagerInterface */
        return $this->container->get('doctrine.orm.entity_manager');
    }

    /**
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        if ($this->container->has('logger')) {
            /** @var \Psr\Log\LoggerInterface */
            return $this->container->get('logger');
        }
        return new \Psr\Log\NullLogger();
    }

    /**
     * @return \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface|null
     */
    public function getTokenStorage()
    {
        if ($this->container->has('security.token_storage')) {
            /** @var \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface */
            return $this->container->get('security.token_storage');
        }
        return null;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\RequestStack|null
     */
    public function getRequestStack()
    {
        if ($this->container->has('request_stack')) {
            /** @var \Symfony\Component\HttpFoundation\RequestStack */
            return $this->container->get('request_stack');
        }
        return null;
    }

    /**
     * @return \Symfony\Component\Serializer\SerializerInterface|null
     */
    public function getSerializer()
    {
        try {
            /** @var \Symfony\Component\Serializer\SerializerInterface */
            return $this->container->get(\Symfony\Component\Serializer\SerializerInterface::class);
        } catch (\Throwable $e) {
            try {
                /** @var \Symfony\Component\Serializer\SerializerInterface */
                return $this->container->get('serializer');
            } catch (\Throwable $e) {
                return null;
            }
        }
    }

    /**
     * @return \Symfony\Component\Validator\Validator\ValidatorInterface|null
     */
    public function getValidator()
    {
        if ($this->container->has('validator')) {
            /** @var \Symfony\Component\Validator\Validator\ValidatorInterface */
            return $this->container->get('validator');
        }
        return null;
    }
}
