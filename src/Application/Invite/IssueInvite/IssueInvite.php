<?php

declare(strict_types=1);

namespace Daems\Application\Invite\IssueInvite;

use Daems\Domain\Config\BaseUrlResolverInterface;
use Daems\Domain\Invite\InviteToken;
use Daems\Domain\Invite\TokenGeneratorInterface;
use Daems\Domain\Invite\UserInvite;
use Daems\Domain\Invite\UserInviteRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;

final class IssueInvite
{
    private const TTL_DAYS = 7;

    public function __construct(
        private readonly UserInviteRepositoryInterface $invites,
        private readonly TokenGeneratorInterface $tokens,
        private readonly BaseUrlResolverInterface $urls,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(IssueInviteInput $input): IssueInviteOutput
    {
        $now   = $this->clock->now();
        $raw   = $this->tokens->generate();
        $token = InviteToken::fromRaw($raw);
        $exp   = $now->add(new \DateInterval('P' . self::TTL_DAYS . 'D'));

        $this->invites->save(new UserInvite(
            $this->ids->generate(),
            $input->userId,
            $input->tenantId,
            $token->hash,
            $now,
            $exp,
            null,
        ));

        $url = rtrim($this->urls->resolveFrontendBaseUrl($input->tenantId), '/') . '/invite/' . $raw;

        return new IssueInviteOutput($raw, $url, $exp);
    }
}
