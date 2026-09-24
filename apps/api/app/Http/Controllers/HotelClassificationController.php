<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HotelClassificationController extends Controller
{
    public function destinations(Request $request): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'administrator', 403);
        if ($request->isMethod('GET')) {
            return response()->json(['data' => DB::table('catalog_destinations')->orderBy('name')->get(['id', 'slug', 'name'])]);
        }
        $this->rejectUnknown($request, ['name', 'slug']);
        $input = $request->validate(['name' => ['required', 'string', 'max:255', 'not_regex:/<[^>]*>/'], 'slug' => ['required', 'string', 'max:120', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', 'unique:catalog_destinations,slug']]);
        try {
            $id = DB::table('catalog_destinations')->insertGetId($input + ['created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['slug' => 'Destination slug already exists.']);
        }

        return response()->json(['data' => ['id' => $id] + $input], 201);
    }

    public function show(Hotel $hotel): JsonResponse
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('releaseDiscovery', $hotel);

        return response()->json(['data' => ['onboarding_version' => $hotel->onboarding_version, 'fields' => $hotel->classification_draft]]);
    }

    public function update(Request $request, Hotel $hotel): JsonResponse
    {
        return DB::transaction(function () use ($request, $hotel): JsonResponse {
            $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('view', $hotel);
            Gate::authorize('releaseDiscovery', $hotel);
            $this->rejectUnknown($request, ['onboarding_version', 'fields']);
            $data = $request->validate([
                'onboarding_version' => ['required', 'integer', 'min:0'],
                'fields' => ['required', 'array:destination_id,district,themes,amenities,editorial_rank,review_note'],
                'fields.destination_id' => ['present', 'nullable', 'integer', 'exists:catalog_destinations,id'],
                'fields.district' => ['present', 'nullable', Rule::in(array_keys(config('catalog.districts')))],
                'fields.themes' => ['present', 'array', 'list', 'max:6'],
                'fields.themes.*' => ['string', 'distinct', Rule::in(array_keys(config('catalog.themes')))],
                'fields.amenities' => ['present', 'array', 'list', 'max:8'],
                'fields.amenities.*' => ['string', 'distinct', Rule::in(array_keys(config('catalog.amenities')))],
                'fields.editorial_rank' => ['present', 'nullable', 'integer', 'between:0,1000000'],
                'fields.review_note' => ['required', 'string', 'max:2000'],
            ]);
            abort_if($hotel->onboarding_version !== (int) $data['onboarding_version'], 409, 'Draft changed. Reload before saving classification.');
            if ($data['fields']['district'] !== null && $hotel->country !== 'LK') {
                throw ValidationException::withMessages(['fields.district' => 'Sri Lankan districts require country LK.']);
            }
            foreach (['themes', 'amenities'] as $key) {
                sort($data['fields'][$key]);
            }
            $hotel->classification_draft = $data['fields'];
            $hotel->onboarding_version++;
            $hotel->save();
            $hotel->recordAccessEvent($request->user(), 'catalog.classification_saved');

            return $this->show($hotel);
        });
    }

    private function rejectUnknown(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), [...$allowed, '_token']) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
    }
}
