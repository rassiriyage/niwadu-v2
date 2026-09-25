<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomTypeResource;
use App\Models\Hotel;
use App\Models\HotelPhoto;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RoomPhotoController extends Controller
{
    public function index(Hotel $hotel, int $room): RoomTypeResource
    {
        Gate::authorize('view', $hotel);

        return new RoomTypeResource(RoomType::where('hotel_id', $hotel->id)->with('photos')->findOrFail($room));
    }

    public function store(Request $request, Hotel $hotel, int $room): JsonResponse
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('update', $hotel);
        RoomType::where('hotel_id', $hotel->id)->findOrFail($room);
        $this->fields($request, ['version', 'photo', 'caption']);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=12000,max_height=12000'], 'caption' => ['required', 'string', 'max:255']]);
        $path = $data['photo']->store('room-photos/'.$hotel->id.'/'.$room, 'local');
        abort_unless($path, 500, 'Photo storage failed.');
        try {
            return DB::transaction(function () use ($hotel, $room, $request, $data, $path): JsonResponse {
                [$hotel, $model] = $this->editable($hotel, $room, (int) $data['version']);
                abort_if($model->photos()->count() >= 50, 422, 'A room can have at most 50 photos.');
                $photo = new HotelPhoto;
                $photo->forceFill(['hotel_id' => $hotel->id, 'path' => $path, 'mime_type' => $data['photo']->getMimeType(), 'caption' => $data['caption'], 'gallery' => 'room'])->save();
                $position = $model->photos()->max('room_type_photos.position');
                $model->photos()->attach($photo->id, ['hotel_id' => $hotel->id, 'position' => $position === null ? 0 : $position + 1]);
                $model->version++;
                $model->save();
                $hotel->recordAccessEvent($request->user(), 'catalog.room_photo_added');

                return (new RoomTypeResource($model->load('photos')))->response()->setStatusCode(201);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function show(Hotel $hotel, int $room, int $photo): StreamedResponse
    {
        Gate::authorize('view', $hotel);
        $model = RoomType::where('hotel_id', $hotel->id)->findOrFail($room);
        $record = $model->photos()->where('gallery', 'room')->findOrFail($photo);

        return Storage::disk('local')->response($record->path, null, ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    public function reorder(Request $request, Hotel $hotel, int $room): RoomTypeResource
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('update', $hotel);

        return DB::transaction(function () use ($request, $hotel, $room): RoomTypeResource {
            $this->fields($request, ['version', 'photo_ids']);
            $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'photo_ids' => ['present', 'array', 'list', 'max:50'], 'photo_ids.*' => ['required', 'integer', 'distinct']]);
            [$hotel, $model] = $this->editable($hotel, $room, (int) $data['version']);
            $ids = array_map('intval', $data['photo_ids']);
            $current = $model->photos()->pluck('hotel_photos.id')->all();
            abort_if(count($current) !== count($ids) || array_diff($ids, $current) !== [], 422, 'Reorder only this room’s existing photos.');
            $model->photos()->detach();
            foreach ($ids as $position => $id) {
                $model->photos()->attach($id, ['hotel_id' => $hotel->id, 'position' => $position]);
            }
            $model->version++;
            $model->save();
            $hotel->recordAccessEvent($request->user(), 'catalog.room_photos_reordered');

            return new RoomTypeResource($model->load('photos'));
        });
    }

    public function destroy(Request $request, Hotel $hotel, int $room, int $photo): RoomTypeResource
    {
        Gate::authorize('view', $hotel);
        Gate::authorize('update', $hotel);
        $path = null;
        $result = DB::transaction(function () use ($request, $hotel, $room, $photo, &$path): RoomTypeResource {
            $this->fields($request, ['version']);
            $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
            [$hotel, $model] = $this->editable($hotel, $room, (int) $data['version']);
            $record = $model->photos()->where('gallery', 'room')->findOrFail($photo);
            $model->photos()->detach($photo);
            $remaining = $model->photos()->pluck('hotel_photos.id')->all();
            $model->photos()->detach();
            foreach ($remaining as $position => $id) {
                $model->photos()->attach($id, ['hotel_id' => $hotel->id, 'position' => $position]);
            }
            if (! DB::table('room_type_photos')->where('hotel_photo_id', $photo)->exists()) {
                $path = $record->path;
                $record->delete();
            }
            $model->version++;
            $model->save();
            $hotel->recordAccessEvent($request->user(), 'catalog.room_photo_removed');

            return new RoomTypeResource($model->load('photos'));
        });
        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }

        return $result;
    }

    private function editable(Hotel $hotel, int $room, int $version): array
    {
        $hotel = Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
        Gate::authorize('view', $hotel);
        Gate::authorize('update', $hotel);
        $model = RoomType::where('hotel_id', $hotel->id)->findOrFail($room);
        abort_if($model->version !== $version, 409, 'Room changed. Reload before editing photos.');

        return [$hotel, $model];
    }

    private function fields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), [...$allowed, '_token']) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
    }
}
