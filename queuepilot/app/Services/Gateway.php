<?php
declare(strict_types=1);

namespace App\Services;

interface Gateway
{
    /** @return string 'sent' | 'simulated' */
    public function send(string $to, string $body): string;
}
