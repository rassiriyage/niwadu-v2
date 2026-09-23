<?php

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ManualQuoteCalculator
{
    /**
     * Pure calculation over synthetic, complete inputs; not a catalog or inventory guarantee.
     * Nightly amounts cover this room and adult occupancy; zero charges must be explicit.
     * Catalog must resolve availability, restrictions, relationships and freshness before use.
     * Expiry is supplied by the caller, not a hold or a checkout timing policy.
     *
     * @param  array{hotel_id:int,room_type_id:int,rate_plan_id:int,timezone:string,currency:string,inventory_mode:string,quantity:int,adults:int,max_adults:int,children_ages:array,arrival:string,departure:string,policy:array{version:string,text:string},nightly:list<array{stay_date:string,base_minor:int,tax_minor:int,fee_minor:int,mandatory_charges_complete:bool}>}  $input
     * @return array<string, mixed>
     */
    public function calculate(array $input, DateTimeImmutable $now, DateTimeImmutable $expiresAt): array
    {
        $this->keys($input, ['hotel_id', 'room_type_id', 'rate_plan_id', 'timezone', 'currency', 'inventory_mode', 'quantity', 'adults', 'max_adults', 'children_ages', 'arrival', 'departure', 'policy', 'nightly']);
        foreach (['hotel_id', 'room_type_id', 'rate_plan_id', 'adults', 'max_adults'] as $field) {
            $this->require(is_int($input[$field]) && $input[$field] > 0, "Invalid $field.");
        }
        $this->require($input['quantity'] === 1 && $input['children_ages'] === [] && $input['adults'] <= $input['max_adults'], 'Only one room with supported adult occupancy is allowed.');
        $this->require($input['currency'] === 'LKR' && $input['inventory_mode'] === 'manual', 'Only manual LKR inputs are supported.');
        $this->require(is_string($input['timezone']) && in_array($input['timezone'], DateTimeZone::listIdentifiers(), true), 'Invalid hotel timezone.');
        $timezone = new DateTimeZone($input['timezone']);
        $arrival = $this->date($input['arrival'], $timezone);
        $departure = $this->date($input['departure'], $timezone);
        $nights = (int) $arrival->diff($departure)->format('%r%a');
        $this->require($nights >= 1 && $nights <= 30, 'Stay must be 1–30 hotel-local nights.');
        $this->require($input['arrival'] >= $now->setTimezone($timezone)->format('Y-m-d'), 'Arrival is in the past at the hotel.');
        $this->require($expiresAt > $now, 'Quote inputs have expired.');
        $this->require(is_array($input['policy']), 'A complete policy is required.');
        $this->keys($input['policy'], ['version', 'text']);
        foreach (['version', 'text'] as $field) {
            $this->require(is_string($input['policy'][$field]) && trim($input['policy'][$field]) !== '', "Missing policy $field.");
        }
        $this->require(is_array($input['nightly']) && array_is_list($input['nightly']) && count($input['nightly']) === $nights, 'Every stay night must be supplied exactly once in date order.');

        $total = 0;
        $lines = [];
        foreach ($input['nightly'] as $offset => $night) {
            $this->require(is_array($night), 'Invalid nightly input.');
            $this->keys($night, ['stay_date', 'base_minor', 'tax_minor', 'fee_minor', 'mandatory_charges_complete']);
            $this->require($night['stay_date'] === $arrival->modify("+$offset days")->format('Y-m-d'), 'Missing or out-of-order stay night.');
            $this->require($night['mandatory_charges_complete'] === true, 'Mandatory charges must be complete for every night.');
            $lineTotal = 0;
            foreach (['base_minor', 'tax_minor', 'fee_minor'] as $field) {
                $amount = $night[$field];
                $this->require(is_int($amount) && $amount >= 0, "Missing or invalid $field.");
                $lineTotal = $this->add($lineTotal, $amount);
            }
            $total = $this->add($total, $lineTotal);
            $lines[] = [
                'stay_date' => $night['stay_date'], 'base_minor' => $night['base_minor'],
                'tax_minor' => $night['tax_minor'], 'fee_minor' => $night['fee_minor'], 'total_minor' => $lineTotal, 'mandatory_charges_complete' => true,
            ];
        }

        return [
            'hotel_id' => $input['hotel_id'], 'room_type_id' => $input['room_type_id'], 'rate_plan_id' => $input['rate_plan_id'],
            'arrival' => $input['arrival'], 'departure' => $input['departure'], 'timezone' => $input['timezone'],
            'quantity' => 1, 'adults' => $input['adults'], 'currency' => 'LKR',
            'nightly' => $lines, 'total_minor' => $total, 'policy' => $input['policy'],
            'quoted_at' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_RFC3339),
            'expires_at' => $expiresAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_RFC3339),
        ];
    }

    private function date(mixed $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $this->require(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1, 'Invalid local date.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $this->require($date !== false && $date->format('Y-m-d') === $value, 'Invalid local date.');

        return $date;
    }

    /** @param array<string, mixed> $input
     * @param  list<string>  $keys
     */
    private function keys(array $input, array $keys): void
    {
        $this->require(array_diff($keys, array_keys($input)) === [] && array_diff(array_keys($input), $keys) === [], 'Missing or unexpected input fields.');
    }

    private function add(int $total, int $amount): int
    {
        $this->require($amount <= PHP_INT_MAX - $total, 'Money total exceeds integer range.');

        return $total + $amount;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
