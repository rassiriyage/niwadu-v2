<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItineraryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $records = $this->owned($request)->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);

        return JsonResource::collection($records->through(fn (object $record): array => $this->present($record)));
    }

    public function show(Request $request, int $itinerary): JsonResponse
    {
        $record = $this->owned($request)->find($itinerary);
        abort_if($record === null, 404);

        return response()->json(['data' => $this->present($record)]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->input($request);
        $header = validator(['idempotency_key' => $request->header('Idempotency-Key')], [
            'idempotency_key' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i'],
        ])->validate();
        $key = strtolower($header['idempotency_key']);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $result = DB::transaction(function () use ($request, $input, $key, $hash): array {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $prior = DB::table('itinerary_creations')->where('user_id', $request->user()->id)->where('key', $key)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_hash, $hash), 409, 'This creation key was already used with different itinerary details.');

                return json_decode($prior->response, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($this->owned($request)->count() >= 100) {
                throw ValidationException::withMessages(['itineraries' => 'You can save up to 100 itineraries. Delete one before creating another.']);
            }
            $id = DB::table('itineraries')->insertGetId([
                'user_id' => $request->user()->id, 'name' => $input['name'],
                'stops' => json_encode($input['stops'], JSON_THROW_ON_ERROR), 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $response = ['data' => $this->present($this->owned($request)->find($id))];
            DB::table('itinerary_creations')->insert([
                'user_id' => $request->user()->id, 'key' => $key, 'request_hash' => $hash,
                'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $response;
        });

        return response()->json($result, 201);
    }

    public function update(Request $request, int $itinerary): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $itinerary): array {
            $record = $this->owned($request)->where('id', $itinerary)->lockForUpdate()->first();
            abort_if($record === null, 404);
            $input = $this->input($request, true);
            $this->version($record, $input['version']);
            $this->owned($request)->where('id', $itinerary)->update([
                'name' => $input['name'], 'stops' => json_encode($input['stops'], JSON_THROW_ON_ERROR),
                'version' => (int) $record->version + 1, 'updated_at' => now(),
            ]);

            return $this->present($this->owned($request)->find($itinerary));
        });

        return response()->json(['data' => $result]);
    }

    public function destroy(Request $request, int $itinerary): Response
    {
        DB::transaction(function () use ($request, $itinerary): void {
            $record = $this->owned($request)->where('id', $itinerary)->lockForUpdate()->first();
            abort_if($record === null, 404);
            $this->allowFields($request, ['version']);
            $input = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']]);
            $this->version($record, $input['version']);
            $this->owned($request)->where('id', $itinerary)->delete();
        });

        return response()->noContent();
    }

    private function owned(Request $request): Builder
    {
        return DB::table('itineraries')->where('user_id', $request->user()->id);
    }

    private function input(Request $request, bool $version = false): array
    {
        $this->allowFields($request, $version ? ['name', 'stops', 'version'] : ['name', 'stops']);
        $rules = ['name' => ['required', 'string', 'max:120'], 'stops' => ['present', 'array', 'list', 'max:20'],
            'stops.*' => ['required', 'array:slug,nights'], 'stops.*.slug' => ['required', 'string', 'distinct:strict', Rule::in(config('itineraries.destinations'))],
            'stops.*.nights' => ['required', 'integer', 'between:1,14']];
        if ($version) {
            $rules['version'] = ['required', 'integer', 'min:1', 'max:2147483646'];
        }
        $input = $request->validate($rules);

        return ['name' => trim($input['name']), 'stops' => array_map(fn (array $stop): array => ['slug' => $stop['slug'], 'nights' => (int) $stop['nights']], $input['stops']), ...($version ? ['version' => (int) $input['version']] : [])];
    }

    private function allowFields(Request $request, array $fields): void
    {
        if (array_diff(array_keys($request->all()), [...$fields, '_token'])) {
            throw ValidationException::withMessages(['input' => 'Unsupported itinerary fields.']);
        }
    }

    private function version(object $record, int $version): void
    {
        abort_if((int) $record->version !== $version, 409, 'This itinerary changed. Reload it before saving again.');
    }

    private function present(object $record): array
    {
        return ['id' => (int) $record->id, 'name' => $record->name,
            'stops' => json_decode($record->stops, true, flags: JSON_THROW_ON_ERROR), 'version' => (int) $record->version,
            'created_at' => Carbon::parse($record->created_at)->toISOString(), 'updated_at' => Carbon::parse($record->updated_at)->toISOString()];
    }
}
