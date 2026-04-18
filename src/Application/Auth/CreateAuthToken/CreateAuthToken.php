<?php

declare(strict_types=1);

namespace Daems\Application\Auth\CreateAuthToken;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;

final class CreateAuthToken
{
    public function __construct(
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly Clock $clock,
    ) {}

    public function execute(CreateAuthTokenInput $input): CreateAuthTokenOutput
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $raw);

        $now = $this->clock->now();
        $expiresAt = $now->modify('+7 days');

        $id = AuthTokenId::generate();
        $this->tokens->store(new AuthToken(
            $id,
            $hash,
            $input->userId,
            $now,
            $now,
            $expiresAt,
            null,
            $input->userAgent,
            $input->ip,
        ));

        return new CreateAuthTokenOutput($id, $raw, $expiresAt);
    }
}
