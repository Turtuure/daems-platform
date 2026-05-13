<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Parses Nordea netbank CSV export (Finnish locale). Tab- or semicolon-
 * separated depending on the user's export settings; auto-detected.
 *
 * Skips rows with non-positive amounts (outgoing payments — not relevant
 * for fee matching). Skips header row. Skips rows whose Arvopäivä can't
 * be parsed.
 *
 * Multi-bank support (OP, Aktia, Danske) is deferred — Wave 0.8+.
 */
final class NordeaPaymentCsvParser
{
    /**
     * @return list<ParsedPaymentRow>
     */
    public function parse(string $csvContent): array
    {
        $csvContent = trim($csvContent);
        if ($csvContent === '') {
            return [];
        }

        // Detect delimiter from the header line.
        $firstNewline = strpos($csvContent, "\n");
        $header = $firstNewline !== false ? substr($csvContent, 0, $firstNewline) : $csvContent;
        $delimiter = substr_count($header, ';') > substr_count($header, "\t") ? ';' : "\t";

        $lines = preg_split('/\r?\n/', $csvContent) ?: [];
        if (count($lines) < 2) {
            return [];
        }

        $columns = str_getcsv($lines[0], $delimiter, '"', '\\');
        /** @var array<string,int> $columnMap */
        $columnMap = array_flip(array_map(static fn($c) => trim((string) $c), $columns));

        foreach (['Arvopäivä', 'Maksaja', 'Viite', 'Summa EUR'] as $required) {
            if (!isset($columnMap[$required])) {
                throw new InvalidArgumentException("Nordea CSV missing required column: '{$required}'");
            }
        }

        $idxArvo  = $columnMap['Arvopäivä'];
        $idxMaks  = $columnMap['Maksaja'];
        $idxViite = $columnMap['Viite'];
        $idxAmt   = $columnMap['Summa EUR'];

        $rows = [];
        $lineCount = count($lines);
        for ($i = 1; $i < $lineCount; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $fields = str_getcsv($line, $delimiter, '"', '\\');

            $amountStr = isset($fields[$idxAmt]) ? $fields[$idxAmt] : '';
            $amountCents = $this->parseAmountToCents($amountStr);
            if ($amountCents <= 0) {
                continue;
            }

            $valueDateRaw = isset($fields[$idxArvo]) ? $fields[$idxArvo] : '';
            $payer        = isset($fields[$idxMaks]) ? $fields[$idxMaks] : '';
            $referenceRaw = isset($fields[$idxViite]) ? trim($fields[$idxViite]) : '';

            $dt = $this->parseFinnishDate($valueDateRaw);
            if ($dt === null) {
                continue;
            }

            $rows[] = new ParsedPaymentRow(
                rowNumber:   $i + 1,
                reference:   $referenceRaw !== '' ? $referenceRaw : null,
                amountCents: $amountCents,
                valueDate:   $dt,
                payerName:   $payer,
                rawLine:     $line,
            );
        }
        return $rows;
    }

    /**
     * Parses Finnish locale amounts: comma decimal, dot or space thousand separator.
     * Returns 0 for empty or unparseable input; negative for outgoing.
     */
    private function parseAmountToCents(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        // Strip thousand separators (space + dot) — only the comma is decimal.
        $normalized = str_replace([' ', '.'], ['', ''], $raw);
        $normalized = str_replace(',', '.', $normalized);
        if (!is_numeric($normalized)) {
            return 0;
        }
        return (int) round((float) $normalized * 100);
    }

    private function parseFinnishDate(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Nordea uses dd.mm.yyyy
        $dt = DateTimeImmutable::createFromFormat('d.m.Y', $raw);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->setTime(0, 0, 0);
        }
        // Fallback: ISO 2026-08-14 (some users export in ISO format)
        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
