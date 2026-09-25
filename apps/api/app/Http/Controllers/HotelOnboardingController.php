<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveOnboardingRequest;
use App\Models\Hotel;
use App\Models\HotelPhoto;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class HotelOnboardingController extends Controller
{
    private const PROFILE = ['name', 'city', 'country', 'address', 'contact_email', 'phone', 'description'];

    public function show(Hotel $hotel): JsonResponse
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('onboard', $hotel);

        return $this->state($hotel);
    }

    public function update(SaveOnboardingRequest $request, Hotel $hotel): JsonResponse
    {
        return DB::transaction(function () use ($request, $hotel) {
            $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('onboard', $hotel);
            $data = $request->validated();
            abort_if($hotel->onboarding_version !== $data['version'], 409, 'This draft changed in another window. Copy any unsaved text, then reload to use the latest version.');
            $fields = $data['fields'] ?? [];
            abort_if(array_key_exists('rooms', $fields) && DB::table('catalog_conversions')->where('hotel_id', $hotel->id)->exists(), 409, 'Use catalog room IDs after conversion; other wizard fields remain editable.');
            $hotel->fill(array_intersect_key($fields, array_flip(self::PROFILE)));
            $hotel->onboarding_data = array_replace($hotel->onboarding_data ?? [], array_diff_key($fields, array_flip(self::PROFILE)));
            $hotel->onboarding_step = $data['step'] ?? $hotel->onboarding_step;
            $hotel->onboarding_version++;
            $hotel->save();
            $hotel->recordAccessEvent($request->user(), 'onboarding.saved');

            return $this->state($hotel);
        });
    }

    private function state(Hotel $hotel): JsonResponse
    {
        $fields = array_replace(['property_type' => null, 'amenities' => [], 'rooms' => [], 'inventory_request' => null, 'check_in' => null, 'check_out' => null, 'cancellation_policy' => null, 'guest_rules' => null], $hotel->onboarding_data ?? [], $hotel->only(self::PROFILE));
        $missing = [];
        foreach (['name' => 'Hotel name', 'property_type' => 'Property type', 'address' => 'Street address', 'city' => 'City', 'contact_email' => 'Contact email', 'phone' => 'Phone number', 'description' => 'Hotel description', 'check_in' => 'Check-in time', 'check_out' => 'Check-out time', 'cancellation_policy' => 'Cancellation policy'] as $field => $label) {
            if (blank($fields[$field])) {
                $missing[] = $label;
            }
        }
        $rooms = $fields['rooms'];
        $catalogRooms = RoomType::where('hotel_id', $hotel->id)->withCount('photos')->get();
        if ($catalogRooms->isEmpty()) {
            if (count($rooms) === 0 || collect($rooms)->contains(fn (array $room) => blank($room['name'] ?? null) || empty($room['occupancy']) || empty($room['quantity']))) {
                $missing[] = 'Room names, occupancy and quantities';
            }
        } elseif ($catalogRooms->contains(fn (RoomType $room) => $room->photos_count === 0)) {
            $missing[] = 'Room-specific photographs';
        }
        if (blank($fields['inventory_request']) || $fields['inventory_request'] === 'undecided') {
            $missing[] = 'Availability setup preference';
        }
        if ($fields['inventory_request'] === 'manual') {
            if ($catalogRooms->isEmpty()) {
                if (count($rooms) === 0 || collect($rooms)->contains(fn (array $room) => ! isset($room['rate']) || $room['rate'] <= 0)) {
                    $missing[] = 'Indicative room rates';
                }
            } else {
                foreach ($catalogRooms as $room) {
                    $plans = RatePlan::where('room_type_id', $room->id)->whereNotNull('meal_plan')->where('status', '!=', 'archived')->get();
                    if ($plans->isEmpty() || $plans->contains(fn (RatePlan $plan) => ! DB::table('rate_plan_nights')->where('rate_plan_id', $plan->id)->where('mandatory_charges_complete', true)->exists())) {
                        $missing[] = 'Meal plans and complete dated rates';
                        break;
                    }
                }
            }
        }
        if (! $hotel->users()->wherePivot('role', 'hotel_manager')->exists()) {
            $missing[] = 'A hotel manager';
        }
        if (! HotelPhoto::where('hotel_id', $hotel->id)->where('gallery', 'property')->exists()) {
            $missing[] = 'Hotel photographs';
        }

        $conversion = DB::table('catalog_conversions')->where('hotel_id', $hotel->id)->first();

        return response()->json(['catalog_room_count' => $catalogRooms->count(), 'catalog_conversion' => $conversion ? ['id' => $conversion->id, 'rooms' => json_decode($conversion->rooms, true, flags: JSON_THROW_ON_ERROR)] : null, 'hotel_id' => $hotel->id, 'step' => $hotel->onboarding_step, 'version' => $hotel->onboarding_version, 'fields' => $fields, 'missing' => $missing, 'can_publish' => false, 'launch_requirements' => ['Room photographs and complete listing review', 'Verified rooms, rate plans and dated availability', 'Booking and payment setup']]);
    }
}
