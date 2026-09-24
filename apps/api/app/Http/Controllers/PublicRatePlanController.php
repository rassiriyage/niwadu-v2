<?php

namespace App\Http\Controllers;

use App\ManualQuoteSource;
use App\Models\Hotel;
use App\Models\RatePlan;
use App\Models\RoomType;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicRatePlanController extends Controller
{
    public function index(Request $request, string $slug, ManualQuoteSource $source): JsonResponse
    {
        $seen = [];
        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            $key = urldecode(explode('=', $pair, 2)[0]);
            abort_if(! in_array($key, ['arrival', 'departure', 'adults'], true) || isset($seen[$key]), 422, 'Unsupported or repeated selection parameter.');
            $seen[$key] = true;
        }
        $selection = $request->validate([
            'arrival' => ['required', 'date_format:Y-m-d'], 'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'adults' => ['required', 'integer', 'between:1,30'],
        ]);
        abort_if(array_diff(array_keys($request->query()), ['arrival', 'departure', 'adults']) !== [], 422, 'Unsupported search fields.');
        abort_if((new DateTimeImmutable($selection['arrival']))->diff(new DateTimeImmutable($selection['departure']))->days > 30, 422, 'Stay at most 30 nights.');

        return DB::transaction(function () use ($slug, $selection, $source): JsonResponse {
            $hotel = Hotel::where('discovery_slug', $slug)->whereNotNull('discovery_snapshot')->lockForUpdate()->firstOrFail();
            $offers = [];
            $now = DateTimeImmutable::createFromInterface(now());
            foreach (RatePlan::where('hotel_id', $hotel->id)->where('status', 'active')->orderBy('id')->get() as $plan) {
                $quote = $source->resolve($selection + ['hotel_id' => $hotel->id, 'rate_plan_id' => $plan->id], $now);
                if ($quote === null) {
                    continue;
                }
                $room = RoomType::findOrFail($plan->room_type_id);
                $total = array_sum(array_map(fn ($night) => $night['base_minor'] + $night['tax_minor'] + $night['fee_minor'], $quote['input']['nightly']));
                $offers[] = ['rate_plan_id' => $plan->id, 'name' => $plan->name, 'room_type_id' => $room->id, 'room_name' => $room->name,
                    'max_adults' => $room->max_occupancy, 'currency' => 'LKR', 'total_minor' => $total, 'policy' => $quote['input']['policy'],
                    'expires_at' => $quote['expires_at']->format(DATE_ATOM)];
            }

            return response()->json(['data' => $offers, 'meta' => ['inventory_reserved' => false, 'selection' => $selection]]);
        });
    }
}
