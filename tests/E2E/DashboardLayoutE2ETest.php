<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

/**
 * E2E for `/api/v1/backstage/dashboard/{layout,catalog}`.
 *
 * Drives the controller through the full middleware pipeline + container so
 * regressions in route wiring, AuthMiddleware integration, JSON shape, or
 * use-case threading surface here. Value-level layout filtering rules are
 * covered by unit tests on the use cases; this suite pins HTTP semantics:
 * status codes, envelope shape, persistence-across-requests behaviour.
 */
final class DashboardLayoutE2ETest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h         = new KernelHarness(FrozenClock::at('2026-05-10T12:00:00Z'));
        $admin           = $this->h->seedUser('admin-dashboard@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_get_layout_returns_role_default_for_new_user(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/dashboard/layout', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertTrue($body['data']['is_default']);
        self::assertSame('admin', $body['data']['role']);
        self::assertIsArray($body['data']['layout']);
        self::assertGreaterThan(0, count($body['data']['layout']));

        // Shape: each entry is {widget_id, span}.
        foreach ($body['data']['layout'] as $entry) {
            self::assertArrayHasKey('widget_id', $entry);
            self::assertArrayHasKey('span', $entry);
            self::assertIsString($entry['widget_id']);
            self::assertIsInt($entry['span']);
        }
    }

    public function test_put_layout_persists_user_choice(): void
    {
        $put = $this->h->authedRequest(
            'PUT',
            '/api/v1/backstage/dashboard/layout',
            $this->adminToken,
            ['layout' => [['widget_id' => 'core.members_kpi', 'span' => 1]]],
        );

        self::assertSame(204, $put->status());

        $get  = $this->h->authedRequest('GET', '/api/v1/backstage/dashboard/layout', $this->adminToken);
        $body = json_decode($get->body(), true);
        self::assertSame(200, $get->status());
        self::assertFalse($body['data']['is_default']);
        self::assertCount(1, $body['data']['layout']);
        self::assertSame('core.members_kpi', $body['data']['layout'][0]['widget_id']);
    }

    public function test_put_rejects_unknown_widget(): void
    {
        $resp = $this->h->authedRequest(
            'PUT',
            '/api/v1/backstage/dashboard/layout',
            $this->adminToken,
            ['layout' => [['widget_id' => 'core.nope', 'span' => 1]]],
        );

        self::assertSame(400, $resp->status());
        $body = json_decode($resp->body(), true);
        self::assertArrayHasKey('error', $body);
        self::assertSame('invalid_layout', $body['error']['code']);
    }

    public function test_put_rejects_gsa_widget_for_admin(): void
    {
        $resp = $this->h->authedRequest(
            'PUT',
            '/api/v1/backstage/dashboard/layout',
            $this->adminToken,
            ['layout' => [['widget_id' => 'platform.tenants_kpi', 'span' => 1]]],
        );

        self::assertSame(400, $resp->status());
    }

    public function test_put_rejects_missing_layout_key(): void
    {
        $resp = $this->h->authedRequest(
            'PUT',
            '/api/v1/backstage/dashboard/layout',
            $this->adminToken,
            ['not_layout' => []],
        );

        self::assertSame(400, $resp->status());
    }

    public function test_delete_resets_to_default(): void
    {
        $this->h->authedRequest(
            'PUT',
            '/api/v1/backstage/dashboard/layout',
            $this->adminToken,
            ['layout' => [['widget_id' => 'core.members_kpi', 'span' => 1]]],
        );

        $del = $this->h->authedRequest('DELETE', '/api/v1/backstage/dashboard/layout', $this->adminToken);
        self::assertSame(204, $del->status());

        $get  = $this->h->authedRequest('GET', '/api/v1/backstage/dashboard/layout', $this->adminToken);
        $body = json_decode($get->body(), true);
        self::assertTrue($body['data']['is_default']);
    }

    public function test_catalog_filters_by_role(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/dashboard/catalog', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);

        $widgetIds = array_column($body['data'], 'widget_id');
        self::assertNotContains('platform.tenants_kpi', $widgetIds, 'admin must not see GSA widgets');
        self::assertContains('core.members_kpi', $widgetIds);

        // Items carry the expected envelope.
        foreach ($body['data'] as $item) {
            self::assertArrayHasKey('widget_id',     $item);
            self::assertArrayHasKey('label_key',     $item);
            self::assertArrayHasKey('category',      $item);
            self::assertArrayHasKey('default_span',  $item);
            self::assertArrayHasKey('in_layout',     $item);
        }
    }

    public function test_member_cannot_access_dashboard(): void
    {
        $member      = $this->h->seedUser('member-dashboard@x.com', 'pass1234');
        $memberToken = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/dashboard/layout', $memberToken);
        self::assertSame(403, $resp->status());
    }

    public function test_unauth_returns_401(): void
    {
        $resp = $this->h->request('GET', '/api/v1/backstage/dashboard/layout');
        self::assertSame(401, $resp->status());
    }
}
