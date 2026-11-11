<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Service;

/** Per-check diagnostic collection shared by AST visitors. */
final class DraftDiagnostics
{
    /** @var list<array{code: string, path: string, message: string}> */
    private array $errors = [];

    public function add(string $code, string $path, string $message): void
    {
        $this->errors[] = compact('code', 'path', 'message');
    }

    public function hasErrors(): bool { return $this->errors !== []; }

    /** @return list<array{code: string, path: string, message: string}> */
    public function all(): array { return $this->errors; }
}
