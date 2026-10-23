<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminAgentRetirementTest extends KernelTestCase
{
    public function testOldHttpEndpointsAreAbsentAndHistoricalMappingIsRetained(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        foreach ($container->get('router')->getRouteCollection() as $route) {
            self::assertStringNotContainsString('/manage/agent/', $route->getPath());
        }
        $em = $container->get(EntityManagerInterface::class);
        self::assertSame('admin_agent_operation', $em->getClassMetadata(AgentOperation::class)->getTableName());
    }
}
