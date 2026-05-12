<?php

declare(strict_types=1);

namespace Daems\Tests\Support;

use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthTokenInput;
use Daems\Application\Auth\GetAuthMe\GetAuthMe;
use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\LogoutUser\LogoutUser;
use Daems\Application\Auth\RegisterUser\RegisterUser;
use Daems\Application\User\AnonymiseAccount\AnonymiseAccount;
use Daems\Application\User\ChangePassword\ChangePassword;
use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetUserActivity\GetUserActivity;
use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use Daems\Infrastructure\Tenant\TenantResolverInterface;
use DateTimeImmutable;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
use Daems\Infrastructure\Adapter\Api\Controller\UserController;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Kernel;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\LocaleMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\RateLimitLoginMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\Fake\ImmediateTransactionManager;
use Daems\Tests\Support\Fake\InMemoryModuleAuditRepository;
use Daems\Tests\Support\Fake\InMemoryTenantDomainRepository;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryTenantSlugResolver;
use Daems\Tests\Support\Fake\InMemoryUserInviteRepository;
use Daems\Tests\Support\Fake\InMemoryImageStorage;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;

final class KernelHarness
{
    public Container $container;
    public Kernel $kernel;

    public readonly TenantId $testTenantId;

    public InMemoryUserRepository $users;
    public InMemoryUserTenantRepository $userTenants;
    public InMemoryAuthTokenRepository $tokens;
    public InMemoryAuthLoginAttemptRepository $attempts;
    public InMemoryUserInviteRepository $invites;
    public InMemoryImageStorage $imageStorage;
    public InMemoryTenantRepository $tenants;
    public InMemoryTenantModulesRepository $tenantModules;
    public InMemoryModuleAuditRepository $moduleAudit;
    public InMemoryTenantDomainRepository $tenantDomains;
    public FrozenClock $clock;

    /** @var array<array{0:string, 1:array<string,mixed>}> */
    public array $logs = [];

    public function __construct(FrozenClock $clock, bool $debug = false)
    {
        $this->clock = $clock;
        $this->users = new InMemoryUserRepository();
        $this->userTenants = new InMemoryUserTenantRepository();
        // Wire user-repo into the user-tenants fake so findAdminsForTenant()
        // can resolve user name/email — the SQL impl does this via INNER JOIN.
        $this->userTenants->setUsers($this->users);
        $this->tokens = new InMemoryAuthTokenRepository();
        $this->attempts = new InMemoryAuthLoginAttemptRepository();
        $this->invites = new InMemoryUserInviteRepository();
        $this->imageStorage = new InMemoryImageStorage();
        $this->tenants = new InMemoryTenantRepository();
        $this->tenantModules = new InMemoryTenantModulesRepository();
        $this->moduleAudit = new InMemoryModuleAuditRepository();
        $this->tenantDomains = new InMemoryTenantDomainRepository();

        $logs = &$this->logs;
        $logger = new class ($logs) implements LoggerInterface {
            /** @param array<array{0:string,1:array}> $logs */
            public function __construct(private array &$logs) {}
            public function error(string $message, array $context = []): void
            {
                $this->logs[] = [$message, $context];
            }
        };

        $container = new Container();
        $this->container = $container;

        // Module registry — discover modules under modules/* with TEST bindings.
        // Loads the platform catalog (config/modules.php) so manifests pick up
        // platform metadata (is_core, default_available, depends_on, route prefixes).
        // Without it, every module would be treated as non-core, non-default-available
        // and CreateTenant would auto-seed zero rows — diverging from production.
        $composerLoader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
        $moduleRegistry = new \Daems\Infrastructure\Module\ModuleRegistry();
        $moduleRegistry->discover(
            dirname(__DIR__, 3) . '/modules',
            dirname(__DIR__, 2) . '/config/modules.php',
            dirname(__DIR__, 2) . '/lang/en_GB.php',
        );
        $moduleRegistry->registerAutoloader($composerLoader);
        $container->bind(\Daems\Infrastructure\Module\ModuleRegistry::class, fn() => $moduleRegistry);

        $container->singleton(LoggerInterface::class, static fn(): LoggerInterface => $logger);
        $container->singleton(Clock::class, fn(): Clock => $this->clock);
        $container->singleton(UserRepositoryInterface::class, fn() => $this->users);
        $container->singleton(UserTenantRepositoryInterface::class, fn() => $this->userTenants);
        $container->singleton(TenantRepositoryInterface::class, fn() => $this->tenants);
        $container->singleton(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class, fn() => $this->tenantModules);
        $container->singleton(\Daems\Domain\Tenant\ModuleAuditRepositoryInterface::class, fn() => $this->moduleAudit);
        $container->singleton(\Daems\Domain\Tenant\TenantDomainRepositoryInterface::class, fn() => $this->tenantDomains);
        $container->singleton(\Daems\Domain\Tenant\TenantModuleResolver::class, fn(Container $c) => new \Daems\Domain\Tenant\TenantModuleResolver(
            $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
            $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
        ));
        $container->singleton(\Daems\Domain\Tenant\ModuleRouteGuard::class, fn(Container $c) => new \Daems\Domain\Tenant\ModuleRouteGuard(
            $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
            $c->make(\Daems\Domain\Tenant\TenantModuleResolver::class),
        ));
        $container->singleton(\Daems\Frontend\BackstageSidebar::class, fn(Container $c) => new \Daems\Frontend\BackstageSidebar(
            $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
            $c->make(\Daems\Domain\Tenant\TenantModuleResolver::class),
        ));
        $container->singleton(AuthTokenRepositoryInterface::class, fn() => $this->tokens);
        $container->singleton(AuthLoginAttemptRepositoryInterface::class, fn() => $this->attempts);
        $container->singleton(\Daems\Domain\Invite\UserInviteRepositoryInterface::class, fn() => $this->invites);
        $container->singleton(\Daems\Domain\Shared\TransactionManagerInterface::class, fn() => new ImmediateTransactionManager());
        $container->singleton(\Daems\Domain\Invite\TokenGeneratorInterface::class, static function (): \Daems\Domain\Invite\TokenGeneratorInterface {
            return new class implements \Daems\Domain\Invite\TokenGeneratorInterface {
                private int $counter = 0;
                public function generate(): string
                {
                    return 'test-token-' . ++$this->counter;
                }
            };
        });
        $container->singleton(\Daems\Domain\Tenant\TenantSlugResolverInterface::class, static fn(): \Daems\Domain\Tenant\TenantSlugResolverInterface => new InMemoryTenantSlugResolver());
        $container->singleton(\Daems\Domain\Config\BaseUrlResolverInterface::class, static fn(): \Daems\Domain\Config\BaseUrlResolverInterface => new class implements \Daems\Domain\Config\BaseUrlResolverInterface {
            public function resolveFrontendBaseUrl(string $tenantId): string
            {
                return 'https://test.local';
            }
        });
        $container->singleton(\Daems\Domain\Shared\IdGeneratorInterface::class, static fn(): \Daems\Domain\Shared\IdGeneratorInterface => new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function generate(): string
            {
                return \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value();
            }
        });

        // Use cases
        $container->bind(CreateAuthToken::class, static fn(Container $c) => new CreateAuthToken(
            $c->make(AuthTokenRepositoryInterface::class),
            $c->make(Clock::class),
        ));
        $container->bind(AuthenticateToken::class, static fn(Container $c) => new AuthenticateToken(
            $c->make(AuthTokenRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
        ));
        $container->bind(LogoutUser::class, static fn(Container $c) => new LogoutUser(
            $c->make(AuthTokenRepositoryInterface::class),
            $c->make(Clock::class),
        ));
        $container->bind(LoginUser::class, static fn(Container $c) => new LoginUser(
            $c->make(UserRepositoryInterface::class),
            $c->make(AuthLoginAttemptRepositoryInterface::class),
            $c->make(AdminApplicationDismissalRepositoryInterface::class),
            $c->make(Clock::class),
        ));
        $container->bind(RegisterUser::class, static fn(Container $c) => new RegisterUser(
            $c->make(UserRepositoryInterface::class),
        ));

        $container->bind(GetProfile::class, static fn(Container $c) => new GetProfile(
            $c->make(UserRepositoryInterface::class),
            $c->make(UserTenantRepositoryInterface::class),
        ));
        $container->bind(UpdateProfile::class, static fn(Container $c) => new UpdateProfile($c->make(UserRepositoryInterface::class)));
        $container->bind(ChangePassword::class, static fn(Container $c) => new ChangePassword($c->make(UserRepositoryInterface::class)));
        $container->bind(AnonymiseAccount::class, static fn(Container $c) => new AnonymiseAccount(
            $c->make(UserRepositoryInterface::class),
            $c->make(UserTenantRepositoryInterface::class),
            $c->make(AuthTokenRepositoryInterface::class),
            $c->make(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class),
            $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
            $c->make(Clock::class),
            $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
        ));
        $container->bind(GetUserActivity::class, static fn(Container $c) => new GetUserActivity(
            $c->make(ForumRepositoryInterface::class),
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ));

        $container->bind(\Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats::class, static fn(Container $c) => new \Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats(
            $c->make(MemberApplicationRepositoryInterface::class),
            $c->make(SupporterApplicationRepositoryInterface::class),
            $c->make(ProjectProposalRepositoryInterface::class),
            $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
            $c->make(AdminApplicationDismissalRepositoryInterface::class),
        ));

        $container->bind(\Daems\Application\Invite\IssueInvite\IssueInvite::class, static fn(Container $c) => new \Daems\Application\Invite\IssueInvite\IssueInvite(
            $c->make(\Daems\Domain\Invite\UserInviteRepositoryInterface::class),
            $c->make(\Daems\Domain\Invite\TokenGeneratorInterface::class),
            $c->make(\Daems\Domain\Config\BaseUrlResolverInterface::class),
            $c->make(Clock::class),
            $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
        ));

        $container->bind(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class, static fn(Container $c) => new \Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin(
            $c->make(ProjectProposalRepositoryInterface::class),
        ));

        // Image storage — used by Events module via ImageStorageInterface binding.
        $container->singleton(\Daems\Domain\Storage\ImageStorageInterface::class, fn() => $this->imageStorage);

        // Controllers
        $container->bind(GetAuthMe::class, static fn(Container $c) => new GetAuthMe(
            $c->make(UserRepositoryInterface::class),
            $c->make(TenantRepositoryInterface::class),
            $c->make(AuthTokenRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Auth\RedeemInvite\RedeemInvite::class, static fn(Container $c) => new \Daems\Application\Auth\RedeemInvite\RedeemInvite(
            $c->make(\Daems\Domain\Invite\UserInviteRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(Clock::class),
        ));
        $container->bind(AuthController::class, static fn(Container $c) => new AuthController(
            $c->make(RegisterUser::class),
            $c->make(LoginUser::class),
            $c->make(CreateAuthToken::class),
            $c->make(LogoutUser::class),
            $c->make(GetAuthMe::class),
            $c->make(\Daems\Application\Auth\RedeemInvite\RedeemInvite::class),
        ));
        $container->bind(UserController::class, static fn(Container $c) => new UserController(
            $c->make(GetProfile::class),
            $c->make(UpdateProfile::class),
            $c->make(ChangePassword::class),
            $c->make(GetUserActivity::class),
            $c->make(AnonymiseAccount::class),
            $c->make(\Daems\Application\Profile\UpdateMyPublicProfilePrivacy\UpdateMyPublicProfilePrivacy::class),
            $c->make(\Daems\Application\Profile\UpdateMyTimeFormat\UpdateMyTimeFormat::class),
        ));
        $container->bind(\Daems\Application\Profile\UpdateMyPublicProfilePrivacy\UpdateMyPublicProfilePrivacy::class,
            static fn(Container $c) => new \Daems\Application\Profile\UpdateMyPublicProfilePrivacy\UpdateMyPublicProfilePrivacy(
                $c->make(\Daems\Domain\User\UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Profile\UpdateMyTimeFormat\UpdateMyTimeFormat::class,
            static fn(Container $c) => new \Daems\Application\Profile\UpdateMyTimeFormat\UpdateMyTimeFormat(
                $c->make(\Daems\Domain\User\UserRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
            ));
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class, static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\BackstageController(
            $c->make(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class),
            $c->make(\Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats::class),
            $c->make(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class),
        ));
        $container->bind(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class,
            static fn(Container $c) => new \Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings(
                $c->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
            ));
        // E2E uses SQL repo directly for public member lookups (no fake needed for read-only path).
        $container->bind(\Daems\Domain\Member\PublicMemberRepositoryInterface::class,
            static fn(Container $c) => new \DaemsModule\Members\Infrastructure\SqlPublicMemberRepository(
                $c->make(\Daems\Infrastructure\Framework\Database\Connection::class),
            ));
        // Search
        $container->bind(\Daems\Domain\Search\SearchRepositoryInterface::class,
            static fn(Container $c) => new \Daems\Tests\Support\Fake\InMemorySearchRepository());
        $container->bind(\Daems\Application\Search\Search\Search::class,
            static fn(Container $c) => new \Daems\Application\Search\Search\Search(
                $c->make(\Daems\Domain\Search\SearchRepositoryInterface::class),
            ));
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\SearchController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\SearchController(
                $c->make(\Daems\Application\Search\Search\Search::class),
            ));

        // Backstage — Platform-scope (GSA) tenant management use cases (Wave F)
        $container->bind(\Daems\Application\Backstage\Platform\CreateTenant\CreateTenant::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\CreateTenant\CreateTenant(
                $c->make(TenantRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
                $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
                $c->make(Clock::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasics::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasics(
                $c->make(TenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenant::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenant(
                $c->make(TenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
                $c->make(Clock::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenant::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenant(
                $c->make(TenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomain::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomain(
                $c->make(TenantRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantDomainRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
                $c->make(Clock::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomain::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomain(
                $c->make(\Daems\Domain\Tenant\TenantDomainRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomain::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomain(
                $c->make(TenantRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantDomainRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenant::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenant(
                $c->make(UserTenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenant::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenant(
                $c->make(UserTenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailability::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailability(
                $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\ModuleAuditRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
                $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
                $c->make(Clock::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailability::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailability(
                $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\ModuleAuditRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
                $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
                $c->make(Clock::class),
                $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\ListTenants\ListTenants::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\ListTenants\ListTenants(
                $c->make(TenantRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantDomainRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                $c->make(UserTenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdmins::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdmins(
                $c->make(UserTenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetail::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetail(
                $c->make(TenantRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantDomainRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                $c->make(UserTenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModules::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModules(
                $c->make(TenantRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantModuleResolver::class),
                $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
                $c->make(UserRepositoryInterface::class),
            ));

        // Backstage — Tenant-scope module use cases (Wave F)
        $container->bind(\Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenant::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenant(
                $c->make(TenantRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\ModuleAuditRepositoryInterface::class),
                $c->make(UserTenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
                $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
                $c->make(Clock::class),
            ));
        $container->bind(\Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenant::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenant(
                $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                $c->make(\Daems\Domain\Tenant\ModuleAuditRepositoryInterface::class),
                $c->make(UserTenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
                $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
                $c->make(Clock::class),
            ));
        $container->bind(\Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenant::class,
            static fn(Container $c) => new \Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenant(
                $c->make(\Daems\Domain\Tenant\TenantModuleResolver::class),
                $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                $c->make(UserTenantRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
                $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
            ));

        // Backstage — Platform-scope + Tenant-scope controllers (Wave F)
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController(
                $c->make(\Daems\Application\Backstage\Platform\ListTenants\ListTenants::class),
                $c->make(\Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetail::class),
                $c->make(\Daems\Application\Backstage\Platform\CreateTenant\CreateTenant::class),
                $c->make(\Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasics::class),
                $c->make(\Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenant::class),
                $c->make(\Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenant::class),
            ));
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantDomainsController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantDomainsController(
                $c->make(\Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomain::class),
                $c->make(\Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomain::class),
                $c->make(\Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomain::class),
                $c->make(\Daems\Domain\Tenant\TenantDomainRepositoryInterface::class),
            ));
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantAdminsController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantAdminsController(
                $c->make(\Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenant::class),
                $c->make(\Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenant::class),
                $c->make(\Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdmins::class),
            ));
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\PlatformTenantModulesController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\PlatformTenantModulesController(
                $c->make(\Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModules::class),
                $c->make(\Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailability::class),
                $c->make(\Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailability::class),
            ));
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Tenant\TenantSelfModulesController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Tenant\TenantSelfModulesController(
                $c->make(\Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenant::class),
                $c->make(\Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenant::class),
                $c->make(\Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenant::class),
                $c->make(UserTenantRepositoryInterface::class),
            ));

        $container->bind(AuthMiddleware::class, static fn(Container $c) => new AuthMiddleware(
            $c->make(AuthenticateToken::class),
            $c->make(TenantRepositoryInterface::class),
            $c->make(UserTenantRepositoryInterface::class),
        ));
        $container->bind(RateLimitLoginMiddleware::class, static fn(Container $c) => new RateLimitLoginMiddleware(
            $c->make(AuthLoginAttemptRepositoryInterface::class),
            $c->make(Clock::class),
            5,
            15,
            900,
        ));

        // TenantContextMiddleware requires a resolver. In the test harness all
        // requests are tenant-agnostic, so we wire a stub resolver that always
        // returns a fixed test tenant — ensuring the middleware passes through
        // without a real Host header or database lookup.
        $stubTenant = new Tenant(
            TenantId::generate(),
            TenantSlug::fromString('test-tenant'),
            'Test Tenant',
            new DateTimeImmutable('2024-01-01'),
        );
        $this->testTenantId = $stubTenant->id;
        // Seed the test tenant into the InMemory repo so use cases that call
        // findById / findBySlug from inside an authenticated request flow find
        // it without each test having to remember to seedTenant() manually.
        $this->tenants->save($stubTenant);
        $container->bind(TenantContextMiddleware::class, static fn() => new TenantContextMiddleware(
            new class ($stubTenant) implements TenantResolverInterface {
                public function __construct(private readonly Tenant $tenant) {}
                public function resolve(string $host): ?Tenant
                {
                    return $this->tenant;
                }
            },
        ));
        $container->bind(LocaleMiddleware::class, static fn() => new LocaleMiddleware());

        // Membership Core v2 / 0.6a — tier system + sub-tier honor catalog.
        $container->singleton(
            \Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryTenantMembershipSubTierRepository(),
        );
        $container->bind(
            \Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers::class,
            static fn(Container $c) => new \Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers(
                $c->make(\Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Infrastructure\Adapter\Api\Controller\Backstage\MembershipSubTiersController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\MembershipSubTiersController(
                $c->make(\Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers::class),
            ),
        );

        // Seed the test tenant with default sub-tier rows (4 slugs × 2 appliesTo = 8 rows).
        $subTierRepo = $container->make(\Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class);
        $defaults = [['bronze','Bronze',1],['silver','Silver',2],['gold','Gold',3],['platinum','Platinum',4]];
        foreach ([\Daems\Domain\Membership\MembershipType::Supporting, \Daems\Domain\Membership\MembershipType::Basic] as $appliesTo) {
            foreach ($defaults as [$slug, $name, $rank]) {
                $subTierRepo->save(new \Daems\Domain\Membership\TenantMembershipSubTier(
                    \Daems\Domain\Membership\TenantMembershipSubTierId::generate(),
                    $this->testTenantId,
                    $slug, $name, $rank, $appliesTo,
                ));
            }
        }

        // MembershipCore v2 / 0.6b — Governance (board + decisions + expulsions)

        // 9 InMemory repositories
        $container->singleton(
            \Daems\Domain\Governance\BoardRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryBoardRepository(),
        );
        $container->singleton(
            \Daems\Domain\Governance\BoardMemberRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryBoardMemberRepository(),
        );
        $container->singleton(
            \Daems\Domain\Governance\BoardDecisionRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository(),
        );
        $container->singleton(
            \Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryBoardDecisionVoteRepository(),
        );
        $container->singleton(
            \Daems\Domain\Governance\BoardDelegationRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryBoardDelegationRepository(),
        );
        $container->singleton(
            \Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository(),
        );
        $container->singleton(
            \Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryMemberExpulsionRepository(),
        );
        $container->singleton(
            \Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryMemberSubTierAwardRepository(),
        );
        $container->singleton(
            \Daems\Domain\Audit\GsaOverrideRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryGsaOverrideRepository(),
        );

        // Seed TenantGovernanceSettings(14, 60) for the test tenant
        $govSettingsRepo = $container->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class);
        $govSettingsRepo->save(new \Daems\Domain\Governance\TenantGovernanceSettings(
            $this->testTenantId,
            14,
            60,
        ));

        // Pure services
        $container->singleton(
            \Daems\Application\Governance\BoardDecisionResolutionService::class,
            static fn() => new \Daems\Application\Governance\BoardDecisionResolutionService(),
        );
        $container->singleton(
            \Daems\Domain\Membership\IsEligibleForFullMembership::class,
            static fn() => new \Daems\Domain\Membership\IsEligibleForFullMembership(),
        );

        // 9 Executors (no-op closures for tests; E2E checks status/audit rows, not user-table side effects)
        $container->bind(
            \Daems\Application\Governance\Executor\ApproveBasicExecutor::class,
            static fn() => new \Daems\Application\Governance\Executor\ApproveBasicExecutor(
                static function (string $applicationId, \DateTimeImmutable $at, ?string $viaDelegationDecisionId): void {},
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Executor\InviteFullExecutor::class,
            static fn() => new \Daems\Application\Governance\Executor\InviteFullExecutor(
                static function (\Daems\Domain\User\UserId $userId, \DateTimeImmutable $at): void {},
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Executor\ExpelExecutor::class,
            static fn(Container $c) => new \Daems\Application\Governance\Executor\ExpelExecutor(
                static function (\Daems\Domain\User\UserId $userId, string $reason, \DateTimeImmutable $at): void {},
                $c->make(\Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Executor\AwardSubTierExecutor::class,
            fn(Container $c) => new \Daems\Application\Governance\Executor\AwardSubTierExecutor(
                static function (\Daems\Domain\User\UserId $userId, string $slug): void {},
                fn(\Daems\Domain\Governance\BoardId $boardId): \Daems\Domain\Tenant\TenantId => $this->testTenantId,
                $c->make(\Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Executor\RevokeSubTierExecutor::class,
            fn(Container $c) => new \Daems\Application\Governance\Executor\RevokeSubTierExecutor(
                static function (\Daems\Domain\User\UserId $userId): void {},
                fn(\Daems\Domain\Governance\BoardId $boardId): \Daems\Domain\Tenant\TenantId => $this->testTenantId,
                $c->make(\Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Executor\SubTierCrudExecutor::class,
            fn(Container $c) => new \Daems\Application\Governance\Executor\SubTierCrudExecutor(
                $c->make(\Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class),
                fn(\Daems\Domain\Governance\BoardId $boardId): \Daems\Domain\Tenant\TenantId => $this->testTenantId,
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Executor\RemoveBoardMemberExecutor::class,
            static fn(Container $c) => new \Daems\Application\Governance\Executor\RemoveBoardMemberExecutor(
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Executor\DelegateAuthorityExecutor::class,
            fn(Container $c) => new \Daems\Application\Governance\Executor\DelegateAuthorityExecutor(
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
                fn(\Daems\Domain\Governance\BoardId $boardId): \Daems\Domain\Tenant\TenantId => $this->testTenantId,
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Executor\RevokeDelegationExecutor::class,
            static fn(Container $c) => new \Daems\Application\Governance\Executor\RevokeDelegationExecutor(
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Executor\AnnualFeeScheduleExecutor::class,
            static fn(Container $c) => new \Daems\Application\Governance\Executor\AnnualFeeScheduleExecutor(
                $c->make(\Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule::class),
            ),
        );

        // Executor registry (singleton)
        $container->singleton(
            \Daems\Application\Governance\BoardDecisionExecutorRegistry::class,
            static function (Container $c): \Daems\Application\Governance\BoardDecisionExecutorRegistry {
                $reg = new \Daems\Application\Governance\BoardDecisionExecutorRegistry();
                $reg->register($c->make(\Daems\Application\Governance\Executor\ApproveBasicExecutor::class));
                $reg->register($c->make(\Daems\Application\Governance\Executor\InviteFullExecutor::class));
                $reg->register($c->make(\Daems\Application\Governance\Executor\ExpelExecutor::class));
                $reg->register($c->make(\Daems\Application\Governance\Executor\AwardSubTierExecutor::class));
                $reg->register($c->make(\Daems\Application\Governance\Executor\RevokeSubTierExecutor::class));
                $reg->register($c->make(\Daems\Application\Governance\Executor\SubTierCrudExecutor::class));
                $reg->register($c->make(\Daems\Application\Governance\Executor\RemoveBoardMemberExecutor::class));
                $reg->register($c->make(\Daems\Application\Governance\Executor\DelegateAuthorityExecutor::class));
                $reg->register($c->make(\Daems\Application\Governance\Executor\RevokeDelegationExecutor::class));
                $reg->register($c->make(\Daems\Application\Governance\Executor\AnnualFeeScheduleExecutor::class));
                return $reg;
            },
        );

        // Core decision engine
        $container->bind(
            \Daems\Application\Governance\ResolveBoardDecisionIfReady::class,
            static fn(Container $c) => new \Daems\Application\Governance\ResolveBoardDecisionIfReady(
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
                $c->make(\Daems\Application\Governance\BoardDecisionResolutionService::class),
                $c->make(\Daems\Application\Governance\BoardDecisionExecutorRegistry::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\CastBoardVote::class,
            static fn(Container $c) => new \Daems\Application\Governance\CastBoardVote(
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
                $c->make(\Daems\Application\Governance\ResolveBoardDecisionIfReady::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\WithdrawBoardDecision::class,
            static fn(Container $c) => new \Daems\Application\Governance\WithdrawBoardDecision(
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\ExpireOverdueBoardDecisionsCron::class,
            static fn(Container $c) => new \Daems\Application\Governance\ExpireOverdueBoardDecisionsCron(
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
            ),
        );

        // BootstrapBoard with in-memory user lookup
        $container->bind(
            \Daems\Application\Governance\BootstrapBoard::class,
            static fn(Container $c) => new \Daems\Application\Governance\BootstrapBoard(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
                static function (\Daems\Domain\User\UserId $id) use ($c): array {
                    $userRepo = $c->make(\Daems\Domain\User\UserRepositoryInterface::class);
                    $user = $userRepo->findById($id->value());
                    return [
                        'membership_type'   => $user !== null ? $user->membershipType() : 'BASIC',
                        'membership_status' => $user !== null ? $user->membershipStatus() : 'inactive',
                    ];
                },
            ),
        );

        // 8 Propose* use cases
        $container->bind(
            \Daems\Application\Governance\Propose\ProposeApproveBasic::class,
            static fn(Container $c) => new \Daems\Application\Governance\Propose\ProposeApproveBasic(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
                static function (string $applicationId): array {
                    return ['status' => 'pending'];
                },
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Propose\ProposeInviteFull::class,
            static fn(Container $c) => new \Daems\Application\Governance\Propose\ProposeInviteFull(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\IsEligibleForFullMembership::class),
                static function (\Daems\Domain\User\UserId $id): array {
                    return [
                        'membership_type'       => 'BASIC',
                        'membership_status'     => 'active',
                        'membership_started_at' => (new \DateTimeImmutable('-24 months'))->format('Y-m-d H:i:s'),
                    ];
                },
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Propose\ProposeAwardSubTier::class,
            static fn(Container $c) => new \Daems\Application\Governance\Propose\ProposeAwardSubTier(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface::class),
                static function (\Daems\Domain\User\UserId $id): array {
                    return ['membership_type' => 'BASIC', 'membership_status' => 'active'];
                },
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Propose\ProposeRevokeSubTier::class,
            static fn(Container $c) => new \Daems\Application\Governance\Propose\ProposeRevokeSubTier(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Propose\ProposeSubTierCrud::class,
            static fn(Container $c) => new \Daems\Application\Governance\Propose\ProposeSubTierCrud(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Propose\ProposeRemoveBoardMember::class,
            static fn(Container $c) => new \Daems\Application\Governance\Propose\ProposeRemoveBoardMember(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Propose\ProposeDelegateAuthority::class,
            static fn(Container $c) => new \Daems\Application\Governance\Propose\ProposeDelegateAuthority(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Propose\ProposeRevokeDelegation::class,
            static fn(Container $c) => new \Daems\Application\Governance\Propose\ProposeRevokeDelegation(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
            ),
        );

        // Membership Billing — fee schedules (0.7) — InMemory fakes
        $container->singleton(
            \Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class,
            static fn() => new \Daems\Tests\Support\Fake\InMemoryAnnualFeeScheduleRepository(),
        );
        $container->bind(
            \Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule::class,
            static fn(Container $c) => new \Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule(
                $c->make(\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(Clock::class),
            ),
        );
        $container->bind(
            \Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule::class,
            static fn(Container $c) => new \Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule(
                $c->make(\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class),
                $c->make(Clock::class),
            ),
        );

        // Membership Billing — member fee invoices + anniversary use case (0.7 Wave C) — InMemory fakes
        $container->singleton(
            \Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class,
            static fn() => new \Daems\Tests\Support\Fake\InMemoryMemberFeeInvoiceRepository(),
        );
        $container->singleton(
            \Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class,
            static fn() => new \Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository(),
        );
        $container->bind(
            \Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice::class,
            static fn(Container $c) => new \Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice(
                $c->make(\Daems\Domain\User\UserRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
                $c->make(Clock::class),
            ),
        );

        // User fee override use cases (Wave D)
        $container->bind(
            \Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride::class,
            static fn(Container $c) => new \Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride(
                $c->make(\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class),
                $c->make(Clock::class),
            ),
        );
        $container->bind(
            \Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride::class,
            static fn(Container $c) => new \Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride(
                $c->make(\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class),
                $c->make(Clock::class),
            ),
        );

        // Invoice audit + state-change use cases (Wave E)
        $container->singleton(
            \Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class,
            static fn() => new \Daems\Tests\Support\Fake\InMemoryFeeInvoiceAuditRepository(),
        );
        $container->bind(
            \Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice::class,
            static fn(Container $c) => new \Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice(
                $c->make(\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class),
                $c->make(Clock::class),
            ),
        );
        $container->bind(
            \Daems\Application\Membership\Billing\ReduceMemberFeeInvoice\ReduceMemberFeeInvoice::class,
            static fn(Container $c) => new \Daems\Application\Membership\Billing\ReduceMemberFeeInvoice\ReduceMemberFeeInvoice(
                $c->make(\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class),
                $c->make(Clock::class),
            ),
        );
        $container->bind(
            \Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment::class,
            static fn(Container $c) => new \Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment(
                $c->make(\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class),
                $c->make(Clock::class),
            ),
        );

        // Delegate variants (3)
        $container->bind(
            \Daems\Application\Governance\Delegate\ApproveBasicAsDelegate::class,
            static fn(Container $c) => new \Daems\Application\Governance\Delegate\ApproveBasicAsDelegate(
                $c->make(\Daems\Application\Governance\Propose\ProposeApproveBasic::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
                $c->make(\Daems\Application\Governance\Executor\ApproveBasicExecutor::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Delegate\InviteFullAsDelegate::class,
            static fn(Container $c) => new \Daems\Application\Governance\Delegate\InviteFullAsDelegate(
                $c->make(\Daems\Application\Governance\Propose\ProposeInviteFull::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
                $c->make(\Daems\Application\Governance\Executor\InviteFullExecutor::class),
            ),
        );
        $container->bind(
            \Daems\Application\Governance\Delegate\AwardSubTierAsDelegate::class,
            static fn(Container $c) => new \Daems\Application\Governance\Delegate\AwardSubTierAsDelegate(
                $c->make(\Daems\Application\Governance\Propose\ProposeAwardSubTier::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
                $c->make(\Daems\Application\Governance\Executor\AwardSubTierExecutor::class),
            ),
        );

        // Membership: 4 expulsion use cases
        $container->bind(
            \Daems\Application\Membership\InitiateMemberExpulsion::class,
            static fn(Container $c) => new \Daems\Application\Membership\InitiateMemberExpulsion(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Membership\SubmitExpulsionStatement::class,
            static fn(Container $c) => new \Daems\Application\Membership\SubmitExpulsionStatement(
                $c->make(\Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Membership\AdvanceExpulsionToVote::class,
            static fn(Container $c) => new \Daems\Application\Membership\AdvanceExpulsionToVote(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Membership\FileExpulsionAppeal::class,
            static fn(Container $c) => new \Daems\Application\Membership\FileExpulsionAppeal(
                $c->make(\Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class),
            ),
        );

        // Audit: GsaForceApproveBasic
        $container->bind(
            \Daems\Application\Audit\GsaForceApproveBasic::class,
            static fn(Container $c) => new \Daems\Application\Audit\GsaForceApproveBasic(
                $c->make(\Daems\Domain\Audit\GsaOverrideRepositoryInterface::class),
                static function (string $applicationId, \DateTimeImmutable $at, ?string $viaDelegationDecisionId): void {},
            ),
        );

        // Controllers (6)
        $container->bind(
            \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\BoardController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\BoardController(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
                $c->make(\Daems\Application\Governance\BootstrapBoard::class),
            ),
        );
        $container->bind(
            \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\BoardDecisionController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\BoardDecisionController(
                $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface::class),
                $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
                $c->make(\Daems\Application\Governance\Propose\ProposeApproveBasic::class),
                $c->make(\Daems\Application\Governance\Delegate\ApproveBasicAsDelegate::class),
                $c->make(\Daems\Application\Governance\Propose\ProposeInviteFull::class),
                $c->make(\Daems\Application\Governance\Delegate\InviteFullAsDelegate::class),
                $c->make(\Daems\Application\Governance\Propose\ProposeAwardSubTier::class),
                $c->make(\Daems\Application\Governance\Delegate\AwardSubTierAsDelegate::class),
                $c->make(\Daems\Application\Governance\Propose\ProposeRevokeSubTier::class),
                $c->make(\Daems\Application\Governance\Propose\ProposeSubTierCrud::class),
                $c->make(\Daems\Application\Governance\Propose\ProposeRemoveBoardMember::class),
                $c->make(\Daems\Application\Governance\Propose\ProposeDelegateAuthority::class),
                $c->make(\Daems\Application\Governance\Propose\ProposeRevokeDelegation::class),
                $c->make(\Daems\Application\Governance\CastBoardVote::class),
                $c->make(\Daems\Application\Governance\WithdrawBoardDecision::class),
            ),
        );
        $container->bind(
            \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\ExpulsionController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\ExpulsionController(
                $c->make(\Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class),
                $c->make(\Daems\Application\Membership\InitiateMemberExpulsion::class),
                $c->make(\Daems\Application\Membership\SubmitExpulsionStatement::class),
                $c->make(\Daems\Application\Membership\AdvanceExpulsionToVote::class),
                $c->make(\Daems\Application\Membership\FileExpulsionAppeal::class),
            ),
        );
        $container->bind(
            \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\DelegationController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\DelegationController(
                $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\EligibilityController::class,
            static fn() => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\EligibilityController(
                static function (\Daems\Domain\Tenant\TenantId $tenantId, \DateTimeImmutable $at): array {
                    return [];
                },
            ),
        );
        $container->bind(
            \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\GsaOverrideController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\GsaOverrideController(
                $c->make(\Daems\Application\Audit\GsaForceApproveBasic::class),
            ),
        );
        $container->bind(
            \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\BackstageBillingController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\BackstageBillingController(
                $c->make(\Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule::class),
                $c->make(\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class),
                $c->make(\Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride::class),
                $c->make(\Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride::class),
                $c->make(\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class),
                $c->make(\Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice::class),
                $c->make(\Daems\Application\Membership\Billing\ReduceMemberFeeInvoice\ReduceMemberFeeInvoice::class),
                $c->make(\Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment::class),
                $c->make(\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class),
                $c->make(\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class),
            ),
        );

        // Dashboard — widget registry, repo, use cases, widget instances.
        // MUST be bound before module bindings run so modules can register their widgets.
        $container->singleton(
            \Daems\Domain\Dashboard\WidgetRegistry::class,
            static fn() => new \Daems\Domain\Dashboard\WidgetRegistry(),
        );
        $container->singleton(
            \Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class,
            static fn() => new \Daems\Infrastructure\Dashboard\InMemoryUserDashboardRepository(),
        );
        $container->singleton(
            \Daems\Domain\Admin\AdminStatsRepositoryInterface::class,
            static fn(): \Daems\Domain\Admin\AdminStatsRepositoryInterface => new class implements \Daems\Domain\Admin\AdminStatsRepositoryInterface {
                public function getStatsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): \Daems\Domain\Admin\AdminStats
                {
                    return new \Daems\Domain\Admin\AdminStats(
                        members: 0,
                        pendingApplications: 0,
                        upcomingEvents: 0,
                        activeProjects: 0,
                        membersSparkline: [],
                        applicationsSparkline: [],
                        eventsSparkline: [],
                        projectsSparkline: [],
                        forumSparkline: [],
                        insightsSparkline: [],
                        membersChange: 0.0,
                        applicationsChange: 0.0,
                        eventsChange: 0.0,
                        projectsChange: 0.0,
                        memberGrowth: ['labels' => [], 'series' => []],
                    );
                }
                public function getMemberGrowthForTenant(string $period, \Daems\Domain\Tenant\TenantId $tenantId): array
                {
                    return ['labels' => [], 'series' => []];
                }
                public function getMembersByTier(\Daems\Domain\Tenant\TenantId $tenantId): array
                {
                    return ['supporting' => 0, 'basic' => 0, 'full' => 0, 'honorary' => 0];
                }
            },
        );
        $container->bind(
            \Daems\Application\Admin\GetAdminStats\GetAdminStats::class,
            static fn(Container $c) => new \Daems\Application\Admin\GetAdminStats\GetAdminStats(
                $c->make(\Daems\Domain\Admin\AdminStatsRepositoryInterface::class),
            ),
        );
        $container->singleton(
            \Daems\Domain\Platform\PlatformStatsRepositoryInterface::class,
            static fn(): \Daems\Domain\Platform\PlatformStatsRepositoryInterface => new class implements \Daems\Domain\Platform\PlatformStatsRepositoryInterface {
                public function get(): \Daems\Domain\Platform\PlatformStats
                {
                    return new \Daems\Domain\Platform\PlatformStats(
                        tenantCount:        0,
                        userCount:          0,
                        dbSizeMb:           0,
                        mysqlUptimeSeconds: 0,
                        usersSparkline:     [],
                        tenantsSparkline:   [],
                        activitySparkline:  [],
                        tenants:            [],
                        tenantActivity:     ['labels' => [], 'series' => []],
                        recentActivity:     [],
                    );
                }
            },
        );
        $container->bind(
            \Daems\Application\Platform\GetPlatformStats\GetPlatformStats::class,
            static fn(Container $c) => new \Daems\Application\Platform\GetPlatformStats\GetPlatformStats(
                $c->make(\Daems\Domain\Platform\PlatformStatsRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Dashboard\GetUserLayout\GetUserLayout::class,
            static fn(Container $c) => new \Daems\Application\Dashboard\GetUserLayout\GetUserLayout(
                $c->make(\Daems\Domain\Dashboard\WidgetRegistry::class),
                $c->make(\Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout::class,
            static fn(Container $c) => new \Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout(
                $c->make(\Daems\Domain\Dashboard\WidgetRegistry::class),
                $c->make(\Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class),
                new \DateTimeImmutable(),
            ),
        );
        $container->bind(
            \Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout::class,
            static fn(Container $c) => new \Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout(
                $c->make(\Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class),
            ),
        );
        $container->bind(
            \Daems\Application\Dashboard\ListCatalog\ListCatalog::class,
            static fn(Container $c) => new \Daems\Application\Dashboard\ListCatalog\ListCatalog(
                $c->make(\Daems\Domain\Dashboard\WidgetRegistry::class),
            ),
        );
        $container->bind(
            \Daems\Infrastructure\Adapter\Api\Controller\DashboardController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\DashboardController(
                $c->make(\Daems\Application\Dashboard\GetUserLayout\GetUserLayout::class),
                $c->make(\Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout::class),
                $c->make(\Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout::class),
                $c->make(\Daems\Application\Dashboard\ListCatalog\ListCatalog::class),
                $c->make(\Daems\Domain\Tenant\TenantModuleResolver::class),
            ),
        );

        $registry = $container->make(\Daems\Domain\Dashboard\WidgetRegistry::class);
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\MembersKpiWidget(
            $container->make(\Daems\Application\Admin\GetAdminStats\GetAdminStats::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\MembersByTierKpiWidget(
            $container->make(\Daems\Domain\Admin\AdminStatsRepositoryInterface::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\ApplicationsKpiWidget(
            $container->make(\Daems\Application\Admin\GetAdminStats\GetAdminStats::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\MemberGrowthChartWidget(
            $container->make(\Daems\Application\Admin\GetAdminStats\GetAdminStats::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\PlatformActivityChartWidget());
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\QuickActionsWidget());
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\PendingAppsListWidget());
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\ActivityFeedWidget(
            $container->make(\Daems\Application\Platform\GetPlatformStats\GetPlatformStats::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\PendingDecisionsForMeKpiWidget(
            $container->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
            $container->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
            $container->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
            $container->make(\Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\OpenExpulsionsKpiWidget(
            $container->make(\Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\DelegationsActiveKpiWidget(
            $container->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\EligibleForFullMembershipWidget(
            static fn(): array => [],
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\TenantsKpiWidget(
            $container->make(\Daems\Application\Platform\GetPlatformStats\GetPlatformStats::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\PlatformUsersKpiWidget(
            $container->make(\Daems\Application\Platform\GetPlatformStats\GetPlatformStats::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\DbSizeKpiWidget(
            $container->make(\Daems\Application\Platform\GetPlatformStats\GetPlatformStats::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\UptimeKpiWidget(
            $container->make(\Daems\Application\Platform\GetPlatformStats\GetPlatformStats::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\TenantStatusGridWidget(
            $container->make(\Daems\Application\Platform\GetPlatformStats\GetPlatformStats::class),
        ));
        $registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\TenantActivityChartWidget(
            $container->make(\Daems\Application\Platform\GetPlatformStats\GetPlatformStats::class),
        ));

        // Module bindings (TEST mode — uses bindings.test.php if present, else bindings.php).
        $moduleRegistry->registerBindings($container, \Daems\Infrastructure\Module\ModuleRegistry::TEST);

        $container->singleton(Router::class, static function () use ($container): Router {
            $router = new Router(static fn(string $class): mixed => $container->make($class));
            (require dirname(__DIR__, 2) . '/routes/api.php')($router, $container);
            return $router;
        });

        $this->kernel = new Kernel($container, $logger, $debug);
    }

    /** Returns the TenantId for the seeded test tenant (slug: daems). */
    public function daemsTenantId(): TenantId
    {
        return $this->testTenantId;
    }

    /**
     * @param string|null $role  Pass 'admin' to give the user UserTenantRole::Admin in the
     *                           test tenant. Any other non-null value is attached as-is via
     *                           UserTenantRole::fromStringOrRegistered().
     */
    public function seedUser(string $email = 'user@x.com', string $password = 'pass1234', ?string $role = null): User
    {
        $u = new User(
            UserId::generate(),
            'Test User',
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            '1990-01-01',
        );
        $this->users->save($u);

        if ($role !== null) {
            $tenantRole = UserTenantRole::fromStringOrRegistered($role);
            $this->userTenants->attach($u->id(), $this->testTenantId, $tenantRole);
        }

        return $u;
    }

    public function seedPlatformAdmin(string $email = 'gsa@x.com', string $password = 'pass1234'): User
    {
        $u = new User(
            id: UserId::generate(),
            name: 'GSA User',
            email: $email,
            passwordHash: password_hash($password, PASSWORD_BCRYPT),
            dateOfBirth: '1990-01-01',
            isPlatformAdmin: true,
        );
        $this->users->save($u);
        return $u;
    }

    public function tokenFor(User $user): string
    {
        $out = $this->container->make(CreateAuthToken::class)
            ->execute(new CreateAuthTokenInput($user->id(), 'e2e', '127.0.0.1'));
        return $out->rawToken;
    }

    public function request(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
        string $ip = '127.0.0.1',
    ): Response {
        return $this->kernel->handle(Request::forTesting(
            $method,
            $uri,
            [],
            $body,
            $headers,
            ['REMOTE_ADDR' => $ip],
        ));
    }

    public function authedRequest(string $method, string $uri, string $token, array $body = [], string $ip = '127.0.0.1'): Response
    {
        return $this->request($method, $uri, $body, ['Authorization' => 'Bearer ' . $token], $ip);
    }

    /**
     * Cross-domain accessor for module-owned InMemory fakes.
     *
     * After module extraction, fakes for Projects (and any future module) live
     * in `modules/<name>/backend/tests/Support/` and are bound to their domain
     * port via `bindings.test.php`. Tests in core that legitimately exercise
     * cross-domain flows (e.g. F007 identity spoofing across Project endpoints)
     * still need direct access to the same repo instance the API uses, so they
     * can seed state and inspect side effects.
     *
     * Resolves through the container — guaranteed to be the SAME singleton the
     * production code path receives. New module fakes get added by extending
     * the match below.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'projects'           => $this->container->make(\Daems\Domain\Project\ProjectRepositoryInterface::class),
            'proposals'          => $this->container->make(\Daems\Domain\Project\ProjectProposalRepositoryInterface::class),
            'commentAudit'       => $this->container->make(\Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface::class),
            'forum'              => $this->container->make(\Daems\Domain\Forum\ForumRepositoryInterface::class),
            'events'             => $this->container->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
            'eventProposals'     => $this->container->make(\DaemsModule\Events\Domain\EventProposalRepositoryInterface::class),
            'memberApps'         => $this->container->make(MemberApplicationRepositoryInterface::class),
            'supporterApps'      => $this->container->make(SupporterApplicationRepositoryInterface::class),
            'memberDirectory'    => $this->container->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
            'dismissals'         => $this->container->make(AdminApplicationDismissalRepositoryInterface::class),
            'memberCounters'     => $this->container->make(\Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface::class),
            'supporterCounters'  => $this->container->make(\Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface::class),
            'memberStatusAudit'  => $this->container->make(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class),
            default           => throw new \LogicException("Undefined property: KernelHarness::\${$name}"),
        };
    }
}
