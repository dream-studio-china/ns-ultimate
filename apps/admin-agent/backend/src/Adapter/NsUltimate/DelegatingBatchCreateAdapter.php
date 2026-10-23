<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use NsUltimate\Apps\AdminAgent\Backend\Contract\BatchCreateAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reuses one explicitly selected single-record create adapter so field validation, domain
 * authorization and persistence verification do not diverge between single and batch creation.
 */
abstract class DelegatingBatchCreateAdapter implements BatchCreateAdapter
{
    public function __construct(private readonly MutationAdapter $inner) {}

    final public function entityKey(): string
    {
        return $this->inner->entityKey();
    }

    final public function authorize(): void
    {
        $this->inner->authorize('create', null);
    }

    final public function prepare(array $rows): array
    {
        $prepared = [];
        foreach ($rows as $row) {
            if (!is_array($row) || $row === [] || array_is_list($row)) {
                throw new BadRequestHttpException('Each batch row must be a field object.');
            }
            $draft = $this->inner->prepare('create', null, $row);
            $identity = $this->identity($draft['changes']);
            if ($identity === '') {
                throw new \LogicException('Batch create adapter produced an empty row identity.');
            }
            $prepared[] = [
                'title' => $draft['title'],
                'changes' => $draft['changes'],
                'differences' => $draft['differences'],
                'identity' => $identity,
            ];
        }
        $this->assertBatch(array_column($prepared, 'changes'));

        return ['rows' => $prepared];
    }

    final public function execute(array $rows): array
    {
        $results = [];
        foreach ($rows as $index => $changes) {
            if (!is_array($changes) || ($changes !== [] && array_is_list($changes))) {
                throw new \LogicException('Stored batch row is not a field object.');
            }
            $result = $this->inner->execute('create', null, hash('sha256', json_encode($changes, JSON_THROW_ON_ERROR)), $changes);
            if (($result['verified'] ?? false) !== true || !is_string($result['identifier'] ?? null) || $result['identifier'] === '') {
                throw new \RuntimeException('Batch create read-back verification failed.');
            }
            $results[] = ['row' => $index + 1, 'identifier' => $result['identifier']];
        }

        return $results;
    }

    /**
     * Domain uniqueness/dependency key used to reject duplicates inside one proposal.
     *
     * @param array<string, mixed> $changes
     */
    abstract protected function identity(array $changes): string;

    /**
     * Optional homogeneous-batch constraints after all rows have been normalized.
     *
     * @param list<array<string, mixed>> $rows
     */
    protected function assertBatch(array $rows): void {}
}
