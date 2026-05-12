<?php

declare(strict_types=1);

use Daems\Application\Admin\GetAdminStats\GetAdminStats;
use Daems\Application\Auth\GetAuthMe\GetAuthMe;
use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\RegisterUser\RegisterUser;
use Daems\Application\User\AnonymiseAccount\AnonymiseAccount;
use Daems\Application\User\ChangePassword\ChangePassword;
use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetUserActivity\GetUserActivity;
use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Adapter\Api\Controller\AdminController;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
use Daems\Infrastructure\Adapter\Api\Controller\UserController;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAdminRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository;
use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthToken;
use Daems\Application\Auth\LogoutUser\LogoutUser;
use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAuthLoginAttemptRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAuthTokenRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Infrastructure\Framework\Http\Kernel;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\LocaleMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\RateLimitLoginMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Framework\Logging\ErrorLogLogger;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use Daems\Infrastructure\Tenant\HostTenantResolver;
use Daems\Infrastructure\Tenant\TenantResolverInterface;
use Daems\Domain\Tenant\ModuleAuditRepositoryInterface;
use Daems\Domain\Tenant\ModuleRouteGuard;
use Daems\Domain\Tenant\TenantDomainRepositoryInterface;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlModuleAuditRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantDomainRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantModulesRepository;

// Load .env
(static function (): void {
    $file = dirname(__DIR__) . '/.env';
    if (!file_exists($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $val;
            putenv("{$key}={$val}");
        }
    }
})();

// Module registry — discover external modules under C:\laragon\www\modules\* at boot.
// Phase 1 (insights pilot): autoloader registered before any binding so cross-module
// type references resolve. Bindings and routes are registered after core bindings.
$composerLoader = require __DIR__ . '/../vendor/autoload.php';
$moduleRegistry = new \Daems\Infrastructure\Module\ModuleRegistry();
$moduleRegistry->discover(
    __DIR__ . '/../../modules',
    __DIR__ . '/../config/modules.php',
    __DIR__ . '/../lang/en_GB.php',
);
$moduleRegistry->registerAutoloader($composerLoader);

$container = new Container();
$container->bind(\Daems\Infrastructure\Module\ModuleRegistry::class, fn() => $moduleRegistry);

// Database (lazy singleton — only connects when first used)
$container->singleton(Connection::class, static function (): Connection {
    return new Connection([
        'host'     => $_ENV['DB_HOST']     ?? '127.0.0.1',
        'port'     => $_ENV['DB_PORT']     ?? '3306',
        'database' => $_ENV['DB_DATABASE'] ?? 'daems_db',
        'username' => $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
    ]);
});

// Admin
$container->singleton(AdminStatsRepositoryInterface::class,
    static fn(Container $c) => new SqlAdminRepository($c->make(Connection::class)),
);
$container->bind(GetAdminStats::class,
    static fn(Container $c) => new GetAdminStats($c->make(AdminStatsRepositoryInterface::class)),
);
$container->bind(AdminController::class,
    static fn(Container $c) => new AdminController(
        $c->make(GetAdminStats::class),
        $c->make(AdminStatsRepositoryInterface::class),
    ),
);

// Invite infrastructure
$container->singleton(\Daems\Domain\Invite\TokenGeneratorInterface::class,
    static fn() => new \Daems\Infrastructure\Token\RandomTokenGenerator(),
);
$container->singleton(\Daems\Domain\Tenant\TenantSlugResolverInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantSlugResolver(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->singleton(\Daems\Domain\Config\BaseUrlResolverInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Config\EnvBaseUrlResolver(
        [
            'daems'      => 'http://daem-society.local',
            'sahegroup'  => 'http://sahegroup.local',
        ],
        'http://daems-platform.local',
        $c->make(\Daems\Domain\Tenant\TenantSlugResolverInterface::class),
    ),
);
$container->singleton(\Daems\Domain\Invite\UserInviteRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserInviteRepository($c->make(Connection::class)->pdo()),
);
$container->singleton(\Daems\Domain\Shared\TransactionManagerInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\PdoTransactionManager($c->make(Connection::class)->pdo()),
);
$container->singleton(\Daems\Domain\Shared\IdGeneratorInterface::class,
    static fn() => new class implements \Daems\Domain\Shared\IdGeneratorInterface {
        public function generate(): string
        {
            return \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value();
        }
    },
);
$container->bind(\Daems\Application\Invite\IssueInvite\IssueInvite::class,
    static fn(Container $c) => new \Daems\Application\Invite\IssueInvite\IssueInvite(
        $c->make(\Daems\Domain\Invite\UserInviteRepositoryInterface::class),
        $c->make(\Daems\Domain\Invite\TokenGeneratorInterface::class),
        $c->make(\Daems\Domain\Config\BaseUrlResolverInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin(
        $c->make(ProjectProposalRepositoryInterface::class),
    ),
);

// Image storage — used by Events module via ImageStorageInterface binding.
$container->singleton(\Daems\Domain\Storage\ImageStorageInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Storage\LocalImageStorage(
        publicRoot: dirname(__DIR__) . '/public',
        urlPrefix:  rtrim((string) ($_ENV['APP_URL'] ?? 'http://daems-platform.local'), '/'),
        ids:        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);

$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\BackstageController(
        $c->make(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class),
        $c->make(\Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats::class),
        $c->make(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class),
    ),
);

$container->bind(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings(
        $c->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
    ),
);

// Search
$container->bind(\Daems\Domain\Search\SearchRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlSearchRepository(
        $c->make(Connection::class),
    ));
$container->bind(\Daems\Application\Search\Search\Search::class,
    static fn(Container $c) => new \Daems\Application\Search\Search\Search(
        $c->make(\Daems\Domain\Search\SearchRepositoryInterface::class),
    ));
$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\SearchController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\SearchController(
        $c->make(\Daems\Application\Search\Search\Search::class),
    ));

// Auth
$container->singleton(UserRepositoryInterface::class,
    static fn(Container $c) => new SqlUserRepository($c->make(Connection::class)),
);
$container->singleton(LoggerInterface::class, static fn() => new ErrorLogLogger());
$container->singleton(Clock::class, static fn() => new SystemClock());

$container->singleton(AuthTokenRepositoryInterface::class,
    static fn(Container $c) => new SqlAuthTokenRepository($c->make(Connection::class)),
);
$container->singleton(AuthLoginAttemptRepositoryInterface::class,
    static fn(Container $c) => new SqlAuthLoginAttemptRepository(
        $c->make(Connection::class),
        $c->make(LoggerInterface::class),
    ),
);
$container->bind(CreateAuthToken::class,
    static fn(Container $c) => new CreateAuthToken(
        $c->make(AuthTokenRepositoryInterface::class),
        $c->make(Clock::class),
        (int) ($_ENV['AUTH_TOKEN_TTL_DAYS'] ?? 7),
    ),
);
$container->bind(AuthenticateToken::class,
    static fn(Container $c) => new AuthenticateToken(
        $c->make(AuthTokenRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(Clock::class),
        $c->make(LoggerInterface::class),
        (int) ($_ENV['AUTH_TOKEN_TTL_DAYS'] ?? 7),
        (int) ($_ENV['AUTH_TOKEN_HARD_CAP_DAYS'] ?? 30),
    ),
);
$container->bind(LogoutUser::class,
    static fn(Container $c) => new LogoutUser(
        $c->make(AuthTokenRepositoryInterface::class),
        $c->make(Clock::class),
    ),
);
// Tenant infrastructure
$container->singleton(TenantRepositoryInterface::class,
    static fn(Container $c) => new SqlTenantRepository($c->make(Connection::class)->pdo()),
);
$container->singleton(UserTenantRepositoryInterface::class,
    static fn(Container $c) => new SqlUserTenantRepository($c->make(Connection::class)->pdo()),
);

// Tenant module + domain repositories (Wave F: module registry + tenant mgmt)
$container->singleton(TenantModulesRepositoryInterface::class,
    static fn(Container $c) => new SqlTenantModulesRepository($c->make(Connection::class)->pdo()),
);
$container->singleton(ModuleAuditRepositoryInterface::class,
    static fn(Container $c) => new SqlModuleAuditRepository($c->make(Connection::class)->pdo()),
);
$container->singleton(TenantDomainRepositoryInterface::class,
    static fn(Container $c) => new SqlTenantDomainRepository($c->make(Connection::class)->pdo()),
);

// Domain services for module gating
$container->singleton(TenantModuleResolver::class,
    static fn(Container $c) => new TenantModuleResolver(
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(TenantModulesRepositoryInterface::class),
    ),
);
$container->singleton(ModuleRouteGuard::class,
    static fn(Container $c) => new ModuleRouteGuard(
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(TenantModuleResolver::class),
    ),
);

$container->singleton(\Daems\Frontend\BackstageSidebar::class,
    static fn(Container $c) => new \Daems\Frontend\BackstageSidebar(
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(TenantModuleResolver::class),
    ),
);

$container->singleton(HostTenantResolver::class,
    static fn(Container $c) => new HostTenantResolver(
        $c->make(TenantRepositoryInterface::class),
        (array) (require dirname(__DIR__) . '/config/tenant-fallback.php'),
    ),
);
$container->singleton(TenantResolverInterface::class,
    static fn(Container $c) => $c->make(HostTenantResolver::class),
);
$container->bind(TenantContextMiddleware::class,
    static fn(Container $c) => new TenantContextMiddleware($c->make(TenantResolverInterface::class)),
);
$container->bind(LocaleMiddleware::class, static fn() => new LocaleMiddleware());

$container->bind(AuthMiddleware::class,
    static fn(Container $c) => new AuthMiddleware(
        $c->make(AuthenticateToken::class),
        $c->make(TenantRepositoryInterface::class),
        $c->make(UserTenantRepositoryInterface::class),
    ),
);
$container->bind(RateLimitLoginMiddleware::class,
    static fn(Container $c) => new RateLimitLoginMiddleware(
        $c->make(AuthLoginAttemptRepositoryInterface::class),
        $c->make(Clock::class),
        (int) ($_ENV['AUTH_RATE_LIMIT_MAX_FAILS'] ?? 5),
        (int) ($_ENV['AUTH_RATE_LIMIT_WINDOW_MIN'] ?? 15),
        (int) ($_ENV['AUTH_RATE_LIMIT_LOCKOUT_MIN'] ?? 15) * 60,
        (int) ($_ENV['AUTH_RATE_LIMIT_MAX_FAILS_PER_IP'] ?? 20),
    ),
);

$container->bind(LoginUser::class,
    static fn(Container $c) => new LoginUser(
        $c->make(UserRepositoryInterface::class),
        $c->make(AuthLoginAttemptRepositoryInterface::class),
        $c->make(AdminApplicationDismissalRepositoryInterface::class),
        $c->make(Clock::class),
    ),
);
$container->bind(RegisterUser::class,
    static fn(Container $c) => new RegisterUser($c->make(UserRepositoryInterface::class)),
);
$container->bind(GetAuthMe::class,
    static fn(Container $c) => new GetAuthMe(
        $c->make(UserRepositoryInterface::class),
        $c->make(TenantRepositoryInterface::class),
        $c->make(AuthTokenRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Auth\RedeemInvite\RedeemInvite::class,
    static fn(Container $c) => new \Daems\Application\Auth\RedeemInvite\RedeemInvite(
        $c->make(\Daems\Domain\Invite\UserInviteRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(Clock::class),
    ),
);
$container->bind(AuthController::class,
    static fn(Container $c) => new AuthController(
        $c->make(RegisterUser::class),
        $c->make(LoginUser::class),
        $c->make(CreateAuthToken::class),
        $c->make(LogoutUser::class),
        $c->make(GetAuthMe::class),
        $c->make(\Daems\Application\Auth\RedeemInvite\RedeemInvite::class),
    ),
);

// User profile
$container->bind(GetProfile::class,
    static fn(Container $c) => new GetProfile(
        $c->make(UserRepositoryInterface::class),
        $c->make(UserTenantRepositoryInterface::class),
    ),
);
$container->bind(UpdateProfile::class,
    static fn(Container $c) => new UpdateProfile($c->make(UserRepositoryInterface::class)),
);
$container->bind(ChangePassword::class,
    static fn(Container $c) => new ChangePassword($c->make(UserRepositoryInterface::class)),
);
$container->bind(AnonymiseAccount::class,
    static fn(Container $c) => new AnonymiseAccount(
        $c->make(UserRepositoryInterface::class),
        $c->make(UserTenantRepositoryInterface::class),
        $c->make(AuthTokenRepositoryInterface::class),
        $c->make(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(GetUserActivity::class,
    static fn(Container $c) => new GetUserActivity(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
    ),
);
$container->bind(UserController::class,
    static fn(Container $c) => new UserController(
        $c->make(GetProfile::class),
        $c->make(UpdateProfile::class),
        $c->make(ChangePassword::class),
        $c->make(GetUserActivity::class),
        $c->make(AnonymiseAccount::class),
        $c->make(\Daems\Application\Profile\UpdateMyPublicProfilePrivacy\UpdateMyPublicProfilePrivacy::class),
        $c->make(\Daems\Application\Profile\UpdateMyTimeFormat\UpdateMyTimeFormat::class),
    ),
);
$container->bind(\Daems\Application\Profile\UpdateMyPublicProfilePrivacy\UpdateMyPublicProfilePrivacy::class,
    static fn(Container $c) => new \Daems\Application\Profile\UpdateMyPublicProfilePrivacy\UpdateMyPublicProfilePrivacy(
        $c->make(\Daems\Domain\User\UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Profile\UpdateMyTimeFormat\UpdateMyTimeFormat::class,
    static fn(Container $c) => new \Daems\Application\Profile\UpdateMyTimeFormat\UpdateMyTimeFormat(
        $c->make(\Daems\Domain\User\UserRepositoryInterface::class),
        $c->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
    ),
);

$container->bind(\Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats(
        $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
        $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
        $c->make(\Daems\Domain\Project\ProjectProposalRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(\Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface::class),
    ),
);

// Backstage — Platform-scope (GSA) tenant management use cases
$container->bind(\Daems\Application\Backstage\Platform\CreateTenant\CreateTenant::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\CreateTenant\CreateTenant(
        $c->make(TenantRepositoryInterface::class),
        $c->make(TenantModulesRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(Clock::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasics::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasics(
        $c->make(TenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenant::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenant(
        $c->make(TenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(Clock::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenant::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenant(
        $c->make(TenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomain::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomain(
        $c->make(TenantRepositoryInterface::class),
        $c->make(TenantDomainRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(Clock::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomain::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomain(
        $c->make(TenantDomainRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomain::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomain(
        $c->make(TenantRepositoryInterface::class),
        $c->make(TenantDomainRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenant::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenant(
        $c->make(UserTenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenant::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenant(
        $c->make(UserTenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailability::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailability(
        $c->make(TenantModulesRepositoryInterface::class),
        $c->make(ModuleAuditRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(Clock::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailability::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailability(
        $c->make(TenantModulesRepositoryInterface::class),
        $c->make(ModuleAuditRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\ListTenants\ListTenants::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\ListTenants\ListTenants(
        $c->make(TenantRepositoryInterface::class),
        $c->make(TenantDomainRepositoryInterface::class),
        $c->make(TenantModulesRepositoryInterface::class),
        $c->make(UserTenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdmins::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdmins(
        $c->make(UserTenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetail::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetail(
        $c->make(TenantRepositoryInterface::class),
        $c->make(TenantDomainRepositoryInterface::class),
        $c->make(TenantModulesRepositoryInterface::class),
        $c->make(UserTenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModules::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModules(
        $c->make(TenantRepositoryInterface::class),
        $c->make(TenantModulesRepositoryInterface::class),
        $c->make(TenantModuleResolver::class),
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(UserRepositoryInterface::class),
    ),
);

// Backstage — Tenant-scope (admin in current tenant) module use cases
$container->bind(\Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenant::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenant(
        $c->make(TenantRepositoryInterface::class),
        $c->make(TenantModulesRepositoryInterface::class),
        $c->make(ModuleAuditRepositoryInterface::class),
        $c->make(UserTenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(Clock::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenant::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenant(
        $c->make(TenantModulesRepositoryInterface::class),
        $c->make(ModuleAuditRepositoryInterface::class),
        $c->make(UserTenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(Clock::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenant::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenant(
        $c->make(TenantModuleResolver::class),
        $c->make(TenantModulesRepositoryInterface::class),
        $c->make(UserTenantRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
    ),
);

// Backstage — Platform-scope + Tenant-scope controllers
$container->singleton(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController(
        $c->make(\Daems\Application\Backstage\Platform\ListTenants\ListTenants::class),
        $c->make(\Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetail::class),
        $c->make(\Daems\Application\Backstage\Platform\CreateTenant\CreateTenant::class),
        $c->make(\Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasics::class),
        $c->make(\Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenant::class),
        $c->make(\Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenant::class),
    ),
);
$container->singleton(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantDomainsController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantDomainsController(
        $c->make(\Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomain::class),
        $c->make(\Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomain::class),
        $c->make(\Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomain::class),
        $c->make(TenantDomainRepositoryInterface::class),
    ),
);
$container->singleton(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantAdminsController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantAdminsController(
        $c->make(\Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenant::class),
        $c->make(\Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant\RevokeAdminFromUserForTenant::class),
        $c->make(\Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdmins::class),
    ),
);
$container->singleton(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\PlatformTenantModulesController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\PlatformTenantModulesController(
        $c->make(\Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModules::class),
        $c->make(\Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailability::class),
        $c->make(\Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailability::class),
    ),
);
$container->singleton(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Tenant\TenantSelfModulesController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Tenant\TenantSelfModulesController(
        $c->make(\Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenant::class),
        $c->make(\Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenant::class),
        $c->make(\Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenant::class),
        $c->make(UserTenantRepositoryInterface::class),
    ),
);

// Membership Core v2 / 0.6a — tier system + sub-tier honor catalog.
$container->bind(
    \Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantMembershipSubTierRepository(
        $c->make(Connection::class)->pdo(),
    ),
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

// MembershipCore v2 / 0.6b — Governance (board + decisions + expulsions)

// 9 SQL repositories
$container->bind(
    \Daems\Domain\Governance\BoardRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Domain\Governance\BoardMemberRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardMemberRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Domain\Governance\BoardDecisionRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDecisionRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDecisionVoteRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Domain\Governance\BoardDelegationRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDelegationRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantGovernanceSettingsRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberExpulsionRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberSubTierAwardRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Domain\Audit\GsaOverrideRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlGsaOverrideRepository(
        $c->make(Connection::class)->pdo(),
    ),
);

// Pure services
$container->singleton(
    \Daems\Application\Governance\BoardDecisionResolutionService::class,
    static fn() => new \Daems\Application\Governance\BoardDecisionResolutionService(),
);
$container->singleton(
    \Daems\Domain\Membership\IsEligibleForFullMembership::class,
    static fn() => new \Daems\Domain\Membership\IsEligibleForFullMembership(),
);

// 9 Executors — each bound with the closures it needs
$container->bind(
    \Daems\Application\Governance\Executor\ApproveBasicExecutor::class,
    static fn(Container $c) => new \Daems\Application\Governance\Executor\ApproveBasicExecutor(
        static function (string $applicationId, \DateTimeImmutable $at, ?string $viaDelegationDecisionId) use ($c): void {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare("UPDATE member_applications SET status = 'approved', approved_at = ? WHERE id = ?");
            $stmt->execute([$at->format('Y-m-d H:i:s'), $applicationId]);
        },
    ),
);
$container->bind(
    \Daems\Application\Governance\Executor\InviteFullExecutor::class,
    static fn(Container $c) => new \Daems\Application\Governance\Executor\InviteFullExecutor(
        static function (\Daems\Domain\User\UserId $userId, \DateTimeImmutable $at) use ($c): void {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare("UPDATE users SET membership_type = 'FULL', invited_to_full_at = ? WHERE id = ?");
            $stmt->execute([$at->format('Y-m-d H:i:s'), $userId->value()]);
        },
    ),
);
$container->bind(
    \Daems\Application\Governance\Executor\ExpelExecutor::class,
    static fn(Container $c) => new \Daems\Application\Governance\Executor\ExpelExecutor(
        static function (\Daems\Domain\User\UserId $userId, string $reason, \DateTimeImmutable $at) use ($c): void {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare("UPDATE users SET membership_status = 'expelled', membership_ended_at = ?, membership_status_reason = ? WHERE id = ?");
            $stmt->execute([$at->format('Y-m-d H:i:s'), $reason, $userId->value()]);
        },
        $c->make(\Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class),
    ),
);
$container->bind(
    \Daems\Application\Governance\Executor\AwardSubTierExecutor::class,
    static fn(Container $c) => new \Daems\Application\Governance\Executor\AwardSubTierExecutor(
        static function (\Daems\Domain\User\UserId $userId, string $slug) use ($c): void {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare("UPDATE users SET membership_subtier = ? WHERE id = ?");
            $stmt->execute([$slug, $userId->value()]);
        },
        static function (\Daems\Domain\Governance\BoardId $boardId) use ($c): \Daems\Domain\Tenant\TenantId {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare('SELECT tenant_id FROM boards WHERE id = ?');
            $stmt->execute([$boardId->value()]);
            $tid = $stmt->fetchColumn();
            if (!is_string($tid)) {
                throw new \DomainException("board={$boardId->value()} has no tenant");
            }
            return \Daems\Domain\Tenant\TenantId::fromString($tid);
        },
        $c->make(\Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface::class),
    ),
);
$container->bind(
    \Daems\Application\Governance\Executor\RevokeSubTierExecutor::class,
    static fn(Container $c) => new \Daems\Application\Governance\Executor\RevokeSubTierExecutor(
        static function (\Daems\Domain\User\UserId $userId) use ($c): void {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare("UPDATE users SET membership_subtier = NULL WHERE id = ?");
            $stmt->execute([$userId->value()]);
        },
        static function (\Daems\Domain\Governance\BoardId $boardId) use ($c): \Daems\Domain\Tenant\TenantId {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare('SELECT tenant_id FROM boards WHERE id = ?');
            $stmt->execute([$boardId->value()]);
            $tid = $stmt->fetchColumn();
            if (!is_string($tid)) {
                throw new \DomainException("board={$boardId->value()} has no tenant");
            }
            return \Daems\Domain\Tenant\TenantId::fromString($tid);
        },
        $c->make(\Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface::class),
    ),
);
$container->bind(
    \Daems\Application\Governance\Executor\SubTierCrudExecutor::class,
    static fn(Container $c) => new \Daems\Application\Governance\Executor\SubTierCrudExecutor(
        $c->make(\Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class),
        static function (\Daems\Domain\Governance\BoardId $boardId) use ($c): \Daems\Domain\Tenant\TenantId {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare('SELECT tenant_id FROM boards WHERE id = ?');
            $stmt->execute([$boardId->value()]);
            $tid = $stmt->fetchColumn();
            if (!is_string($tid)) {
                throw new \DomainException("board={$boardId->value()} has no tenant");
            }
            return \Daems\Domain\Tenant\TenantId::fromString($tid);
        },
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
    static fn(Container $c) => new \Daems\Application\Governance\Executor\DelegateAuthorityExecutor(
        $c->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
        static function (\Daems\Domain\Governance\BoardId $boardId) use ($c): \Daems\Domain\Tenant\TenantId {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare('SELECT tenant_id FROM boards WHERE id = ?');
            $stmt->execute([$boardId->value()]);
            $tid = $stmt->fetchColumn();
            if (!is_string($tid)) {
                throw new \DomainException("board={$boardId->value()} has no tenant");
            }
            return \Daems\Domain\Tenant\TenantId::fromString($tid);
        },
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

// Executor registry (singleton; all 10 executors registered)
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

// BootstrapBoard with user-lookup closure
$container->bind(
    \Daems\Application\Governance\BootstrapBoard::class,
    static fn(Container $c) => new \Daems\Application\Governance\BootstrapBoard(
        $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
        $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
        static function (\Daems\Domain\User\UserId $id) use ($c): array {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare('SELECT membership_type, membership_status FROM users WHERE id = ?');
            $stmt->execute([$id->value()]);
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            return [
                'membership_type'   => is_array($r) && is_string($r['membership_type'] ?? null) ? $r['membership_type'] : 'BASIC',
                'membership_status' => is_array($r) && is_string($r['membership_status'] ?? null) ? $r['membership_status'] : 'inactive',
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
        static function (string $applicationId) use ($c): array {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare('SELECT status FROM member_applications WHERE id = ?');
            $stmt->execute([$applicationId]);
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            return ['status' => is_array($r) && is_string($r['status'] ?? null) ? $r['status'] : 'unknown'];
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
        static function (\Daems\Domain\User\UserId $id) use ($c): array {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare('SELECT membership_type, membership_status, membership_started_at FROM users WHERE id = ?');
            $stmt->execute([$id->value()]);
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            return [
                'membership_type'       => is_array($r) && is_string($r['membership_type']       ?? null) ? $r['membership_type']       : 'BASIC',
                'membership_status'     => is_array($r) && is_string($r['membership_status']     ?? null) ? $r['membership_status']     : 'inactive',
                'membership_started_at' => is_array($r) && is_string($r['membership_started_at'] ?? null) ? $r['membership_started_at'] : null,
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
        static function (\Daems\Domain\User\UserId $id) use ($c): array {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare('SELECT membership_type, membership_status FROM users WHERE id = ?');
            $stmt->execute([$id->value()]);
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            return [
                'membership_type'   => is_array($r) && is_string($r['membership_type']   ?? null) ? $r['membership_type']   : 'BASIC',
                'membership_status' => is_array($r) && is_string($r['membership_status'] ?? null) ? $r['membership_status'] : 'inactive',
            ];
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

// Membership Billing — fee schedules (0.7)
$container->bind(
    \Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlAnnualFeeScheduleRepository(
        $c->make(Connection::class)->pdo(),
    ),
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

// Membership Billing — member fee invoices + anniversary use case (0.7 Wave C)
$container->bind(
    \Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserFeeOverrideRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice::class,
    static fn(Container $c) => new \Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice(
        $c->make(UserRepositoryInterface::class),
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
$container->bind(
    \Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlFeeInvoiceAuditRepository(
        $c->make(Connection::class)->pdo(),
    ),
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

// MarkOverdueInvoices cron (Wave F)
$container->bind(
    \Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices::class,
    static fn(Container $c) => new \Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices(
        $c->make(\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class),
        $c->make(\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class),
        $c->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
        $c->make(Clock::class),
    ),
);

// LapseInactiveMember use case (Wave F § 4)
$container->bind(
    \Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember::class,
    static fn(Container $c) => new \Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember(
        $c->make(UserRepositoryInterface::class),
        $c->make(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
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
        static function (string $applicationId, \DateTimeImmutable $at, ?string $viaDelegationDecisionId) use ($c): void {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare("UPDATE member_applications SET status = 'approved', approved_at = ? WHERE id = ?");
            $stmt->execute([$at->format('Y-m-d H:i:s'), $applicationId]);
        },
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
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\EligibilityController(
        static function (\Daems\Domain\Tenant\TenantId $tenantId, \DateTimeImmutable $at) use ($c): array {
            $pdo = $c->make(Connection::class)->pdo();
            $threshold = $at->modify('-12 months')->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                "SELECT u.id, u.name, u.member_number, u.membership_started_at,
                        TIMESTAMPDIFF(MONTH, u.membership_started_at, ?) AS months_since_join
                   FROM users u
                   JOIN user_tenants ut ON ut.user_id = u.id
                  WHERE ut.tenant_id = ?
                    AND u.membership_type = 'BASIC'
                    AND u.membership_status = 'active'
                    AND u.membership_started_at IS NOT NULL
                    AND u.membership_started_at <= ?
                  ORDER BY u.membership_started_at ASC"
            );
            $stmt->execute([$at->format('Y-m-d H:i:s'), $tenantId->value(), $threshold]);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (!is_array($r)) continue;
                $out[] = [
                    'id'                    => is_string($r['id'] ?? null) ? $r['id'] : '',
                    'name'                  => is_string($r['name'] ?? null) ? $r['name'] : '',
                    'member_number'         => is_string($r['member_number'] ?? null) ? $r['member_number'] : null,
                    'membership_started_at' => is_string($r['membership_started_at'] ?? null) ? $r['membership_started_at'] : '',
                    'months_since_join'     => is_int($r['months_since_join'] ?? null) ? $r['months_since_join'] : (int) (string) ($r['months_since_join'] ?? 0),
                ];
            }
            return $out;
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
$container->bind(
    \Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Dashboard\SqlUserDashboardRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->singleton(
    \Daems\Domain\Platform\PlatformStatsRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlPlatformStatsRepository(
        $c->make(Connection::class)->pdo(),
    ),
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

// Register core + platform widgets. Module widgets register themselves
// inside each module's bindings.php (loaded just below).
$registry = $container->make(\Daems\Domain\Dashboard\WidgetRegistry::class);
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\MembersKpiWidget(
    $container->make(GetAdminStats::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\MembersByTierKpiWidget(
    $container->make(\Daems\Domain\Admin\AdminStatsRepositoryInterface::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\ApplicationsKpiWidget(
    $container->make(GetAdminStats::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\MemberGrowthChartWidget(
    $container->make(GetAdminStats::class),
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
    static function (\Daems\Domain\Tenant\TenantId $tenantId, \DateTimeImmutable $at) use ($container): array {
        $pdo = $container->make(\Daems\Infrastructure\Framework\Database\Connection::class)->pdo();
        $threshold = $at->modify('-12 months')->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "SELECT u.id, u.name, u.member_number, u.membership_started_at,
                    TIMESTAMPDIFF(MONTH, u.membership_started_at, ?) AS months_since_join
               FROM users u
               JOIN user_tenants ut ON ut.user_id = u.id
              WHERE ut.tenant_id = ?
                AND u.membership_type = 'BASIC'
                AND u.membership_status = 'active'
                AND u.membership_started_at IS NOT NULL
                AND u.membership_started_at <= ?
              ORDER BY u.membership_started_at ASC"
        );
        $stmt->execute([$at->format('Y-m-d H:i:s'), $tenantId->value(), $threshold]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (!is_array($r)) continue;
            $out[] = [
                'id'                    => is_string($r['id'] ?? null) ? $r['id'] : '',
                'name'                  => is_string($r['name'] ?? null) ? $r['name'] : '',
                'member_number'         => is_string($r['member_number'] ?? null) ? $r['member_number'] : null,
                'membership_started_at' => is_string($r['membership_started_at'] ?? null) ? $r['membership_started_at'] : '',
                'months_since_join'     => is_int($r['months_since_join'] ?? null) ? $r['months_since_join'] : (int) (string) ($r['months_since_join'] ?? 0),
            ];
        }
        return $out;
    },
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

// Module bindings — invoke each discovered module's bindings.php.
$moduleRegistry->registerBindings($container, \Daems\Infrastructure\Module\ModuleRegistry::PROD);

// Router
$container->singleton(Router::class, static function () use ($container): Router {
    $router = new Router(static fn(string $class): mixed => $container->make($class));
    (require dirname(__DIR__) . '/routes/api.php')($router, $container);
    return $router;
});

return new Kernel(
    $container,
    $container->make(LoggerInterface::class),
    ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
);
