<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly int $clientId,
        public readonly string $clientCode,
        public readonly float $balance,
        public readonly float $requested,
    ) {
        parent::__construct(sprintf(
            'Insufficient wallet balance for %s: has %.2f XAF, needs %.2f XAF.',
            $clientCode,
            $balance,
            $requested
        ));
    }
}
