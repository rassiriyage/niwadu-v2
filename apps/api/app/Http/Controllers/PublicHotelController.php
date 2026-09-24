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
            $query->whereRaw("LOWER({$name}) LIKE LOWER(?) ESCAPE '!'", ['%'.$literal.'%']);
        }
        if ($types !== []) {
            $query->whereIn('discovery_snapshot->public->property_type', $types);
        }
        $applied = ['sort' => 'name', 'page' => (int) ($filters['page'] ?? 1), 'property_types' => $types];
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

        return response()->json(['data' => ['property_types' => $types, 'capabilities' => $this->capabilities()]]);
    }

    private function capabilities(): array
    {
        return ['filters' => ['q', 'property_types'], 'sorts' => ['name'], 'availability_search' => false, 'price_sort' => false];
    }
}
