<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Core\Utils\UUID;
use App\Store\Entity\Store;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Entity\StaffOperation;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StaffCommandService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @param array<string, mixed> $payload
     * @param callable(): array{before: array<string, mixed>, result: array<string, mixed>} $execute
     * @return array<string, mixed> */
    public function run(Store $store, string $actorUuid, string $action, array $payload, callable $execute): array
    {
        $commandUuid = $payload['commandUuid'] ?? null;
        if (!is_string($commandUuid) || !UUID::is_valid($commandUuid)) {
            throw new HttpException(422, 'invalid_command');
        }
        $fingerprint = self::version(['action' => $action, 'payload' => $payload]);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            // Serialize this app's commands per store, including duplicate command keys.
            $this->em->refresh($store, LockMode::PESSIMISTIC_WRITE);
            $receipt = $this->em->getRepository(StaffOperation::class)->findOneBy([
                'storeUuid' => $store->getUuid(), 'actorUuid' => $actorUuid, 'commandUuid' => $commandUuid,
            ]);
            if ($receipt instanceof StaffOperation) {
                if (!hash_equals($receipt->getFingerprint(), $fingerprint)) {
                    throw new HttpException(409, 'command_conflict');
                }
                $connection->commit();
                return $receipt->getResult();
            }
            $change = $execute();
            $this->em->persist(new StaffOperation($store->getUuid(), $actorUuid, $commandUuid, $action, $fingerprint, $change['before'], $change['result']));
            $this->em->flush();
            $connection->commit();
            return $change['result'];
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) $connection->rollBack();
            $this->em->clear();
            throw $error;
        }
    }

    /** @param array<string, mixed> $state */
    public static function version(array $state): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            return array_map($normalize, $value);
        };
        return hash('sha256', json_encode($normalize($state), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    public static function expectVersion(array $payload, string $version): void
    {
        if (!is_string($payload['version'] ?? null) || !hash_equals($version, $payload['version'])) {
            throw new HttpException(409, 'stale_record');
        }
    }
}
