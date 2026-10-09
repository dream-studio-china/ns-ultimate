<?php

namespace App\Core\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;


class ControllerListener
{
    /** @var TokenStorageInterface */
    private $tokenStorage;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(TokenStorageInterface $tokenStorage, LoggerInterface $logger)
    {
        $this->tokenStorage = $tokenStorage;
        $this->logger = $logger;
    }

    public function onKernelController(ControllerEvent $event): void
    {
        // get operation user
        if ($this->tokenStorage->getToken()) {
            $operator = $this->tokenStorage->getToken()->getUser();
            if (is_object($operator) && method_exists($operator, 'getId')) {
                $operator = $operator->getId();
            }
        }
        else return;

        $controller = $event->getController();
        $request = $event->getRequest();

        $method = $request->getMethod();
        $uri = $request->getRequestUri();

        $content = $request->getContent();
        if(strlen($content) > 1024 /* 1K */) {
            $content = '...';
        }

        if(preg_match("/(PUT|POST)/i", $method)) {
            $this->logger->info(
                "User [#$operator] Requests $method $uri: $content"
            );
        }
    }
}
