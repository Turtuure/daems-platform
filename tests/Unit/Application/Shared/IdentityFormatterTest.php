<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Shared;

use Daems\Application\Shared\IdentityFormatter;
use PHPUnit\Framework\TestCase;

final class IdentityFormatterTest extends TestCase
{
    public function test_returns_question_marks_for_empty_name(): void
    {
        self::assertSame('??', IdentityFormatter::initials(''));
        self::assertSame('??', IdentityFormatter::initials('   '));
    }

    public function test_returns_first_letter_for_single_word(): void
    {
        self::assertSame('K', IdentityFormatter::initials('Kalle'));
    }

    public function test_returns_first_letters_of_first_two_words(): void
    {
        self::assertSame('KM', IdentityFormatter::initials('Kalle Mäkinen'));
        self::assertSame('JD', IdentityFormatter::initials('John Doe'));
    }

    public function test_caps_at_two_letters_for_three_word_name(): void
    {
        self::assertSame('JM', IdentityFormatter::initials('John Michael Doe'));
    }

    public function test_uppercases_lowercase_input(): void
    {
        self::assertSame('AB', IdentityFormatter::initials('alice bob'));
    }

    public function test_collapses_extra_whitespace(): void
    {
        self::assertSame('AB', IdentityFormatter::initials('  alice    bob  '));
    }
}
