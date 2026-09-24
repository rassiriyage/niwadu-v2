<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchPublicHotelsRequest;
use App\Http\Resources\PublicHotelResource;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicHotelController extends Controller
{
    public function index(SearchPublicHotelsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $types = array_values(array_unique($filters['property_types'] ?? []));
        sort($types);
        $query = Hotel::whereNotNull('discovery_snapshot');
        $name = $query->getQuery()->getGrammar()->wrap('discovery_snapshot->public->name');
        if (isset($filters['q'])) {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']);
            $destinationName = $query->getQuery()->getGrammar()->wrap('discovery_snapshot->public->destination->name');
            $query->where(fn ($part) => $part->whereRaw("LOWER({$name}) LIKE LOWER(?) ESCAPE '!'", ['%'.$literal.'%'])
                ->orWhereRaw("LOWER({$destinationName}) LIKE LOWER(?) ESCAPE '!'", ['%'.$literal.'%']));
        }
        if ($types !== []) {
            $query->whereIn('discovery_snapshot->public->property_type', $types);
        }
        foreach (['destination' => 'destination->slug', 'district' => 'district'] as $filter => $path) {
            if (isset($filters[$filter])) {
                $query->where('discovery_snapshot->public->'.$path, $filters[$filter]);
            }
        }
        if (isset($filters['region'])) {
            $query->whereIn('discovery_snapshot->public->district', config('catalog.north_east'));
        }
        if (! empty($filters['themes'])) {
            $query->where(function ($part) use ($filters): void {
                foreach (array_unique($filters['themes']) as $theme) {
                    $part->orWhereJsonContains('discovery_snapshot->public->themes', $theme);
                }
            });
        }
        foreach (array_unique($filters['amenities'] ?? []) as $amenity) {
            $query->whereJsonContains('discovery_snapshot->public->amenities', $amenity);
        }
        if ($filters['sort'] === 'editorial') {
            $rank = $query->getQuery()->getGrammar()->wrap('discovery_snapshot->public->editorial_rank');
            $query->orderByRaw("CASE WHEN {$rank} IS NULL THEN 1 ELSE 0 END")->orderByRaw("CAST({$rank} AS BIGINT)");
        }
        $applied = ['sort' => $filters['sort'], 'page' => (int) ($filters['page'] ?? 1), 'property_types' => $types];
        foreach (['themes', 'amenities'] as $field) {
            $applied[$field] = array_values(array_unique($filters[$field] ?? []));
            sort($applied[$field]);
        }
        foreach (['destination', 'district', 'region'] as $field) {
            if (isset($filters[$field])) {
                $applied[$field] = $filters[$field];
            }
        }
        if (isset($filters['q'])) {
            $applied['q'] = $filters['q'];
        }

        return PublicHotelResource::collection($query->orderByRaw("LOWER({$name})")->orderBy('id')->paginate(24)->withQueryString())
            ->additional(['meta' => ['applied_filters' => $applied, 'capabilities' => $this->capabilities()]]);
    }

    public function show(string $slug): PublicHotelResource
    {
        return new PublicHotelResource(Hotel::where('discovery_slug', $slug)->whereNotNull('discovery_snapshot')->firstOrFail());
    }

    public function options(): JsonResponse
    {
        $available = Hotel::whereNotNull('discovery_snapshot')->distinct()
            ->get(['discovery_snapshot->public->property_type as property_type'])->pluck('property_type')->all();
        $types = [];
        foreach (config('catalog.property_types') as $key => $label) {
            if (in_array($key, $available, true)) {
                $types[] = ['key' => $key, 'label' => $label];
            }
        }

        $facets = ['destinations' => [], 'districts' => [], 'themes' => [], 'amenities' => [], 'regions' => []];
        $used = ['districts' => [], 'themes' => [], 'amenities' => []];
        $destinations = [];
        foreach (Hotel::whereNotNull('discovery_snapshot')->cursor(['discovery_snapshot']) as $hotel) {
            $public = $hotel->discovery_snapshot['public'];
            if (isset($public['destination'])) {
                $destinations[$public['destination']['slug']] = $public['destination']['name'];
            }
            if (isset($public['district'])) {
                $used['districts'][$public['district']] = true;
            }
            foreach (['themes', 'amenities'] as $field) {
                foreach ($public[$field] ?? [] as $value) {
                    $used[$field][$value] = true;
                }
            }
        }
        asort($destinations);
        foreach ($destinations as $key => $label) {
            $facets['destinations'][] = compact('key', 'label');
        }
        foreach ($used as $field => $values) {
            foreach (config('catalog.'.$field) as $key => $label) {
                if (isset($values[$key])) {
                    $facets[$field][] = compact('key', 'label');
                }
            }
        }
        if (array_intersect(array_keys($used['districts']), config('catalog.north_east')) !== []) {
            $facets['regions'][] = ['key' => 'north-east', 'label' => 'North & East'];
        }

        return response()->json(['data' => ['property_types' => $types, ...$facets, 'capabilities' => $this->capabilities()]]);
    }

    private function capabilities(): array
    {
        return ['filters' => ['q', 'property_types', 'destination', 'district', 'region', 'themes', 'amenities'], 'sorts' => Hotel::whereNotNull('discovery_snapshot->public->editorial_rank')->exists() ? ['name', 'editorial'] : ['name'], 'star_filter' => false, 'availability_search' => false, 'price_sort' => false];
    }
}
