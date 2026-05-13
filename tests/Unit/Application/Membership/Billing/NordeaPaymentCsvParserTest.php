<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\ImportPaymentsCsv\NordeaPaymentCsvParser;
use PHPUnit\Framework\TestCase;

final class NordeaPaymentCsvParserTest extends TestCase
{
    public function test_parses_semicolon_separated_finnish_format(): void
    {
        $csv = "Kirjauspäivä;Arvopäivä;Maksaja;Saaja;Nimi;Tiliotetapahtumalaji;Viesti;Tilinumero;Viite;Summa EUR\n"
            . "15.08.2026;14.08.2026;Matti Meikäläinen;Daem Society ry;;TILISIIRTO;;FI00 1234 5678 9012;1234567;50,00\n"
            . "15.08.2026;14.08.2026;Liisa Lankinen;Daem Society ry;;TILISIIRTO;;FI00 1234 5678 9012;7654321;50,00\n"
            . "16.08.2026;16.08.2026;Daem Society ry;Pekka Päämies;;TILISIIRTO;;FI00 9999 8888 7777;;-150,00\n";

        $rows = (new NordeaPaymentCsvParser())->parse($csv);

        $this->assertCount(2, $rows);
        $this->assertSame('1234567', $rows[0]->reference);
        $this->assertSame(5000, $rows[0]->amountCents);
        $this->assertSame('Matti Meikäläinen', $rows[0]->payerName);
        $this->assertSame('2026-08-14', $rows[0]->valueDate->format('Y-m-d'));
    }

    public function test_skips_rows_with_missing_reference(): void
    {
        $csv = "Arvopäivä;Maksaja;Viite;Summa EUR\n"
            . "14.08.2026;Matti;;50,00\n"
            . "14.08.2026;Liisa;1234567;50,00\n";

        $rows = (new NordeaPaymentCsvParser())->parse($csv);

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->reference);
        $this->assertSame('1234567', $rows[1]->reference);
    }

    public function test_throws_on_missing_required_column(): void
    {
        $csv = "Arvopäivä;Maksaja;Summa EUR\n14.08.2026;Matti;50,00\n";
        $this->expectException(\InvalidArgumentException::class);
        (new NordeaPaymentCsvParser())->parse($csv);
    }

    public function test_handles_tab_separator(): void
    {
        $csv = "Arvopäivä\tMaksaja\tViite\tSumma EUR\n14.08.2026\tMatti\t1234567\t50,00\n";
        $rows = (new NordeaPaymentCsvParser())->parse($csv);
        $this->assertCount(1, $rows);
        $this->assertSame(5000, $rows[0]->amountCents);
    }

    public function test_handles_european_decimal_with_thousand_separator(): void
    {
        $csv = "Arvopäivä;Maksaja;Viite;Summa EUR\n14.08.2026;Matti;1234567;1.234,56\n";
        $rows = (new NordeaPaymentCsvParser())->parse($csv);
        $this->assertCount(1, $rows);
        $this->assertSame(123456, $rows[0]->amountCents);
    }

    public function test_skips_rows_with_unparseable_date(): void
    {
        $csv = "Arvopäivä;Maksaja;Viite;Summa EUR\nGARBAGE;Matti;1234567;50,00\n14.08.2026;Liisa;7654321;50,00\n";
        $rows = (new NordeaPaymentCsvParser())->parse($csv);
        $this->assertCount(1, $rows);
        $this->assertSame('7654321', $rows[0]->reference);
    }
}
