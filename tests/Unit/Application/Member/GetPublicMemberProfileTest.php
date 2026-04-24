<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Member;

use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfileInput;
use Daems\Domain\Member\PublicMemberProfile;
use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use PHPUnit\Framework\TestCase;

final class GetPublicMemberProfileTest extends TestCase
{
    public function test_returns_profile_for_known_number(): void
    {
        $repo = $this->fakeRepoWith('123', 'Sam', 'DAEMS', true, '/avatars/sam.webp');
        $uc = new GetPublicMemberProfile($repo);
        $out = $uc->execute(new GetPublicMemberProfileInput('123'));

        self::assertSame('Sam', $out->name);
        self::assertSame('DAEMS', $out->tenantMemberNumberPrefix);
        self::assertSame('/avatars/sam.webp', $out->avatarUrl);
        self::assertTrue($out->publicAvatarVisible);
    }

    public function test_returns_null_avatar_when_visibility_off(): void
    {
        $repo = $this->fakeRepoWith('456', 'Juma', null, false, null);
        $uc = new GetPublicMemberProfile($repo);
        $out = $uc->execute(new GetPublicMemberProfileInput('456'));

        self::assertFalse($out->publicAvatarVisible);
        self::assertNull($out->avatarUrl);
    }

    public function test_throws_not_found_for_unknown_number(): void
    {
        $repo = new class implements PublicMemberRepositoryInterface {
            public function findByMemberNumber(string $n): ?PublicMemberProfile { return null; }
        };
        $uc = new GetPublicMemberProfile($repo);
        $this->expectException(NotFoundException::class);
        $uc->execute(new GetPublicMemberProfileInput('999'));
    }

    private function fakeRepoWith(string $number, string $name, ?string $prefix, bool $avatarVisible, ?string $avatarUrl): PublicMemberRepositoryInterface
    {
        $profile = new PublicMemberProfile(
            memberNumberRaw: $number,
            name: $name,
            memberType: 'basic',
            role: 'member',
            joinedAt: '2024-06-11',
            tenantSlug: 'daems',
            tenantName: 'Daems',
            tenantMemberNumberPrefix: $prefix,
            publicAvatarVisible: $avatarVisible,
            avatarInitials: strtoupper(substr($name, 0, 2)),
            avatarUrl: $avatarUrl,
        );
        return new class ($profile) implements PublicMemberRepositoryInterface {
            public function __construct(private readonly PublicMemberProfile $p) {}
            public function findByMemberNumber(string $n): ?PublicMemberProfile {
                return $n === $this->p->memberNumberRaw ? $this->p : null;
            }
        };
    }
}
