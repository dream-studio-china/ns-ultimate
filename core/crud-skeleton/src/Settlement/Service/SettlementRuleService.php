<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Core\Service\BaseService;
use App\Settlement\Entity\SettlementRule;
use App\Settlement\Entity\SettlementRuleVersion;
use App\Settlement\Repository\SettlementRuleVersionRepository;
use Doctrine\DBAL\LockMode;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<SettlementRule> */
final class SettlementRuleService extends BaseService implements SettlementRuleServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly SettlementRuleVersionRepository $versionRepository,
        private readonly SettlementRuleConfiguration $configuration,
    ) {
        parent::__construct($container, SettlementRule::class);
    }

    public function new(): SettlementRule
    {
        return new SettlementRule();
    }

    public function createDraftVersion(
        SettlementRule $rule,
        array $definition,
        int $priority,
        \DateTimeImmutable $effectiveFrom,
        ?\DateTimeImmutable $effectiveTo,
    ): SettlementRuleVersion {
        $this->validateVersionConfiguration($definition, $effectiveFrom, $effectiveTo);

        return $this->wrapInTransaction(function () use ($rule, $definition, $priority, $effectiveFrom, $effectiveTo): SettlementRuleVersion {
            $version = new SettlementRuleVersion(
                ruleUuid: $rule->getUuid(),
                version: $this->versionRepository->nextVersionForRule($rule->getUuid()),
                definition: $definition,
                definitionHash: hash('sha256', $this->canonicalJson($definition)),
                effectiveFrom: $effectiveFrom,
                priority: $priority,
                effectiveTo: $effectiveTo,
            );
            $this->getEntityManager()->persist($version);

            return $version;
        });
    }

    public function updateDraftVersion(
        SettlementRuleVersion $version,
        array $definition,
        int $priority,
        \DateTimeImmutable $effectiveFrom,
        ?\DateTimeImmutable $effectiveTo,
    ): SettlementRuleVersion {
        $this->validateVersionConfiguration($definition, $effectiveFrom, $effectiveTo);

        return $this->wrapInTransaction(function () use ($version, $definition, $priority, $effectiveFrom, $effectiveTo): SettlementRuleVersion {
            $version->configure($definition, hash('sha256', $this->canonicalJson($definition)), $priority, $effectiveFrom, $effectiveTo);
            return $version;
        });
    }

    public function publishVersion(SettlementRule $rule, SettlementRuleVersion $version, string $publishedBy): SettlementRuleVersion
    {
        if ($version->getRuleUuid() !== $rule->getUuid()) {
            throw new \InvalidArgumentException('Rule version does not belong to the rule.');
        }
        return $this->wrapInTransaction(function () use ($rule, $version, $publishedBy): SettlementRuleVersion {
            $this->getEntityManager()->lock($rule, LockMode::PESSIMISTIC_WRITE);
            $this->configuration->validate($version->getDefinition());
            if ($this->versionRepository->hasOverlappingPublishedVersion($version)) {
                throw new \LogicException('Published rule versions for one rule may not overlap.');
            }
            $version->publish($publishedBy);
            $rule->setCurrentVersion($version->getVersion());
            $rule->setStatus(SettlementRule::STATUS_PUBLISHED);
            return $version;
        });
    }

    /** @param array<string, mixed> $definition */
    private function validateVersionConfiguration(array $definition, \DateTimeImmutable $effectiveFrom, ?\DateTimeImmutable $effectiveTo): void
    {
        $this->configuration->validate($definition);
        if ($effectiveTo !== null && $effectiveTo <= $effectiveFrom) {
            throw new \InvalidArgumentException('effectiveTo must be after effectiveFrom.');
        }
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR);
    }
}
