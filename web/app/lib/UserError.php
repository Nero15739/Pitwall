<?php
declare(strict_types=1);

namespace PitWall;

/** A problem with what the user sent. Its message is safe to show them as-is. */
final class UserError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
