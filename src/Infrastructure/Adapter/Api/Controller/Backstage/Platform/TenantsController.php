<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform;

use Daems\Application\Backstage\Platform\CreateTenant\CreateTenant;
use Daems\Application\Backstage\Platform\CreateTenant\CreateTenantInput;
use Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetail;
use Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetailInput;
use Daems\Application\Backstage\Platform\ListTenants\ListTenants;
use Daems\Application\Backstage\Platform\ListTenants\ListTenantsInput;
use Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenant;
use Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenantInput;
use Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenant;
use Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenantInput;
use Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasics;
use Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasicsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DomainException;
use InvalidArgumentException;

/**
 * GSA-only HTTP wrapper for the platform-scope tenant lifecycle use cases.
 *
 * All methods require the caller to be a platform admin; non-GSA actors
 * receive 403. Domain exceptions (slug duplicate, slug-immutable, last-primary,
 * etc.) all extend \DomainException and are mapped to 422 with the exception
 * message as `error`. InvalidArgumentException from Input DTOs (malformed
 * slug etc.) is also 422. Anything else bubbles as 500.
 */
final class TenantsController
{
    public function __construct(
        private readonly ListTenants $listTenants,
        private readonly GetTenantDetail $getTenantDetail,
        private readonly CreateTenant $createTenant,
        private readonly UpdateTenantBasics $updateTenantBasics,
        private readonly SuspendTenant $suspendTenant,
        private readonly ReactivateTenant $reactivateTenant,
    ) {}

    public function list(Request $request): Response
    {
        $actor = $this->requirePlatformAdmin($request);

        $statusFilter = null;
        $rawStatus = $request->query('status');
        if (is_string($rawStatus) && ($rawStatus === 'active' || $rawStatus === 'suspended')) {
            $statusFilter = $rawStatus;
        }

        try {
            $out = $this->listTenants->execute(new ListTenantsInput($actor->id, $statusFilter));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        }

        return Response::json(['data' => ['tenants' => $out->tenants]]);
    }

    /** @param array<string, string> $params */
    public function get(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('Tenant ID is required.');
        }

        try {
            $out = $this->getTenantDetail->execute(
                new GetTenantDetailInput($actor->id, TenantId::fromString($id)),
            );
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(['data' => $out->detail]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $b = $request->all();

        $slug              = isset($b['slug']) && is_string($b['slug']) ? $b['slug'] : '';
        $defaultLocale     = isset($b['defaultLocale']) && is_string($b['defaultLocale']) ? $b['defaultLocale'] : 'en_GB';
        $memberPrefix      = isset($b['memberNumberPrefix']) && is_string($b['memberNumberPrefix']) ? $b['memberNumberPrefix'] : '';
        $displayNames      = self::stringMap($b['displayNamesI18n'] ?? null);
        $publicDescriptions = self::stringMap($b['publicDescriptionsI18n'] ?? null);
        $supportedLocales  = self::stringList($b['supportedLocales'] ?? null);

        try {
            $out = $this->createTenant->execute(new CreateTenantInput(
                actingUserId:           $actor->id,
                slug:                   $slug,
                displayNamesI18n:       $displayNames,
                publicDescriptionsI18n: $publicDescriptions,
                supportedLocales:       $supportedLocales,
                defaultLocale:          $defaultLocale,
                memberNumberPrefix:     $memberPrefix,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(['data' => [
            'id'        => $out->tenantId->value(),
            'createdAt' => $out->createdAt->format(\DateTimeInterface::ATOM),
        ]], 201);
    }

    /** @param array<string, string> $params */
    public function patch(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('Tenant ID is required.');
        }

        $b = $request->all();
        $slug              = isset($b['slug']) && is_string($b['slug']) ? $b['slug'] : '';
        $defaultLocale     = isset($b['defaultLocale']) && is_string($b['defaultLocale']) ? $b['defaultLocale'] : 'en_GB';
        $memberPrefix      = isset($b['memberNumberPrefix']) && is_string($b['memberNumberPrefix']) ? $b['memberNumberPrefix'] : '';
        $displayNames      = self::stringMap($b['displayNamesI18n'] ?? null);
        $publicDescriptions = self::stringMap($b['publicDescriptionsI18n'] ?? null);
        $supportedLocales  = self::stringList($b['supportedLocales'] ?? null);

        try {
            $this->updateTenantBasics->execute(new UpdateTenantBasicsInput(
                actingUserId:           $actor->id,
                tenantId:               TenantId::fromString($id),
                slug:                   $slug,
                displayNamesI18n:       $displayNames,
                publicDescriptionsI18n: $publicDescriptions,
                supportedLocales:       $supportedLocales,
                defaultLocale:          $defaultLocale,
                memberNumberPrefix:     $memberPrefix,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(null, 204);
    }

    /** @param array<string, string> $params */
    public function suspend(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('Tenant ID is required.');
        }

        $reason = $request->string('reason') ?? '';

        try {
            $this->suspendTenant->execute(new SuspendTenantInput(
                actingUserId: $actor->id,
                tenantId:     TenantId::fromString($id),
                reason:       $reason,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(null, 204);
    }

    /** @param array<string, string> $params */
    public function reactivate(Request $request, array $params): Response
    {
        $actor = $this->requirePlatformAdmin($request);
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('Tenant ID is required.');
        }

        try {
            $this->reactivateTenant->execute(new ReactivateTenantInput(
                actingUserId: $actor->id,
                tenantId:     TenantId::fromString($id),
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(null, 204);
    }

    private function requirePlatformAdmin(Request $request): ActingUser
    {
        $actor = $request->requireActingUser();
        if (!$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }
        return $actor;
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }
        return $out;
    }
}
