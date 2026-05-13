<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\ImportPaymentsCsv\NordeaPaymentCsvParser;
use Daems\Application\Membership\Billing\ImportPaymentsCsv\PaymentMatchResult;
use Daems\Application\Membership\Billing\ImportPaymentsCsv\PreviewImportPayments;
use Daems\Application\Membership\Billing\ImportPaymentsCsv\PreviewImportPaymentsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryMemberFeeInvoiceRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PreviewImportPaymentsTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_high_amount_mismatch_no_match_classification(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $invoice = $this->invoice(amountCents: 5000);
        $invoices->save($invoice);
        $reference = substr($invoice->id()->value(), 0, 12);

        $csv = "Arvopäivä;Maksaja;Viite;Summa EUR\n"
            . "14.08.2026;Matti;{$reference};50,00\n"          // high match (exact)
            . "14.08.2026;Liisa;{$reference};45,00\n"          // amount_mismatch (-5€)
            . "14.08.2026;Pekka;NON_EXISTENT_REF_X;50,00\n";   // no_match (ref unknown)

        $useCase = new PreviewImportPayments(new NordeaPaymentCsvParser(), $invoices);
        $out = $useCase->handle(new PreviewImportPaymentsInput(
            actor:      $this->actor(),
            tenantId:   TenantId::fromString(self::TENANT_ID),
            csvContent: $csv,
        ));

        $this->assertCount(3, $out->results);
        $this->assertSame(PaymentMatchResult::CONFIDENCE_HIGH,            $out->results[0]->confidence);
        $this->assertSame(PaymentMatchResult::CONFIDENCE_AMOUNT_MISMATCH, $out->results[1]->confidence);
        $this->assertSame(PaymentMatchResult::CONFIDENCE_NO_MATCH,        $out->results[2]->confidence);
        $this->assertSame(1, $out->highConfidenceCount());
    }

    public function test_empty_csv_returns_no_results(): void
    {
        $useCase = new PreviewImportPayments(new NordeaPaymentCsvParser(), new InMemoryMemberFeeInvoiceRepository());
        $out = $useCase->handle(new PreviewImportPaymentsInput(
            actor:      $this->actor(),
            tenantId:   TenantId::fromString(self::TENANT_ID),
            csvContent: '',
        ));
        $this->assertSame([], $out->results);
    }

    public function test_non_admin_rejected(): void
    {
        $useCase = new PreviewImportPayments(new NordeaPaymentCsvParser(), new InMemoryMemberFeeInvoiceRepository());
        $this->expectException(ForbiddenException::class);
        $useCase->handle(new PreviewImportPaymentsInput(
            actor:      $this->actor(UserTenantRole::Member),
            tenantId:   TenantId::fromString(self::TENANT_ID),
            csvContent: "Arvopäivä;Maksaja;Viite;Summa EUR\n14.08.2026;X;1234;50,00\n",
        ));
    }

    public function test_csv_row_with_empty_reference_is_no_match(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $invoices->save($this->invoice(amountCents: 5000));

        $useCase = new PreviewImportPayments(new NordeaPaymentCsvParser(), $invoices);
        $out = $useCase->handle(new PreviewImportPaymentsInput(
            actor:      $this->actor(),
            tenantId:   TenantId::fromString(self::TENANT_ID),
            csvContent: "Arvopäivä;Maksaja;Viite;Summa EUR\n14.08.2026;Matti;;50,00\n",
        ));
        $this->assertCount(1, $out->results);
        $this->assertSame(PaymentMatchResult::CONFIDENCE_NO_MATCH, $out->results[0]->confidence);
        $this->assertStringContainsString('missing reference', (string) $out->results[0]->reasonForLowConfidence);
    }

    private function invoice(int $amountCents): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            year:                2026,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable('2026-07-15'),
            amountCents:         $amountCents,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             new DateTimeImmutable('2026-09-13'),
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable('2026-07-15'),
        );
    }

    private function actor(UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString(self::ADMIN_ID),
            email:              'admin@test',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: $role,
        );
    }
}
