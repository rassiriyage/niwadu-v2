<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomTypeResource;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RoomTypeController extends Controller
{
    public function index(Request $request, Hotel $hotel): AnonymousResourceCollection
    {
        Gate::authorize('view', $hotel);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);

        return RoomTypeResource::collection(RoomType::where('hotel_id', $hotel->id)->with('photos')
            ->orderBy('id')->paginate($data['per_page'] ?? 25)->withQueryString());
    }

    public function show(Hotel $hotel, int $room): RoomTypeResource
    {
        Gate::authorize('view', $hotel);

        return new RoomTypeResource(RoomType::where('hotel_id', $hotel->id)->with('photos')->findOrFail($room));
    }
}
