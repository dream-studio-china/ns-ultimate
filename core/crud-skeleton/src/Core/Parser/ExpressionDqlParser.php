<?php

namespace App\Core\Parser;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\ArrayNode;
use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\UnaryNode;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Validator\Exception\ValidatorException;

/**
 * Expression -> QueryBuilder fragment compiler.
 *
 * - Builds safe QueryBuilder fragments (joins, where expressions, parameters)
 * - Supports PSR-16 cache for compiled expressions
 * - Throws ValidatorException for parse/eval/unsupported-node errors
 */
class ExpressionDqlParser
{
    private string $expression = '';
    /** @var array<string, mixed> */
    private array $values = [];
    /** @var list<string> */
    private array $names = [];
    private string $where = '';
    private string $dataClass = '';

    /** @var array<string,string> */
    private array $joins = [];
    /** @var ArrayCollection<int,Parameter> */
    private ArrayCollection $parameters;

    private ?SimpleCacheInterface $cache;
    private ExpressionLanguage $expressionLanguage;

    // constants
    private const EXPRESSION_SIGNATURE = 'entity';
    private const ROOT_ALIAS = 'filter_entity';
    private const PARAM_PREFIX = 'filter_parameter_';
    private const CACHE_VERSION = 2;
    private const LOGIC_OPERATORS = ['&&', '||'];

    private const OPERATORS = [
        '==' => '%1$s = %2$s',
        '!=' => '%1$s != %2$s',
        '>'  => '%1$s > %2$s',
        '>=' => '%1$s >= %2$s',
        '<'  => '%1$s < %2$s',
        '<=' => '%1$s <= %2$s',
        '&&' => '%1$s AND %2$s',
        '||' => '%1$s OR %2$s',
        '+'  => '%1$s + %2$s',
        '-'  => '%1$s - %2$s',
        '*'  => '%1$s * %2$s',
        '/'  => '%1$s / %2$s',
        'in' => '%1$s IN (%2$s)',
        'not in' => '%1$s NOT IN (%2$s)',
    ];

    public function __construct(?SimpleCacheInterface $cache = null, ?ExpressionLanguage $language = null)
    {
        $this->cache = $cache;
        $this->expressionLanguage = $language ?? new ExpressionLanguage();
        $this->parameters = new ArrayCollection();
    }

    public function setExpression(string $expr): self
    {
        $this->expression = $expr;
        return $this;
    }

    public function setDataClass(string $dataClass): self
    {
        $this->dataClass = $dataClass;
        return $this;
    }

    /** @param array<string, mixed> $values */
    public function setValues(array $values): self
    {
        // keep entity signature key present for compatibility
        $this->values = array_merge([self::EXPRESSION_SIGNATURE => ''], $values);
        $this->names = array_keys($this->values);
        return $this;
    }

    public function reset(): self
    {
        $this->where = '';
        $this->joins = [];
        $this->parameters = new ArrayCollection();
        return $this;
    }

    /**
     * Compile expression into joins/where/parameters and optionally apply to a QueryBuilder.
     * Uses PSR-16 cache when available.
     *
     * @param int $cacheTtl seconds
     * @return $this
     * @throws ValidatorException
     */
    public function compile(int $cacheTtl = 3600): self
    {
        if (trim($this->expression) === '') {
            throw new ValidatorException('Empty expression is not allowed');
        }

        // Ensure the expression signature variable (e.g. 'entity') is always allowed
        if (empty($this->names) || !in_array(self::EXPRESSION_SIGNATURE, $this->names, true)) {
            $this->names[] = self::EXPRESSION_SIGNATURE;
        }

        $cacheKey = null;
        if ($this->cache) {
            $cacheKey = 'expr_' . md5(self::CACHE_VERSION . '|' . $this->dataClass . '|' . $this->expression . '|' . implode(',', $this->names));
            try {
                if ($this->cache->has($cacheKey)) {
                    $cached = $this->cache->get($cacheKey);
                    if (is_array($cached)) {
                        $this->joins = $cached['joins'] ?? [];
                        $this->where = $cached['where'] ?? '';
                        $this->parameters = new ArrayCollection();
                        foreach (($cached['params'] ?? []) as $n => $v) {
                            $this->parameters->add(new Parameter($n, $v));
                        }

                        return $this;
                    }
                }
            } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
                // ignore cache errors
            }
        }

        try {
            $parsed = $this->expressionLanguage->parse($this->expression, $this->names);
        } catch (SyntaxError $e) {
            throw new ValidatorException('Expression syntax error: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new ValidatorException('Expression parse error: ' . $e->getMessage());
        }

        try {
            $fragment = $this->recursiveCompile($parsed->getNodes());
        } catch (ValidatorException $ve) {
            throw $ve;
        } catch (\Exception $e) {
            throw new ValidatorException('Expression compile error: ' . $e->getMessage());
        }

        $this->where = $fragment;

        // store in cache
        if ($this->cache && $cacheKey) {
            $toCache = [
                'where' => $this->where,
                'joins' => $this->joins,
                'params' => [],
            ];
            foreach ($this->parameters as $p) {
                $toCache['params'][$p->getName()] = $p->getValue();
            }
            try {
                $this->cache->set($cacheKey, $toCache, $cacheTtl);
            } catch (\Exception $e) {
                // ignore cache set failures
            }
        }

        return $this;
    }

    public function getWhere(): string
    {
        return $this->where;
    }

    /**
     * @return array<string, string>
     */
    public function getJoins(): array
    {
        return $this->joins;
    }

    /** @return ArrayCollection<int,Parameter> */
    public function getParameters(): ArrayCollection
    {
        return $this->parameters;
    }

    public function getDataClass(): string
    {
        return $this->dataClass;
    }

    public function getRootAlias(): string
    {
        return self::ROOT_ALIAS;
    }

    /**
     * Return a structured fragments array representing compiled parts.
     * - joins: array(alias => path)
     * - where: string
     * - params: array(name => value)
     *
     * This keeps the parser DB-agnostic; actual QueryBuilder assembly is done by ExpressionQueryBuilderAssembler.
     *
     * @return array{joins: array<string, string>, where: string, params: array<string, mixed>}
     */
    public function getFragments(): array
    {
        $params = [];
        foreach ($this->parameters as $p) {
            $params[$p->getName()] = $p->getValue();
        }

        return [
            'joins' => $this->joins,
            'where' => $this->where,
            'params' => $params,
        ];
    }

    /**
     * Convenience: return parameters as associative array (name => value).
     * @return array<string,mixed>
     */
    public function getParametersArray(): array
    {
        $out = [];
        foreach ($this->parameters as $p) {
            $out[$p->getName()] = $p->getValue();
        }
        return $out;
    }

    /**
     * Recursively compile AST nodes to a DQL fragment (without WHERE prefix).
     * Supports a strict set of node types for safety.
     *
     * @throws ValidatorException
     */
    private function recursiveCompile(Node $node, int $depth = 0): string
    {
        $nodes = $node->nodes;

        $isGrouped = count($nodes) && !($node instanceof GetAttrNode);

        $out = $isGrouped ? '(' : '';

        // If the entire expression is a single attribute access (no operators),
        // treat it as a truthy check by appending IS NOT NULL at top-level.
        $isTopLevelAttrCheck = ($depth === 0 && $node instanceof GetAttrNode);

        if ($node instanceof BinaryNode) {
            $op = $node->attributes['operator'] ?? null;
            if ($op === 'matches') {
                $left = $this->recursiveCompile($node->nodes['left'], $depth + 1);
                [$right, $isRegex] = $this->compileMatchOperand($node->nodes['right'], $depth + 1);
                $out .= $isRegex
                    ? sprintf('REGEXP(%s, %s) = TRUE', $left, $right)
                    : sprintf("%s LIKE %s ESCAPE '!'", $left, $right);
                $out .= $isGrouped ? ')' : '';

                return $out;
            }
            if (!isset(self::OPERATORS[$op])) {
                throw new ValidatorException('Unsupported operator: ' . ($op ?? 'unknown'));
            }

            $left = $this->recursiveCompile($node->nodes['left'], $depth + 1);
            $right = $this->recursiveCompile($node->nodes['right'], $depth + 1);

            // handle empty IN / NOT IN as constant predicates
            if (in_array($op, ['in', 'not in'], true) && str_starts_with($right, ':')) {
                $paramName = ltrim($right, ':');
                $paramValue = null;
                $foundParam = null;
                $found = false;
                foreach ($this->parameters as $p) {
                    if ($p->getName() === $paramName) {
                        $paramValue = $p->getValue();
                        $foundParam = $p;
                        $found = true;
                        break;
                    }
                }
                if ($found && is_array($paramValue) && count($paramValue) === 0) {
                    // Remove the now-unused empty array parameter to avoid Doctrine "Too many parameters" error
                    if ($foundParam !== null) {
                        $this->parameters->removeElement($foundParam);
                    }
                    $out .= ($op === 'in' ? '1 = 0' : '1 = 1');
                    $out .= $isGrouped ? ')' : '';

                    return $out;
                }
            }

            // special-case logic operators to append IS NOT NULL for attr checks
            if (in_array($op, self::LOGIC_OPERATORS, true)) {
                if ($node->nodes['left'] instanceof GetAttrNode) {
                    $left = $left . ' IS NOT NULL';
                }
                if ($node->nodes['right'] instanceof GetAttrNode) {
                    $right = $right . ' IS NOT NULL';
                }
            }

            $out .= sprintf(self::OPERATORS[$op], $left, $right);
        } elseif ($node instanceof NameNode) {
            $varName = $node->attributes['name'] ?? '';
            if ($varName === self::EXPRESSION_SIGNATURE) {
                throw new ValidatorException('Standalone entity variable is not allowed.');
            }
            if (!in_array($varName, $this->names, true) || !array_key_exists($varName, $this->values)) {
                throw new ValidatorException(sprintf('Undefined variable "%s" in expression.', $varName));
            }
            $idx = $this->parameters->count() + 1;
            $paramName = self::PARAM_PREFIX . $idx;
            $this->parameters->add(new Parameter($paramName, $this->values[$varName]));
            $out .= ':' . $paramName;
        } elseif ($node instanceof ArrayNode) {
            try {
                $val = $node->evaluate([], $this->values);
            } catch (\Throwable $e) {
                throw new ValidatorException('Failed to evaluate array value: ' . $e->getMessage());
            }
            if (!is_array($val)) { // @phpstan-ignore function.alreadyNarrowedType
                throw new ValidatorException('Array expression did not evaluate to array.');
            }
            $idx = $this->parameters->count() + 1;
            $name = self::PARAM_PREFIX . $idx;
            $this->parameters->add(new Parameter($name, $val));

            return ':' . $name;
        } elseif ($node instanceof ConstantNode) {
            $idx = $this->parameters->count() + 1;
            $name = self::PARAM_PREFIX . $idx;
            $this->parameters->add(new Parameter($name, $node->attributes['value']));
            $out .= ':' . $name;
        } elseif ($node instanceof UnaryNode) {
            if (($node->attributes['operator'] ?? '') === '!') {
                // assume single child
                foreach ($nodes as $child) {
                    $inner = $this->recursiveCompile($child, $depth + 1);
                    $out .= $inner . ' IS NULL';
                }
            } else {
                throw new ValidatorException('Unsupported unary operator');
            }
        } elseif ($node instanceof GetAttrNode) {
            // handle only chains like entity.getX().getY()
            $dump = $node->dump();
            $pattern = sprintf('/^%s(\\.get([A-Z]\w+)\(\))+$/', self::EXPRESSION_SIGNATURE);
            if (preg_match($pattern, $dump)) {
                // extract getX tokens
                preg_match_all('/\\.get([A-Z]\w+)/', $dump, $matches);
                if (empty($matches[1])) {
                    throw new ValidatorException('Invalid attribute access in expression');
                }
                $alias = self::ROOT_ALIAS;
                $joinKey = $alias;
                $final = '';
                foreach ($matches[1] as $i => $m) {
                    $prop = lcfirst($m);
                    $final = $joinKey . '.' . $prop;
                    if ($i < count($matches[1]) - 1) {
                        $joinKey .= '_' . $prop;
                        $this->joins[$joinKey] = $final;
                    }
                }
                // final alias.property should be appended in caller
                $out .= $final;

                // If this GetAttrNode is the entire expression, append IS NOT NULL
                if ($isTopLevelAttrCheck) {
                    $out .= ' IS NOT NULL';
                }
            } else {
                // fallback: evaluate against provided values (safe-eval)
                try {
                    $val = $node->evaluate([], $this->values);
                } catch (\Throwable $e) {
                    throw new ValidatorException('Failed to evaluate dynamic value: ' . $e->getMessage());
                }
                $idx = $this->parameters->count() + 1;
                $name = self::PARAM_PREFIX . $idx;
                $this->parameters->add(new Parameter($name, $val));
                $out .= ':' . $name;
            }
        } else {
            // generic traversal for other nodes (be conservative)
            foreach ($nodes as $child) {
                $out .= $this->recursiveCompile($child, $depth + 1) . ' ';
            }
        }

        $out .= $isGrouped ? ')' : '';
        return $out;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function compileMatchOperand(Node $node, int $depth): array
    {
        $operand = $this->recursiveCompile($node, $depth);
        if (!str_starts_with($operand, ':' . self::PARAM_PREFIX)) {
            throw new ValidatorException('The matches operator requires a string pattern.');
        }

        $parameterName = substr($operand, 1);
        foreach ($this->parameters as $parameter) {
            if ($parameter->getName() !== $parameterName) {
                continue;
            }

            $value = $parameter->getValue();
            if (!is_string($value)) {
                throw new ValidatorException('The matches operator requires a string pattern.');
            }

            $regex = $this->parseRegexLiteral($value);
            if ($regex !== null) {
                $parameter->setValue($regex);

                return [$operand, true];
            }

            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
            $parameter->setValue('%' . $literal . '%');

            return [$operand, false];
        }

        throw new ValidatorException('The matches pattern parameter was not compiled.');
    }

    private function parseRegexLiteral(string $value): ?string
    {
        if (!str_starts_with($value, '/')) {
            return null;
        }
        if (preg_match('/^\/((?:\\\\.|[^\/])*)\/([a-zA-Z]*)$/s', $value, $matches) !== 1) {
            return null;
        }

        $flags = $matches[2];
        if (preg_match('/[^gimsux]/', $flags) === 1) {
            throw new ValidatorException('Unsupported matches regex flags: ' . $flags);
        }

        $inlineFlags = '';
        foreach (str_split('imsx') as $flag) {
            if (str_contains($flags, $flag)) {
                $inlineFlags .= $flag;
            }
        }

        $pattern = str_replace('\/', '/', $matches[1]);

        return ($inlineFlags === '' ? '' : '(?' . $inlineFlags . ')') . $pattern;
    }

    /**
     * Return the full DQL source for the compiled expression.
     * If a QueryBuilder is provided, prefer its canonical DQL.
     * Otherwise build a SELECT/FROM/LEFT JOIN[/WHERE] string using internal state.
     *
     * @param QueryBuilder|null $qb
     * @throws ValidatorException
     */
    public function getSource(?QueryBuilder $qb = null): string
    {
        // Prefer canonical DQL from provided QueryBuilder
        if ($qb !== null) {
            try {
                return (string) $qb->getDQL();
            } catch (\Exception $e) {
                // fall through to manual builder
            }
        }

        if (trim($this->dataClass) === '') {
            throw new ValidatorException('Data class is not set; cannot build full DQL');
        }

        // Use the parser's ROOT_ALIAS as the FROM alias because joins/where are built against it
        $alias = self::ROOT_ALIAS;
        $dql = sprintf('SELECT %s FROM %s %s', $alias, $this->dataClass, $alias);

        // Append joins in insertion order
        foreach ($this->joins as $joinAlias => $path) {
            $dql .= ' LEFT JOIN ' . $path . ' ' . $joinAlias;
        }

        if ($this->where !== '') {
            $dql .= ' WHERE ' . $this->where;
        }

        return $dql;
    }

    /**
     * Validate parser fragments (joins/where/params) against Doctrine metadata without executing queries.
     * Throws ValidatorException when validation fails.
     *
     * @param \Doctrine\ORM\EntityManagerInterface $em
     * @throws ValidatorException
     */
    public function validateFragments(\Doctrine\ORM\EntityManagerInterface $em): void
    {
        $fragments = $this->getFragments();
        $joins = $fragments['joins'] ?? [];
        $where = $fragments['where'] ?? '';

        $rootAlias = $this->getRootAlias();
        $rootClass = $this->dataClass;

        if (trim($rootClass) === '') {
            throw new ValidatorException('Parser dataClass is not set; cannot validate fragments');
        }

        // Recursive resolver: given a dotted path (which may start with a root alias or a join alias),
        // return the class reached after traversing the path. Throws ValidatorException on errors.
        $resolvePathToClass = null;
        $resolvePathToClass = function(string $path) use (&$resolvePathToClass, $joins, $em, $rootAlias, $rootClass) : string {
            $segments = explode('.', $path);
            if (count($segments) === 0) {
                throw new ValidatorException('Empty path');
            }

            // Determine starting class
            $first = array_shift($segments);
            if ($first === $rootAlias || $first === self::EXPRESSION_SIGNATURE) {
                $currentClass = $rootClass;
            } elseif (isset($joins[$first])) {
                // The join mapping itself may point to a path that starts with another alias.
                $mapped = $joins[$first];
                // Recursively resolve the join's mapping to a class
                $currentClass = $resolvePathToClass($mapped);
            } else {
                throw new ValidatorException(sprintf('Unknown alias "%s" in path "%s"', $first, $path));
            }

            // Traverse remaining segments on the currentClass
            foreach ($segments as $i => $seg) {
                /** @var class-string<object> $currentClass */
                $meta = $em->getClassMetadata($currentClass);
                if ($meta->hasAssociation($seg)) {
                    $assoc = $meta->getAssociationMapping($seg);
                    $currentClass = $assoc->targetEntity;
                    continue;
                }
                if ($meta->hasField($seg)) {
                    if ($i < count($segments) - 1) {
                        throw new ValidatorException(sprintf('Property "%s" on %s is a field, cannot traverse to "%s"', $seg, $currentClass, $segments[$i+1]));
                    }
                    // Field exists and is the final segment
                    return $currentClass;
                }
                throw new ValidatorException(sprintf('Property or association "%s" not found on %s', $seg, $currentClass));
            }

            return $currentClass;
        };

        // Validate each join mapping itself (ensure its target path is resolvable)
        foreach ($joins as $alias => $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            // The path might be like 'filter_entity.owner' or 'filter_entity_owner.address'
            $resolvePathToClass($path);
        }

        // Validate where-referenced properties (tokens like alias.prop.subprop)
        if (is_string($where) && $where !== '') {
            preg_match_all('/([a-zA-Z_][\w]*)(?:\.[a-zA-Z_][\w]*)+/', $where, $matches);
            if (!empty($matches[0])) {
                foreach ($matches[0] as $token) {
                    if (strpos($token, ':') === 0) {
                        continue;
                    }

                    // token is a dotted path, try to resolve it fully
                    try {
                        $resolvePathToClass($token);
                    } catch (ValidatorException $ve) {
                        // rethrow with more context
                        throw new ValidatorException('Validation failed for token "' . $token . '": ' . $ve->getMessage());
                    }
                }
            }
        }
    }
}
