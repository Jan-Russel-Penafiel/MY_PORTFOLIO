<?php

declare(strict_types=1);

function isValidClientGcashNumber(string $value): bool
{
    return preg_match('/^\d{11}$/', $value) === 1;
}
