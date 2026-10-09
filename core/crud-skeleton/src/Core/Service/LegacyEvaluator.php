<?php

namespace App\Core\Service;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Psr\Log\LoggerInterface;

class LegacyEvaluator
{
    /** @var ExpressionLanguage|null */
    protected $language;
    /** @var LoggerInterface|null */
    protected $logger;
    /** @var array<string, mixed> */
    protected $globals;

    /**
     * @param array<string, mixed> $globals
     */
    public function __construct(?ExpressionLanguage $language = null, ?LoggerInterface $logger = null, array $globals = [])
    {
        $this->language = $language ?: new ExpressionLanguage();
        $this->logger = $logger;
        $this->globals = $globals;
    }

    /**
     * Evaluate expression in the given context. Returns the raw evaluation result.
     * Catches exceptions and logs them if logger is provided, returning false on error.
     * @param array<string, mixed> $context
     * @return mixed
     */
    public function evaluate(string $expr, array $context = [])
    {
        try {
            $vals = array_merge($this->globals, $context);
            if ($this->language === null) {
                return false;
            }
            return $this->language->evaluate($expr, $vals);
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('LegacyEvaluator evaluate error: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Convenience method for boolean evaluation (used for filters/sorters). Always returns boolean.
     * @param array<string, mixed> $context
     */
    public function evaluateBool(string $expr, array $context = []): bool
    {
        return (bool)$this->evaluate($expr, $context);
    }
}
