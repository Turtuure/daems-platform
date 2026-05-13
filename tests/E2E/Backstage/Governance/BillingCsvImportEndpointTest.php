<?php
declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage\Governance;

use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BillingCsvImportEndpointTest extends TestCase
{
    private KernelHarness $h;
    private User $admin;
    private string $adminToken;
    private TenantId $tenantId;
    private MemberFeeInvoice $invoice;
    private string $tmpCsv = '';

    protected function setUp(): void
    {
        $this->h = new KernelHarness(new FrozenClock(new DateTimeImmutable('2026-08-15T10:00:00')));
        $this->tenantId = $this->h->daemsTenantId();

        $this->admin = $this->h->seedUser('admin@billing-csv.test', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($this->admin);

        $member = $this->h->seedUser('member@billing-csv.test', 'pass1234', 'member');
        $repo = $this->h->container->make(MemberFeeInvoiceRepositoryInterface::class);
        $this->invoice = $this->makeInvoice($member->id());
        $repo->save($this->invoice);
    }

    protected function tearDown(): void
    {
        if ($this->tmpCsv !== '' && is_file($this->tmpCsv)) {
            @unlink($this->tmpCsv);
        }
        $_FILES = [];
    }

    public function test_preview_and_confirm_round_trip(): void
    {
        $reference = substr($this->invoice->id()->value(), 0, 12);
        $csv = "Arvopäivä;Maksaja;Viite;Summa EUR\n"
            . "14.08.2026;Member Mc Member;{$reference};50,00\n";

        $this->tmpCsv = tempnam(sys_get_temp_dir(), 'csv-import-test') ?: '/tmp/csv-import-test.csv';
        file_put_contents($this->tmpCsv, $csv);

        // PREVIEW — controller reads $_FILES directly, so prime it before the request.
        $_FILES['csv'] = [
            'name'     => 'nordea.csv',
            'type'     => 'text/csv',
            'tmp_name' => $this->tmpCsv,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($this->tmpCsv) ?: 0,
        ];
        $previewResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/governance/billing/payments/import-csv',
            $this->adminToken,
            [],
        );
        $this->assertSame(200, $previewResp->status(), 'preview: ' . $previewResp->body());
        $previewBody = json_decode($previewResp->body(), true);
        $this->assertIsArray($previewBody);
        $this->assertSame(1, $previewBody['high_confidence_count']);
        $this->assertSame($this->invoice->id()->value(), $previewBody['results'][0]['matched_invoice_id']);

        // CONFIRM
        $confirmResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/governance/billing/payments/import-csv/confirm',
            $this->adminToken,
            [
                'matches' => [[
                    'invoice_id'   => $this->invoice->id()->value(),
                    'amount_cents' => 5000,
                    'paid_at'      => '2026-08-14T00:00:00+00:00',
                    'reference'    => $reference,
                ]],
            ],
        );
        $this->assertSame(200, $confirmResp->status(), 'confirm: ' . $confirmResp->body());
        $confirmBody = json_decode($confirmResp->body(), true);
        $this->assertIsArray($confirmBody);
        $this->assertCount(1, $confirmBody['applied']);
        $this->assertSame([], $confirmBody['errors']);

        // Verify state
        /** @var MemberFeeInvoiceRepositoryInterface $repo */
        $repo = $this->h->container->make(MemberFeeInvoiceRepositoryInterface::class);
        $loaded = $repo->findById($this->invoice->id());
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $loaded?->status());
    }

    public function test_preview_rejects_when_csv_missing(): void
    {
        $_FILES = [];
        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/governance/billing/payments/import-csv',
            $this->adminToken,
            [],
        );
        $this->assertSame(400, $resp->status());
    }

    private function makeInvoice(UserId $userId): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $this->tenantId,
            userId:              $userId,
            year:                2026,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable('2026-07-15'),
            amountCents:         5000,
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
}
