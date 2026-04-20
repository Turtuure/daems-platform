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
use Daems\Application\Event\GetEvent\GetEvent;
use Daems\Application\Event\ListEvents\ListEvents;
use Daems\Application\Event\RegisterForEvent\RegisterForEvent;
use Daems\Application\Event\UnregisterFromEvent\UnregisterFromEvent;
use Daems\Application\Forum\CreateForumPost\CreateForumPost;
use Daems\Application\Forum\CreateForumTopic\CreateForumTopic;
use Daems\Application\Forum\GetForumCategory\GetForumCategory;
use Daems\Application\Forum\GetForumThread\GetForumThread;
use Daems\Application\Forum\IncrementTopicView\IncrementTopicView;
use Daems\Application\Forum\LikeForumPost\LikeForumPost;
use Daems\Application\Forum\ListForumCategories\ListForumCategories;
use Daems\Application\Insight\GetInsight\GetInsight;
use Daems\Application\Insight\ListInsights\ListInsights;
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
use Daems\Application\Project\ListProjects\ListProjects;
use Daems\Application\Project\SubmitProjectProposal\SubmitProjectProposal;
use Daems\Application\Project\UpdateProject\UpdateProject;
use Daems\Application\User\ChangePassword\ChangePassword;
use Daems\Application\User\DeleteAccount\DeleteAccount;
use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetUserActivity\GetUserActivity;
use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;
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
use Daems\Infrastructure\Adapter\Api\Controller\ApplicationController;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
use Daems\Infrastructure\Adapter\Api\Controller\EventController;
use Daems\Infrastructure\Adapter\Api\Controller\ForumController;
use Daems\Infrastructure\Adapter\Api\Controller\InsightController;
use Daems\Infrastructure\Adapter\Api\Controller\ProjectController;
use Daems\Infrastructure\Adapter\Api\Controller\UserController;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Kernel;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\RateLimitLoginMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\Fake\InMemoryEventRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\Fake\InMemoryInsightRepository;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
use Daems\Tests\Support\Fake\InMemoryMemberApplicationRepository;
use Daems\Tests\Support\Fake\InMemoryMemberDirectoryRepository;
use Daems\Tests\Support\Fake\InMemoryProjectProposalRepository;
use Daems\Tests\Support\Fake\InMemoryProjectRepository;
use Daems\Tests\Support\Fake\InMemorySupporterApplicationRepository;
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
    public InMemoryProjectRepository $projects;
    public InMemoryForumRepository $forum;
    public InMemoryEventRepository $events;
    public InMemoryProjectProposalRepository $proposals;
    public InMemoryInsightRepository $insights;
    public InMemoryMemberApplicationRepository $memberApps;
    public InMemorySupporterApplicationRepository $supporterApps;
    public InMemoryMemberDirectoryRepository $memberDirectory;
    public InMemoryAdminApplicationDismissalRepository $dismissals;
    public FrozenClock $clock;

    /** @var array<array{0:string, 1:array<string,mixed>}> */
    public array $logs = [];

    public function __construct(FrozenClock $clock, bool $debug = false)
    {
        $this->clock = $clock;
        $this->users = new InMemoryUserRepository();
        $this->userTenants = new InMemoryUserTenantRepository();
        $this->tokens = new InMemoryAuthTokenRepository();
        $this->attempts = new InMemoryAuthLoginAttemptRepository();
        $this->projects = new InMemoryProjectRepository();
        $this->forum = new InMemoryForumRepository();
        $this->events = new InMemoryEventRepository();
        $this->proposals = new InMemoryProjectProposalRepository();
        $this->insights = new InMemoryInsightRepository();
        $this->memberApps = new InMemoryMemberApplicationRepository();
        $this->supporterApps = new InMemorySupporterApplicationRepository();
        $this->memberDirectory = new InMemoryMemberDirectoryRepository();
        $this->dismissals = new InMemoryAdminApplicationDismissalRepository();

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

        $container->singleton(LoggerInterface::class, static fn(): LoggerInterface => $logger);
        $container->singleton(Clock::class, fn(): Clock => $this->clock);
        $container->singleton(UserRepositoryInterface::class, fn() => $this->users);
        $container->singleton(UserTenantRepositoryInterface::class, fn() => $this->userTenants);
        $container->singleton(TenantRepositoryInterface::class, fn() => new class implements TenantRepositoryInterface {
            public function findById(\Daems\Domain\Tenant\TenantId $id): ?\Daems\Domain\Tenant\Tenant { return null; }
            public function findBySlug(string $slug): ?\Daems\Domain\Tenant\Tenant { return null; }
            public function findByDomain(string $domain): ?\Daems\Domain\Tenant\Tenant { return null; }
            /** @return list<\Daems\Domain\Tenant\Tenant> */
            public function findAll(): array { return []; }
        });
        $container->singleton(AuthTokenRepositoryInterface::class, fn() => $this->tokens);
        $container->singleton(AuthLoginAttemptRepositoryInterface::class, fn() => $this->attempts);
        $container->singleton(AdminApplicationDismissalRepositoryInterface::class, fn() => $this->dismissals);
        $container->singleton(ProjectRepositoryInterface::class, fn() => $this->projects);
        $container->singleton(ForumRepositoryInterface::class, fn() => $this->forum);
        $container->singleton(EventRepositoryInterface::class, fn() => $this->events);
        $container->singleton(ProjectProposalRepositoryInterface::class, fn() => $this->proposals);
        $container->singleton(InsightRepositoryInterface::class, fn() => $this->insights);
        $container->singleton(MemberApplicationRepositoryInterface::class, fn() => $this->memberApps);
        $container->singleton(SupporterApplicationRepositoryInterface::class, fn() => $this->supporterApps);
        $container->singleton(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class, fn() => $this->memberDirectory);

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
        $container->bind(DeleteAccount::class, static fn(Container $c) => new DeleteAccount($c->make(UserRepositoryInterface::class)));
        $container->bind(GetUserActivity::class, static fn(Container $c) => new GetUserActivity(
            $c->make(ForumRepositoryInterface::class),
            $c->make(EventRepositoryInterface::class),
        ));

        $container->bind(ListProjects::class, static fn(Container $c) => new ListProjects($c->make(ProjectRepositoryInterface::class)));
        $container->bind(GetProject::class, static fn(Container $c) => new GetProject($c->make(ProjectRepositoryInterface::class)));
        $container->bind(CreateProject::class, static fn(Container $c) => new CreateProject($c->make(ProjectRepositoryInterface::class)));
        $container->bind(UpdateProject::class, static fn(Container $c) => new UpdateProject($c->make(ProjectRepositoryInterface::class)));
        $container->bind(ArchiveProject::class, static fn(Container $c) => new ArchiveProject($c->make(ProjectRepositoryInterface::class)));
        $container->bind(AddProjectComment::class, static fn(Container $c) => new AddProjectComment(
            $c->make(ProjectRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
        ));
        $container->bind(LikeProjectComment::class, static fn(Container $c) => new LikeProjectComment($c->make(ProjectRepositoryInterface::class)));
        $container->bind(JoinProject::class, static fn(Container $c) => new JoinProject($c->make(ProjectRepositoryInterface::class)));
        $container->bind(LeaveProject::class, static fn(Container $c) => new LeaveProject($c->make(ProjectRepositoryInterface::class)));
        $container->bind(AddProjectUpdate::class, static fn(Container $c) => new AddProjectUpdate(
            $c->make(ProjectRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
        ));
        $container->bind(SubmitProjectProposal::class, static fn(Container $c) => new SubmitProjectProposal(
            $c->make(ProjectProposalRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
        ));

        $container->bind(ListForumCategories::class, static fn(Container $c) => new ListForumCategories($c->make(ForumRepositoryInterface::class)));
        $container->bind(GetForumCategory::class, static fn(Container $c) => new GetForumCategory($c->make(ForumRepositoryInterface::class)));
        $container->bind(GetForumThread::class, static fn(Container $c) => new GetForumThread($c->make(ForumRepositoryInterface::class)));
        $container->bind(CreateForumTopic::class, static fn(Container $c) => new CreateForumTopic(
            $c->make(ForumRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
        ));
        $container->bind(CreateForumPost::class, static fn(Container $c) => new CreateForumPost(
            $c->make(ForumRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
        ));
        $container->bind(LikeForumPost::class, static fn(Container $c) => new LikeForumPost($c->make(ForumRepositoryInterface::class)));
        $container->bind(IncrementTopicView::class, static fn(Container $c) => new IncrementTopicView($c->make(ForumRepositoryInterface::class)));

        $container->bind(ListEvents::class, static fn(Container $c) => new ListEvents($c->make(EventRepositoryInterface::class)));
        $container->bind(GetEvent::class, static fn(Container $c) => new GetEvent($c->make(EventRepositoryInterface::class)));
        $container->bind(RegisterForEvent::class, static fn(Container $c) => new RegisterForEvent($c->make(EventRepositoryInterface::class)));
        $container->bind(UnregisterFromEvent::class, static fn(Container $c) => new UnregisterFromEvent($c->make(EventRepositoryInterface::class)));

        $container->bind(ListInsights::class, static fn(Container $c) => new ListInsights($c->make(InsightRepositoryInterface::class)));
        $container->bind(GetInsight::class, static fn(Container $c) => new GetInsight($c->make(InsightRepositoryInterface::class)));

        $container->bind(SubmitMemberApplication::class, static fn(Container $c) => new SubmitMemberApplication($c->make(MemberApplicationRepositoryInterface::class)));
        $container->bind(SubmitSupporterApplication::class, static fn(Container $c) => new SubmitSupporterApplication($c->make(SupporterApplicationRepositoryInterface::class)));

        $container->bind(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplications::class, static fn(Container $c) => new \Daems\Application\Backstage\ListPendingApplications\ListPendingApplications(
            $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
            $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\DecideApplication\DecideApplication::class, static fn(Container $c) => new \Daems\Application\Backstage\DecideApplication\DecideApplication(
            $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
            $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
            $c->make(Clock::class),
        ));
        $container->bind(\Daems\Application\Backstage\ListMembers\ListMembers::class, static fn(Container $c) => new \Daems\Application\Backstage\ListMembers\ListMembers(
            $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus::class, static fn(Container $c) => new \Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus(
            $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
            $c->make(Clock::class),
        ));
        $container->bind(\Daems\Application\Backstage\GetMemberAudit\GetMemberAudit::class, static fn(Container $c) => new \Daems\Application\Backstage\GetMemberAudit\GetMemberAudit(
            $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
        ));

        // Controllers
        $container->bind(GetAuthMe::class, static fn(Container $c) => new GetAuthMe(
            $c->make(UserRepositoryInterface::class),
            $c->make(TenantRepositoryInterface::class),
            $c->make(AuthTokenRepositoryInterface::class),
        ));
        $container->bind(AuthController::class, static fn(Container $c) => new AuthController(
            $c->make(RegisterUser::class),
            $c->make(LoginUser::class),
            $c->make(CreateAuthToken::class),
            $c->make(LogoutUser::class),
            $c->make(GetAuthMe::class),
        ));
        $container->bind(UserController::class, static fn(Container $c) => new UserController(
            $c->make(GetProfile::class),
            $c->make(UpdateProfile::class),
            $c->make(ChangePassword::class),
            $c->make(GetUserActivity::class),
            $c->make(DeleteAccount::class),
        ));
        $container->bind(ProjectController::class, static fn(Container $c) => new ProjectController(
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
        ));
        $container->bind(ForumController::class, static fn(Container $c) => new ForumController(
            $c->make(ListForumCategories::class),
            $c->make(GetForumCategory::class),
            $c->make(GetForumThread::class),
            $c->make(CreateForumTopic::class),
            $c->make(CreateForumPost::class),
            $c->make(LikeForumPost::class),
            $c->make(IncrementTopicView::class),
        ));
        $container->bind(EventController::class, static fn(Container $c) => new EventController(
            $c->make(ListEvents::class),
            $c->make(GetEvent::class),
            $c->make(RegisterForEvent::class),
            $c->make(UnregisterFromEvent::class),
        ));
        $container->bind(InsightController::class, static fn(Container $c) => new InsightController(
            $c->make(ListInsights::class),
            $c->make(GetInsight::class),
        ));
        $container->bind(ApplicationController::class, static fn(Container $c) => new ApplicationController(
            $c->make(SubmitMemberApplication::class),
            $c->make(SubmitSupporterApplication::class),
        ));
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class, static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\BackstageController(
            $c->make(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplications::class),
            $c->make(\Daems\Application\Backstage\DecideApplication\DecideApplication::class),
            $c->make(\Daems\Application\Backstage\ListMembers\ListMembers::class),
            $c->make(\Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus::class),
            $c->make(\Daems\Application\Backstage\GetMemberAudit\GetMemberAudit::class),
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
        $container->bind(TenantContextMiddleware::class, static fn() => new TenantContextMiddleware(
            new class ($stubTenant) implements TenantResolverInterface {
                public function __construct(private readonly Tenant $tenant) {}
                public function resolve(string $host): ?Tenant
                {
                    return $this->tenant;
                }
            },
        ));

        $container->singleton(Router::class, static function () use ($container): Router {
            $router = new Router(static fn(string $class): mixed => $container->make($class));
            (require dirname(__DIR__, 2) . '/routes/api.php')($router, $container);
            return $router;
        });

        $this->kernel = new Kernel($container, $logger, $debug);
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
}
