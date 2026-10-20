<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

/** Expands a deliberately small JSON template language; it never evaluates code. */
final class PrintDocumentRenderer
{
    /** @param array<string, mixed> $template
     * @param array<string, mixed> $data
     * @return array{kind: string, pages: list<string>, content: string} */
    public function render(array $template, array $data): array
    {
        $kind = $template['kind'] ?? null;
        if (!in_array($kind, ['receipt', 'label'], true)) {
            throw new \InvalidArgumentException('Template kind must be receipt or label.');
        }
        $width = $this->dimension($template['widthMm'] ?? 40, 'widthMm');
        $height = $this->dimension($template['heightMm'] ?? 30, 'heightMm');
        $pages = $this->nodes($template['nodes'] ?? [], $data, 0);
        if ($kind === 'receipt') {
            $pages = [implode('', $pages)];
        }
        $pages = array_values(array_filter($pages, static fn (string $page): bool => trim($page) !== ''));
        if ($pages === [] || count($pages) > 200) {
            throw new \InvalidArgumentException('A print document must produce between 1 and 200 pages.');
        }
        if ($kind === 'label') {
            foreach ($pages as $page) {
                preg_match_all('/<(?:TEXT|BC128|BC39|QRC) x="(\d+)" y="(\d+)"(?: h="(\d+)")?/', $page, $positions, PREG_SET_ORDER);
                foreach ($positions as $position) {
                    $x = (int) $position[1];
                    $y = (int) $position[2];
                    $heightDots = isset($position[3]) ? (int) $position[3] : 0;
                    if ($x > $width * 8 || $y + $heightDots > $height * 8) {
                        throw new \InvalidArgumentException('Label element coordinates exceed the configured label size.');
                    }
                }
            }
        }
        $content = $kind === 'label' ? implode('', array_map(static fn (string $page): string => sprintf('<PAGE><SIZE>%s,%s</SIZE>%s</PAGE>', $width, $height, $page), $pages)) : implode('', $pages);
        if (strlen($content) > 100_000) {
            throw new \InvalidArgumentException('Expanded print document is too large.');
        }
        return ['kind' => $kind, 'pages' => $pages, 'content' => $content];
    }

    /** @param mixed $nodes
     * @param array<string, mixed> $scope
     * @return list<string> */
    private function nodes(mixed $nodes, array $scope, int $depth): array
    {
        if (!is_array($nodes) || $depth > 5) {
            throw new \InvalidArgumentException('Invalid template structure or excessive loop depth.');
        }
        $out = [''];
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_string($node['type'] ?? null)) {
                throw new \InvalidArgumentException('Each template node must have a type.');
            }
            if ($node['type'] === 'each') {
                $items = $this->value($scope, $node['path'] ?? '');
                if (!is_array($items) || count($items) > 100) {
                    throw new \InvalidArgumentException('Loop data must be an array with at most 100 items.');
                }
                $expanded = [];
                foreach (array_values($items) as $index => $item) {
                    if (!is_array($item)) { throw new \InvalidArgumentException('Loop items must be objects.'); }
                    $expanded = [...$expanded, ...$this->nodes($node['children'] ?? [], array_replace($scope, ['item' => $item, 'index' => $index]), $depth + 1)];
                    if (count($expanded) > 200) { throw new \InvalidArgumentException('Template expands to too many pages.'); }
                }
                $out = $this->combine($out, $expanded);
                continue;
            }
            if ($node['type'] === 'repeat') {
                $count = $this->value($scope, $node['countPath'] ?? '');
                if (!is_int($count) || $count < 0 || $count > 100) {
                    throw new \InvalidArgumentException('Repeat count must be an integer between 0 and 100.');
                }
                $expanded = [];
                for ($index = 0; $index < $count; ++$index) {
                    $expanded = [...$expanded, ...$this->nodes($node['children'] ?? [], array_replace($scope, ['repeatIndex' => $index]), $depth + 1)];
                    if (count($expanded) > 200) { throw new \InvalidArgumentException('Template expands to too many pages.'); }
                }
                $out = $this->combine($out, $expanded);
                continue;
            }
            if ($node['type'] === 'if') {
                $condition = $this->value($scope, $node['path'] ?? '');
                $matches = array_key_exists('equals', $node)
                    ? $condition === $node['equals']
                    : !in_array($condition, ['', null, false, 0], true);
                if ($matches) {
                    $out = $this->combine($out, $this->nodes($node['children'] ?? [], $scope, $depth + 1));
                }
                continue;
            }
            $piece = match ($node['type']) {
                'text' => $this->escape($this->literal($node['value'] ?? '')),
                'field' => $this->escape($this->stringValue($scope, $node['path'] ?? '')),
                'line' => '<BR>',
                'separator' => '--------------------------------<BR>',
                'bold' => '<BOLD>'.$this->plainChildren($node['children'] ?? [], $scope, $depth + 1).'</BOLD>',
                'labelText' => $this->labelText($node, $scope),
                'barcode' => $this->barcode($node, $scope),
                'qr' => $this->qr($node, $scope),
                'page' => "\n",
                default => throw new \InvalidArgumentException('Unsupported print template node type.'),
            };
            foreach ($out as &$current) { $current .= $piece; }
            unset($current);
        }
        return $out;
    }

    /** @param list<string> $left @param list<string> $right @return list<string> */
    private function combine(array $left, array $right): array
    {
        if ($right === []) { return $left; }
        $result = [];
        foreach ($left as $prefix) { foreach ($right as $suffix) { $result[] = $prefix.$suffix; } }
        if (count($result) > 200) { throw new \InvalidArgumentException('Template expands to too many pages.'); }
        return $result;
    }

    /** @param mixed $nodes @param array<string, mixed> $scope */
    private function plainChildren(mixed $nodes, array $scope, int $depth): string
    {
        return implode('', $this->nodes($nodes, $scope, $depth));
    }

    /** @param array<string, mixed> $scope */
    private function stringValue(array $scope, mixed $path): string
    {
        $value = $this->value($scope, $path);
        if (is_scalar($value) || $value === null) { return (string) $value; }
        throw new \InvalidArgumentException('Template fields must resolve to scalar values.');
    }

    private function literal(mixed $value): string
    {
        if (is_scalar($value) || $value === null) { return (string) $value; }
        throw new \InvalidArgumentException('Text template values must be scalar.');
    }

    /** @param array<string, mixed> $scope */
    private function value(array $scope, mixed $path): mixed
    {
        if (!is_string($path) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $path) !== 1) {
            throw new \InvalidArgumentException('Template paths may only contain simple object properties.');
        }
        $value = $scope;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) { return ''; }
            $value = $value[$part];
        }
        return $value;
    }

    private function escape(string $value): string
    {
        return str_replace(['<', '>'], ['&lt', '&gt'], $value);
    }

    /** @param array<string, mixed> $node @param array<string, mixed> $scope */
    private function labelText(array $node, array $scope): string
    {
        $x = $this->coordinate($node['x'] ?? 0, 'x');
        $y = $this->coordinate($node['y'] ?? 0, 'y');
        $font = $this->coordinate($node['font'] ?? 9, 'font', 1, 9);
        $value = $this->escape($this->stringValue($scope, $node['path'] ?? ''));
        return sprintf('<TEXT x="%d" y="%d" font="%d">%s</TEXT>', $x, $y, $font, $value);
    }

    /** @param array<string, mixed> $node @param array<string, mixed> $scope */
    private function barcode(array $node, array $scope): string
    {
        $type = $node['format'] ?? 'BC128';
        if (!in_array($type, ['BC128', 'BC39'], true)) { throw new \InvalidArgumentException('Unsupported label barcode format.'); }
        $x = $this->coordinate($node['x'] ?? 0, 'x');
        $y = $this->coordinate($node['y'] ?? 0, 'y');
        $height = $this->coordinate($node['height'] ?? 64, 'height', 1, 255);
        $value = $this->escape($this->stringValue($scope, $node['path'] ?? ''));
        if ($value === '') { throw new \InvalidArgumentException('Barcode content cannot be empty.'); }
        return sprintf('<%s x="%d" y="%d" h="%d">%s</%s>', $type, $x, $y, $height, $value, $type);
    }

    /** @param array<string, mixed> $node @param array<string, mixed> $scope */
    private function qr(array $node, array $scope): string
    {
        $x = $this->coordinate($node['x'] ?? 0, 'x');
        $y = $this->coordinate($node['y'] ?? 0, 'y');
        $value = $this->stringValue($scope, $node['path'] ?? '');
        $characters = preg_match_all('/./us', $value);
        if ($value === '' || $characters === false || $characters > 256) { throw new \InvalidArgumentException('QR content must contain 1 to 256 characters.'); }
        $value = $this->escape($value);
        return sprintf('<QRC x="%d" y="%d" s="2" e="L" c="1">%s</QRC>', $x, $y, $value);
    }

    private function coordinate(mixed $value, string $name, int $min = 0, int $max = 2048): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('%s must be an integer between %d and %d dots.', $name, $min, $max));
        }
        return $value;
    }

    private function dimension(mixed $value, string $name): int
    {
        if (!is_int($value) || $value < 10 || $value > 200) {
            throw new \InvalidArgumentException(sprintf('%s must be an integer between 10 and 200 mm.', $name));
        }
        return $value;
    }
}
