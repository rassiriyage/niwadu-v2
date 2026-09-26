<?php

namespace App;

use App\Models\BookingIntent;
use App\Models\BookingQuote;
use App\Models\Hotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class BookingWorkflow
{
    public function __construct(private BookingQuoteSource $source, private ManualQuoteCalculator $calculator) {}

    /**
     * Internal traveller-owned workflow. Quotes reserve no inventory; no public booking route exists.
     *
     * @param  array<string,mixed>  $selection
     * @return array<string,mixed>
     */
    public function requestQuote(User $owner, array $selection): array
    {
        $this->validate($selection, [
            'hotel_id' => ['required', 'integer', 'min:1'], 'rate_plan_id' => ['required', 'integer', 'min:1'],
            'arrival' => ['required', 'date_format:Y-m-d'], 'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'adults' => ['required', 'integer', 'min:1'],
        ]);
        foreach (['hotel_id', 'rate_plan_id', 'adults'] as $field) {
            $selection[$field] = (int) $selection[$field];
        }
        $this->requireOwner($owner);
        $unavailable = ['state' => 'unavailable', 'reason' => 'quote_unavailable', 'quote' => null];
        $resolved = $this->source->resolve($selection, now()->toDateTimeImmutable());
        if ($resolved === null) {
            return $unavailable;
        }
        $manual = ($resolved['source'] ?? null) === 'manual';
        if (! $manual && (! app()->environment('testing') || ($resolved['source'] ?? null) !== 'fixture'
            || ! Hotel::whereKey($selection['hotel_id'])->where('status', 'published')->exists())) {
            return $unavailable;
        }
        $input = $resolved['input'] ?? null;
        $expires = $resolved['expires_at'] ?? null;
        if (! is_array($input) || ! $expires instanceof DateTimeImmutable) {
            return $unavailable;
        }
        if ($manual && ! is_array($resolved['source_revision'] ?? null)) {
            return $unavailable;
        }
        foreach ($selection as $field => $value) {
            if (($input[$field] ?? null) !== $value) {
                return $unavailable;
            }
        }
        try {
            $snapshot = $this->calculator->calculate($input, now()->toDateTimeImmutable(), $expires);
        } catch (InvalidArgumentException) {
            return $unavailable;
        }
        $quote = BookingQuote::create([
            'user_id' => $owner->id, 'hotel_id' => $selection['hotel_id'], 'source' => $resolved['source'],
            'room_type_id' => $manual ? $input['room_type_id'] : null,
            'rate_plan_id' => $manual ? $input['rate_plan_id'] : null,
            'source_revision' => $manual ? $resolved['source_revision'] : null,
            'snapshot' => $snapshot, 'expires_at' => $expires,
        ]);

        return ['state' => 'available', 'reason' => null, 'quote' => [
            'id' => $quote->id, 'source' => $quote->source, 'snapshot' => $quote->snapshot,
            'expires_at' => $quote->expires_at->toIso8601String(),
        ]];
    }

    /**
     * Atomically records planning intent only; it does not acquire a hold or call a provider.
     *
     * @param  array<string,mixed>  $command
     * @return array<string,mixed>
     */
    public function createIntent(User $owner, array $command, string $idempotencyKey): array
    {
        $this->validate($command, ['quote_id' => ['required', 'uuid']]);
        Validator::make(['key' => $idempotencyKey], ['key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:-]{8,128}\z/']])->validate();
        $hash = hash('sha256', $command['quote_id']);

        return DB::transaction(function () use ($owner, $command, $idempotencyKey, $hash): array {
            // Serialize this owner's intent writes before idempotency and quote checks.
            abort_unless(User::whereKey($owner->id)->lockForUpdate()->first(), 404);
            $existing = BookingIntent::where('user_id', $owner->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'idempotency_payload_mismatch');

                return $this->intentData($existing);
            }
            $quote = BookingQuote::whereKey($command['quote_id'])->where('user_id', $owner->id)->lockForUpdate()->first();
            abort_unless($quote, 404);
            abort_if(BookingIntent::where('booking_quote_id', $quote->id)->exists(), 409, 'quote_already_used');
            abort_if($quote->expires_at->lessThanOrEqualTo(now()), 409, 'quote_expired');
            if ($quote->source === 'manual') {
                $selection = array_intersect_key($quote->snapshot, array_flip(['hotel_id', 'rate_plan_id', 'arrival', 'departure', 'adults']));
                $current = $this->source->resolve($selection, now()->toDateTimeImmutable());
                abort_unless(($current['source'] ?? null) === 'manual', 409, 'quote_unavailable');
                abort_unless(is_string($quote->source_revision['fingerprint'] ?? null)
                    && hash_equals($quote->source_revision['fingerprint'], $current['source_revision']['fingerprint'] ?? ''), 409, 'quote_changed');
                abort_if($quote->expires_at->lessThanOrEqualTo(now()), 409, 'quote_expired');
            } else {
                abort_unless(app()->environment('testing') && $quote->source === 'fixture', 409, 'quote_unavailable');
                abort_unless(Hotel::whereKey($quote->hotel_id)->where('status', 'published')->exists(), 409, 'quote_unavailable');
            }
            $intent = BookingIntent::create([
                'booking_quote_id' => $quote->id, 'user_id' => $owner->id, 'hotel_id' => $quote->hotel_id,
                'idempotency_key' => $idempotencyKey, 'request_hash' => $hash,
            ]);

            return $this->intentData($intent);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function getIntent(User $owner, string $id): array
    {
        $this->requireOwner($owner);
        $intent = BookingIntent::whereKey($id)->where('user_id', $owner->id)->first();
        abort_unless($intent, 404);

        return $this->intentData($intent);
    }

    /** @return array<string,mixed> */
    private function intentData(BookingIntent $intent): array
    {
        $hold = DB::table('manual_inventory_holds')->where('booking_intent_id', $intent->id)->first();
        $state = match ($hold?->state) {
            'active' => CarbonImmutable::parse($hold->expires_at)->greaterThan(now()) ? 'held' : 'hold_expired',
            'released' => 'hold_released',
            'expired' => 'hold_expired',
            default => 'awaiting_hold',
        };

        return [
            'id' => $intent->id, 'quote_id' => $intent->booking_quote_id, 'hotel_id' => $intent->hotel_id,
            'state' => $state,
            'payment' => ['state' => 'unavailable', 'reason' => 'payment_setup_incomplete'],
        ];
    }

    private function requireOwner(User $owner): void
    {
        abort_unless($owner->exists && User::whereKey($owner->id)->exists(), 404);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,array>  $rules
     */
    private function validate(array $input, array $rules): void
    {
        $unexpected = array_diff(array_keys($input), array_keys($rules));
        if ($unexpected !== []) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'Unexpected field.'));
        }
        Validator::make($input, $rules)->validate();
    }
}
