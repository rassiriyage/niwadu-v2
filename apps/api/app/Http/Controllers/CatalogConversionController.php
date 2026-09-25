<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CatalogConversionController extends Controller
{
    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        return DB::transaction(function () use ($request, $hotel): JsonResponse {
            $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('view', $hotel);
            Gate::authorize('onboard', $hotel);
            if (array_diff(array_keys($request->all()), ['onboarding_version', '_token']) !== []) {
                throw ValidationException::withMessages(['input' => 'Only the reviewed onboarding version is allowed.']);
            }
            $data = $request->validate(['onboarding_version' => ['required', 'integer', 'min:0']]);
            $existing = DB::table('catalog_conversions')->where('hotel_id', $hotel->id)->first();
            if ($existing) {
                abort_if((int) $existing->onboarding_version !== (int) $data['onboarding_version'], 409, 'Draft rooms were already converted. Load the existing catalog rooms.');

                return response()->json(['data' => ['id' => $existing->id, 'rooms' => json_decode($existing->rooms, true, flags: JSON_THROW_ON_ERROR)]]);
            }
            abort_if($hotel->onboarding_version !== (int) $data['onboarding_version'], 409, 'Draft changed. Review it again before conversion.');
            abort_if(RoomType::where('hotel_id', $hotel->id)->exists(), 409, 'Existing catalog rooms require reconciliation before converting draft rooms.');
            $draft = $hotel->onboarding_data['rooms'] ?? [];
            validator(['rooms' => $draft], ['rooms' => ['required', 'array', 'list', 'min:1', 'max:50'],
                'rooms.*.name' => ['required', 'string', 'max:255'], 'rooms.*.occupancy' => ['required', 'integer', 'between:1,30']])->validate();
            $rooms = [];
            foreach ($draft as $index => $source) {
                $room = new RoomType;
                $room->forceFill(['hotel_id' => $hotel->id, 'name' => $source['name'], 'max_occupancy' => $source['occupancy'], 'status' => 'draft', 'version' => 1])->save();
                $rooms[] = ['draft_index' => $index, 'room_type_id' => $room->id];
            }
            $id = DB::table('catalog_conversions')->insertGetId(['hotel_id' => $hotel->id, 'onboarding_version' => $hotel->onboarding_version,
                'actor_id' => $request->user()->id, 'source_snapshot' => json_encode($draft, JSON_THROW_ON_ERROR), 'rooms' => json_encode($rooms, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $hotel->onboarding_version++;
            $hotel->save();
            $hotel->recordAccessEvent($request->user(), 'catalog.draft_rooms_converted');

            return response()->json(['data' => ['id' => $id, 'rooms' => $rooms]], 201);
        });
    }
}
