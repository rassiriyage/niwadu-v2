<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveHotelRequest;
use App\Http\Resources\HotelResource;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class HotelController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return HotelResource::collection(Hotel::visibleTo($request->user())->orderBy('name')->orderBy('id')->paginate(30));
    }

    public function store(SaveHotelRequest $request): HotelResource
    {
        $hotel = DB::transaction(function () use ($request) {
            $hotel = new Hotel($request->validated());
            $hotel->created_by = $request->user()->id;
            $hotel->save();
            $hotel->recordAccessEvent($request->user(), 'hotel.created');

            return $hotel->refresh();
        });

        return new HotelResource($hotel);
    }

    public function show(Hotel $hotel): HotelResource
    {
        Gate::authorize('view', $hotel);

        return new HotelResource($hotel);
    }

    public function update(SaveHotelRequest $request, Hotel $hotel): HotelResource
    {
        DB::transaction(function () use ($request, $hotel) {
            $hotel->update($request->validated());
            $hotel->recordAccessEvent($request->user(), 'hotel.updated');
        });

        return new HotelResource($hotel);
    }
}
