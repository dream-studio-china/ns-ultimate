<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Migrations;

use App\Core\Utils\UUID;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move store-scoped WeChat identities into the AppID-aware Core WechatUser table.';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->connection->iterateAssociative('SELECT user_id, app_id, openid, app_type, created_at FROM store_wechat_identity ORDER BY id') as $identity) {
            $appId = (string) $identity['app_id'];
            $openid = (string) $identity['openid'];
            $appType = (string) $identity['app_type'];
            $userId = (int) $identity['user_id'];

            $this->abortIf($appId === '' || $openid === '' || strlen($openid) > 128, 'Cannot migrate a Store WeChat identity with an invalid AppID or OpenID.');

            $existingIdentity = $this->connection->fetchAssociative(
                'SELECT id, user_id, app_type FROM wechat_user WHERE app_id = :appId AND openid = :openid',
                ['appId' => $appId, 'openid' => $openid],
            );
            if ($existingIdentity !== false) {
                $this->abortIf(
                    (int) $existingIdentity['user_id'] !== $userId || (string) $existingIdentity['app_type'] !== $appType,
                    sprintf('AppID/OpenID identity conflicts with a different Core user or app type (AppID %s).', $appId),
                );
                continue;
            }

            // Preserve a matching pre-AppID Core account instead of creating a duplicate User identity.
            $legacyIdentity = $this->connection->fetchAssociative(
                'SELECT id FROM wechat_user WHERE app_id IS NULL AND openid = :openid AND app_type = :appType AND user_id = :userId',
                ['openid' => $openid, 'appType' => $appType, 'userId' => $userId],
            );
            if ($legacyIdentity !== false) {
                $this->connection->executeStatement(
                    'UPDATE wechat_user SET app_id = :appId WHERE id = :id AND app_id IS NULL',
                    ['appId' => $appId, 'id' => $legacyIdentity['id']],
                );
                continue;
            }

            $this->connection->insert('wechat_user', [
                'uuid' => UUID::v4(),
                'user_id' => $userId,
                'app_id' => $appId,
                'openid' => $openid,
                'app_type' => $appType,
                'last_login_at' => $identity['created_at'],
                'created_at' => $identity['created_at'],
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->fetchOne('SELECT id FROM wechat_user WHERE app_id IS NOT NULL LIMIT 1') !== false,
            'Cannot reverse the WeChat identity migration after AppID-scoped identities have been created or updated.',
        );
    }
}
