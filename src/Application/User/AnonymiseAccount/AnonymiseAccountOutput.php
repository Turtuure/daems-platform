<?php

declare(strict_types=1);

namespace Daems\Application\User\AnonymiseAccount;

final class AnonymiseAccountOutput
{
    public function __construct(
        public readonly bool $success,
    ) {}
}
