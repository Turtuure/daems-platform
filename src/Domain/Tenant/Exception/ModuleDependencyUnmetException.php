<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant\Exception;

final class ModuleDependencyUnmetException extends \DomainException
{
    /**
     * @param list<string> $missingDeps
     */
    public static function for(string $moduleSlug, array $missingDeps): self
    {
        $list = implode(', ', $missingDeps);
        return new self(
            "Module '{$moduleSlug}' cannot be enabled: required dependencies not enabled: {$list}"
        );
    }
}
