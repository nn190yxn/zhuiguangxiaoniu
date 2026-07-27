<?php
declare(strict_types=1);

final class WorkloadComparisonService {
    public function compare(
        float $currentValue,
        float $previousValue,
        int $currentSampleSize,
        int $previousSampleSize,
        bool $currentLowSample,
        bool $previousLowSample,
        array $pastValues = []
    ): array {
        $currentValue = round($currentValue, 2);
        $previousValue = round($previousValue, 2);
        $changeValue = round($currentValue - $previousValue, 2);

        if ($previousValue > 0.0) {
            $state = $currentValue === 0.0 ? 'down_to_zero' : 'comparable';
            $changeRate = round($changeValue / $previousValue, 4);
        } else {
            $state = $currentValue > 0.0 ? 'new' : 'flat';
            $changeRate = null;
        }

        return [
            'current_value' => $currentValue,
            'previous_value' => $previousValue,
            'change_value' => $changeValue,
            'change_rate' => $changeRate,
            'comparison_state' => $state,
            'past_four_period_average' => $this->averageValue(array_slice($pastValues, 0, 4)),
            'current_sample_size' => max(0, $currentSampleSize),
            'previous_sample_size' => max(0, $previousSampleSize),
            'low_sample' => $currentLowSample || $previousLowSample,
        ];
    }

    public function average(array $values): array {
        $numericValues = array_map(static fn(mixed $value): float => (float) $value, $values);
        $numerator = round(array_sum($numericValues), 2);
        $denominator = count($numericValues);
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'value' => $denominator > 0 ? round($numerator / $denominator, 2) : 0.0,
        ];
    }

    public function topQuartileReference(array $values): float {
        $numericValues = array_map(static fn(mixed $value): float => (float) $value, $values);
        if ($numericValues === []) {
            return 0.0;
        }
        rsort($numericValues, SORT_NUMERIC);
        $index = max(0, (int) ceil(count($numericValues) * 0.25) - 1);
        return round($numericValues[$index], 2);
    }

    public function benchmarks(array $values): array {
        return [
            'sample_size' => count($values),
            'average' => $this->average($values),
            'top_quartile_reference' => $this->topQuartileReference($values),
        ];
    }

    private function averageValue(array $values): float {
        return $this->average($values)['value'];
    }
}
