<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

/** Expands a deliberately small JSON template language; it never evaluates code. */
final class PrintDocumentRenderer
{
    /** @param array<string, mixed> $template
     * @param array<string, mixed> $data
     * @return array{kind: string, pages: list<string>, content: string, widthMm: int, heightMm: ?int} */
    public function render(array $template, array $data): array
    {
        $kind = $template['kind'] ?? null;
        if (!in_array($kind, ['receipt', 'label'], true)) {
            throw new \InvalidArgumentException('Template kind must be receipt or label.');
        }
        $width = $this->dimension($template['widthMm'] ?? ($kind === 'receipt' ? 80 : 40), 'widthMm');
        $height = $this->dimension($template['heightMm'] ?? 30, 'heightMm');
        $pages = $this->nodes($template['nodes'] ?? [], $data, 0, ['kind' => $kind, 'width' => $width]);
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
        $envelope = $template['labelEnvelope'] ?? 'sized';
        if (!in_array($envelope, ['sized', 'legacy'], true) || ($envelope === 'legacy' && $kind !== 'label')) {
            throw new \InvalidArgumentException('Label envelope must be sized or legacy and only applies to labels.');
        }
        $content = $kind === 'label' ? implode('', array_map(static fn (string $page): string => $envelope === 'legacy'
            ? '<PAGE>'.$page.'</PAGE>'
            : sprintf('<PAGE><SIZE>%s,%s</SIZE>%s</PAGE>', $width, $height, $page), $pages)) : implode('', $pages);
        if (strlen($content) > 100_000) {
            throw new \InvalidArgumentException('Expanded print document is too large.');
        }
        return ['kind' => $kind, 'pages' => $pages, 'content' => $content, 'widthMm' => $width, 'heightMm' => $kind === 'label' ? $height : null];
    }

    /** @param mixed $nodes
     * @param array<string, mixed> $scope
     * @param array{kind: string, width: int} $layout
     * @return list<string> */
    private function nodes(mixed $nodes, array $scope, int $depth, array $layout): array
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
                    $expanded = [...$expanded, ...$this->nodes($node['children'] ?? [], array_replace($scope, ['item' => $item, 'index' => $index]), $depth + 1, $layout)];
                    if (count($expanded) > 200) { throw new \InvalidArgumentException('Template expands to too many pages.'); }
                }
                $out = $this->combine($out, $layout['kind'] === 'receipt' ? [implode('', $expanded)] : $expanded);
                continue;
            }
            if ($node['type'] === 'repeat') {
                $count = $this->value($scope, $node['countPath'] ?? '');
                if (!is_int($count) || $count < 0 || $count > 100) {
                    throw new \InvalidArgumentException('Repeat count must be an integer between 0 and 100.');
                }
                $expanded = [];
                for ($index = 0; $index < $count; ++$index) {
                    $expanded = [...$expanded, ...$this->nodes($node['children'] ?? [], array_replace($scope, ['repeatIndex' => $index]), $depth + 1, $layout)];
                    if (count($expanded) > 200) { throw new \InvalidArgumentException('Template expands to too many pages.'); }
                }
                $out = $this->combine($out, $layout['kind'] === 'receipt' ? [implode('', $expanded)] : $expanded);
                continue;
            }
            if ($node['type'] === 'if') {
                $condition = $this->value($scope, $node['path'] ?? '');
                $matches = array_key_exists('equals', $node)
                    ? $condition === $node['equals']
                    : !in_array($condition, ['', null, false, 0], true);
                if ($matches) {
                    $out = $this->combine($out, $this->nodes($node['children'] ?? [], $scope, $depth + 1, $layout));
                }
                continue;
            }
            $piece = match ($node['type']) {
                'text' => $this->escape($this->literal($node['value'] ?? '')),
                'field' => $this->escape($this->stringValue($scope, $node['path'] ?? '')),
                'line' => '<BR>',
                'separator' => '--------------------------------<BR>',
                'bold' => '<BOLD>'.$this->plainChildren($node['children'] ?? [], $scope, $depth + 1, $layout).'</BOLD>',
                'style' => $this->receiptStyle($node, $scope, $depth, $layout),
                'receiptCommand' => $this->receiptCommand($node, $layout),
                'columns' => $this->columns($node, $scope, $layout),
                'labelText' => $this->labelText($node, $scope, $layout),
                'barcode' => $this->barcode($node, $scope),
                'qr' => $this->qr($node, $scope, $layout),
                'page' => "\n",
                default => throw new \InvalidArgumentException('Unsupported print template node type.'),
            };
            foreach ($out as &$current) { $current .= $piece; }
            unset($current);
        }
        return $out;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private function combine(array $left, array $right): array
    {
        if ($right === []) { return $left; }
        $result = [];
        foreach ($left as $prefix) { foreach ($right as $suffix) { $result[] = $prefix.$suffix; } }
        if (count($result) > 200) { throw new \InvalidArgumentException('Template expands to too many pages.'); }
        return $result;
    }

    /**
     * @param mixed $nodes
     * @param array<string, mixed> $scope
     * @param array{kind: string, width: int} $layout
     */
    private function plainChildren(mixed $nodes, array $scope, int $depth, array $layout): string
    {
        return implode('', $this->nodes($nodes, $scope, $depth, $layout));
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

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $scope
     * @param array{kind: string, width: int} $layout
     */
    private function labelText(array $node, array $scope, array $layout): string
    {
        $x = $this->coordinate($node['x'] ?? 0, 'x');
        $y = $this->coordinate($node['y'] ?? 0, 'y');
        $font = $this->coordinate($node['font'] ?? 9, 'font', 1, 9);
        $value = array_key_exists('value', $node) ? $this->literal($node['value']) : $this->stringValue($scope, $node['path'] ?? '');
        $w = $this->coordinate($node['w'] ?? 1, 'w', 1, 10);
        $h = $this->coordinate($node['h'] ?? 1, 'h', 1, 10);
        $rotation = $node['r'] ?? 0;
        if (!in_array($rotation, [0, 90, 180, 270], true)) { throw new \InvalidArgumentException('Label rotation must be 0, 90, 180 or 270.'); }
        if (isset($node['align'])) {
            if ($layout['kind'] !== 'label' || $font !== 9 || $rotation !== 0 || !in_array($node['align'], ['left', 'center', 'right'], true)) {
                throw new \InvalidArgumentException('Automatic label alignment requires unrotated font 9 text.');
            }
            $widthMode = $node['widthMode'] ?? 'display';
            if (!in_array($widthMode, ['display', 'legacy'], true)) {
                throw new \InvalidArgumentException('Label width mode must be display or legacy.');
            }
            // The legacy algorithm counts every non-ASCII code point as two
            // half-cells, including accented letters and combining marks.
            $cells = $widthMode === 'legacy' ? $this->legacyLabelCells($value) : $this->displayWidth($value);
            $textWidth = $cells * 12 * $w;
            $available = $layout['width'] * 8;
            $x = match ($node['align']) {
                'center' => max(0, intdiv($available - $textWidth, 2)),
                'right' => max(0, $available - $textWidth),
                default => $x,
            };
        }
        // Preserve the exact command shape of existing templates unless scaling/rotation is requested.
        $attributes = array_intersect(['w', 'h', 'r', 'align'], array_keys($node)) !== []
            ? sprintf(' w="%d" h="%d" r="%d"', $w, $h, $rotation) : '';
        return sprintf('<TEXT x="%d" y="%d" font="%d"%s>%s</TEXT>', $x, $y, $font, $attributes, $this->escape($value));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $scope
     */
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

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $scope
     * @param array{kind: string, width: int} $layout
     */
    private function qr(array $node, array $scope, array $layout): string
    {
        $x = $this->coordinate($node['x'] ?? 0, 'x');
        $y = $this->coordinate($node['y'] ?? 0, 'y');
        $value = $this->stringValue($scope, $node['path'] ?? '');
        $characters = preg_match_all('/./us', $value);
        if ($value === '' || $characters === false || $characters > 256) { throw new \InvalidArgumentException('QR content must contain 1 to 256 characters.'); }
        $errorCorrection = $node['errorCorrection'] ?? 'L';
        if (!in_array($errorCorrection, ['L', 'M', 'Q', 'H'], true)) { throw new \InvalidArgumentException('Invalid QR error correction level.'); }
        if ($layout['kind'] === 'receipt') {
            $size = $this->coordinate($node['size'] ?? 6, 'size', 0, 16);
            $align = $node['align'] ?? 'center';
            if (!in_array($align, ['left', 'center', 'right'], true) || preg_match('/\s/u', $value)) {
                throw new \InvalidArgumentException('Receipt QR requires a valid alignment and content without whitespace.');
            }
            return sprintf('<QRCODE s=%d e=%s l=%s>%s</QRCODE>', $size, $errorCorrection, $align, $this->escape($value));
        }
        $size = $this->coordinate($node['size'] ?? 2, 'size', 1, 10);
        return sprintf('<QRC x="%d" y="%d" s="%d" e="%s" c="1">%s</QRC>', $x, $y, $size, $errorCorrection, $this->escape($value));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $scope
     * @param array{kind: string, width: int} $layout
     */
    private function receiptStyle(array $node, array $scope, int $depth, array $layout): string
    {
        if ($layout['kind'] !== 'receipt') { throw new \InvalidArgumentException('Style blocks are only supported on receipts.'); }
        $align = $node['align'] ?? 'left';
        if (!in_array($align, ['left', 'center', 'right'], true)) { throw new \InvalidArgumentException('Invalid receipt alignment.'); }
        $width = $this->coordinate($node['width'] ?? 1, 'width', 1, 2);
        $height = $this->coordinate($node['height'] ?? 1, 'height', 1, 2);
        if (isset($node['bold']) && !is_bool($node['bold'])) { throw new \InvalidArgumentException('Receipt bold must be boolean.'); }
        $tag = ['left' => 'L', 'center' => 'C', 'right' => 'R'][$align];
        $size = $width === 2 ? ($height === 2 ? 'B' : 'WB') : ($height === 2 ? 'HB' : 'N');
        $content = $this->plainChildren($node['children'] ?? [], $scope, $depth + 1, $layout);
        // Vendor alignment is line-based: the terminating break must be inside the alignment tag.
        if (!str_ends_with($content, '<BR>')) { $content .= '<BR>'; }
        if ($node['bold'] ?? false) { $content = '<BOLD>'.$content.'</BOLD>'; }
        return '<'.$tag.'><'.$size.'>'.$content.'</'.$size.'></'.$tag.'>';
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $scope
     * @param array{kind: string, width: int} $layout
     */
    private function columns(array $node, array $scope, array $layout): string
    {
        $columns = $node['columns'] ?? null;
        if ($layout['kind'] !== 'receipt' || !is_array($columns) || $columns === [] || count($columns) > 8) {
            throw new \InvalidArgumentException('Receipt columns require between 1 and 8 column definitions.');
        }
        $out = '';
        $totalWidth = 0;
        foreach ($columns as $column) {
            if (!is_array($column)) { throw new \InvalidArgumentException('Invalid receipt column.'); }
            $width = $this->coordinate($column['width'] ?? null, 'column width', 1, 96);
            $totalWidth += $width;
            if ($totalWidth > 96) { throw new \InvalidArgumentException('Receipt column widths may total at most 96 display cells.'); }
            $align = $column['align'] ?? 'left';
            if (!in_array($align, ['left', 'right', 'center'], true)) { throw new \InvalidArgumentException('Invalid column alignment.'); }
            $value = array_key_exists('value', $column) ? $this->literal($column['value']) : $this->stringValue($scope, $column['path'] ?? '');
            // Fixed cells must not contain line breaks or printer controls.
            if (preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new \InvalidArgumentException('Receipt columns cannot contain control characters.'); }
            $value = $this->truncateDisplay($value, $width);
            $padding = $width - $this->displayWidth($value);
            $left = $align === 'right' ? $padding : ($align === 'center' ? intdiv($padding, 2) : 0);
            $out .= str_repeat(' ', $left).$value.str_repeat(' ', $padding - $left);
        }
        return $this->escape($out);
    }

    private function displayWidth(string $value): int
    {
        return mb_strwidth($value, 'UTF-8');
    }

    private function legacyLabelCells(string $value): int
    {
        $characters = preg_split('//u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) { throw new \InvalidArgumentException('Label text must be valid UTF-8.'); }
        $cells = 0;
        foreach ($characters as $character) { $cells += strlen($character) > 1 ? 2 : 1; }
        return $cells;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{kind: string, width: int} $layout
     */
    private function receiptCommand(array $node, array $layout): string
    {
        $command = $node['value'] ?? null;
        if ($layout['kind'] !== 'receipt' || !is_string($command)
            || preg_match('/^<(?:BR|\/?(?:L|C|R|N|HB|WB|B|BOLD|CB))>$/D', $command) !== 1) {
            throw new \InvalidArgumentException('Receipt commands must be a single allowlisted formatting tag.');
        }
        // Unlike style blocks, legacy commands are stateful and must not gain
        // implicit closing tags or line breaks. Data still passes through field escaping.
        return $command;
    }

    private function truncateDisplay(string $value, int $width): string
    {
        return mb_strimwidth($value, 0, $width, '', 'UTF-8');
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
