<?php

namespace Tests\Feature;

use App\BookingQuoteSource;
use App\BookingWorkflow;
use App\ManualQuoteCalculator;
use App\Models\BookingIntent;
use App\Models\BookingQuote;
use App\Models\Hotel;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function selection(Hotel $hotel): array
    {
        return ['hotel_id' => $hotel->id, 'rate_plan_id' => 7, 'arrival' => '2026-10-01', 'departure' => '2026-10-02', 'adults' => 2];
    }

    private function workflow(int $base = 10000, bool $stopped = false, int $ttlMinutes = 5): BookingWorkflow
    {
        $source = new class($base, $stopped, $ttlMinutes) implements BookingQuoteSource
        {
            public function __construct(private int $base, private bool $stopped, private int $ttlMinutes) {}

            public function resolve(array $selection, DateTimeImmutable $now): ?array
            {
                return ['source' => 'fixture', 'expires_at' => $now->modify("{$this->ttlMinutes} minutes"), 'input' => [
                    ...$selection, 'room_type_id' => 4, 'timezone' => 'Asia/Colombo', 'currency' => 'LKR', 'inventory_mode' => 'manual',
                    'quantity' => 1, 'max_adults' => 2, 'children_ages' => [],
                    'policy' => ['version' => 'fixture-v1', 'text' => 'Synthetic non-refundable policy.'],
                    'nightly' => [['stay_date' => '2026-10-01', 'base_minor' => $this->base, 'tax_minor' => 1800, 'fee_minor' => 0, 'mandatory_charges_complete' => true]],
                    'nightly_conditions' => [['stay_date' => '2026-10-01', 'available' => 1, 'stop_sell' => $this->stopped, 'restrictions_passed' => true]],
                ]];
            }
        };

        return new BookingWorkflow($source, new ManualQuoteCalculator);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
    }

    public function test_default_source_is_unavailable_and_does_not_persist_a_quote(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $result = app(BookingWorkflow::class)->requestQuote($user, $this->selection($hotel));
        $this->assertSame(['state' => 'unavailable', 'reason' => 'quote_unavailable', 'quote' => null], $result);
        $this->assertDatabaseCount('booking_quotes', 0);
        $this->assertDatabaseCount('booking_intents', 0);
    }

    public function test_traveller_without_membership_can_persist_fixture_quote_and_pending_intent(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $workflow = $this->workflow();
        $quote = $workflow->requestQuote($user, $this->selection($hotel))['quote'];
        $this->assertSame(11800, $quote['snapshot']['total_minor']);
        $this->assertSame('fixture', $quote['source']);
        $intent = $workflow->createIntent($user, ['quote_id' => $quote['id']], 'intent-test-1');
        $this->assertSame('awaiting_hold', $intent['state']);
        $this->assertSame(['state' => 'unavailable', 'reason' => 'payment_setup_incomplete'], $intent['payment']);
        $this->assertSame($intent, $workflow->getIntent($user, $intent['id']));
        $this->assertArrayNotHasKey('checkout_url', $intent);
        $this->assertDatabaseCount('booking_intents', 1);
        $this->assertDatabaseCount('hotel_user', 0);
        $this->assertSame(11800, BookingQuote::findOrFail($quote['id'])->snapshot['total_minor']);
        $this->workflow(99999)->requestQuote($user, $this->selection($hotel));
        $this->assertSame(11800, BookingQuote::findOrFail($quote['id'])->snapshot['total_minor']);
    }

    public function test_intent_replay_is_stable_even_after_quote_expiry(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $workflow = $this->workflow();
        $quote = $workflow->requestQuote($user, $this->selection($hotel))['quote'];
        $first = $workflow->createIntent($user, ['quote_id' => $quote['id']], 'stable-intent-1');
        $this->travel(6)->minutes();
        $this->assertSame($first, $workflow->createIntent($user, ['quote_id' => $quote['id']], 'stable-intent-1'));
        $this->assertDatabaseCount('booking_intents', 1);
    }

    public function test_expired_quote_cannot_create_an_intent(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $workflow = $this->workflow();
        $quote = $workflow->requestQuote($user, $this->selection($hotel))['quote'];
        $this->travel(5)->minutes();
        try {
            $workflow->createIntent($user, ['quote_id' => $quote['id']], 'expired-test-1');
            $this->fail('Expiry must reject.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame('quote_expired', $error->getMessage());
        }
        $this->assertDatabaseCount('booking_intents', 0);
    }

    public function test_changed_key_payload_and_second_key_for_same_quote_conflict(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $workflow = $this->workflow();
        $first = $workflow->requestQuote($user, $this->selection($hotel))['quote'];
        $second = $workflow->requestQuote($user, $this->selection($hotel))['quote'];
        $workflow->createIntent($user, ['quote_id' => $first['id']], 'reused-key-1');
        foreach ([[$second['id'], 'reused-key-1', 'idempotency_payload_mismatch'], [$first['id'], 'another-key-1', 'quote_already_used']] as [$id, $key, $reason]) {
            try {
                $workflow->createIntent($user, ['quote_id' => $id], $key);
                $this->fail('Conflicting intent must reject.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
                $this->assertSame($reason, $error->getMessage());
            }
        }
        $this->assertDatabaseCount('booking_intents', 1);
    }

    public function test_other_users_and_hotel_staff_cannot_access_traveller_records(): void
    {
        $owner = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $workflow = $this->workflow();
        $quote = $workflow->requestQuote($owner, $this->selection($hotel))['quote'];
        $intent = $workflow->createIntent($owner, ['quote_id' => $quote['id']], 'private-test-1');
        foreach (['hotel_manager', 'reservations'] as $role) {
            $other = User::factory()->create();
            $hotel->users()->attach($other, ['role' => $role]);
            foreach (['read', 'create'] as $operation) {
                try {
                    $operation === 'read' ? $workflow->getIntent($other, $intent['id']) : $workflow->createIntent($other, ['quote_id' => $quote['id']], 'private-test-1');
                    $this->fail('Foreign owner must reject.');
                } catch (HttpException $error) {
                    $this->assertSame(404, $error->getStatusCode());
                }
            }
        }
    }

    public function test_draft_hotel_and_invalid_nightly_conditions_are_unavailable(): void
    {
        $user = User::factory()->create();
        $draft = Hotel::factory()->create();
        $published = Hotel::factory()->create(['status' => 'published']);
        $this->assertSame('unavailable', $this->workflow()->requestQuote($user, $this->selection($draft))['state']);
        $this->assertSame('unavailable', $this->workflow(stopped: true)->requestQuote($user, $this->selection($published))['state']);
        $this->assertDatabaseCount('booking_quotes', 0);
    }

    public function test_fixtures_are_disabled_outside_testing(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $this->app->instance('env', 'production');
        $this->assertSame('unavailable', $this->workflow()->requestQuote($user, $this->selection($hotel))['state']);
        $this->assertDatabaseCount('booking_quotes', 0);
    }

    public function test_client_cannot_inject_money_or_status_into_intent(): void
    {
        $user = User::factory()->create();
        foreach (['payment', 'state', 'paid', 'confirmed', 'provider', 'payment_gateway', 'total_minor', 'guest'] as $field) {
            try {
                $this->workflow()->createIntent($user, ['quote_id' => fake()->uuid(), $field => 'injected'], 'injection-test-1');
                $this->fail('Unexpected fields must reject.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey($field, $error->errors());
            }
        }
        $this->assertDatabaseCount('booking_intents', 0);
    }

    public function test_quote_request_rejects_client_price_and_provider_fields(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        foreach (['source', 'nightly', 'total_minor', 'room_type_id', 'pms_connection_id', 'payment'] as $field) {
            try {
                $this->workflow()->requestQuote($user, [...$this->selection($hotel), $field => 'injected']);
                $this->fail('Only the selection may come from a caller.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey($field, $error->errors());
            }
        }
        $this->assertDatabaseCount('booking_quotes', 0);
    }

    public function test_unpublished_after_quote_and_production_fixture_cannot_create_intent(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $workflow = $this->workflow();
        $quote = $workflow->requestQuote($user, $this->selection($hotel))['quote'];
        foreach (['draft', 'production'] as $blocker) {
            if ($blocker === 'draft') {
                $hotel->forceFill(['status' => 'draft'])->save();
            } else {
                $hotel->forceFill(['status' => 'published'])->save();
                $this->app->instance('env', 'production');
            }
            try {
                $workflow->createIntent($user, ['quote_id' => $quote['id']], 'blocked-intent-1');
                $this->fail('Unavailable quote cannot progress.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
                $this->assertSame('quote_unavailable', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('booking_intents', 0);
    }

    public function test_source_cannot_change_the_selected_rate_plan(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $source = new class implements BookingQuoteSource
        {
            public function resolve(array $selection, DateTimeImmutable $now): ?array
            {
                return ['source' => 'fixture', 'expires_at' => $now->modify('+5 minutes'), 'input' => [...$selection, 'rate_plan_id' => 999]];
            }
        };
        $workflow = new BookingWorkflow($source, new ManualQuoteCalculator);
        $this->assertSame('unavailable', $workflow->requestQuote($user, $this->selection($hotel))['state']);
        $this->assertDatabaseCount('booking_quotes', 0);
    }

    public function test_schema_rejects_intent_owner_different_from_quote_owner(): void
    {
        $quote = BookingQuote::factory()->create();
        $this->expectException(QueryException::class);
        BookingIntent::factory()->create(['booking_quote_id' => $quote->id, 'user_id' => User::factory()->create()->id, 'hotel_id' => $quote->hotel_id]);
    }

    public function test_schema_rejects_intent_hotel_different_from_quote_hotel(): void
    {
        $quote = BookingQuote::factory()->create();
        $this->expectException(QueryException::class);
        BookingIntent::factory()->create(['booking_quote_id' => $quote->id, 'user_id' => $quote->user_id, 'hotel_id' => Hotel::factory()->create()->id]);
    }

    public function test_schema_rejects_second_intent_for_same_quote(): void
    {
        $intent = BookingIntent::factory()->create();
        $this->expectException(QueryException::class);
        BookingIntent::factory()->create(['booking_quote_id' => $intent->booking_quote_id]);
    }

    public function test_only_existing_principals_can_request_quotes(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $this->expectException(HttpException::class);
        $this->workflow()->requestQuote(User::factory()->make(), $this->selection($hotel));
    }

    public function test_real_login_identity_owns_intent_and_account_switch_cannot_read_it(): void
    {
        $first = User::factory()->create(['password' => 'traveller-test-password']);
        $second = User::factory()->create(['password' => 'another-test-password', 'platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create(['status' => 'published']);
        $workflow = $this->workflow();
        $this->postJson('/api/v1/login', ['email' => $first->email, 'password' => 'traveller-test-password'])->assertOk();
        $this->getJson('/api/v1/session')->assertJsonPath('user.id', $first->id);
        $quote = $workflow->requestQuote(Auth::user(), $this->selection($hotel))['quote'];
        $intent = $workflow->createIntent(Auth::user(), ['quote_id' => $quote['id']], 'session-owner-test');
        $this->assertDatabaseHas('booking_intents', ['id' => $intent['id'], 'user_id' => $first->id]);
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertGuest();
        $this->postJson('/api/v1/login', ['email' => $second->email, 'password' => 'another-test-password'])->assertOk();
        $this->getJson('/api/v1/session')->assertJsonPath('user.id', $second->id);
        try {
            $workflow->getIntent(Auth::user(), $intent['id']);
            $this->fail('Even platform staff cannot use traveller ownership to read another account.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $ownQuote = $workflow->requestQuote(Auth::user(), $this->selection($hotel))['quote'];
        $ownIntent = $workflow->createIntent(Auth::user(), ['quote_id' => $ownQuote['id']], 'session-owner-test');
        $this->assertNotSame($intent['id'], $ownIntent['id']);
        $this->assertDatabaseHas('booking_intents', ['id' => $ownIntent['id'], 'user_id' => $second->id]);
        $this->assertDatabaseCount('hotel_user', 0);
    }

    public function test_source_expired_at_resolution_creates_no_quote_or_intent(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create(['status' => 'published']);
        foreach ([0, -1] as $ttlMinutes) {
            $result = $this->workflow(ttlMinutes: $ttlMinutes)->requestQuote($user, $this->selection($hotel));
            $this->assertSame(['state' => 'unavailable', 'reason' => 'quote_unavailable', 'quote' => null], $result);
        }
        $this->assertDatabaseCount('booking_quotes', 0);
        $this->assertDatabaseCount('booking_intents', 0);
    }

    public function test_stored_quote_cannot_be_repriced(): void
    {
        $quote = BookingQuote::factory()->create();
        $this->expectException(\LogicException::class);
        $quote->update(['snapshot' => ['total_minor' => 1]]);
    }
}
