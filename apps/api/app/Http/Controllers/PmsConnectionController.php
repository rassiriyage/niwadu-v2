<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePmsConnectionRequest;
use App\Http\Resources\PmsConnectionResource;
use App\Models\Hotel;
use App\Models\PmsConnection;
use App\Services\PmsProviderCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PmsConnectionController extends Controller
{
    public function providers(Request $request, PmsProviderCatalog $catalog): JsonResponse
    {
        Gate::authorize('viewAny', PmsConnection::class);

        return response()->json(['data' => $catalog->all()]);
    }

    public function index(Request $request, Hotel $hotel): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PmsConnection::class);

        return PmsConnectionResource::collection(
            PmsConnection::where('hotel_id', $hotel->id)->orderBy('id')->paginate(25)
        );
    }

    public function store(SavePmsConnectionRequest $request, Hotel $hotel): PmsConnectionResource
    {
        $connection = DB::transaction(function () use ($request, $hotel): PmsConnection {
            $connection = PmsConnection::create([
                ...$request->safe()->except(['credentials', 'enabled']),
                'hotel_id' => $hotel->id,
                'credentials' => $request->validated('credentials'),
                'enabled' => (bool) $request->validated('enabled', false),
                'created_by' => $request->user()->id,
                'capabilities' => app(PmsProviderCatalog::class)->definition($request->validated('provider'))['capabilities'],
                'freshness' => null,
            ]);
            $hotel->recordAccessEvent($request->user(), 'pms.connection.created');

            return $connection;
        });

        return new PmsConnectionResource($connection);
    }

    public function update(SavePmsConnectionRequest $request, PmsConnection $pmsConnection): PmsConnectionResource
    {
        Gate::authorize('update', $pmsConnection);
        $data = $request->safe()->except(['credentials']);
        if ($request->has('credentials')) {
            $data['credentials'] = $request->validated('credentials');
        }
        $pmsConnection->fill($data)->save();
        $pmsConnection->hotel->recordAccessEvent($request->user(), 'pms.connection.updated');

        return new PmsConnectionResource($pmsConnection);
    }
}
