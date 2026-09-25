<?php

namespace App;

use App\Models\BookingIntent;
use App\Models\BookingQuote;
use App\Models\Hotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

/** Internal one-room manual stock holds. No payment or booking acceptance is implied. */
final class ManualInventoryHoldService
{
    public function __construct(private ManualQuoteSource $source) {}

    /** The caller supplies an approved server expiry; an intent is its durable idempotency identity. */
    public function acquire(User $owner, string $intentId, DateTimeImmutable $expiresAt): array
    {
        $intent = BookingIntent::whereKey($intentId)->where('user_id', $owner->id)->first();
        abort_unless($owner->exists && $intent, 404);
        $expiry = CarbonImmutable::instance($expiresAt)->utc()->startOfSecond();

        return DB::transaction(function () use ($intent, $expiry): array {
            Hotel::whereKey($intent->hotel_id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('manual_inventory_holds')->where('booking_intent_id', $intent->id)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(CarbonImmutable::parse($existing->expires_at)->equalTo($expiry), 409, 'hold_expiry_mismatch');

                return $this->data($this->expireLocked($existing));
            }
            abort_unless($expiry->greaterThan(now()), 409, 'hold_expiry_invalid');
            $quote = BookingQuote::findOrFail($intent->booking_quote_id);
            abort_unless($quote->source === 'manual', 409, 'manual_quote_required');
            abort_if($quote->expires_at->lessThanOrEqualTo(now()), 409, 'quote_expired');
            $selection = array_intersect_key($quote->snapshot, array_flip(['hotel_id', 'rate_plan_id', 'arrival', 'departure', 'adults']));
            $current = $this->source->resolve($selection, now()->toDateTimeImmutable());
            abort_unless($current, 409, 'quote_unavailable');
            abort_unless(is_string($quote->source_revision['fingerprint'] ?? null)
                && hash_equals($quote->source_revision['fingerprint'], $current['source_revision']['fingerprint']), 409, 'quote_changed');
            abort_if($quote->expires_at->lessThanOrEqualTo(now()), 409, 'quote_expired');
            abort_unless($expiry->greaterThan(now()), 409, 'hold_expiry_invalid');
            $revision = $current['source_revision'];
            $dates = array_column($current['input']['nightly'], 'stay_date');
            $nights = DB::table('inventory_nights')->where('inventory_pool_id', $revision['inventory_pool_id'])
                ->whereIn('stay_date', $dates)->orderBy('stay_date')->lockForUpdate()->get();
            abort_unless($nights->count() === count($dates), 409, 'inventory_unavailable');
            $id = (string) Str::uuid();
            DB::table('manual_inventory_holds')->insert([
                'id' => $id, 'booking_intent_id' => $intent->id, 'inventory_pool_id' => $revision['inventory_pool_id'],
                'ownership_version' => $revision['ownership_version'], 'night_count' => count($dates),
                'state' => 'active', 'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($nights as $night) {
                $updated = DB::table('inventory_nights')->where('id', $night->id)->whereRaw('capacity - held - sold >= 1')
                    ->update(['held' => DB::raw('held + 1'), 'version' => DB::raw('version + 1')]);
                abort_unless($updated === 1, 409, 'inventory_unavailable');
                DB::table('manual_inventory_hold_nights')->insert(['hold_id' => $id, 'inventory_night_id' => $night->id]);
            }

            abort_unless($expiry->greaterThan(now()), 409, 'hold_expiry_invalid');

            return $this->data(DB::table('manual_inventory_holds')->where('id', $id)->first());
        }, 3);
    }

    /** Releases stock only. This is not a booking cancellation or a refund. */
    public function release(User $owner, string $holdId): array
    {
        $intent = BookingIntent::where('user_id', $owner->id)->whereIn('id',
            DB::table('manual_inventory_holds')->select('booking_intent_id')->where('id', $holdId))->first();
        abort_unless($owner->exists && $intent, 404);

        return DB::transaction(function () use ($intent, $holdId): array {
            Hotel::whereKey($intent->hotel_id)->lockForUpdate()->firstOrFail();
            $hold = DB::table('manual_inventory_holds')->where('id', $holdId)->lockForUpdate()->first();
            if ($hold->state === 'active') {
                $hold = $this->endLocked($hold, CarbonImmutable::parse($hold->expires_at)->lessThanOrEqualTo(now()) ? 'expired' : 'released');
            }

            return $this->data($hold);
        }, 3);
    }

    /** Bounded, restartable recovery. Each hold commits separately; no lease or in-memory timer is required. */
    public function expireDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new LogicException('Expiry batch size must be between 1 and 1000.');
        }
        $holds = DB::table('manual_inventory_holds as holds')->join('booking_intents as intents', 'intents.id', '=', 'holds.booking_intent_id')
            ->where('holds.state', 'active')->where('holds.expires_at', '<=', now())->orderBy('holds.expires_at')->orderBy('holds.id')
            ->limit($limit)->get(['holds.id', 'intents.hotel_id']);
        $expired = 0;
        foreach ($holds as $candidate) {
            $expired += DB::transaction(function () use ($candidate): int {
                Hotel::whereKey($candidate->hotel_id)->lockForUpdate()->firstOrFail();
                $hold = DB::table('manual_inventory_holds')->where('id', $candidate->id)->lockForUpdate()->first();
                if ($hold->state !== 'active' || CarbonImmutable::parse($hold->expires_at)->greaterThan(now())) {
                    return 0;
                }
                $this->endLocked($hold, 'expired');

                return 1;
            }, 3);
        }

        return $expired;
    }

    private function expireLocked(stdClass $hold): stdClass
    {
        return $hold->state === 'active' && CarbonImmutable::parse($hold->expires_at)->lessThanOrEqualTo(now())
            ? $this->endLocked($hold, 'expired') : $hold;
    }

    /** Hotel and hold locks must already be held. Ledger/counter inconsistencies roll back for investigation. */
    private function endLocked(stdClass $hold, string $state): stdClass
    {
        $nights = DB::table('inventory_nights')->whereIn('id', DB::table('manual_inventory_hold_nights')
            ->where('hold_id', $hold->id)->select('inventory_night_id'))->orderBy('stay_date')->lockForUpdate()->get();
        if ($nights->count() !== $hold->night_count) {
            throw new LogicException('Hold night ledger is incomplete.');
        }
        foreach ($nights as $night) {
            if ($night->inventory_pool_id !== $hold->inventory_pool_id || $night->held < 1) {
                throw new LogicException('Hold inventory accounting is inconsistent.');
            }
            DB::table('inventory_nights')->where('id', $night->id)->update(['held' => DB::raw('held - 1'), 'version' => DB::raw('version + 1')]);
        }
        DB::table('manual_inventory_holds')->where('id', $hold->id)->update(['state' => $state, 'ended_at' => now(), 'updated_at' => now()]);

        return DB::table('manual_inventory_holds')->where('id', $hold->id)->first();
    }

    private function data(stdClass $hold): array
    {
        return ['id' => $hold->id, 'intent_id' => $hold->booking_intent_id, 'state' => $hold->state,
            'expires_at' => CarbonImmutable::parse($hold->expires_at)->utc()->toIso8601String()];
    }
}
