<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Core\Service\BaseService;
use App\Core\Utils\UUID;
use App\Settlement\Entity\SettlementRule;
use App\Settlement\Entity\SettlementRuleVersion;
use App\Settlement\Repository\SettlementRuleRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<SettlementRuleVersion> */
final class SettlementRuleVersionService extends BaseService implements SettlementRuleVersionServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly SettlementRuleServiceInterface $ruleService,
        private readonly SettlementRuleRepository $ruleRepository,
    ) {
        parent::__construct($container, SettlementRuleVersion::class);
    }

    public function new(): SettlementRuleVersion
    {
        // The Core create lifecycle needs an initialized temporary entity. update()
        // replaces it with a real version once it has the parent rule UUID.
        return new SettlementRuleVersion('', 0, [], str_repeat('0', 64), new \DateTimeImmutable(), 0);
    }

    /**
     * The generic Core update lifecycle is safe only for draft versions. The
     * domain service owns the immutable-version transition and configuration hash.
     *
     * @param array<string, mixed>|null $data
     */
    public function update(mixed $object, ?array $data = null, bool $noFlush = false): object|false
    {
        if (!$object instanceof SettlementRuleVersion || $data === null) {
            return parent::update($object, $data, $noFlush);
        }
        [$definition, $priority, $effectiveFrom, $effectiveTo] = $this->configurationFrom($data);

        if ($object->getId() === null) {
            $ruleUuid = $data['ruleUuid'] ?? null;
            if (!is_string($ruleUuid) || !UUID::is_valid($ruleUuid)) {
                throw new \InvalidArgumentException('ruleUuid must be a valid UUID.');
            }
            $rule = $this->ruleRepository->findByUuid($ruleUuid);
            if (!$rule instanceof SettlementRule) {
                throw new \InvalidArgumentException('Settlement rule not found.');
            }

            return $this->ruleService->createDraftVersion($rule, $definition, $priority, $effectiveFrom, $effectiveTo);
        }

        return $this->ruleService->updateDraftVersion($object, $definition, $priority, $effectiveFrom, $effectiveTo);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: int, 2: \DateTimeImmutable, 3: \DateTimeImmutable|null}
     */
    private function configurationFrom(array $data): array
    {
        if (!is_array($data['definition'] ?? null) || !is_int($data['priority'] ?? null) || !isset($data['effectiveFrom'])) {
            throw new \InvalidArgumentException('definition, integer priority, and effectiveFrom are required.');
        }
        try {
            $effectiveFrom = $data['effectiveFrom'] instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($data['effectiveFrom'])
                : new \DateTimeImmutable((string) $data['effectiveFrom']);
            $effectiveTo = !array_key_exists('effectiveTo', $data) || $data['effectiveTo'] === null
                ? null
                : ($data['effectiveTo'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($data['effectiveTo'])
                    : new \DateTimeImmutable((string) $data['effectiveTo']));
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('Invalid rule version effective date.', 0, $exception);
        }

        return [$data['definition'], $data['priority'], $effectiveFrom, $effectiveTo];
    }
}
