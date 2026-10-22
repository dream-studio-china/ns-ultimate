<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('admin_agent.mutation_adapter')]
interface MutationAdapter
{
    /** Explicit capability key, never a PHP class supplied by a caller. */
    public function entityKey(): string;

    /** Enforce action/record access on every operation access; prepare/execute also enforce field permissions. */
    public function authorize(string $action, ?string $identifier): void;

    /** Must not mutate domain data. Return canonical values from the actual target, not model-supplied old values.
     * @param array<string, mixed> $changes
     * @return array{identifier: ?string, title: string, beforeHash: string, changes: array<string, mixed>, differences: list<array{property: string, before: mixed, after: mixed}>}
     */
    public function prepare(string $action, ?string $identifier, array $changes): array;

    /** Runs inside the operation transaction. Lock/re-read the target, compare beforeHash, validate again, then verify persistence.
     * @param array<string, mixed> $changes
     * @return array{identifier: string, verified: bool}
     */
    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array;
}
