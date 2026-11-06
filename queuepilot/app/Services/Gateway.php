<?php
declare(strict_types=1);

namespace App\Services;

interface Gateway
{
    /**
     * @return string 'sent' when a provider accepted it, 'logged' when the log
     *                transport recorded it instead of sending. Both are
     *                deliveries of a sort, but only 'sent' left the building, so
     *                the caller must be able to tell them apart. Failures throw.
     */
    public function send(string $to, string $body): string;
}
