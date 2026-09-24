<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CoverageController extends Controller
{
    public const DISTRICTS = ['ampara', 'anuradhapura', 'badulla', 'batticaloa', 'colombo', 'galle', 'gampaha', 'hambantota', 'jaffna', 'kalutara', 'kandy', 'kegalle', 'kilinochchi', 'kurunegala', 'mannar', 'matale', 'matara', 'moneragala', 'mullaitivu', 'nuwaraeliya', 'polonnaruwa', 'puttalam', 'ratnapura', 'trincomalee', 'vavuniya'];

    public function show(Request $request): JsonResponse
    {
        $coverage = DB::table('user_coverage')->where('user_id', $request->user()->id)->first();

        return response()->json(['districts' => $coverage ? json_decode($coverage->districts, true, flags: JSON_THROW_ON_ERROR) : [], 'version' => $coverage ? (int) $coverage->version : 0]);
    }

    public function update(Request $request): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['districts', 'version', '_token'])) {
            throw ValidationException::withMessages(['coverage' => 'Only districts and version are allowed.']);
        }
        $data = $request->validate([
            'districts' => ['present', 'array', 'list', 'max:25'],
            'districts.*' => ['required', 'string', 'distinct:strict', Rule::in(self::DISTRICTS)],
            'version' => ['required', 'integer', 'min:0', 'max:2147483646'],
        ]);
        sort($data['districts'], SORT_STRING);
        $saved = DB::transaction(function () use ($request, $data): array {
            // Lock the always-present owner row to serialize first saves as well as updates.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $coverage = DB::table('user_coverage')->where('user_id', $request->user()->id)->first();
            $version = $coverage ? (int) $coverage->version : 0;
            abort_if($version !== (int) $data['version'], 409, 'Your coverage changed. Reload it before saving again.');
            $values = ['districts' => json_encode($data['districts'], JSON_THROW_ON_ERROR), 'version' => $version + 1, 'updated_at' => now()];
            if ($coverage) {
                DB::table('user_coverage')->where('user_id', $request->user()->id)->update($values);
            } else {
                DB::table('user_coverage')->insert(['user_id' => $request->user()->id, 'created_at' => now(), ...$values]);
            }

            return ['districts' => $data['districts'], 'version' => $version + 1];
        });

        return response()->json($saved);
    }
}
