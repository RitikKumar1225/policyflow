<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculationService
{
    private const BASE_RATES = [
        'BASIC' => '12',
        'PLUS' => '20',
        'PREMIUM' => '30',
    ];

    private const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    private const COVERAGE_MULTIPLIERS = [
        100000 => '1.00',
        300000 => '1.25',
        500000 => '1.50',
    ];

    /**
     * Calculate premium using decimal-safe arithmetic (bcmath).
     */
    public function calculate(
        string $planCode,
        Carbon $dateOfBirth,
        Carbon $tripStart,
        Carbon $tripEnd,
        string $destination,
        int $sumInsured,
    ): string {
        $this->validateInputs($planCode, $destination, $sumInsured);

        $baseDailyRate = self::BASE_RATES[$planCode];
        $travelDays = (string) $this->calculateTravelDays($tripStart, $tripEnd);
        $ageMultiplier = $this->getAgeMultiplier($dateOfBirth, $tripStart);
        $destinationMultiplier = self::DESTINATION_MULTIPLIERS[$destination];
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sumInsured];

        // premium = base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier
        $premium = bcmul($baseDailyRate, $travelDays, 4);
        $premium = bcmul($premium, $ageMultiplier, 4);
        $premium = bcmul($premium, $destinationMultiplier, 4);
        $premium = bcmul($premium, $coverageMultiplier, 4);

        // Round to 2 decimal places
        return $this->roundTo2Decimals($premium);
    }

    public function calculateTravelDays(Carbon $tripStart, Carbon $tripEnd): int
    {
        $days = $tripStart->diffInDays($tripEnd) + 1;
        return max(1, $days);
    }

    public function getAgeMultiplier(Carbon $dateOfBirth, Carbon $tripStart): string
    {
        $age = $dateOfBirth->age;
        // Calculate age at trip start
        $age = $dateOfBirth->diffInYears($tripStart);

        return match (true) {
            $age >= 0 && $age <= 17 => '1.10',
            $age >= 18 && $age <= 60 => '1.00',
            $age >= 61 && $age <= 70 => '1.50',
            $age >= 71 => '2.00',
            default => throw new InvalidArgumentException('Invalid age calculated'),
        };
    }

    public function validateInputs(string $planCode, string $destination, int $sumInsured): void
    {
        if (!isset(self::BASE_RATES[$planCode])) {
            throw new InvalidArgumentException(
                "Unsupported plan code: {$planCode}. Supported: " . implode(', ', array_keys(self::BASE_RATES))
            );
        }

        if (!isset(self::DESTINATION_MULTIPLIERS[$destination])) {
            throw new InvalidArgumentException(
                "Unsupported destination: {$destination}. Supported: " . implode(', ', array_keys(self::DESTINATION_MULTIPLIERS))
            );
        }

        if (!isset(self::COVERAGE_MULTIPLIERS[$sumInsured])) {
            throw new InvalidArgumentException(
                "Unsupported sum insured: {$sumInsured}. Supported: " . implode(', ', array_keys(self::COVERAGE_MULTIPLIERS))
            );
        }
    }

    /**
     * Round using bcmath for decimal safety.
     */
    private function roundTo2Decimals(string $value): string
    {
        // Use bcadd with scale 2 to achieve rounding
        return number_format((float) bcadd($value, '0', 2), 2, '.', '');
    }

    public static function getSupportedPlans(): array
    {
        return array_keys(self::BASE_RATES);
    }

    public static function getSupportedDestinations(): array
    {
        return array_keys(self::DESTINATION_MULTIPLIERS);
    }

    public static function getSupportedCoverages(): array
    {
        return array_keys(self::COVERAGE_MULTIPLIERS);
    }
}
