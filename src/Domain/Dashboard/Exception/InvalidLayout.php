<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard\Exception;

final class InvalidLayout extends \DomainException
{
    public static function unknownWidget(string $id): self    { return new self("Unknown widget: {$id}"); }
    public static function aboveRole(string $id): self        { return new self("Widget '{$id}' requires higher role"); }
    public static function disabledModule(string $id, string $module): self { return new self("Widget '{$id}' requires module '{$module}' which is not enabled"); }
    public static function invalidSpan(string $id, int $span): self { return new self("Widget '{$id}' has invalid span {$span}"); }
    public static function duplicateWidget(string $id): self  { return new self("Widget '{$id}' appears twice in layout"); }
    public static function malformedEntry(): self             { return new self('Layout entry missing widget_id or span'); }
}
