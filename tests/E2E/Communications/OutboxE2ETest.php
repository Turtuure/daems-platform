<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Communications;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Tests\Support\InMemoryMailOutboxRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * E2E coverage for the Wave C6 outbox HTTP API:
 *   GET  /api/v1/backstage/communications/outbox
 *   GET  /api/v1/backstage/communications/outbox/{id}
 *   POST /api/v1/backstage/communications/outbox/{id}/retry
 *
 * Uses KernelHarness — module bindings.test.php wires the InMemory outbox
 * fake, so we seed via the container-resolved repo (same singleton the
 * controller sees) and exercise the live router stack incl. middlewares.
 */
final class OutboxE2ETest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;
    private MailOutboxId $queuedId;
    private MailOutboxId $failedId;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-14T09:00:00Z'));

        $admin = $this->h->seedUser('admin-outbox@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);

        $tenantId = $this->h->daemsTenantId();

        // Seed two rows: one Queued, one Failed (retry-eligible).
        $queued = InMemoryMailOutboxRepository::makeQueued(
            tenantId:       $tenantId,
            queuedBy:       $admin->id(),
            recipientEmail: 'queued@example.com',
            kind:           MailKind::GroupMessage,
            status:         MailOutboxStatus::Queued,
        );
        $failed = InMemoryMailOutboxRepository::makeQueued(
            tenantId:       $tenantId,
            queuedBy:       $admin->id(),
            recipientEmail: 'failed@example.com',
            kind:           MailKind::PaymentReminder,
            status:         MailOutboxStatus::Failed,
            attemptCount:   3,
        );
        $this->queuedId = $queued->id;
        $this->failedId = $failed->id;

        $repo = $this->h->commsOutbox;
        $repo->save($queued);
        $repo->save($failed);
    }

    public function test_admin_lists_outbox_returns_paginated_rows(): void
    {
        $resp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/communications/outbox',
            $this->adminToken,
        );

        self::assertSame(200, $resp->status(), 'list: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('total', $body);
        self::assertArrayHasKey('page', $body);
        self::assertArrayHasKey('per_page', $body);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['data']);

        $first = $body['data'][0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('status', $first);
        self::assertArrayHasKey('kind', $first);
        self::assertArrayHasKey('recipient_email', $first);
        self::assertArrayHasKey('queued_at', $first);
    }

    public function test_admin_retries_failed_row_flips_status_to_queued(): void
    {
        // Sanity: row is Failed before retry.
        $row = $this->h->commsOutbox->findById($this->failedId);
        self::assertNotNull($row);
        self::assertSame(MailOutboxStatus::Failed, $row->status);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/communications/outbox/' . $this->failedId->value() . '/retry',
            $this->adminToken,
        );

        self::assertSame(200, $resp->status(), 'retry: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertSame(['data' => ['success' => true]], $body);

        // Verify side effect — row is now Queued with attempt_count reset.
        $after = $this->h->commsOutbox->findById($this->failedId);
        self::assertNotNull($after);
        self::assertSame(MailOutboxStatus::Queued, $after->status);
        self::assertSame(0, $after->attemptCount);
        self::assertNull($after->lastError);
    }
}
