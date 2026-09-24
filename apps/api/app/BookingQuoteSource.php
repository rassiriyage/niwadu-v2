<?php

namespace App;

use DateTimeImmutable;

interface BookingQuoteSource
{
    /**
     * A real source must resolve public eligibility, catalog identity, ownership and freshness.
     * No source can infer these guarantees from onboarding drafts or a price alone.
     *
     * @param  array{hotel_id:int,rate_plan_id:int,arrival:string,departure:string,adults:int}  $selection
     * @return array{source:string,expires_at:DateTimeImmutable,input:array<string,mixed>}|null
     */
    public function resolve(array $selection, DateTimeImmutable $now): ?array;
}
