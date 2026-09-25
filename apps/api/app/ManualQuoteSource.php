<?php

namespace App;

use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RatePlan;
use App\Models\RoomType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ManualQuoteSource
{
    public function resolve(array $selection, DateTimeImmutable $now): ?array
    {
        $validator = Validator::make($selection, [
            'hotel_id' => ['required', 'integer', 'min:1'], 'rate_plan_id' => ['required', 'integer', 'min:1'],
            'arrival' => ['required', 'date_format:Y-m-d'], 'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'adults' => ['required', 'integer', 'between:1,30'],
        ]);
        if ($validator->fails() || array_diff(array_keys($selection), ['hotel_id', 'rate_plan_id', 'arrival', 'departure', 'adults']) !== []) {
            return null;
        }

        return DB::transaction(function () use ($selection, $now): ?array {
            $hotel = Hotel::whereKey($selection['hotel_id'])->lockForUpdate()->first();
            $plan = RatePlan::where('hotel_id', $selection['hotel_id'])->find($selection['rate_plan_id']);
            if (! $hotel || $hotel->discovery_snapshot === null || ! $plan || $plan->status !== 'active' || ! in_array($plan->currency, ['LKR', 'USD'], true)) {
                return null;
            }
            $room = RoomType::where('hotel_id', $hotel->id)->find($plan->room_type_id);
            $pool = InventoryPool::where('hotel_id', $hotel->id)->where('room_type_id', $plan->room_type_id)->first();
            if (! $room || $room->status !== 'active' || ! $pool || $pool->owner !== 'manual' || $pool->sales_state !== 'open'
                || ! in_array($pool->timezone, DateTimeZone::listIdentifiers(), true) || $selection['adults'] > $room->max_occupancy
                || blank($plan->policy['version'] ?? null) || blank($plan->policy['text'] ?? null)) {
                return null;
            }
            $arrival = new DateTimeImmutable($selection['arrival'], new DateTimeZone($pool->timezone));
            $departure = new DateTimeImmutable($selection['departure'], new DateTimeZone($pool->timezone));
            $count = $arrival->diff($departure)->days;
            if ($count < 1 || $count > 30 || $selection['arrival'] < $now->setTimezone(new DateTimeZone($pool->timezone))->format('Y-m-d')) {
                return null;
            }
            $stock = DB::table('inventory_nights')->where('inventory_pool_id', $pool->id)->whereBetween('stay_date', [$selection['arrival'], $selection['departure']])->get()->keyBy('stay_date');
            $rates = DB::table('rate_plan_nights')->where('rate_plan_id', $plan->id)->whereBetween('stay_date', [$selection['arrival'], $selection['departure']])->get()->keyBy('stay_date');
            // Departure restrictions belong to the checkout date, not the final occupied night.
            $checkout = $rates[$selection['departure']] ?? null;
            if (! $checkout || $checkout->closed_to_departure) {
                return null;
            }
            $nightly = [];
            $conditions = [];
            $versions = [];
            for ($date = $arrival; $date < $departure; $date = $date->modify('+1 day')) {
                $key = $date->format('Y-m-d');
                $inventory = $stock[$key] ?? null;
                $rate = $rates[$key] ?? null;
                if (! $inventory || ! $rate || ! $rate->mandatory_charges_complete || $rate->stop_sell
                    || $inventory->capacity - $inventory->held - $inventory->sold < 1
                    || $inventory->held < 0 || $inventory->sold < 0 || $rate->min_stay > $count || $rate->max_stay < $count
                    || ($key === $selection['arrival'] && $rate->closed_to_arrival)) {
                    return null;
                }
                foreach (['base_minor', 'tax_minor', 'fee_minor'] as $field) {
                    if ($rate->{$field} === null || $rate->{$field} < 0 || $rate->{$field} > 1000000000) {
                        return null;
                    }
                }
                $nightly[] = ['stay_date' => $key, 'base_minor' => (int) $rate->base_minor, 'tax_minor' => (int) $rate->tax_minor,
                    'fee_minor' => (int) $rate->fee_minor, 'mandatory_charges_complete' => true];
                $conditions[] = ['stay_date' => $key, 'available' => (int) ($inventory->capacity - $inventory->held - $inventory->sold), 'stop_sell' => false, 'restrictions_passed' => true];
                $versions[] = ['stay_date' => $key, 'inventory_version' => (int) $inventory->version, 'rate_version' => (int) $rate->version];
            }
            $input = ['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'rate_plan_id' => $plan->id,
                'timezone' => $pool->timezone, 'currency' => $plan->currency, 'meal_plan' => $plan->meal_plan, 'inventory_mode' => 'manual', 'quantity' => 1,
                'adults' => (int) $selection['adults'], 'max_adults' => $room->max_occupancy, 'children_ages' => [],
                'arrival' => $selection['arrival'], 'departure' => $selection['departure'],
                'policy' => ['version' => (string) $plan->policy['version'], 'text' => $plan->policy['text']],
                'nightly' => $nightly, 'nightly_conditions' => $conditions];
            $revision = ['schema_version' => 1, 'discovery_version' => $hotel->discovery_version, 'room_version' => $room->version,
                'inventory_pool_id' => $pool->id, 'pool_version' => $pool->version, 'ownership_version' => $pool->ownership_version,
                'rate_plan_version' => $plan->version, 'nights' => $versions, 'checkout_rate_version' => (int) $checkout->version];
            $revision['fingerprint'] = hash('sha256', json_encode(['input' => $input, 'revision' => $revision], JSON_THROW_ON_ERROR));

            return ['source' => 'manual', 'expires_at' => $now->modify('+60 seconds'), 'input' => $input, 'source_revision' => $revision];
        });
    }
}
