<?php

declare(strict_types=1);

namespace Verdant\Media;

interface MediaBindingLookup
{
    public function bindingForTarget(string $targetKey, string $kind, bool $lock = false): ?array;
}
