<?php

declare(strict_types=1);

namespace App\Settlement\Context;

use App\Settlement\Contract\SettlementSubject;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class SettlementContextResolverRegistry
{
    /** @var list<SettlementContextResolverInterface> */
    private array $resolvers = [];

    /**
     * @param iterable<SettlementContextResolverInterface> $resolvers
     */
    public function __construct(
        #[AutowireIterator('settlement.context_resolver')]
        iterable $resolvers,
    ) {
        $this->resolvers = [];
        foreach ($resolvers as $resolver) {
            $this->resolvers[] = $resolver;
        }
    }

    public function supports(SettlementSubject $subject): bool
    {
        return $this->find($subject) !== null;
    }

    public function get(SettlementSubject $subject): SettlementContextResolverInterface
    {
        $resolver = $this->find($subject);
        if ($resolver === null) {
            throw new \InvalidArgumentException(sprintf('No context resolver supports subject "%s:%s"', $subject->type, $subject->id));
        }
        return $resolver;
    }

    private function find(SettlementSubject $subject): ?SettlementContextResolverInterface
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($subject)) {
                return $resolver;
            }
        }
        return null;
    }
}
