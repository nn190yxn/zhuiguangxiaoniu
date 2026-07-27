<?php
declare(strict_types=1);

final class WorkloadBusinessPeriodException extends RuntimeException {}

final class WorkloadBusinessPeriodService {
    private const PERIOD_TYPES = ['day', 'business_week', 'month_to_date', 'full_month', 'quarter', 'custom'];

    public function resolve(array $input): array {
        $periodType = strtolower(trim((string) ($input['period_type'] ?? '')));
        if (!in_array($periodType, self::PERIOD_TYPES, true)) {
            throw new WorkloadBusinessPeriodException('周期类型无效');
        }

        $anchor = $this->date($input['anchor_date'] ?? $input['date'] ?? date('Y-m-d'), '锚点日期');
        [$currentFrom, $currentTo] = $this->currentRange($periodType, $anchor, $input);
        $currentPeriod = $this->period($currentFrom, $currentTo);
        $previousPeriod = $this->previousPeriod($periodType, $anchor, $currentPeriod);
        $alignedCount = min($currentPeriod['business_day_count'], $previousPeriod['business_day_count']);
        $comparisonCurrent = $this->alignedPeriod($currentPeriod['business_dates'], $alignedCount);
        $comparisonPrevious = $this->alignedPeriod($previousPeriod['business_dates'], $alignedCount);

        return [
            'period_type' => $periodType,
            'anchor_date' => $anchor->format('Y-m-d'),
            'current_period' => $currentPeriod,
            'previous_period' => $previousPeriod,
            'comparison_current_period' => $comparisonCurrent,
            'comparison_previous_period' => $comparisonPrevious,
            'alignment' => [
                'business_day_count' => $alignedCount,
                'current_truncated' => $alignedCount < $currentPeriod['business_day_count'],
                'previous_truncated' => $alignedCount < $previousPeriod['business_day_count'],
            ],
        ];
    }

    public function isBusinessDay(DateTimeImmutable $date): bool {
        return (int) $date->format('N') !== 1;
    }

    private function currentRange(string $periodType, DateTimeImmutable $anchor, array $input): array {
        if ($periodType === 'day') {
            return [$anchor, $anchor];
        }
        if ($periodType === 'business_week') {
            $offset = (int) $anchor->format('N') === 1 ? 6 : (int) $anchor->format('N') - 2;
            $from = $anchor->modify('-' . $offset . ' days');
            return [$from, $from->modify('+5 days')];
        }
        if ($periodType === 'month_to_date') {
            return [$anchor->modify('first day of this month'), $anchor];
        }
        if ($periodType === 'full_month') {
            return [$anchor->modify('first day of this month'), $anchor->modify('last day of this month')];
        }
        if ($periodType === 'quarter') {
            $startMonth = ((int) floor(((int) $anchor->format('n') - 1) / 3) * 3) + 1;
            $from = $anchor->setDate((int) $anchor->format('Y'), $startMonth, 1);
            return [$from, $from->modify('+3 months')->modify('-1 day')];
        }

        $from = $this->date($input['date_from'] ?? null, '开始日期');
        $to = $this->date($input['date_to'] ?? null, '结束日期');
        if ($from > $to) {
            throw new WorkloadBusinessPeriodException('开始日期不能晚于结束日期');
        }
        if ($from->diff($to)->days + 1 > 366) {
            throw new WorkloadBusinessPeriodException('自定义周期不能超过 366 天');
        }
        return [$from, $to];
    }

    private function previousPeriod(
        string $periodType,
        DateTimeImmutable $anchor,
        array $currentPeriod
    ): array {
        if ($currentPeriod['business_day_count'] === 0) {
            return $this->alignedPeriod([], 0);
        }
        if ($periodType === 'business_week') {
            $from = (new DateTimeImmutable($currentPeriod['date_from']))->modify('-7 days');
            return $this->period($from, $from->modify('+5 days'));
        }
        if ($periodType === 'month_to_date') {
            $from = $anchor->modify('first day of previous month');
            $to = $anchor->modify('last day of previous month');
            return $this->periodFromFirstBusinessDays($from, $to, $currentPeriod['business_day_count']);
        }
        if ($periodType === 'full_month') {
            $from = $anchor->modify('first day of previous month');
            return $this->period($from, $from->modify('last day of this month'));
        }
        if ($periodType === 'quarter') {
            $currentFrom = new DateTimeImmutable($currentPeriod['date_from']);
            $from = $currentFrom->modify('-3 months');
            return $this->period($from, $currentFrom->modify('-1 day'));
        }

        $cursor = (new DateTimeImmutable($currentPeriod['date_from']))->modify('-1 day');
        return $this->periodBefore($cursor, $currentPeriod['business_day_count']);
    }

    private function period(DateTimeImmutable $from, DateTimeImmutable $to): array {
        $businessDates = [];
        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
            if ($this->isBusinessDay($cursor)) {
                $businessDates[] = $cursor->format('Y-m-d');
            }
        }
        return [
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
            'business_dates' => $businessDates,
            'business_day_count' => count($businessDates),
        ];
    }

    private function periodFromFirstBusinessDays(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit
    ): array {
        $period = $this->period($from, $to);
        $dates = array_slice($period['business_dates'], 0, $limit);
        if ($dates === []) {
            return $this->alignedPeriod([], 0);
        }
        return [
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $dates[count($dates) - 1],
            'business_dates' => $dates,
            'business_day_count' => count($dates),
        ];
    }

    private function periodBefore(DateTimeImmutable $cursor, int $businessDayCount): array {
        $dates = [];
        while (count($dates) < $businessDayCount) {
            if ($this->isBusinessDay($cursor)) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('-1 day');
        }
        sort($dates);
        return $this->alignedPeriod($dates, count($dates));
    }

    private function alignedPeriod(array $dates, int $limit): array {
        $dates = array_slice($dates, 0, $limit);
        return [
            'date_from' => $dates[0] ?? null,
            'date_to' => $dates === [] ? null : $dates[count($dates) - 1],
            'business_dates' => $dates,
            'business_day_count' => count($dates),
        ];
    }

    private function date(mixed $value, string $label): DateTimeImmutable {
        $text = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $text) {
            throw new WorkloadBusinessPeriodException($label . '格式必须为 YYYY-MM-DD');
        }
        return $date;
    }
}
