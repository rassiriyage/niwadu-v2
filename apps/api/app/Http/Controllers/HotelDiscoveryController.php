<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReleaseDiscoveryRequest;
use App\Http\Requests\WithdrawDiscoveryRequest;
use App\Http\Resources\PublicHotelResource;
use App\Models\Hotel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HotelDiscoveryController extends Controller
{
    public function review(Hotel $hotel): JsonResponse
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('releaseDiscovery', $hotel);

        return $this->state($hotel);
    }

    public function release(ReleaseDiscoveryRequest $request, Hotel $hotel): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $hotel): JsonResponse {
                $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
                Gate::authorize('releaseDiscovery', $hotel);
                $input = $request->validated();
                abort_if($hotel->onboarding_version !== (int) $input['onboarding_version']
                    || $hotel->discovery_version !== (int) $input['discovery_version'], 409, 'The reviewed draft or release changed. Reload and review before trying again.');
                abort_if($hotel->discovery_slug !== null && $hotel->discovery_slug !== $input['slug'], 409, 'The public slug is reserved and cannot change.');
                $public = Validator::make($this->proposed($hotel), $this->metadataRules())->validate();
                $hotel->discovery_slug = $input['slug'];
                $hotel->discovery_snapshot = ['source_onboarding_version' => $hotel->onboarding_version, 'public' => $public];
                $hotel->discovery_version++;
                $hotel->discovery_approved_by = $request->user()->id;
                $hotel->discovery_approved_at = now();
                $hotel->save();
                $hotel->recordAccessEvent($request->user(), 'discovery.released');

                return $this->state($hotel);
            });
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'The requested public slug is unavailable.');
        }
    }

    public function withdraw(WithdrawDiscoveryRequest $request, Hotel $hotel): JsonResponse
    {
        return DB::transaction(function () use ($request, $hotel): JsonResponse {
            $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('releaseDiscovery', $hotel);
            abort_if($hotel->discovery_version !== (int) $request->validated('discovery_version'), 409, 'The release changed. Reload before withdrawing.');
            if ($hotel->discovery_snapshot !== null) {
                $hotel->discovery_snapshot = null;
                $hotel->discovery_version++;
                $hotel->discovery_approved_by = null;
                $hotel->discovery_approved_at = null;
                $hotel->save();
                $hotel->recordAccessEvent($request->user(), 'discovery.withdrawn');
            }

            return $this->state($hotel);
        });
    }

    private function proposed(Hotel $hotel): array
    {
        return array_map(fn ($value) => is_string($value) ? trim($value) : $value, [
            'name' => $hotel->name, 'description' => $hotel->description,
            'property_type' => $hotel->onboarding_data['property_type'] ?? null,
            'city' => $hotel->city, 'country' => $hotel->country,
        ]);
    }

    private function metadataRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'not_regex:/<[^>]*>/'],
            'description' => ['required', 'string', 'max:10000', 'not_regex:/<[^>]*>/'],
            'property_type' => ['required', Rule::in(array_keys(config('catalog.property_types')))],
            'city' => ['required', 'string', 'max:255', 'not_regex:/<[^>]*>/'],
            'country' => ['required', 'string', 'regex:/\A[A-Z]{2}\z/'],
        ];
    }

    private function state(Hotel $hotel): JsonResponse
    {
        $proposed = $this->proposed($hotel);

        return response()->json(['data' => [
            'onboarding_version' => $hotel->onboarding_version, 'discovery_version' => $hotel->discovery_version,
            'slug' => $hotel->discovery_slug, 'proposed' => $proposed,
            'errors' => Validator::make($proposed, $this->metadataRules())->errors(),
            'current' => $hotel->discovery_snapshot === null ? null : new PublicHotelResource($hotel),
        ]]);
    }
}
