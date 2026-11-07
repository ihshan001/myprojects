<?php
declare(strict_types=1);

namespace App\Domain\Queue;

final class InvalidTransition extends \RuntimeException
{
    public function __construct(string $message, public readonly string $from, public readonly string $to)
    {
        parent::__construct($message);
    }
}
