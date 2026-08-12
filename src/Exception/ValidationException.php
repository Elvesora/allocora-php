<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Exception;

final class ValidationException extends ApiException
{
    /**
     * Raw validation response details are intentionally discarded because
     * upstream values are not a trusted public information boundary.
     *
     * @return array{}
     */
    public function errors(): array
    {
        return [];
    }
}
