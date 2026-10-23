<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\API\MySQL\ExceptionConverter as MySQLExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentBatchOperation;
use PHPUnit\Framework\TestCase;

/**
 * Offline regression for MySQL's reserved ROWS keyword. SQLite accepts a bare
 * "rows" column, so the integration database cannot expose this failure.
 */
final class AgentBatchOperationEntityTest extends TestCase
{
    public function testMysqlOrmInsertSqlUsesTheNonReservedPhysicalColumn(): void
    {
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([dirname(__DIR__, 2).'/src/Entity']));
        $configuration->setProxyDir(sys_get_temp_dir());
        $configuration->setProxyNamespace('NsUltimateOfflineProxies');
        $configuration->enableNativeLazyObjects(true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());

        $connection = new Connection([], new class implements Driver {
            public function connect(array $params): DriverConnection
            {
                throw new \LogicException('The offline SQL check must not connect to a database.');
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return new MySQLPlatform();
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                return new MySQLExceptionConverter();
            }
        });
        self::assertInstanceOf(MySQLPlatform::class, $connection->getDatabasePlatform());

        $em = new EntityManager($connection, $configuration);
        $metadata = $em->getClassMetadata(AgentBatchOperation::class);
        self::assertSame('proposal_rows', $metadata->getColumnName('rows'));

        $sql = $em->getUnitOfWork()->getEntityPersister(AgentBatchOperation::class)->getInsertSQL();
        self::assertStringContainsString('proposal_rows', $sql);
        self::assertDoesNotMatchRegularExpression('/\brows\b/i', $sql);
        self::assertStringNotContainsString('proposalRows', $sql);
    }
}
