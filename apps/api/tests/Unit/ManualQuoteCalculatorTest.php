<?php

namespace Tests\Unit;

use App\ManualQuoteCalculator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManualQuoteCalculatorTest extends TestCase
{
    private function input(): array
    {
        return [
            'hotel_id' => 1, 'room_type_id' => 2, 'rate_plan_id' => 3,
            'timezone' => 'Asia/Colombo', 'currency' => 'LKR', 'inventory_mode' => 'manual',
            'quantity' => 1, 'adults' => 2, 'max_adults' => 2, 'children_ages' => [],
            'arrival' => '2026-10-01', 'departure' => '2026-10-03',
            'policy' => ['version' => 'v1', 'text' => 'Non-refundable; full payment required.'],
            'nightly_conditions' => [
                ['stay_date' => '2026-10-01', 'available' => 1, 'stop_sell' => false, 'restrictions_passed' => true],
                ['stay_date' => '2026-10-02', 'available' => 1, 'stop_sell' => false, 'restrictions_passed' => true],
            ],
            'nightly' => [
                ['stay_date' => '2026-10-01', 'base_minor' => 10001, 'tax_minor' => 1800, 'fee_minor' => 500, 'mandatory_charges_complete' => true],
                ['stay_date' => '2026-10-02', 'base_minor' => 20002, 'tax_minor' => 3600, 'fee_minor' => 0, 'mandatory_charges_complete' => true],
            ],
        ];
    }

    private function calculate(array $input, string $now = '2026-09-30T20:00:00Z'): array
    {
        return (new ManualQuoteCalculator)->calculate($input, new DateTimeImmutable($now), new DateTimeImmutable('2026-10-01T00:00:00Z'));
    }

    public function test_totals_all_nights_in_minor_units_without_reserving_inventory(): void
    {
        $input = $this->input();
        $quote = $this->calculate($input);
        $this->assertSame(35903, $quote['total_minor']);
        $this->assertSame(['2026-10-01', '2026-10-02'], array_column($quote['nightly'], 'stay_date'));
        $this->assertSame([true, true], array_column($quote['nightly'], 'mandatory_charges_complete'));
        $this->assertSame([12301, 23602], array_column($quote['nightly'], 'total_minor'));
        $this->assertSame($input['policy'], $quote['policy']);
        $this->assertSame(3, $quote['rate_plan_id']);
        $this->assertSame('2026-09-30T20:00:00+00:00', $quote['quoted_at']);
        $this->assertSame('2026-10-01T00:00:00+00:00', $quote['expires_at']);
        $this->assertSame($this->input(), $input);
    }

    #[DataProvider('supportedOffers')]
    public function test_preserves_selected_meal_plan_and_currency_without_conversion(string $currency, ?string $mealPlan): void
    {
        $input = array_replace($this->input(), ['currency' => $currency, 'meal_plan' => $mealPlan]);
        $quote = $this->calculate($input);
        $this->assertSame($currency, $quote['currency']);
        $this->assertSame($mealPlan, $quote['meal_plan']);
        $this->assertSame(35903, $quote['total_minor']);
        $this->assertSame([10001, 20002], array_column($quote['nightly'], 'base_minor'));
    }

    public static function supportedOffers(): array
    {
        $offers = [];
        foreach (['LKR', 'USD'] as $currency) {
            foreach ([null, 'RO', 'BB', 'HB', 'FB'] as $mealPlan) {
                $offers[$currency.'-'.($mealPlan ?? 'unspecified')] = [$currency, $mealPlan];
            }
        }

        return $offers;
    }

    public function test_legacy_missing_meal_plan_stays_unspecified(): void
    {
        $quote = $this->calculate($this->input());
        $this->assertArrayHasKey('meal_plan', $quote);
        $this->assertNull($quote['meal_plan']);
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_incomplete_or_unsupported_inputs(array $changes): void
    {
        $input = array_replace_recursive($this->input(), $changes);
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($input);
    }

    public static function invalidInputs(): array
    {
        return [
            'first night sold out' => [['nightly_conditions' => [0 => ['available' => 0]]]],
            'second night sold out' => [['nightly_conditions' => [1 => ['available' => 0]]]],
            'negative availability' => [['nightly_conditions' => [1 => ['available' => -1]]]],
            'float availability' => [['nightly_conditions' => [1 => ['available' => 1.5]]]],
            'string availability' => [['nightly_conditions' => [1 => ['available' => '1']]]],
            'unknown availability' => [['nightly_conditions' => [1 => ['available' => null]]]],
            'first night stopped' => [['nightly_conditions' => [0 => ['stop_sell' => true]]]],
            'second night stopped' => [['nightly_conditions' => [1 => ['stop_sell' => true]]]],
            'unknown stop sell' => [['nightly_conditions' => [1 => ['stop_sell' => null]]]],
            'falsy stop sell' => [['nightly_conditions' => [1 => ['stop_sell' => 0]]]],
            'first night restricted' => [['nightly_conditions' => [0 => ['restrictions_passed' => false]]]],
            'second night restricted' => [['nightly_conditions' => [1 => ['restrictions_passed' => false]]]],
            'unknown restrictions' => [['nightly_conditions' => [1 => ['restrictions_passed' => null]]]],
            'truthy restrictions' => [['nightly_conditions' => [1 => ['restrictions_passed' => 1]]]],
            'duplicate condition date' => [['nightly_conditions' => [1 => ['stay_date' => '2026-10-01']]]],
            'misaligned condition date' => [['nightly_conditions' => [1 => ['stay_date' => '2026-10-03']]]],
            'unknown conditions' => [['nightly_conditions' => null]],
            'unknown condition row' => [['nightly_conditions' => [1 => null]]],
            'no tax' => [['nightly' => [0 => ['tax_minor' => null]]]],
            'no fee' => [['nightly' => [1 => ['fee_minor' => null]]]],
            'first night incomplete' => [['nightly' => [0 => ['mandatory_charges_complete' => false]]]],
            'second night incomplete' => [['nightly' => [1 => ['mandatory_charges_complete' => false]]]],
            'first night unknown' => [['nightly' => [0 => ['mandatory_charges_complete' => null]]]],
            'legacy date field rejected' => [['nightly' => [0 => ['date' => '2026-10-01']]]],
            'second night unknown' => [['nightly' => [1 => ['mandatory_charges_complete' => null]]]],
            'truthy completeness rejected' => [['nightly' => [1 => ['mandatory_charges_complete' => 1]]]],
            'legacy global completeness rejected' => [['mandatory_charges_complete' => true]],
            'float money' => [['nightly' => [0 => ['base_minor' => 1.5]]]],
            'numeric string' => [['nightly' => [0 => ['tax_minor' => '1800']]]],
            'negative fee' => [['nightly' => [0 => ['fee_minor' => -1]]]],
            'overflow' => [['nightly' => [0 => ['base_minor' => PHP_INT_MAX]]]],
            'duplicate night' => [['nightly' => [1 => ['stay_date' => '2026-10-01']]]],
            'extra date' => [['nightly' => [1 => ['stay_date' => '2026-10-03']]]],
            'invalid date' => [['arrival' => '2026-09-31']],
            'timestamp date' => [['arrival' => '2026-10-01T00:00:00Z']],
            'zero nights' => [['departure' => '2026-10-01']],
            'reversed stay' => [['departure' => '2026-09-30']],
            'over thirty nights' => [['departure' => '2026-11-01']],
            'multi room' => [['quantity' => 2]],
            'children' => [['children_ages' => [4]]],
            'too many adults' => [['adults' => 3]],
            'zero adults' => [['adults' => 0]],
            'unsupported currency' => [['currency' => 'EUR']],
            'lowercase currency' => [['currency' => 'usd']],
            'unknown meal plan' => [['meal_plan' => 'AI']],
            'lowercase meal plan' => [['meal_plan' => 'bb']],
            'empty meal plan' => [['meal_plan' => '']],
            'numeric meal plan' => [['meal_plan' => 0]],
            'PMS ownership' => [['inventory_mode' => 'pms']],
            'no policy' => [['policy' => ['text' => '']]],
            'no policy version' => [['policy' => ['version' => '']]],
            'invalid timezone' => [['timezone' => 'not/a-zone']],
            'invalid identity' => [['rate_plan_id' => 0]],
            'forged total' => [['total_minor' => 1]],
        ];
    }

    public function test_missing_night_is_not_treated_as_free(): void
    {
        $input = $this->input();
        array_pop($input['nightly']);
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($input);
    }

    public function test_missing_completeness_on_either_night_rejects(): void
    {
        foreach ([0, 1] as $index) {
            $input = $this->input();
            unset($input['nightly'][$index]['mandatory_charges_complete']);
            try {
                $this->calculate($input);
                $this->fail('A missing nightly completeness flag must reject.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Missing or unexpected input fields.', $exception->getMessage());
            }
        }
    }

    #[DataProvider('missingConditions')]
    public function test_missing_conditions_reject(string $path): void
    {
        $input = $this->input();
        if ($path === 'all') {
            unset($input['nightly_conditions']);
        } elseif ($path === 'first' || $path === 'second') {
            unset($input['nightly_conditions'][$path === 'first' ? 0 : 1]);
        } else {
            unset($input['nightly_conditions'][1][$path]);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($input);
    }

    public static function missingConditions(): array
    {
        return array_map(fn (string $path): array => [$path], ['all', 'first', 'second', 'stay_date', 'available', 'stop_sell', 'restrictions_passed']);
    }

    public function test_reordered_conditions_reject(): void
    {
        $input = $this->input();
        $input['nightly_conditions'] = array_reverse($input['nightly_conditions']);
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($input);
    }

    public function test_expiry_is_exclusive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($this->input(), '2026-10-01T00:00:00Z');
    }

    public function test_arrival_is_compared_to_the_hotel_local_day(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ManualQuoteCalculator)->calculate($this->input(), new DateTimeImmutable('2026-10-01T19:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'));
    }

    public function test_thirty_nights_and_explicit_zero_charges_are_supported(): void
    {
        $input = $this->input();
        $input['departure'] = '2026-10-31';
        $night = $input['nightly'][0];
        $night['base_minor'] = 100;
        $night['tax_minor'] = 0;
        $night['fee_minor'] = 0;
        $input['nightly'] = [];
        $input['nightly_conditions'] = [];
        for ($day = 1; $day <= 30; $day++) {
            $input['nightly_conditions'][] = ['stay_date' => sprintf('2026-10-%02d', $day), 'available' => 1, 'stop_sell' => false, 'restrictions_passed' => true];
            $input['nightly'][] = array_replace($night, ['stay_date' => sprintf('2026-10-%02d', $day)]);
        }
        $this->assertSame(3000, $this->calculate($input)['total_minor']);
    }
}
