<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Table;

final class Version20261024010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add counter sessions and merged order tracking.'; }

    public function isTransactional(): bool
    {
        // MySQL DDL commits implicitly; do not advertise a transaction it cannot retain.
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $sessions = new Table('counter_session');
        $sessions->addColumn('id', 'integer', ['autoincrement' => true]);
        $sessions->setPrimaryKey(['id']);
        $sessions->addColumn('uuid', 'string', ['length' => 36]);
        $sessions->addColumn('counter_id', 'integer');
        $sessions->addColumn('active_counter_uuid', 'string', ['length' => 36, 'notnull' => false]);
        $sessions->addColumn('started_at', 'datetime_immutable');
        $sessions->addColumn('ended_at', 'datetime_immutable', ['notnull' => false]);
        $sessions->addUniqueIndex(['uuid'], 'uniq_counter_session_uuid');
        $sessions->addUniqueIndex(['active_counter_uuid'], 'uniq_counter_session_active');
        $sessions->addIndex(['counter_id', 'ended_at'], 'idx_counter_session_open');
        $sessions->addForeignKeyConstraint('counter', ['counter_id'], ['id'], ['onDelete' => 'RESTRICT'], 'FK_COUNTER_SESSION_COUNTER');
        foreach ($platform->getCreateTableSQL($sessions) as $sql) {
            $this->addSql($sql);
        }
        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE counter_order ADD counter_session_id INTEGER DEFAULT NULL REFERENCES counter_session (id) ON DELETE RESTRICT');
        } else {
            $this->addSql('ALTER TABLE counter_order ADD counter_session_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE counter_order ADD CONSTRAINT FK_COUNTER_ORDER_SESSION FOREIGN KEY (counter_session_id) REFERENCES counter_session (id) ON DELETE RESTRICT');
        }
        $this->addSql('CREATE INDEX idx_counter_order_session ON counter_order (counter_session_id)');

        $merges = new Table('counter_order_merge');
        $merges->addColumn('id', 'integer', ['autoincrement' => true]);
        $merges->setPrimaryKey(['id']);
        $merges->addColumn('source_order_uuid', 'string', ['length' => 36]);
        $merges->addColumn('target_order_uuid', 'string', ['length' => 36]);
        $merges->addColumn('created_at', 'datetime_immutable');
        $merges->addUniqueIndex(['source_order_uuid'], 'uniq_counter_order_merge_source');
        $merges->addIndex(['target_order_uuid'], 'idx_counter_order_merge_target');
        foreach ($platform->getCreateTableSQL($merges) as $sql) {
            $this->addSql($sql);
        }

        // Queue the backfill just like DDL. postUp runs even during dry runs and
        // direct writes there would either fail on absent tables or mutate live data.
        // Reusing each counter UUID is deterministic and unique within this new table.
        $this->addSql('INSERT INTO counter_session (uuid, counter_id, active_counter_uuid, started_at, ended_at) SELECT c.uuid, c.id, c.uuid, CURRENT_TIMESTAMP, NULL FROM counter c WHERE EXISTS (SELECT 1 FROM counter_order b WHERE b.counter_id = c.id)');
        $this->addSql('UPDATE counter_order SET counter_session_id = (SELECT s.id FROM counter_session s WHERE s.counter_id = counter_order.counter_id AND s.ended_at IS NULL) WHERE counter_session_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE counter_order_merge');
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE counter_order DROP FOREIGN KEY FK_COUNTER_ORDER_SESSION');
        }
        $this->addSql('DROP INDEX idx_counter_order_session'.($this->connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' ON counter_order'));
        $this->addSql('ALTER TABLE counter_order DROP counter_session_id');
        $this->addSql('DROP TABLE counter_session');
    }
}
