<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomTypeResource;
use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RatePlan;
use App\Models\RoomType;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManualCatalogController extends Controller
{
    public function saveRoom(Request $request, Hotel $hotel, ?int $room = null): JsonResponse
    {
        return DB::transaction(function () use ($request, $hotel, $room) {
            $hotel = $this->authorizeHotel($hotel, 'update');
            $data = $this->validateInput($request, [
                'client_key' => ['sometimes', 'required', 'uuid'],
                'name' => ['required', 'string', 'max:255'], 'max_occupancy' => ['required', 'integer', 'between:1,30'],
                'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            ], $room !== null);
            if (isset($data['client_key'])) {
                $data['client_key'] = strtolower($data['client_key']);
            }
            $creation = ['name' => $data['name'], 'max_occupancy' => (int) $data['max_occupancy'], 'status' => $data['status']];
            $fingerprint = hash('sha256', json_encode($creation, JSON_THROW_ON_ERROR));
            if ($room === null && isset($data['client_key'])) {
                $existing = RoomType::where('hotel_id', $hotel->id)->where('client_key', $data['client_key'])->first();
                if ($existing) {
                    abort_if($existing->creation_fingerprint !== $fingerprint, 409, 'This creation key was already used with different room details.');

                    return (new RoomTypeResource($existing->load('photos')))->response();
                }
            }
            $model = $room === null ? new RoomType : $this->room($hotel, $room);
            if ($room !== null && isset($data['client_key'])) {
                abort_if($data['client_key'] !== $model->client_key, 409, 'Room creation key cannot change.');
            }
            if ($room === null && isset($data['client_key'])) {
                $data['creation_fingerprint'] = $fingerprint;
            }
            if ($room !== null) {
                $this->version($model->version, $data);
            }
            unset($data['version']);
            $model->forceFill($data + ['hotel_id' => $hotel->id, 'version' => ($model->version ?? 0) + 1])->save();
            $hotel->recordAccessEvent($request->user(), 'catalog.room_saved');

            return (new RoomTypeResource($model->load('photos')))->response()->setStatusCode($room === null ? 201 : 200);
        });
    }

    public function pool(Request $request, Hotel $hotel, int $room): JsonResponse
    {
        return DB::transaction(function () use ($request, $hotel, $room) {
            $hotel = $this->authorizeHotel($hotel, 'releaseDiscovery');
            $this->room($hotel, $room);
            $data = $this->validateInput($request, [
                'owner' => ['required', Rule::in(['manual'])], 'sales_state' => ['required', Rule::in(['open', 'closed'])],
                'timezone' => ['required', 'timezone'],
            ]);
            $pool = InventoryPool::where('room_type_id', $room)->first() ?? new InventoryPool;
            $this->version($pool->version ?? 0, $data);
            abort_if($pool->exists && ! in_array($pool->owner, ['unconfigured', 'manual'], true), 409, 'Inventory ownership cannot be switched here.');
            abort_if($pool->exists && $pool->timezone !== $data['timezone'] && DB::table('inventory_nights')->where('inventory_pool_id', $pool->id)->where(fn ($query) => $query->where('held', '>', 0)->orWhere('sold', '>', 0))->exists(), 409, 'Timezone cannot change while inventory has obligations.');
            $ownershipVersion = ($pool->ownership_version ?? 0) + ($pool->owner === 'manual' ? 0 : 1);
            unset($data['version']);
            $pool->forceFill($data + ['hotel_id' => $hotel->id, 'room_type_id' => $room,
                'version' => ($pool->version ?? 0) + 1, 'ownership_version' => $ownershipVersion])->save();
            $hotel->recordAccessEvent($request->user(), 'inventory.pool_saved');

            return response()->json(['data' => $pool]);
        });
    }

    public function plans(Hotel $hotel, int $room): JsonResponse
    {
        Gate::authorize('view', $hotel);
        $this->room($hotel, $room);

        return response()->json(['data' => RatePlan::where('room_type_id', $room)->orderBy('id')->get(),
            'inventory_pool' => InventoryPool::where('room_type_id', $room)->first()]);
    }

    public function savePlan(Request $request, Hotel $hotel, int $room, ?int $plan = null): JsonResponse
    {
        return DB::transaction(function () use ($request, $hotel, $room, $plan) {
            $hotel = $this->authorizeHotel($hotel, 'authorRates');
            $this->room($hotel, $room);
            $this->rateAuthoringPool($hotel, $room);
            $data = $this->validateInput($request, [
                'name' => ['required', 'string', 'max:255'], 'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
                'meal_plan' => ['sometimes', 'nullable', Rule::in(['RO', 'BB', 'HB', 'FB'])],
                'currency' => ['required', Rule::in(['LKR', 'USD'])], 'policy' => ['required_if:status,active', 'array:version,text'],
                'policy.version' => ['nullable', 'required_if:status,active', 'string', 'max:100'], 'policy.text' => ['nullable', 'required_if:status,active', 'string', 'max:10000'],
            ], $plan !== null);
            $model = $plan === null ? new RatePlan : $this->plan($hotel, $room, $plan);
            if (array_key_exists('policy', $data) || ! $model->exists) {
                $data['policy'] = array_replace(['version' => null, 'text' => null], $data['policy'] ?? []);
            }
            if ($plan !== null) {
                $this->version($model->version, $data);
                abort_if($model->currency !== $data['currency'] || (array_key_exists('meal_plan', $data) && $model->meal_plan !== $data['meal_plan']), 409, 'Currency and meal plan cannot change. Create a separate offer.');
            }
            $meal = $data['meal_plan'] ?? $model->meal_plan;
            if ($meal !== null) {
                abort_if(RatePlan::where('room_type_id', $room)->where('meal_plan', $meal)->where('currency', $data['currency'])->when($model->exists, fn ($query) => $query->where('id', '!=', $model->id))->exists(), 409, 'This room already has that meal plan and currency.');
            }
            if (Gate::denies('manageInventory', $hotel)) {
                abort_if($data['status'] !== 'draft' || ($model->exists && $model->status !== 'draft'), 403, 'Onboarding staff may author draft offers only.');
            }
            if ($data['status'] === 'active') {
                $this->manualPool($room);
            }
            unset($data['version']);
            $model->forceFill($data + ['hotel_id' => $hotel->id, 'room_type_id' => $room, 'version' => ($model->version ?? 0) + 1])->save();
            $hotel->recordAccessEvent($request->user(), 'inventory.rate_plan_saved');

            return response()->json(['data' => $model], $plan === null ? 201 : 200);
        });
    }

    public function saveStock(Request $request, Hotel $hotel, int $room, string $date): JsonResponse
    {
        return $this->saveNight($request, $hotel, $room, $date);
    }

    public function saveRate(Request $request, Hotel $hotel, int $room, int $plan, string $date): JsonResponse
    {
        return $this->saveNight($request, $hotel, $room, $date, $plan);
    }

    private function saveNight(Request $request, Hotel $hotel, int $room, string $date, ?int $plan = null): JsonResponse
    {
        return DB::transaction(function () use ($request, $hotel, $room, $date, $plan) {
            $hotel = $this->authorizeHotel($hotel, $plan === null ? 'manageInventory' : 'authorRates');
            $this->room($hotel, $room);
            $pool = $plan === null ? $this->manualPool($room) : $this->rateAuthoringPool($hotel, $room);
            validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01']])->validate();
            $rules = ['capacity' => ['required', 'integer', 'between:0,100000']];
            if ($plan !== null) {
                $offering = $this->plan($hotel, $room, $plan);
                abort_if(Gate::denies('manageInventory', $hotel) && $offering->status !== 'draft', 403, 'Onboarding staff may author draft offers only.');
                $rules = [];
                foreach (['base_minor', 'tax_minor', 'fee_minor'] as $key) {
                    $rules[$key] = ['present', 'nullable', 'required_if:mandatory_charges_complete,true', 'integer', 'between:0,1000000000'];
                }
                foreach (['mandatory_charges_complete', 'stop_sell', 'closed_to_arrival', 'closed_to_departure'] as $key) {
                    $rules[$key] = ['required', 'boolean'];
                }
                $rules['min_stay'] = ['required', 'integer', 'between:1,30'];
                $rules['max_stay'] = ['required', 'integer', 'between:1,30', 'gte:min_stay'];
            }
            $data = $this->validateInput($request, $rules);
            $table = $plan === null ? 'inventory_nights' : 'rate_plan_nights';
            $identity = [$plan === null ? 'inventory_pool_id' : 'rate_plan_id' => $plan ?? $pool->id, 'stay_date' => $date];
            $existing = DB::table($table)->where($identity)->first();
            $this->version($existing->version ?? 0, $data);
            if ($plan === null) {
                abort_if($data['capacity'] < ($existing->held ?? 0) + ($existing->sold ?? 0), 409, 'Capacity cannot be below held and sold units.');
            }
            $data['version'] = ($existing->version ?? 0) + 1;
            DB::table($table)->updateOrInsert($identity, $data);
            $hotel->recordAccessEvent($request->user(), $plan === null ? 'inventory.stock_saved' : 'inventory.rate_saved');

            return response()->json(['data' => DB::table($table)->where($identity)->first()]);
        });
    }

    public function stockCalendar(Request $request, Hotel $hotel, int $room): JsonResponse
    {
        return $this->calendar($request, $hotel, $room);
    }

    public function rateCalendar(Request $request, Hotel $hotel, int $room, int $plan): JsonResponse
    {
        return $this->calendar($request, $hotel, $room, $plan);
    }

    private function calendar(Request $request, Hotel $hotel, int $room, ?int $plan = null): JsonResponse
    {
        Gate::authorize('view', $hotel);
        $this->room($hotel, $room);
        $range = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after:from']]);
        $from = new DateTimeImmutable($range['from']);
        $to = new DateTimeImmutable($range['to']);
        abort_if($from->diff($to)->days > 366, 422, 'Read at most 366 days.');
        if ($plan !== null) {
            $this->plan($hotel, $room, $plan);
        }
        $pool = InventoryPool::where('room_type_id', $room)->first();
        $rows = DB::table($plan === null ? 'inventory_nights' : 'rate_plan_nights')
            ->where($plan === null ? 'inventory_pool_id' : 'rate_plan_id', $plan ?? $pool?->id)
            ->where('stay_date', '>=', $range['from'])->where('stay_date', '<', $range['to'])->get()->keyBy('stay_date');
        $days = [];
        for ($date = $from; $date < $to; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $days[] = isset($rows[$key]) ? array_merge((array) $rows[$key], ['configured' => true]) : ['stay_date' => $key, 'configured' => false];
        }

        return response()->json(['data' => $days]);
    }

    private function authorizeHotel(Hotel $hotel, string $action): Hotel
    {
        $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
        Gate::authorize('view', $hotel);
        Gate::authorize($action, $hotel);

        return $hotel;
    }

    private function room(Hotel $hotel, int $room): RoomType
    {
        return RoomType::where('hotel_id', $hotel->id)->findOrFail($room);
    }

    private function plan(Hotel $hotel, int $room, int $plan): RatePlan
    {
        return RatePlan::where('hotel_id', $hotel->id)->where('room_type_id', $room)->findOrFail($plan);
    }

    private function rateAuthoringPool(Hotel $hotel, int $room): ?InventoryPool
    {
        $pool = InventoryPool::where('room_type_id', $room)->first();
        abort_if($pool && $pool->owner === 'pms', 409, 'PMS-owned rates cannot be manually authored.');
        abort_if((! $pool || $pool->owner !== 'manual') && $hotel->status !== 'draft', 409, 'Manual ownership is required outside initial setup.');

        return $pool;
    }

    private function manualPool(int $room): InventoryPool
    {
        $pool = InventoryPool::where('room_type_id', $room)->first();
        abort_unless($pool && $pool->owner === 'manual', 409, 'Explicit manual inventory ownership is required.');

        return $pool;
    }

    private function version(int $current, array $data): void
    {
        abort_if($current !== (int) $data['version'], 409, 'This record changed. Reload before saving.');
    }

    private function validateInput(Request $request, array $rules, bool $version = true): array
    {
        if ($version) {
            $rules['version'] = ['required', 'integer', 'min:0'];
        }
        $allowed = array_map(fn ($key) => explode('.', $key)[0], array_keys($rules));
        if (array_diff(array_keys($request->all()), array_merge($allowed, ['_token'])) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }

        return $request->validate($rules);
    }
}
