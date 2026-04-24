<?php

declare(strict_types=1);

use Daems\Application\Event\GetEvent\GetEvent;
use Daems\Application\Event\GetEventBySlugForLocale\GetEventBySlugForLocale;
use Daems\Application\Event\ListEvents\ListEvents;
use Daems\Application\Event\ListEventsForLocale\ListEventsForLocale;
use Daems\Application\Event\SubmitEventProposal\SubmitEventProposal;
use Daems\Application\Forum\CreateForumPost\CreateForumPost;
use Daems\Application\Forum\CreateForumTopic\CreateForumTopic;
use Daems\Application\Forum\GetForumCategory\GetForumCategory;
use Daems\Application\Forum\GetForumThread\GetForumThread;
use Daems\Application\Forum\IncrementTopicView\IncrementTopicView;
use Daems\Application\Forum\LikeForumPost\LikeForumPost;
use Daems\Application\Forum\ListForumCategories\ListForumCategories;
use Daems\Application\Insight\GetInsight\GetInsight;
use Daems\Application\Insight\ListInsights\ListInsights;
use Daems\Application\Admin\GetAdminStats\GetAdminStats;
use Daems\Application\Auth\GetAuthMe\GetAuthMe;
use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\RegisterUser\RegisterUser;
use Daems\Application\User\AnonymiseAccount\AnonymiseAccount;
use Daems\Application\User\ChangePassword\ChangePassword;
use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetUserActivity\GetUserActivity;
use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Application\Event\RegisterForEvent\RegisterForEvent;
use Daems\Application\Event\UnregisterFromEvent\UnregisterFromEvent;
use Daems\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use Daems\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplication;
use Daems\Application\Project\AddProjectComment\AddProjectComment;
use Daems\Application\Project\AddProjectUpdate\AddProjectUpdate;
use Daems\Application\Project\ArchiveProject\ArchiveProject;
use Daems\Application\Project\CreateProject\CreateProject;
use Daems\Application\Project\GetProject\GetProject;
use Daems\Application\Project\JoinProject\JoinProject;
use Daems\Application\Project\LeaveProject\LeaveProject;
use Daems\Application\Project\LikeProjectComment\LikeProjectComment;
use Daems\Application\Project\GetProjectBySlugForLocale\GetProjectBySlugForLocale;
use Daems\Application\Project\ListProjects\ListProjects;
use Daems\Application\Project\ListProjectsForLocale\ListProjectsForLocale;
use Daems\Application\Project\SubmitProjectProposal\SubmitProjectProposal;
use Daems\Application\Project\UpdateProject\UpdateProject;
use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Domain\Event\EventProposalRepositoryInterface;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Adapter\Api\Controller\AdminController;
use Daems\Infrastructure\Adapter\Api\Controller\EventController;
use Daems\Infrastructure\Adapter\Api\Controller\ForumController;
use Daems\Infrastructure\Adapter\Api\Controller\InsightController;
use Daems\Infrastructure\Adapter\Api\Controller\ApplicationController;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
use Daems\Infrastructure\Adapter\Api\Controller\ProjectController;
use Daems\Infrastructure\Adapter\Api\Controller\UserController;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAdminRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
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

$container = new Container();

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
// Events admin — use cases
$container->bind(\Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\CreateEvent\CreateEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\CreateEvent\CreateEvent(
        $c->make(EventRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\UpdateEvent\UpdateEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UpdateEvent\UpdateEvent(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\PublishEvent\PublishEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\PublishEvent\PublishEvent(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ArchiveEvent\ArchiveEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ArchiveEvent\ArchiveEvent(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent(
        $c->make(EventRepositoryInterface::class),
    ),
);

// Projects admin — use cases
$container->bind(\Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin(
        $c->make(ProjectRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin(
        $c->make(ProjectRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProject::class,
    static fn(Container $c) => new \Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProject(
        $c->make(ProjectRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus(
        $c->make(ProjectRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\SetProjectFeatured\SetProjectFeatured::class,
    static fn(Container $c) => new \Daems\Application\Backstage\SetProjectFeatured\SetProjectFeatured(
        $c->make(ProjectRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin(
        $c->make(ProjectProposalRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal(
        $c->make(ProjectProposalRepositoryInterface::class),
        $c->make(ProjectRepositoryInterface::class),
        $c->make(AdminApplicationDismissalRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\RejectProjectProposal\RejectProjectProposal::class,
    static fn(Container $c) => new \Daems\Application\Backstage\RejectProjectProposal\RejectProjectProposal(
        $c->make(ProjectProposalRepositoryInterface::class),
        $c->make(AdminApplicationDismissalRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
        $c->make(Clock::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin(
        $c->make(ProjectRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin(
        $c->make(ProjectRepositoryInterface::class),
        $c->make(\Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);

// Image storage
$container->singleton(\Daems\Domain\Storage\ImageStorageInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Storage\LocalImageStorage(
        publicRoot: dirname(__DIR__) . '/public',
        urlPrefix:  rtrim((string) ($_ENV['APP_URL'] ?? 'http://daems-platform.local'), '/'),
        ids:        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\UploadEventImage\UploadEventImage::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UploadEventImage\UploadEventImage(
        $c->make(EventRepositoryInterface::class),
        $c->make(\Daems\Domain\Storage\ImageStorageInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\DeleteEventImage\DeleteEventImage::class,
    static fn(Container $c) => new \Daems\Application\Backstage\DeleteEventImage\DeleteEventImage(
        $c->make(EventRepositoryInterface::class),
        $c->make(\Daems\Domain\Storage\ImageStorageInterface::class),
    ),
);

// MediaController
$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\MediaController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\MediaController(
        $c->make(\Daems\Application\Backstage\UploadEventImage\UploadEventImage::class),
        $c->make(\Daems\Application\Backstage\DeleteEventImage\DeleteEventImage::class),
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
        $c->make(\Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin::class),
        $c->make(\Daems\Application\Backstage\CreateEvent\CreateEvent::class),
        $c->make(\Daems\Application\Backstage\UpdateEvent\UpdateEvent::class),
        $c->make(\Daems\Application\Backstage\PublishEvent\PublishEvent::class),
        $c->make(\Daems\Application\Backstage\ArchiveEvent\ArchiveEvent::class),
        $c->make(\Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations::class),
        $c->make(\Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent::class),
        $c->make(\Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin::class),
        $c->make(\Daems\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin::class),
        $c->make(\Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProject::class),
        $c->make(\Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus::class),
        $c->make(\Daems\Application\Backstage\SetProjectFeatured\SetProjectFeatured::class),
        $c->make(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class),
        $c->make(\Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal::class),
        $c->make(\Daems\Application\Backstage\RejectProjectProposal\RejectProjectProposal::class),
        $c->make(\Daems\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin::class),
        $c->make(\Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetail::class),
        $c->make(\Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDelete::class),
        $c->make(\Daems\Application\Backstage\Forum\ResolveForumReportByLock\ResolveForumReportByLock::class),
        $c->make(\Daems\Application\Backstage\Forum\ResolveForumReportByWarn\ResolveForumReportByWarn::class),
        $c->make(\Daems\Application\Backstage\Forum\ResolveForumReportByEdit\ResolveForumReportByEdit::class),
        $c->make(\Daems\Application\Backstage\Forum\DismissForumReport\DismissForumReport::class),
        $c->make(\Daems\Application\Backstage\Forum\ListForumTopicsForAdmin\ListForumTopicsForAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\PinForumTopic\PinForumTopic::class),
        $c->make(\Daems\Application\Backstage\Forum\UnpinForumTopic\UnpinForumTopic::class),
        $c->make(\Daems\Application\Backstage\Forum\LockForumTopic\LockForumTopic::class),
        $c->make(\Daems\Application\Backstage\Forum\UnlockForumTopic\UnlockForumTopic::class),
        $c->make(\Daems\Application\Backstage\Forum\DeleteForumTopicAsAdmin\DeleteForumTopicAsAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\ListForumPostsForAdmin\ListForumPostsForAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\EditForumPostAsAdmin\EditForumPostAsAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\DeleteForumPostAsAdmin\DeleteForumPostAsAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\WarnForumUser\WarnForumUser::class),
        $c->make(ListForumCategories::class),
        $c->make(\Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin\CreateForumCategoryAsAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\UpdateForumCategoryAsAdmin\UpdateForumCategoryAsAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\DeleteForumCategoryAsAdmin\DeleteForumCategoryAsAdmin::class),
        $c->make(\Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin\ListForumModerationAuditForAdmin::class),
        $c->make(\Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations::class),
        $c->make(\Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation::class),
        $c->make(\Daems\Application\Backstage\GetProjectWithAllTranslations\GetProjectWithAllTranslations::class),
        $c->make(\Daems\Application\Backstage\UpdateProjectTranslation\UpdateProjectTranslation::class),
        $c->make(\Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin::class),
        $c->make(\Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposal::class),
        $c->make(\Daems\Application\Backstage\RejectEventProposal\RejectEventProposal::class),
        $c->make(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class),
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

// i18n admin use cases
$container->bind(\Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations::class,
    static fn(Container $c) => new \Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\GetProjectWithAllTranslations\GetProjectWithAllTranslations::class,
    static fn(Container $c) => new \Daems\Application\Backstage\GetProjectWithAllTranslations\GetProjectWithAllTranslations(
        $c->make(ProjectRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\UpdateProjectTranslation\UpdateProjectTranslation::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UpdateProjectTranslation\UpdateProjectTranslation(
        $c->make(ProjectRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin(
        $c->make(EventProposalRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposal::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposal(
        $c->make(EventProposalRepositoryInterface::class),
        $c->make(EventRepositoryInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\RejectEventProposal\RejectEventProposal::class,
    static fn(Container $c) => new \Daems\Application\Backstage\RejectEventProposal\RejectEventProposal(
        $c->make(EventProposalRepositoryInterface::class),
        $c->make(Clock::class),
    ),
);

// Events
$container->singleton(EventRepositoryInterface::class,
    static fn(Container $c) => new SqlEventRepository($c->make(Connection::class)),
);
$container->bind(ListEvents::class,
    static fn(Container $c) => new ListEvents($c->make(EventRepositoryInterface::class)),
);
$container->bind(GetEvent::class,
    static fn(Container $c) => new GetEvent($c->make(EventRepositoryInterface::class)),
);
$container->bind(RegisterForEvent::class,
    static fn(Container $c) => new RegisterForEvent($c->make(EventRepositoryInterface::class)),
);
$container->bind(UnregisterFromEvent::class,
    static fn(Container $c) => new UnregisterFromEvent($c->make(EventRepositoryInterface::class)),
);
$container->bind(ListEventsForLocale::class,
    static fn(Container $c) => new ListEventsForLocale($c->make(EventRepositoryInterface::class)),
);
$container->bind(GetEventBySlugForLocale::class,
    static fn(Container $c) => new GetEventBySlugForLocale($c->make(EventRepositoryInterface::class)),
);
$container->singleton(EventProposalRepositoryInterface::class,
    static fn(Container $c) => new SqlEventProposalRepository($c->make(Connection::class)),
);
$container->bind(SubmitEventProposal::class,
    static fn(Container $c) => new SubmitEventProposal(
        $c->make(EventProposalRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(EventController::class,
    static fn(Container $c) => new EventController(
        $c->make(ListEvents::class),
        $c->make(GetEvent::class),
        $c->make(RegisterForEvent::class),
        $c->make(UnregisterFromEvent::class),
        $c->make(ListEventsForLocale::class),
        $c->make(GetEventBySlugForLocale::class),
        $c->make(SubmitEventProposal::class),
    ),
);

// Insights
$container->singleton(InsightRepositoryInterface::class,
    static fn(Container $c) => new SqlInsightRepository($c->make(Connection::class)),
);
$container->bind(ListInsights::class,
    static fn(Container $c) => new ListInsights($c->make(InsightRepositoryInterface::class)),
);
$container->bind(GetInsight::class,
    static fn(Container $c) => new GetInsight($c->make(InsightRepositoryInterface::class)),
);
$container->bind(InsightController::class,
    static fn(Container $c) => new InsightController($c->make(ListInsights::class), $c->make(GetInsight::class)),
);

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
        $c->make(EventRepositoryInterface::class),
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
    ),
);
$container->bind(\Daems\Application\Profile\UpdateMyPublicProfilePrivacy\UpdateMyPublicProfilePrivacy::class,
    static fn(Container $c) => new \Daems\Application\Profile\UpdateMyPublicProfilePrivacy\UpdateMyPublicProfilePrivacy(
        $c->make(\Daems\Domain\User\UserRepositoryInterface::class),
    ),
);

// Forum
$container->singleton(ForumRepositoryInterface::class,
    static fn(Container $c) => new SqlForumRepository($c->make(Connection::class)),
);
$container->singleton(\Daems\Domain\Forum\ForumReportRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumReportRepository($c->make(Connection::class)),
);
$container->singleton(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumModerationAuditRepository($c->make(Connection::class)),
);
$container->singleton(\Daems\Domain\Forum\ForumUserWarningRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumUserWarningRepository($c->make(Connection::class)),
);
$container->bind(ListForumCategories::class,
    static fn(Container $c) => new ListForumCategories($c->make(ForumRepositoryInterface::class)),
);
$container->bind(GetForumCategory::class,
    static fn(Container $c) => new GetForumCategory($c->make(ForumRepositoryInterface::class)),
);
$container->bind(GetForumThread::class,
    static fn(Container $c) => new GetForumThread($c->make(ForumRepositoryInterface::class)),
);
$container->bind(CreateForumTopic::class,
    static fn(Container $c) => new CreateForumTopic(
        $c->make(ForumRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(CreateForumPost::class,
    static fn(Container $c) => new CreateForumPost(
        $c->make(ForumRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(LikeForumPost::class,
    static fn(Container $c) => new LikeForumPost($c->make(ForumRepositoryInterface::class)),
);
$container->bind(IncrementTopicView::class,
    static fn(Container $c) => new IncrementTopicView($c->make(ForumRepositoryInterface::class)),
);
$container->bind(\Daems\Application\Forum\ReportForumTarget\ReportForumTarget::class,
    static fn(Container $c) => new \Daems\Application\Forum\ReportForumTarget\ReportForumTarget(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(\Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface::class),
    ),
);

// Forum admin (Task 21)
$container->bind(\Daems\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdmin(
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(ForumRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetail::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetail(
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(ForumRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDelete::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDelete(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\ResolveForumReportByLock\ResolveForumReportByLock::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\ResolveForumReportByLock\ResolveForumReportByLock(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\ResolveForumReportByWarn\ResolveForumReportByWarn::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\ResolveForumReportByWarn\ResolveForumReportByWarn(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumUserWarningRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\ResolveForumReportByEdit\ResolveForumReportByEdit::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\ResolveForumReportByEdit\ResolveForumReportByEdit(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\DismissForumReport\DismissForumReport::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\DismissForumReport\DismissForumReport(
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\ListForumTopicsForAdmin\ListForumTopicsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\ListForumTopicsForAdmin\ListForumTopicsForAdmin(
        $c->make(ForumRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\PinForumTopic\PinForumTopic::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\PinForumTopic\PinForumTopic(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\UnpinForumTopic\UnpinForumTopic::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\UnpinForumTopic\UnpinForumTopic(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\LockForumTopic\LockForumTopic::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\LockForumTopic\LockForumTopic(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\UnlockForumTopic\UnlockForumTopic::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\UnlockForumTopic\UnlockForumTopic(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\DeleteForumTopicAsAdmin\DeleteForumTopicAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\DeleteForumTopicAsAdmin\DeleteForumTopicAsAdmin(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\ListForumPostsForAdmin\ListForumPostsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\ListForumPostsForAdmin\ListForumPostsForAdmin(
        $c->make(ForumRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\EditForumPostAsAdmin\EditForumPostAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\EditForumPostAsAdmin\EditForumPostAsAdmin(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\DeleteForumPostAsAdmin\DeleteForumPostAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\DeleteForumPostAsAdmin\DeleteForumPostAsAdmin(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\WarnForumUser\WarnForumUser::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\WarnForumUser\WarnForumUser(
        $c->make(\Daems\Domain\Forum\ForumUserWarningRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin\CreateForumCategoryAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin\CreateForumCategoryAsAdmin(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\UpdateForumCategoryAsAdmin\UpdateForumCategoryAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\UpdateForumCategoryAsAdmin\UpdateForumCategoryAsAdmin(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\DeleteForumCategoryAsAdmin\DeleteForumCategoryAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\DeleteForumCategoryAsAdmin\DeleteForumCategoryAsAdmin(
        $c->make(ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin\ListForumModerationAuditForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin\ListForumModerationAuditForAdmin(
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ),
);

$container->bind(ForumController::class,
    static fn(Container $c) => new ForumController(
        $c->make(ListForumCategories::class),
        $c->make(GetForumCategory::class),
        $c->make(GetForumThread::class),
        $c->make(CreateForumTopic::class),
        $c->make(CreateForumPost::class),
        $c->make(LikeForumPost::class),
        $c->make(IncrementTopicView::class),
        $c->make(\Daems\Application\Forum\ReportForumTarget\ReportForumTarget::class),
    ),
);

// Projects
$container->singleton(ProjectRepositoryInterface::class,
    static fn(Container $c) => new SqlProjectRepository($c->make(Connection::class)),
);
$container->bind(ListProjects::class,
    static fn(Container $c) => new ListProjects($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(GetProject::class,
    static fn(Container $c) => new GetProject($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(CreateProject::class,
    static fn(Container $c) => new CreateProject($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(UpdateProject::class,
    static fn(Container $c) => new UpdateProject($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(ArchiveProject::class,
    static fn(Container $c) => new ArchiveProject($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(AddProjectComment::class,
    static fn(Container $c) => new AddProjectComment(
        $c->make(ProjectRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(LikeProjectComment::class,
    static fn(Container $c) => new LikeProjectComment($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(JoinProject::class,
    static fn(Container $c) => new JoinProject($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(LeaveProject::class,
    static fn(Container $c) => new LeaveProject($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(AddProjectUpdate::class,
    static fn(Container $c) => new AddProjectUpdate(
        $c->make(ProjectRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->singleton(ProjectProposalRepositoryInterface::class,
    static fn(Container $c) => new SqlProjectProposalRepository($c->make(Connection::class)),
);
$container->singleton(\Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectCommentModerationAuditRepository(
        $c->make(Connection::class),
    ),
);
$container->bind(SubmitProjectProposal::class,
    static fn(Container $c) => new SubmitProjectProposal(
        $c->make(ProjectProposalRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
    ),
);
$container->bind(ListProjectsForLocale::class,
    static fn(Container $c) => new ListProjectsForLocale($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(GetProjectBySlugForLocale::class,
    static fn(Container $c) => new GetProjectBySlugForLocale($c->make(ProjectRepositoryInterface::class)),
);
$container->bind(ProjectController::class,
    static fn(Container $c) => new ProjectController(
        $c->make(ListProjects::class),
        $c->make(GetProject::class),
        $c->make(CreateProject::class),
        $c->make(UpdateProject::class),
        $c->make(ArchiveProject::class),
        $c->make(AddProjectComment::class),
        $c->make(LikeProjectComment::class),
        $c->make(JoinProject::class),
        $c->make(LeaveProject::class),
        $c->make(AddProjectUpdate::class),
        $c->make(SubmitProjectProposal::class),
        $c->make(ListProjectsForLocale::class),
        $c->make(GetProjectBySlugForLocale::class),
    ),
);

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
