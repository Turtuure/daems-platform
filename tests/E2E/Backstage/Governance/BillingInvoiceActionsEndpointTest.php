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

final class BillingInvoiceActionsEndpointTest extends TestCase
{
    private KernelHarness $h;
    private User $admin;
    private string $adminToken;
    private string $invoiceId;
    private TenantId $tenantId;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(new FrozenClock(new DateTimeImmutable('2026-08-15T10:00:00')));
        $this->tenantId = $this->h->daemsTenantId();

        $this->admin = $this->h->seedUser('admin@billing.test', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($this->admin);

        // Seed a BASIC member + a PENDING invoice via the InMemory repo directly.
        $member = $this->h->seedUser('member@billing.test', 'pass1234', 'member');
        $repo = $this->h->container->make(MemberFeeInvoiceRepositoryInterface::class);
        $invoice = new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $this->tenantId,
            userId:              $member->id(),
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
        $repo->save($invoice);
        $this->invoiceId = $invoice->id()->value();
    }

    public function test_mark_paid_then_audit_visible(): void
    {
        $r = $this->h->authedRequest(
            'POST',
            "/api/v1/backstage/governance/billing/invoices/{$this->invoiceId}/mark-paid",
            $this->adminToken,
            [
                'amount_cents' => 5000,
                'paid_at'      => '2026-08-15T10:00:00+00:00',
                'method'       => 'bank_transfer',
                'reference'    => 'Nordea 12345/2026',
            ],
        );
        $this->assertSame(200, $r->status(), 'mark-paid: ' . $r->body());
        $body = json_decode($r->body(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['marked_paid']);

        // Audit row exists
        $r = $this->h->authedRequest(
            'GET',
            "/api/v1/backstage/governance/billing/invoices/{$this->invoiceId}/audit",
            $this->adminToken,
        );
        $this->assertSame(200, $r->status());
        $body = json_decode($r->body(), true);
        $this->assertIsArray($body);
        $this->assertCount(1, $body['rows']);
        $this->assertSame('paid', $body['rows'][0]['action']);
    }

    public function test_waive_then_double_waive_409_or_400(): void
    {
        $r = $this->h->authedRequest(
            'POST',
            "/api/v1/backstage/governance/billing/invoices/{$this->invoiceId}/waive",
            $this->adminToken,
            ['reason' => 'Pitkäaikaissairaus'],
        );
        $this->assertSame(200, $r->status(), 'first waive: ' . $r->body());

        $r2 = $this->h->authedRequest(
            'POST',
            "/api/v1/backstage/governance/billing/invoices/{$this->invoiceId}/waive",
            $this->adminToken,
            ['reason' => 'Again'],
        );
        // Entity throws \DomainException when already in final state → 404 in controller.
        // (No 400 path because reason is non-empty.)
        $this->assertContains($r2->status(), [400, 404, 409], 'second waive: ' . $r2->body());
    }

    public function test_reduce_lowers_amount_and_lists_in_invoices(): void
    {
        $r = $this->h->authedRequest(
            'POST',
            "/api/v1/backstage/governance/billing/invoices/{$this->invoiceId}/reduce",
            $this->adminToken,
            [
                'amount_cents' => 2500,
                'reason'       => 'Opiskelija-alennus 50%',
            ],
        );
        $this->assertSame(200, $r->status(), 'reduce: ' . $r->body());

        $r = $this->h->authedRequest('GET', '/api/v1/backstage/governance/billing/invoices', $this->adminToken);
        $this->assertSame(200, $r->status());
        $body = json_decode($r->body(), true);
        $this->assertIsArray($body);
        $row = $body['rows'][0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(2500, $row['amount_cents']);
        $this->assertSame(5000, $row['original_amount_cents']);
        $this->assertSame('REDUCED', $row['status']);
    }

    public function test_non_admin_listing_rejected(): void
    {
        $member = $this->h->seedUser('outsider@billing.test', 'pass1234', 'member');
        $token = $this->h->tokenFor($member);

        $r = $this->h->authedRequest('GET', '/api/v1/backstage/governance/billing/invoices', $token);
        $this->assertSame(403, $r->status());
    }
}
