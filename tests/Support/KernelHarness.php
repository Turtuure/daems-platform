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
use Daems\Application\Event\GetEventBySlugForLocale\GetEventBySlugForLocale;
use Daems\Application\Event\ListEvents\ListEvents;
use Daems\Application\Event\ListEventsForLocale\ListEventsForLocale;
use Daems\Application\Event\RegisterForEvent\RegisterForEvent;
use Daems\Application\Event\SubmitEventProposal\SubmitEventProposal;
use Daems\Application\Event\UnregisterFromEvent\UnregisterFromEvent;
use Daems\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use Daems\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplication;
use Daems\Application\User\AnonymiseAccount\AnonymiseAccount;
use Daems\Application\User\ChangePassword\ChangePassword;
use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetUserActivity\GetUserActivity;
use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Event\EventProposalRepositoryInterface;
use Daems\Domain\Event\EventRepositoryInterface;
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
use Daems\Infrastructure\Adapter\Api\Controller\ApplicationController;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
use Daems\Infrastructure\Adapter\Api\Controller\EventController;
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
use Daems\Tests\Support\Fake\InMemoryEventProposalRepository;
use Daems\Tests\Support\Fake\InMemoryEventRepository;
use Daems\Tests\Support\Fake\ImmediateTransactionManager;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
use Daems\Tests\Support\Fake\InMemoryMemberApplicationRepository;
use Daems\Tests\Support\Fake\InMemoryMemberDirectoryRepository;
use Daems\Tests\Support\Fake\InMemoryMemberStatusAuditRepository;
use Daems\Tests\Support\Fake\InMemorySupporterApplicationRepository;
use Daems\Tests\Support\Fake\InMemoryTenantMemberCounterRepository;
use Daems\Tests\Support\Fake\InMemoryTenantSupporterCounterRepository;
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
    public InMemoryEventRepository $events;
    public InMemoryMemberApplicationRepository $memberApps;
    public InMemorySupporterApplicationRepository $supporterApps;
    public InMemoryMemberDirectoryRepository $memberDirectory;
    public InMemoryAdminApplicationDismissalRepository $dismissals;
    public InMemoryUserInviteRepository $invites;
    public InMemoryTenantMemberCounterRepository $memberCounters;
    public InMemoryTenantSupporterCounterRepository $supporterCounters;
    public InMemoryMemberStatusAuditRepository $memberStatusAudit;
    public InMemoryImageStorage $imageStorage;
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
        $this->events = new InMemoryEventRepository();
        $this->memberApps = new InMemoryMemberApplicationRepository();
        $this->supporterApps = new InMemorySupporterApplicationRepository();
        $this->memberDirectory = new InMemoryMemberDirectoryRepository();
        $this->dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->invites = new InMemoryUserInviteRepository();
        $this->memberCounters = new InMemoryTenantMemberCounterRepository();
        $this->supporterCounters = new InMemoryTenantSupporterCounterRepository();
        $this->memberStatusAudit = new InMemoryMemberStatusAuditRepository();
        $this->imageStorage = new InMemoryImageStorage();

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
        $composerLoader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
        $moduleRegistry = new \Daems\Infrastructure\Module\ModuleRegistry();
        $moduleRegistry->discover(dirname(__DIR__, 3) . '/modules');
        $moduleRegistry->registerAutoloader($composerLoader);
        $container->bind(\Daems\Infrastructure\Module\ModuleRegistry::class, fn() => $moduleRegistry);

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
            public function updatePrefix(\Daems\Domain\Tenant\TenantId $tenantId, ?string $prefix): void {}
            public function updateDefaultTimeFormat(\Daems\Domain\Tenant\TenantId $tenantId, string $format): void {}
        });
        $container->singleton(AuthTokenRepositoryInterface::class, fn() => $this->tokens);
        $container->singleton(AuthLoginAttemptRepositoryInterface::class, fn() => $this->attempts);
        $container->singleton(AdminApplicationDismissalRepositoryInterface::class, fn() => $this->dismissals);
        $container->singleton(EventRepositoryInterface::class, fn() => $this->events);
        $container->singleton(MemberApplicationRepositoryInterface::class, fn() => $this->memberApps);
        $container->singleton(SupporterApplicationRepositoryInterface::class, fn() => $this->supporterApps);
        $container->singleton(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class, fn() => $this->memberDirectory);
        $container->singleton(\Daems\Domain\Invite\UserInviteRepositoryInterface::class, fn() => $this->invites);
        $container->singleton(\Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface::class, fn() => $this->memberCounters);
        $container->singleton(\Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface::class, fn() => $this->supporterCounters);
        $container->singleton(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class, fn() => $this->memberStatusAudit);
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
            $c->make(EventRepositoryInterface::class),
        ));

        $container->bind(\Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats::class, static fn(Container $c) => new \Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats(
            $c->make(UserTenantRepositoryInterface::class),
            $c->make(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats::class, static fn(Container $c) => new \Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats(
            $c->make(MemberApplicationRepositoryInterface::class),
            $c->make(SupporterApplicationRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\Events\ListEventsStats\ListEventsStats::class, static fn(Container $c) => new \Daems\Application\Backstage\Events\ListEventsStats\ListEventsStats(
            $c->make(EventRepositoryInterface::class),
            $c->make(\Daems\Domain\Event\EventProposalRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats::class, static fn(Container $c) => new \Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats(
            $c->make(MemberApplicationRepositoryInterface::class),
            $c->make(SupporterApplicationRepositoryInterface::class),
            $c->make(ProjectProposalRepositoryInterface::class),
            $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
            $c->make(AdminApplicationDismissalRepositoryInterface::class),
        ));

        $container->bind(ListEvents::class, static fn(Container $c) => new ListEvents($c->make(EventRepositoryInterface::class)));
        $container->bind(GetEvent::class, static fn(Container $c) => new GetEvent($c->make(EventRepositoryInterface::class)));
        $container->bind(RegisterForEvent::class, static fn(Container $c) => new RegisterForEvent($c->make(EventRepositoryInterface::class)));
        $container->bind(UnregisterFromEvent::class, static fn(Container $c) => new UnregisterFromEvent($c->make(EventRepositoryInterface::class)));

        $container->bind(SubmitMemberApplication::class, static fn(Container $c) => new SubmitMemberApplication($c->make(MemberApplicationRepositoryInterface::class)));
        $container->bind(SubmitSupporterApplication::class, static fn(Container $c) => new SubmitSupporterApplication($c->make(SupporterApplicationRepositoryInterface::class)));

        $container->bind(\Daems\Application\Invite\IssueInvite\IssueInvite::class, static fn(Container $c) => new \Daems\Application\Invite\IssueInvite\IssueInvite(
            $c->make(\Daems\Domain\Invite\UserInviteRepositoryInterface::class),
            $c->make(\Daems\Domain\Invite\TokenGeneratorInterface::class),
            $c->make(\Daems\Domain\Config\BaseUrlResolverInterface::class),
            $c->make(Clock::class),
            $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\ActivateMember\MemberActivationService::class, static fn(Container $c) => new \Daems\Application\Backstage\ActivateMember\MemberActivationService(
            $c->make(UserRepositoryInterface::class),
            $c->make(UserTenantRepositoryInterface::class),
            $c->make(\Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface::class),
            $c->make(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class),
            $c->make(Clock::class),
            $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\ActivateSupporter\SupporterActivationService::class, static fn(Container $c) => new \Daems\Application\Backstage\ActivateSupporter\SupporterActivationService(
            $c->make(UserRepositoryInterface::class),
            $c->make(UserTenantRepositoryInterface::class),
            $c->make(\Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface::class),
            $c->make(Clock::class),
            $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplications::class, static fn(Container $c) => new \Daems\Application\Backstage\ListPendingApplications\ListPendingApplications(
            $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
            $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\DismissApplication\DismissApplication::class, static fn(Container $c) => new \Daems\Application\Backstage\DismissApplication\DismissApplication(
            $c->make(AdminApplicationDismissalRepositoryInterface::class),
            $c->make(Clock::class),
            $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin::class, static fn(Container $c) => new \Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin(
            $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
            $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
            $c->make(AdminApplicationDismissalRepositoryInterface::class),
            $c->make(ProjectProposalRepositoryInterface::class),
            $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
            $c->make(ForumRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\DecideApplication\DecideApplication::class, static fn(Container $c) => new \Daems\Application\Backstage\DecideApplication\DecideApplication(
            $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
            $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
            $c->make(\Daems\Application\Backstage\ActivateMember\MemberActivationService::class),
            $c->make(\Daems\Application\Backstage\ActivateSupporter\SupporterActivationService::class),
            $c->make(\Daems\Application\Invite\IssueInvite\IssueInvite::class),
            $c->make(AdminApplicationDismissalRepositoryInterface::class),
            $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
            $c->make(Clock::class),
        ));
        $container->bind(\Daems\Application\Backstage\ListMembers\ListMembers::class, static fn(Container $c) => new \Daems\Application\Backstage\ListMembers\ListMembers(
            $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus::class, static fn(Container $c) => new \Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus(
            $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
            $c->make(AnonymiseAccount::class),
            $c->make(Clock::class),
        ));
        $container->bind(\Daems\Application\Backstage\GetMemberAudit\GetMemberAudit::class, static fn(Container $c) => new \Daems\Application\Backstage\GetMemberAudit\GetMemberAudit(
            $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
        ));

        // Events admin — use cases
        $container->bind(\Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin::class, static fn(Container $c) => new \Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin(
            $c->make(EventRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\CreateEvent\CreateEvent::class, static fn(Container $c) => new \Daems\Application\Backstage\CreateEvent\CreateEvent(
            $c->make(EventRepositoryInterface::class),
            $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\UpdateEvent\UpdateEvent::class, static fn(Container $c) => new \Daems\Application\Backstage\UpdateEvent\UpdateEvent(
            $c->make(EventRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\PublishEvent\PublishEvent::class, static fn(Container $c) => new \Daems\Application\Backstage\PublishEvent\PublishEvent(
            $c->make(EventRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\ArchiveEvent\ArchiveEvent::class, static fn(Container $c) => new \Daems\Application\Backstage\ArchiveEvent\ArchiveEvent(
            $c->make(EventRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations::class, static fn(Container $c) => new \Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations(
            $c->make(EventRepositoryInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent::class, static fn(Container $c) => new \Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent(
            $c->make(EventRepositoryInterface::class),
        ));

        $container->bind(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class, static fn(Container $c) => new \Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin(
            $c->make(ProjectProposalRepositoryInterface::class),
        ));

        // Image storage
        $container->singleton(\Daems\Domain\Storage\ImageStorageInterface::class, fn() => $this->imageStorage);
        $container->bind(\Daems\Application\Backstage\UploadEventImage\UploadEventImage::class, static fn(Container $c) => new \Daems\Application\Backstage\UploadEventImage\UploadEventImage(
            $c->make(EventRepositoryInterface::class),
            $c->make(\Daems\Domain\Storage\ImageStorageInterface::class),
        ));
        $container->bind(\Daems\Application\Backstage\DeleteEventImage\DeleteEventImage::class, static fn(Container $c) => new \Daems\Application\Backstage\DeleteEventImage\DeleteEventImage(
            $c->make(EventRepositoryInterface::class),
            $c->make(\Daems\Domain\Storage\ImageStorageInterface::class),
        ));

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
        $container->bind(ListEventsForLocale::class,
            static fn(Container $c) => new ListEventsForLocale($c->make(EventRepositoryInterface::class)));
        $container->bind(GetEventBySlugForLocale::class,
            static fn(Container $c) => new GetEventBySlugForLocale($c->make(EventRepositoryInterface::class)));
        $container->singleton(EventProposalRepositoryInterface::class,
            static fn() => new InMemoryEventProposalRepository());
        $container->bind(SubmitEventProposal::class,
            static fn(Container $c) => new SubmitEventProposal(
                $c->make(EventProposalRepositoryInterface::class),
                $c->make(UserRepositoryInterface::class),
            ));
        $container->bind(EventController::class, static fn(Container $c) => new EventController(
            $c->make(ListEvents::class),
            $c->make(GetEvent::class),
            $c->make(RegisterForEvent::class),
            $c->make(UnregisterFromEvent::class),
            $c->make(ListEventsForLocale::class),
            $c->make(GetEventBySlugForLocale::class),
            $c->make(SubmitEventProposal::class),
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
            $c->make(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin::class),
            $c->make(\Daems\Application\Backstage\DismissApplication\DismissApplication::class),
            $c->make(\Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin::class),
            $c->make(\Daems\Application\Backstage\CreateEvent\CreateEvent::class),
            $c->make(\Daems\Application\Backstage\UpdateEvent\UpdateEvent::class),
            $c->make(\Daems\Application\Backstage\PublishEvent\PublishEvent::class),
            $c->make(\Daems\Application\Backstage\ArchiveEvent\ArchiveEvent::class),
            $c->make(\Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations::class),
            $c->make(\Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent::class),
            $c->make(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class),
            $c->make(\Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats::class),
            $c->make(\Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats::class),
            $c->make(\Daems\Application\Backstage\Events\ListEventsStats\ListEventsStats::class),
            $c->make(\Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats::class),
            $c->make(\Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations::class),
            $c->make(\Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation::class),
            $c->make(\Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin::class),
            $c->make(\Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposal::class),
            $c->make(\Daems\Application\Backstage\RejectEventProposal\RejectEventProposal::class),
            $c->make(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class),
        ));
        $container->bind(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class,
            static fn(Container $c) => new \Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings(
                $c->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
            ));
        // E2E uses SQL repo directly for public member lookups (no fake needed for read-only path).
        $container->bind(\Daems\Domain\Member\PublicMemberRepositoryInterface::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlPublicMemberRepository(
                $c->make(\Daems\Infrastructure\Framework\Database\Connection::class),
            ));
        $container->bind(\Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile::class,
            static fn(Container $c) => new \Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile(
                $c->make(\Daems\Domain\Member\PublicMemberRepositoryInterface::class),
            ));
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\MemberController::class,
            static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\MemberController(
                $c->make(\Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile::class),
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
        $container->bind(\Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations::class,
            static fn(Container $c) => new \Daems\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations(
                $c->make(EventRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation::class,
            static fn(Container $c) => new \Daems\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation(
                $c->make(EventRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin::class,
            static fn(Container $c) => new \Daems\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin(
                $c->make(EventProposalRepositoryInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposal::class,
            static fn(Container $c) => new \Daems\Application\Backstage\ApproveEventProposal\ApproveEventProposal(
                $c->make(EventProposalRepositoryInterface::class),
                $c->make(EventRepositoryInterface::class),
                $c->make(Clock::class),
                $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
            ));
        $container->bind(\Daems\Application\Backstage\RejectEventProposal\RejectEventProposal::class,
            static fn(Container $c) => new \Daems\Application\Backstage\RejectEventProposal\RejectEventProposal(
                $c->make(EventProposalRepositoryInterface::class),
                $c->make(Clock::class),
            ));
        $container->bind(\Daems\Infrastructure\Adapter\Api\Controller\MediaController::class, static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\MediaController(
            $c->make(\Daems\Application\Backstage\UploadEventImage\UploadEventImage::class),
            $c->make(\Daems\Application\Backstage\DeleteEventImage\DeleteEventImage::class),
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
        $container->bind(LocaleMiddleware::class, static fn() => new LocaleMiddleware());

        // Module bindings (TEST mode — uses bindings.test.php if present, else bindings.php).
        $moduleRegistry->registerBindings($container, \Daems\Infrastructure\Module\ModuleRegistry::TEST);

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
