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
use Daems\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use Daems\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplication;
use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Adapter\Api\Controller\AdminController;
use Daems\Infrastructure\Adapter\Api\Controller\ApplicationController;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
use Daems\Infrastructure\Adapter\Api\Controller\UserController;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAdminRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlSupporterApplicationRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository;
use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthToken;
use Daems\Application\Auth\LogoutUser\LogoutUser;
use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAdminApplicationDismissalRepository;
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
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;

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
$moduleRegistry->discover(__DIR__ . '/../../modules');
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
$container->singleton(\Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantMemberCounterRepository($c->make(Connection::class)->pdo()),
);
$container->singleton(\Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantSupporterCounterRepository($c->make(Connection::class)->pdo()),
);
$container->singleton(\Daems\Domain\Shared\TransactionManagerInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\PdoTransactionManager($c->make(Connection::class)->pdo()),
);
$container->singleton(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberStatusAuditRepository($c->make(Connection::class)),
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
$container->bind(\Daems\Application\Backstage\ActivateMember\MemberActivationService::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ActivateMember\MemberActivationService(
        $c->make(UserRepositoryInterface::class),
        $c->make(\Daems\Domain\Tenant\UserTenantRepositoryInterface::class),
        $c->make(\Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface::class),
        $c->make(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ActivateSupporter\SupporterActivationService::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ActivateSupporter\SupporterActivationService(
        $c->make(UserRepositoryInterface::class),
        $c->make(\Daems\Domain\Tenant\UserTenantRepositoryInterface::class),
        $c->make(\Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);

// Backstage
$container->singleton(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberDirectoryRepository($c->make(Connection::class)),
);
$container->bind(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplications::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListPendingApplications\ListPendingApplications(
        $c->make(MemberApplicationRepositoryInterface::class),
        $c->make(SupporterApplicationRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ListDecidedApplications\ListDecidedApplications::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListDecidedApplications\ListDecidedApplications(
        $c->make(MemberApplicationRepositoryInterface::class),
        $c->make(SupporterApplicationRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\GetApplicationDetail\GetApplicationDetail::class,
    static fn(Container $c) => new \Daems\Application\Backstage\GetApplicationDetail\GetApplicationDetail(
        $c->make(MemberApplicationRepositoryInterface::class),
        $c->make(SupporterApplicationRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\DismissApplication\DismissApplication::class,
    static fn(Container $c) => new \Daems\Application\Backstage\DismissApplication\DismissApplication(
        $c->make(AdminApplicationDismissalRepositoryInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin(
        $c->make(MemberApplicationRepositoryInterface::class),
        $c->make(SupporterApplicationRepositoryInterface::class),
        $c->make(AdminApplicationDismissalRepositoryInterface::class),
        $c->make(ProjectProposalRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(ForumRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\DecideApplication\DecideApplication::class,
    static fn(Container $c) => new \Daems\Application\Backstage\DecideApplication\DecideApplication(
        $c->make(MemberApplicationRepositoryInterface::class),
        $c->make(SupporterApplicationRepositoryInterface::class),
        $c->make(\Daems\Application\Backstage\ActivateMember\MemberActivationService::class),
        $c->make(\Daems\Application\Backstage\ActivateSupporter\SupporterActivationService::class),
        $c->make(\Daems\Application\Invite\IssueInvite\IssueInvite::class),
        $c->make(AdminApplicationDismissalRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
        $c->make(Clock::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ListMembers\ListMembers::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListMembers\ListMembers(
        $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus(
        $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
        $c->make(AnonymiseAccount::class),
        $c->make(Clock::class),
    ),
);
$container->bind(\Daems\Application\Backstage\GetMemberAudit\GetMemberAudit::class,
    static fn(Container $c) => new \Daems\Application\Backstage\GetMemberAudit\GetMemberAudit(
        $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
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
        $c->make(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplications::class),
        $c->make(\Daems\Application\Backstage\DecideApplication\DecideApplication::class),
        $c->make(\Daems\Application\Backstage\ListMembers\ListMembers::class),
        $c->make(\Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus::class),
        $c->make(\Daems\Application\Backstage\GetMemberAudit\GetMemberAudit::class),
        $c->make(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin::class),
        $c->make(\Daems\Application\Backstage\DismissApplication\DismissApplication::class),
        $c->make(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class),
        $c->make(\Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats::class),
        $c->make(\Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats::class),
        $c->make(\Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats::class),
        $c->make(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class),
        $c->make(\Daems\Application\Backstage\ListDecidedApplications\ListDecidedApplications::class),
        $c->make(\Daems\Application\Backstage\GetApplicationDetail\GetApplicationDetail::class),
    ),
);

$container->bind(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings(
        $c->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
    ),
);

$container->bind(\Daems\Domain\Member\PublicMemberRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlPublicMemberRepository(
        $c->make(\Daems\Infrastructure\Framework\Database\Connection::class),
    ),
);
$container->bind(\Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile::class,
    static fn(Container $c) => new \Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile(
        $c->make(\Daems\Domain\Member\PublicMemberRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\MemberController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\MemberController(
        $c->make(\Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile::class),
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

// Membership applications
$container->singleton(MemberApplicationRepositoryInterface::class,
    static fn(Container $c) => new SqlMemberApplicationRepository($c->make(Connection::class)),
);
$container->singleton(SupporterApplicationRepositoryInterface::class,
    static fn(Container $c) => new SqlSupporterApplicationRepository($c->make(Connection::class)),
);
$container->bind(SubmitMemberApplication::class,
    static fn(Container $c) => new SubmitMemberApplication($c->make(MemberApplicationRepositoryInterface::class)),
);
$container->bind(SubmitSupporterApplication::class,
    static fn(Container $c) => new SubmitSupporterApplication($c->make(SupporterApplicationRepositoryInterface::class)),
);
$container->bind(ApplicationController::class,
    static fn(Container $c) => new ApplicationController($c->make(SubmitMemberApplication::class), $c->make(SubmitSupporterApplication::class)),
);

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
$container->singleton(AdminApplicationDismissalRepositoryInterface::class,
    static fn(Container $c) => new SqlAdminApplicationDismissalRepository($c->make(Connection::class)->pdo()),
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

$container->bind(\Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats(
        $c->make(\Daems\Domain\Tenant\UserTenantRepositoryInterface::class),
        $c->make(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats(
        $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
        $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
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
