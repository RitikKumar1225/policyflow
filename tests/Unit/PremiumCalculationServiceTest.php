<?php

namespace Tests\Unit;

use App\Services\PremiumCalculationService;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculationServiceTest extends TestCase
{
    private PremiumCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PremiumCalculationService();
    }

    // ─────────────────────────────────────────────────────────────────
    // Spec Example
    // ─────────────────────────────────────────────────────────────────

    public function test_spec_example_plus_international_300k(): void
    {
        // From spec: PLUS, 5 days, age 28, INTERNATIONAL, ₹300,000
        // 20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00
        $premium = $this->service->calculate(
            'PLUS',
            Carbon::parse('1998-04-10'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-14'),
            'INTERNATIONAL',
            300000,
        );

        $this->assertEquals('225.00', $premium);
    }

    // ─────────────────────────────────────────────────────────────────
    // Plan Code Tests
    // ─────────────────────────────────────────────────────────────────

    public function test_basic_plan_rate(): void
    {
        // BASIC, 1 day, age 30, DOMESTIC, 100000
        // 12 × 1 × 1.00 × 1.00 × 1.00 = 12.00
        $premium = $this->service->calculate(
            'BASIC',
            Carbon::parse('1996-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            100000,
        );

        $this->assertEquals('12.00', $premium);
    }

    public function test_premium_plan_rate(): void
    {
        // PREMIUM, 1 day, age 30, DOMESTIC, 100000
        // 30 × 1 × 1.00 × 1.00 × 1.00 = 30.00
        $premium = $this->service->calculate(
            'PREMIUM',
            Carbon::parse('1996-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            100000,
        );

        $this->assertEquals('30.00', $premium);
    }

    // ─────────────────────────────────────────────────────────────────
    // Age Multiplier Tests
    // ─────────────────────────────────────────────────────────────────

    public function test_minor_age_multiplier(): void
    {
        // Age 10 (0-17): multiplier 1.10
        // BASIC, 1 day, DOMESTIC, 100000 = 12 × 1 × 1.10 × 1.00 × 1.00 = 13.20
        $premium = $this->service->calculate(
            'BASIC',
            Carbon::parse('2016-06-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            100000,
        );

        $this->assertEquals('13.20', $premium);
    }

    public function test_adult_age_multiplier(): void
    {
        // Age 30 (18-60): multiplier 1.00
        $premium = $this->service->calculate(
            'BASIC',
            Carbon::parse('1996-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            100000,
        );

        $this->assertEquals('12.00', $premium);
    }

    public function test_senior_age_multiplier(): void
    {
        // Age 65 (61-70): multiplier 1.50
        // 12 × 1 × 1.50 × 1.00 × 1.00 = 18.00
        $premium = $this->service->calculate(
            'BASIC',
            Carbon::parse('1961-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            100000,
        );

        $this->assertEquals('18.00', $premium);
    }

    public function test_elderly_age_multiplier(): void
    {
        // Age 76 (71+): multiplier 2.00
        // 12 × 1 × 2.00 × 1.00 × 1.00 = 24.00
        $premium = $this->service->calculate(
            'BASIC',
            Carbon::parse('1950-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            100000,
        );

        $this->assertEquals('24.00', $premium);
    }

    // ─────────────────────────────────────────────────────────────────
    // Destination Multiplier Tests
    // ─────────────────────────────────────────────────────────────────

    public function test_international_destination_multiplier(): void
    {
        // BASIC, 1 day, age 30, INTERNATIONAL, 100000
        // 12 × 1 × 1.00 × 1.80 × 1.00 = 21.60
        $premium = $this->service->calculate(
            'BASIC',
            Carbon::parse('1996-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'INTERNATIONAL',
            100000,
        );

        $this->assertEquals('21.60', $premium);
    }

    // ─────────────────────────────────────────────────────────────────
    // Coverage Multiplier Tests
    // ─────────────────────────────────────────────────────────────────

    public function test_300k_coverage_multiplier(): void
    {
        // 12 × 1 × 1.00 × 1.00 × 1.25 = 15.00
        $premium = $this->service->calculate(
            'BASIC',
            Carbon::parse('1996-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            300000,
        );

        $this->assertEquals('15.00', $premium);
    }

    public function test_500k_coverage_multiplier(): void
    {
        // 12 × 1 × 1.00 × 1.00 × 1.50 = 18.00
        $premium = $this->service->calculate(
            'BASIC',
            Carbon::parse('1996-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            500000,
        );

        $this->assertEquals('18.00', $premium);
    }

    // ─────────────────────────────────────────────────────────────────
    // Travel Days Tests
    // ─────────────────────────────────────────────────────────────────

    public function test_travel_days_inclusive(): void
    {
        // Nov 10 to Nov 14 = 5 days inclusive
        $days = $this->service->calculateTravelDays(
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-14'),
        );

        $this->assertEquals(5, $days);
    }

    public function test_single_day_trip(): void
    {
        $days = $this->service->calculateTravelDays(
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
        );

        $this->assertEquals(1, $days);
    }

    // ─────────────────────────────────────────────────────────────────
    // Validation Tests
    // ─────────────────────────────────────────────────────────────────

    public function test_rejects_unsupported_plan_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported plan code');

        $this->service->calculate(
            'GOLD',
            Carbon::parse('1996-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            100000,
        );
    }

    public function test_rejects_unsupported_destination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported destination');

        $this->service->calculate(
            'BASIC',
            Carbon::parse('1996-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'MARS',
            100000,
        );
    }

    public function test_rejects_unsupported_sum_insured(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sum insured');

        $this->service->calculate(
            'BASIC',
            Carbon::parse('1996-01-01'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            'DOMESTIC',
            200000,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // Complex Scenarios
    // ─────────────────────────────────────────────────────────────────

    public function test_complex_premium_calculation(): void
    {
        // PREMIUM, 10 days, age 15 (minor), INTERNATIONAL, 500000
        // 30 × 10 × 1.10 × 1.80 × 1.50 = 891.00
        $premium = $this->service->calculate(
            'PREMIUM',
            Carbon::parse('2011-06-01'),
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-10'),
            'INTERNATIONAL',
            500000,
        );

        $this->assertEquals('891.00', $premium);
    }
}
