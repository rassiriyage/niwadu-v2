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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class HotelPhotoController extends Controller
{
    public function index(Hotel $hotel): JsonResponse
    {
        Gate::authorize('view', $hotel);

        return DB::transaction(function () use ($hotel): JsonResponse {
            $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('view', $hotel);

            return $this->gallery($hotel);
        });
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('onboard', $hotel);
        $this->fields($request, ['version', 'photo', 'caption']);
        $data = $request->validate(['version' => ['sometimes', 'integer', 'min:0'], 'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=12000,max_height=12000'], 'caption' => ['required', 'string', 'max:255']]);
        $path = $data['photo']->store('hotel-photos/'.$hotel->id, 'local');
        abort_unless($path, 500, 'The photo could not be stored. Please retry.');
        try {
            return DB::transaction(function () use ($hotel, $request, $data, $path): JsonResponse {
                $hotel = $this->editable($hotel, $data['version'] ?? null);
                abort_if(HotelPhoto::where('hotel_id', $hotel->id)->where('gallery', 'property')->count() >= 50, 422, 'A draft can hold up to 50 photos.');
                $position = HotelPhoto::where('hotel_id', $hotel->id)->where('gallery', 'property')->max('position');
                $photo = new HotelPhoto;
                $photo->forceFill(['position' => $position === null ? 0 : $position + 1, 'hotel_id' => $hotel->id, 'path' => $path, 'mime_type' => $data['photo']->getMimeType(), 'caption' => $data['caption']])->save();
                $hotel->increment('property_gallery_version');
                $hotel->recordAccessEvent($request->user(), 'photo.added');

                return response()->json(['data' => $photo->only('id', 'caption', 'position'), 'meta' => ['version' => (int) $hotel->property_gallery_version]], 201);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function show(Hotel $hotel, int $photo): StreamedResponse
    {
        Gate::authorize('view', $hotel);
        $record = HotelPhoto::where('hotel_id', $hotel->id)->where('gallery', 'property')->findOrFail($photo);

        return Storage::disk('local')->response($record->path, null, ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    public function destroy(Request $request, Hotel $hotel, int $photo): Response
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('onboard', $hotel);
        $this->fields($request, ['version']);
        $data = $request->validate(['version' => ['sometimes', 'integer', 'min:0']]);
        $record = DB::transaction(function () use ($photo, $hotel, $request, $data): HotelPhoto {
            $hotel = $this->editable($hotel, $data['version'] ?? null);
            $record = HotelPhoto::where('hotel_id', $hotel->id)->where('gallery', 'property')->findOrFail($photo);
            $record->delete();
            $this->positions($hotel, HotelPhoto::where('hotel_id', $hotel->id)->where('gallery', 'property')->orderBy('position')->orderBy('id')->pluck('id')->all());
            $hotel->increment('property_gallery_version');
            $hotel->recordAccessEvent($request->user(), 'photo.removed');

            return $record;
        });
        Storage::disk('local')->delete($record->path);

        return response()->noContent();
    }

    public function reorder(Request $request, Hotel $hotel): JsonResponse
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('onboard', $hotel);
        $this->fields($request, ['version', 'photo_ids']);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'photo_ids' => ['present', 'array', 'list', 'max:50'], 'photo_ids.*' => ['required', 'integer', 'distinct']]);

        return DB::transaction(function () use ($request, $hotel, $data): JsonResponse {
            $hotel = $this->editable($hotel, (int) $data['version']);
            $ids = array_map('intval', $data['photo_ids']);
            $current = HotelPhoto::where('hotel_id', $hotel->id)->where('gallery', 'property')->pluck('id')->all();
            abort_if(count($current) !== count($ids) || array_diff($ids, $current) !== [], 422, 'Reorder only this property gallery’s existing photos.');
            $this->positions($hotel, $ids);
            $hotel->increment('property_gallery_version');
            $hotel->recordAccessEvent($request->user(), 'photo.reordered');

            return $this->gallery($hotel);
        });
    }

    private function positions(Hotel $hotel, array $ids): void
    {
        HotelPhoto::where('hotel_id', $hotel->id)->where('gallery', 'property')->update(['position' => null]);
        foreach ($ids as $position => $id) {
            HotelPhoto::whereKey($id)->where('hotel_id', $hotel->id)->where('gallery', 'property')->update(['position' => $position]);
        }
    }

    private function editable(Hotel $hotel, mixed $version): Hotel
    {
        $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
        Gate::authorize('view', $hotel);
        Gate::authorize('onboard', $hotel);
        abort_if($version !== null && (int) $hotel->property_gallery_version !== (int) $version, 409, 'Property gallery changed. Reload before editing.');

        return $hotel;
    }

    private function gallery(Hotel $hotel): JsonResponse
    {
        return response()->json(['data' => HotelPhoto::where('hotel_id', $hotel->id)->where('gallery', 'property')->orderBy('position')->orderBy('id')->get(['id', 'caption', 'position']), 'meta' => ['version' => (int) $hotel->property_gallery_version]]);
    }

    private function fields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), [...$allowed, '_token']) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
    }
}
