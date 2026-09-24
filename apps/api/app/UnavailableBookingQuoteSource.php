<?php

namespace App;

use DateTimeImmutable;

final class UnavailableBookingQuoteSource implements BookingQuoteSource
{
    public function resolve(array $selection, DateTimeImmutable $now): ?array
    {
        return null;
    }
}
