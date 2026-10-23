<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Explicit create-only batch capability.
 *
 * The generic lifecycle knows nothing about domain fields. Adapters own authorization, row
 * normalization, duplicate identity, dependency checks, insertion and read-back verification.
 */
#[AutoconfigureTag('admin_agent.batch_create_adapter')]
interface BatchCreateAdapter
{
    /** Explicit capability key, never a PHP class supplied by a caller. */
    public function entityKey(): string;

    /** Enforce batch-create access on every operation access. */
    public function authorize(): void;

    /**
     * Validate every row and return canonical per-row previews without mutating domain data.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array{title: string, changes: array<string, mixed>, differences: list<array{property: string, before: mixed, after: mixed}>, identity: string}>}
     */
    public function prepare(array $rows): array;

    /**
     * Runs inside the operation transaction. Insert every canonical row, revalidate dependencies
     * and uniqueness, and verify persistence. Any failure must roll back the whole batch.
     *
     * @param list<array<string, mixed>> $rows canonical changes returned by prepare()
     * @return list<array{row: int, identifier: string}>
     */
    public function execute(array $rows): array;
}
