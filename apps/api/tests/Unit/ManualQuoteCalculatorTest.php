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
            'mandatory_charges_complete' => true,
            'nightly' => [
                ['date' => '2026-10-01', 'base_minor' => 10001, 'tax_minor' => 1800, 'fee_minor' => 500, 'available' => 1, 'stop_sell' => false, 'restrictions_passed' => true],
                ['date' => '2026-10-02', 'base_minor' => 20002, 'tax_minor' => 3600, 'fee_minor' => 0, 'available' => 1, 'stop_sell' => false, 'restrictions_passed' => true],
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
        $this->assertSame([12301, 23602], array_column($quote['nightly'], 'total_minor'));
        $this->assertSame($input['policy'], $quote['policy']);
        $this->assertSame(3, $quote['rate_plan_id']);
        $this->assertSame('2026-09-30T20:00:00+00:00', $quote['quoted_at']);
        $this->assertSame('2026-10-01T00:00:00+00:00', $quote['expires_at']);
        $this->assertSame($this->input(), $input);
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
            'no tax' => [['nightly' => [0 => ['tax_minor' => null]]]],
            'no fee' => [['nightly' => [1 => ['fee_minor' => null]]]],
            'unknown mandatory charges' => [['mandatory_charges_complete' => false]],
            'float money' => [['nightly' => [0 => ['base_minor' => 1.5]]]],
            'numeric string' => [['nightly' => [0 => ['tax_minor' => '1800']]]],
            'negative fee' => [['nightly' => [0 => ['fee_minor' => -1]]]],
            'overflow' => [['nightly' => [0 => ['base_minor' => PHP_INT_MAX]]]],
            'no stock' => [['nightly' => [1 => ['available' => 0]]]],
            'stop sell' => [['nightly' => [1 => ['stop_sell' => true]]]],
            'restriction' => [['nightly' => [0 => ['restrictions_passed' => false]]]],
            'duplicate night' => [['nightly' => [1 => ['date' => '2026-10-01']]]],
            'extra date' => [['nightly' => [1 => ['date' => '2026-10-03']]]],
            'invalid date' => [['arrival' => '2026-09-31']],
            'timestamp date' => [['arrival' => '2026-10-01T00:00:00Z']],
            'zero nights' => [['departure' => '2026-10-01']],
            'reversed stay' => [['departure' => '2026-09-30']],
            'over thirty nights' => [['departure' => '2026-11-01']],
            'multi room' => [['quantity' => 2]],
            'children' => [['children_ages' => [4]]],
            'too many adults' => [['adults' => 3]],
            'zero adults' => [['adults' => 0]],
            'foreign currency' => [['currency' => 'USD']],
            'PMS ownership' => [['inventory_mode' => 'pms']],
            'no policy' => [['policy' => ['text' => '']]],
            'no policy version' => [['policy' => ['version' => '']]],
            'invalid timezone' => [['timezone' => 'not/a-zone']],
            'invalid identity' => [['rate_plan_id' => 0]],
            'forged total' => [['total_minor' => 1]],
        ];
    }

    public function test_missing_night_is_not_free_or_available(): void
    {
        $input = $this->input();
        array_pop($input['nightly']);
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
        for ($day = 1; $day <= 30; $day++) {
            $input['nightly'][] = array_replace($night, ['date' => sprintf('2026-10-%02d', $day)]);
        }
        $this->assertSame(3000, $this->calculate($input)['total_minor']);
    }
}
