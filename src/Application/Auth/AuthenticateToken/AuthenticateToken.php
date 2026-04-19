<?php

declare(strict_types=1);

namespace Daems\Application\Auth\AuthenticateToken;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use Throwable;

final class AuthenticateToken
{
    public function __construct(
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly UserRepositoryInterface $users,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly int $ttlDays = 7,
        private readonly int $hardCapDays = 30,
    ) {}

    public function execute(AuthenticateTokenInput $input): AuthenticateTokenOutput
    {
        $hash = hash('sha256', $input->rawToken);
        $token = $this->tokens->findByHash($hash);
        if ($token === null) {
            return AuthenticateTokenOutput::failure('token-not-found');
        }

        $now = $this->clock->now();
        if (!$token->isValidAt($now)) {
            return AuthenticateTokenOutput::failure('token-invalid');
        }

        $user = $this->users->findById($token->userId()->value());
        if ($user === null) {
            return AuthenticateTokenOutput::failure('user-not-found');
        }

        $slidingExpiry = $now->modify("+{$this->ttlDays} days");
        $hardCap = $token->issuedAt()->modify("+{$this->hardCapDays} days");
        $newExpiry = $slidingExpiry < $hardCap ? $slidingExpiry : $hardCap;

        // Sliding expiry persistence is best-effort. If the UPDATE fails
        // (transient DB hiccup, deadlock), the auth decision is already made
        // from valid in-memory state — we log and let the caller proceed.
        // Alternative (propagate the exception) turns a valid authenticated
        // request into a 500, which is a worse operator experience.
        try {
            $this->tokens->touchLastUsed($token->id(), $now, $newExpiry);
        } catch (Throwable $e) {
            $this->logger->error('touchLastUsed failed', ['exception' => $e, 'token_id' => $token->id()->value()]);
        }

        // TEMP: PR 2 Task 18 will wire activeTenant and roleInActiveTenant properly.
        return AuthenticateTokenOutput::success(
            new ActingUser(
                id:                 $user->id(),
                email:              $user->email(),
                isPlatformAdmin:    $user->isPlatformAdmin(),
                activeTenant:       TenantId::fromString('01958000-0000-7000-8000-000000000001'),
                roleInActiveTenant: null,
            ),
            $token->id(),
        );
    }
}
