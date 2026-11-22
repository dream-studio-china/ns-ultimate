<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Controller;

use Symfony\Component\HttpFoundation\Response;

final class LedgerCsv
{
    /** @param array<string, mixed> $data */
    public static function response(string $kind, array $data): Response
    {
        $stream = fopen('php://temp', 'w+') ?: throw new \RuntimeException('Cannot allocate CSV stream.');
        $rows = $data['rows'];
        $columns = $kind === 'ledger'
            ? ['id', 'accounting_date', 'number', 'summary', 'position', 'side', 'amount', 'description', 'balance']
            : ['code', 'name', 'opening_debit', 'opening_credit', 'movement_debit', 'movement_credit', 'closing_debit', 'closing_credit'];
        $context = ['currency' => $data['currency'], 'scale' => $data['scale'], 'cutoff' => $data['cutoff']];
        if ($kind === 'ledger') {
            $context['opening'] = $data['opening'];
        }
        fputcsv($stream, array_merge($columns, array_keys($context)), escape: '');
        if (isset($data['totals'])) {
            $rows[] = ['name' => 'TOTAL'] + $data['totals'];
        }
        foreach ($rows ?: [[]] as $row) {
            $cells = array_map(static function (string $column) use ($row): string {
                $value = (string) ($row[$column] ?? '');
                if (in_array($column, ['summary', 'description', 'name'], true)
                    && preg_match('/\A(?:[\t\r\n]|[\p{Z}\p{C}]*[=+\-@＝＋－＠])/u', $value)) {
                    return "'".$value;
                }

                return $value;
            }, $columns);
            fputcsv($stream, array_merge($cells, array_values($context)), escape: '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="accounting-'.$kind.'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
