<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\HotelPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class HotelPhotoController extends Controller
{
    public function index(Hotel $hotel): JsonResponse
    {
        Gate::authorize('view', $hotel);

        return response()->json(['data' => HotelPhoto::where('hotel_id', $hotel->id)->orderBy('id')->get(['id', 'caption'])]);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('onboard', $hotel);
        $data = $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=12000,max_height=12000'], 'caption' => ['required', 'string', 'max:255']]);
        $path = $data['photo']->store('hotel-photos/'.$hotel->id, 'local');
        abort_unless($path, 500, 'The photo could not be stored. Please retry.');
        try {
            $photo = DB::transaction(function () use ($hotel, $request, $data, $path) {
                $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
                Gate::authorize('onboard', $hotel);
                abort_if(HotelPhoto::where('hotel_id', $hotel->id)->count() >= 50, 422, 'A draft can hold up to 50 photos.');
                $photo = HotelPhoto::create(['hotel_id' => $hotel->id, 'path' => $path, 'mime_type' => $data['photo']->getMimeType(), 'caption' => $data['caption']]);
                $hotel->recordAccessEvent($request->user(), 'photo.added');

                return $photo;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return response()->json(['data' => $photo->only('id', 'caption')], 201);
    }

    public function show(Hotel $hotel, int $photo): StreamedResponse
    {
        Gate::authorize('view', $hotel);
        $record = HotelPhoto::where('hotel_id', $hotel->id)->findOrFail($photo);

        return Storage::disk('local')->response($record->path, null, ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    public function destroy(Request $request, Hotel $hotel, int $photo): Response
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('onboard', $hotel);
        $record = HotelPhoto::where('hotel_id', $hotel->id)->findOrFail($photo);
        DB::transaction(function () use ($record, $hotel, $request) {
            $record->delete();
            $hotel->recordAccessEvent($request->user(), 'photo.removed');
        });
        Storage::disk('local')->delete($record->path);

        return response()->noContent();
    }
}
