<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats;
use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStatsInput;
use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin;
use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdminInput;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettingsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\Tenant;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class BackstageController
{
    public function __construct(
        private readonly ListProposalsForAdmin $listProposals,
        private readonly ListNotificationsStats $listNotificationsStats,
        private readonly UpdateTenantSettings $updateTenantSettings,
    ) {}

    public function updateTenantSettings(Request $request): Response
    {
        $actor = $request->requireActingUser();
        $rawPrefix = $request->input('member_number_prefix');
        $prefix = is_string($rawPrefix) ? $rawPrefix : null;

        $rawFmt = $request->input('default_time_format');
        $defaultTimeFormat = ($rawFmt === '12' || $rawFmt === '24') ? (string) $rawFmt : null;

        try {
            $out = $this->updateTenantSettings->execute(
                new UpdateTenantSettingsInput($actor, $prefix, $defaultTimeFormat),
            );
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }

        return Response::json(['data' => [
            'member_number_prefix' => $out->memberNumberPrefix,
            'default_time_format'  => $out->defaultTimeFormat,
        ]]);
    }

    public function listProposalsAdmin(Request $request): Response
    {
        $acting = $request->requireActingUser();
        try {
            $out = $this->listProposals->execute(new ListProposalsForAdminInput($acting));
            return Response::json($out->toArray());
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        }
    }

    public function statsNotifications(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $tenant = $this->requireTenant($request);

        try {
            $out = $this->listNotificationsStats->execute(new ListNotificationsStatsInput(
                acting:   $acting,
                tenantId: $tenant->id,
            ));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json(['data' => $out->stats]);
    }

    private function requireTenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new NotFoundException('unknown_tenant');
        }
        return $tenant;
    }
}
