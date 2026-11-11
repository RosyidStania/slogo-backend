<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventType;
use App\Models\Generus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CategoryStatisticsService
{
    public const TIMEZONE = 'Asia/Jakarta';

    private const MONTH_FULL_NAMES = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    private const MONTH_SHORT_NAMES = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'Mei',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Agu',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Des',
    ];

    public function buildStatistics(
        ?string $startMonth,
        ?string $endMonth,
        string $scope = 'all',
        ?string $kelompok = null,
        ?Carbon $todayWib = null
    ): array {
        $nowWib = ($todayWib ? $todayWib->copy() : Carbon::now())->setTimezone(self::TIMEZONE);
        $todayDate = $nowWib->toDateString();
        $normalizedScope = $this->normalizeScope($scope);
        $normalizedKelompok = $this->normalizeNullableString($kelompok);

        $period = $this->resolvePeriod(
            $startMonth,
            $endMonth,
            $normalizedKelompok,
            $todayDate,
            $normalizedScope
        );

        $monthKeys = $this->buildMonthKeys($period['start_month'], $period['end_month']);
        $availableYears = $this->resolveAvailableYears($todayDate, (int) $nowWib->year);

        $allGenerus = Generus::with('pengurusTypes:id,name,code')
            ->whereRaw("LOWER(status) IN ('aktif', 'pasif')")
            ->get();

        $kelompokOptions = $this->extractKelompokOptions($allGenerus);

        $eventTypesQuery = EventType::query()->orderBy('name', 'asc');
        if ($normalizedScope === 'desa') {
            $eventTypesQuery->where('is_group_attendance', false);
        } elseif ($normalizedScope === 'kelompok') {
            $eventTypesQuery->where('is_group_attendance', true);
        }
        $eventTypes = $eventTypesQuery->get();

        $eventsByType = collect();
        if ($eventTypes->isNotEmpty() && !empty($monthKeys)) {
            $startDate = Carbon::createFromFormat('Y-m-d', $period['start_month'] . '-01', self::TIMEZONE)
                ->startOfMonth()
                ->toDateString();
            $endMonthDate = Carbon::createFromFormat('Y-m-d', $period['end_month'] . '-01', self::TIMEZONE)
                ->endOfMonth()
                ->toDateString();
            $effectiveEndDate = $endMonthDate <= $todayDate ? $endMonthDate : $todayDate;

            if ($startDate <= $effectiveEndDate) {
                $events = $this->qualifyingEventsBaseQuery($todayDate, $normalizedScope, $normalizedKelompok)
                    ->whereIn('event_type_id', $eventTypes->pluck('id')->all())
                    ->where('event_date', '>=', $startDate)
                    ->where('event_date', '<=', $effectiveEndDate)
                    ->with(['attendances.generus:id,nama_lengkap,kelompok,jenis_kelamin,jenjang,status'])
                    ->orderBy('event_date', 'asc')
                    ->orderBy('start_time', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                $eventsByType = $events->groupBy('event_type_id');
            }
        }

        $activeCategories = [];
        $emptyCategories = [];

        foreach ($eventTypes as $eventType) {
            $categoryEvents = $eventsByType->get($eventType->id, collect());
            $categoryStats = $this->buildCategoryStatistics(
                $eventType,
                $categoryEvents,
                $monthKeys,
                $allGenerus,
                $normalizedKelompok
            );

            if ($categoryStats['summary']['total_events'] > 0) {
                $activeCategories[] = $categoryStats;
            } else {
                $emptyCategories[] = $categoryStats;
            }
        }

        return [
            'period' => $period,
            'available_years' => $availableYears,
            'kelompok_options' => $kelompokOptions,
            'categories' => array_merge($activeCategories, $emptyCategories),
        ];
    }

    public function resolvePeriod(
        ?string $startMonth,
        ?string $endMonth,
        ?string $kelompok,
        string $todayDate,
        string $scope = 'all'
    ): array {
        $resolvedStart = $this->normalizeNullableString($startMonth);
        $resolvedEnd = $this->normalizeNullableString($endMonth);
        $normalizedKelompok = $this->normalizeNullableString($kelompok);
        $normalizedScope = $this->normalizeScope($scope);

        if ($resolvedEnd === null) {
            $latestDate = $this->qualifyingEventsBaseQuery($todayDate, $normalizedScope, $normalizedKelompok)
                ->max('event_date');

            $resolvedEnd = $latestDate
                ? Carbon::parse($latestDate, self::TIMEZONE)->format('Y-m')
                : Carbon::parse($todayDate, self::TIMEZONE)->format('Y-m');
        }

        if ($resolvedStart === null) {
            $resolvedStart = Carbon::createFromFormat('Y-m-d', $resolvedEnd . '-01', self::TIMEZONE)
                ->startOfMonth()
                ->subMonths(5)
                ->format('Y-m');
        }

        return [
            'start_month' => $resolvedStart,
            'end_month' => $resolvedEnd,
            'label' => $this->formatPeriodLabel($resolvedStart, $resolvedEnd),
        ];
    }

    protected function buildMonthKeys(string $startMonth, string $endMonth): array
    {
        $cursor = Carbon::createFromFormat('Y-m-d', $startMonth . '-01', self::TIMEZONE)->startOfMonth();
        $end = Carbon::createFromFormat('Y-m-d', $endMonth . '-01', self::TIMEZONE)->startOfMonth();

        $keys = [];
        while ($cursor->lessThanOrEqualTo($end)) {
            $keys[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        return $keys;
    }

    protected function resolveTargetGenerus(
        Event $event,
        ?EventType $eventType,
        Collection $allGenerus,
        ?string $filterKelompok
    ): Collection {
        $filteredGenerus = $allGenerus;

        $eventKelompok = $this->normalizeNullableString($event->kelompok);
        if ($eventKelompok !== null) {
            $filteredGenerus = $filteredGenerus->filter(
                fn (Generus $g) => $this->matchesKelompok($g->kelompok, $eventKelompok)
            );
        }

        $normalizedFilterKelompok = $this->normalizeNullableString($filterKelompok);
        if ($normalizedFilterKelompok !== null) {
            $filteredGenerus = $filteredGenerus->filter(
                fn (Generus $g) => $this->matchesKelompok($g->kelompok, $normalizedFilterKelompok)
            );
        }

        $targetKategori = $this->decodeTargetKategori($event->target_kategori);
        if (empty($targetKategori) && $eventType !== null) {
            $targetKategori = $this->decodeTargetKategori($eventType->target_kategori);
        }

        if (empty($targetKategori)) {
            return $filteredGenerus->values();
        }

        return $filteredGenerus
            ->filter(fn (Generus $g) => $this->matchesTargetKategori($g, $targetKategori))
            ->values();
    }

    protected function matchesTargetKategori(Generus $generus, array $targetKategori): bool
    {
        $jenjangLower = strtolower((string) ($generus->jenjang ?? ''));

        foreach ($targetKategori as $target) {
            $tLower = strtolower(trim($target));
            if ($tLower === '') {
                continue;
            }

            if ($tLower === 'pengurus' || $tLower === 'pengurus usman') {
                if (
                    $generus->is_pengurus
                    || ($generus->pengurusTypes && $generus->pengurusTypes->contains(
                        fn ($pt) => strtolower((string) $pt->code) === 'pengurus_usman'
                            || strtolower((string) $pt->name) === 'pengurus usman'
                    ))
                ) {
                    return true;
                }
            } elseif ($tLower === 'pengurus muda mudi') {
                if (
                    $generus->is_pengurus_muda_mudi
                    || ($generus->pengurusTypes && $generus->pengurusTypes->contains(
                        fn ($pt) => strtolower((string) $pt->code) === 'pengurus_muda_mudi'
                            || strtolower((string) $pt->name) === 'pengurus muda mudi'
                    ))
                ) {
                    return true;
                }
            } elseif ($tLower === 'ketua/wakil kelompok') {
                if (
                    $generus->is_ketua_wakil_kelompok
                    || ($generus->pengurusTypes && $generus->pengurusTypes->contains(
                        fn ($pt) => strtolower((string) $pt->code) === 'ketua_wakil_kelompok'
                            || strtolower((string) $pt->name) === 'ketua/wakil kelompok'
                    ))
                ) {
                    return true;
                }
            } elseif (
                $generus->pengurusTypes
                && $generus->pengurusTypes->contains(
                    fn ($pt) => strtolower((string) $pt->name) === $tLower
                        || strtolower((string) $pt->code) === $tLower
                )
            ) {
                return true;
            } elseif ($jenjangLower !== '' && str_contains($jenjangLower, $tLower)) {
                return true;
            }
        }

        return false;
    }

    protected function buildCategoryStatistics(
        EventType $eventType,
        Collection $categoryEvents,
        array $monthKeys,
        Collection $allGenerus,
        ?string $filterKelompok
    ): array {
        $monthlyBuckets = [];
        foreach ($monthKeys as $monthKey) {
            $monthlyBuckets[$monthKey] = [
                'events' => [],
                'target_total' => 0,
                'hadir' => 0,
                'terlambat' => 0,
                'izin_sakit' => 0,
                'alpa' => 0,
                'infaq' => 0,
                'has_desa_event' => false,
                'has_group_event' => false,
            ];
        }

        $groupAccum = [];
        $targetPutra = 0;
        $targetPutri = 0;
        $hadirPutra = 0;
        $hadirPutri = 0;

        $totalEvents = 0;
        $totalTarget = 0;
        $totalHadir = 0;
        $totalTerlambat = 0;
        $totalIzinSakit = 0;
        $totalAlpa = 0;
        $totalInfaq = 0;

        $hasDesaEvent = false;
        $hasMatchingGroupEvent = false;

        foreach ($categoryEvents as $event) {
            $eventDateStr = Carbon::parse($event->event_date, self::TIMEZONE)->toDateString();
            $monthKey = substr($eventDateStr, 0, 7);
            if (!isset($monthlyBuckets[$monthKey])) {
                continue;
            }

            $eventKelompok = $this->normalizeNullableString($event->kelompok);
            $isMatchingGroupEvent = $filterKelompok !== null
                && $eventKelompok !== null
                && $this->matchesKelompok($eventKelompok, $filterKelompok);

            if ($eventKelompok === null) {
                $hasDesaEvent = true;
                $monthlyBuckets[$monthKey]['has_desa_event'] = true;
            } elseif ($isMatchingGroupEvent) {
                $hasMatchingGroupEvent = true;
                $monthlyBuckets[$monthKey]['has_group_event'] = true;
            }

            $targetGenerus = $this->resolveTargetGenerus($event, $eventType, $allGenerus, $filterKelompok);

            $attendances = $event->attendances;
            if ($filterKelompok !== null) {
                $attendances = $attendances->filter(
                    fn ($att) => $this->matchesKelompok($att->generus?->kelompok, $filterKelompok)
                );
            }

            $targetTotal = $targetGenerus->count();
            $attSummary = $this->summarizeAttendances($attendances, $targetTotal);
            $hadirAttendances = $attSummary['hadir_attendances'];
            $hadirCount = $attSummary['hadir'];
            $terlambatCount = $attSummary['terlambat'];
            $izinSakitCount = $attSummary['izin_sakit'];
            $alpaCount = $attSummary['alpa'];
            $eventPercentage = $targetTotal > 0 ? (int) round(($hadirCount / $targetTotal) * 100) : 0;

            $eventInfaq = ($filterKelompok === null || $isMatchingGroupEvent)
                ? (int) ($event->infaq ?? 0)
                : 0;

            $monthlyBuckets[$monthKey]['events'][] = [
                'id' => (int) $event->id,
                'name' => (string) $event->name,
                'event_date' => $eventDateStr,
                'kelompok' => $eventKelompok,
                'percentage' => $eventPercentage,
            ];
            $monthlyBuckets[$monthKey]['target_total'] += $targetTotal;
            $monthlyBuckets[$monthKey]['hadir'] += $hadirCount;
            $monthlyBuckets[$monthKey]['terlambat'] += $terlambatCount;
            $monthlyBuckets[$monthKey]['izin_sakit'] += $izinSakitCount;
            $monthlyBuckets[$monthKey]['alpa'] += $alpaCount;
            $monthlyBuckets[$monthKey]['infaq'] += $eventInfaq;

            $totalEvents++;
            $totalTarget += $targetTotal;
            $totalHadir += $hadirCount;
            $totalTerlambat += $terlambatCount;
            $totalIzinSakit += $izinSakitCount;
            $totalAlpa += $alpaCount;
            $totalInfaq += $eventInfaq;

            $targetPutra += $targetGenerus->filter(
                fn (Generus $g) => strtoupper((string) $g->jenis_kelamin) === 'L'
            )->count();
            $targetPutri += $targetGenerus->filter(
                fn (Generus $g) => strtoupper((string) $g->jenis_kelamin) === 'P'
            )->count();
            $hadirPutra += $hadirAttendances->filter(
                fn ($att) => strtoupper((string) ($att->generus?->jenis_kelamin ?? '')) === 'L'
            )->count();
            $hadirPutri += $hadirAttendances->filter(
                fn ($att) => strtoupper((string) ($att->generus?->jenis_kelamin ?? '')) === 'P'
            )->count();

            $this->accumulateGroupBreakdown($groupAccum, $targetGenerus, $attendances);
        }

        $monthlySeries = [];
        $activePercentages = [];

        foreach ($monthKeys as $monthKey) {
            $bucket = $monthlyBuckets[$monthKey];
            $eventsCount = count($bucket['events']);
            $hasEvent = $eventsCount > 0;
            $percentage = null;

            if ($hasEvent) {
                $percentage = $bucket['target_total'] > 0
                    ? (int) round(($bucket['hadir'] / $bucket['target_total']) * 100)
                    : 0;
                $activePercentages[] = $percentage;
            }

            $monthInfaq = ($filterKelompok !== null && !$bucket['has_group_event'] && $bucket['has_desa_event'])
                ? null
                : $bucket['infaq'];

            $monthlySeries[] = [
                'month' => $monthKey,
                'label' => $this->formatShortMonthLabel($monthKey),
                'has_event' => $hasEvent,
                'events_count' => $eventsCount,
                'events' => $bucket['events'],
                'target_total' => $bucket['target_total'],
                'hadir' => $bucket['hadir'],
                'terlambat' => $bucket['terlambat'],
                'izin_sakit' => $bucket['izin_sakit'],
                'alpa' => $bucket['alpa'],
                'infaq' => $monthInfaq,
                'percentage' => $percentage,
            ];
        }

        $activeMonthsCount = count($activePercentages);
        $trendDelta = $activeMonthsCount >= 2
            ? ($activePercentages[$activeMonthsCount - 1] - $activePercentages[0])
            : null;

        $avgPercentage = $totalTarget > 0 ? (int) round(($totalHadir / $totalTarget) * 100) : 0;

        $resolvedTotalInfaq = ($filterKelompok !== null && !$hasMatchingGroupEvent && $hasDesaEvent)
            ? null
            : $totalInfaq;

        $groupBreakdown = array_values(array_map(function (array $row): array {
            $row['percentage'] = $row['target_total'] > 0
                ? (int) round(($row['hadir'] / $row['target_total']) * 100)
                : 0;

            return $row;
        }, $groupAccum));

        usort($groupBreakdown, function (array $a, array $b): int {
            $pctComparison = $b['percentage'] <=> $a['percentage'];

            return $pctComparison !== 0
                ? $pctComparison
                : strcasecmp($a['kelompok'], $b['kelompok']);
        });

        return [
            'event_type_id' => (int) $eventType->id,
            'name' => (string) $eventType->name,
            'code' => (string) $eventType->code,
            'is_group_attendance' => (bool) $eventType->is_group_attendance,
            'summary' => [
                'avg_percentage' => $avgPercentage,
                'trend_delta' => $trendDelta,
                'total_events' => $totalEvents,
                'active_months' => $activeMonthsCount,
                'total_target' => $totalTarget,
                'total_hadir' => $totalHadir,
                'total_terlambat' => $totalTerlambat,
                'total_izin_sakit' => $totalIzinSakit,
                'total_alpa' => $totalAlpa,
                'total_infaq' => $resolvedTotalInfaq,
            ],
            'monthly_series' => $monthlySeries,
            'group_breakdown' => $groupBreakdown,
            'gender_breakdown' => [
                'putra' => [
                    'target' => $targetPutra,
                    'hadir' => $hadirPutra,
                    'percentage' => $targetPutra > 0 ? (int) round(($hadirPutra / $targetPutra) * 100) : 0,
                ],
                'putri' => [
                    'target' => $targetPutri,
                    'hadir' => $hadirPutri,
                    'percentage' => $targetPutri > 0 ? (int) round(($hadirPutri / $targetPutri) * 100) : 0,
                ],
            ],
        ];
    }

    protected function accumulateGroupBreakdown(
        array &$groupAccum,
        Collection $targetGenerus,
        Collection $attendances
    ): void {
        $targetsByGroup = [];
        foreach ($targetGenerus as $generus) {
            $kelompokName = $this->normalizeNullableString($generus->kelompok);
            if ($kelompokName === null) {
                continue;
            }
            $key = mb_strtolower($kelompokName, 'UTF-8');
            if (!isset($targetsByGroup[$key])) {
                $targetsByGroup[$key] = [
                    'name' => $kelompokName,
                    'count' => 0,
                ];
            }
            $targetsByGroup[$key]['count']++;
        }

        $attendancesByGroup = [];
        foreach ($attendances as $att) {
            $kelompokName = $this->normalizeNullableString($att->generus?->kelompok);
            if ($kelompokName === null) {
                continue;
            }
            $key = mb_strtolower($kelompokName, 'UTF-8');
            if (!isset($attendancesByGroup[$key])) {
                $attendancesByGroup[$key] = [
                    'name' => $kelompokName,
                    'items' => [],
                ];
            }
            $attendancesByGroup[$key]['items'][] = $att;
        }

        $allGroupKeys = array_unique(array_merge(array_keys($targetsByGroup), array_keys($attendancesByGroup)));
        if (empty($allGroupKeys)) {
            return;
        }

        foreach ($allGroupKeys as $key) {
            $targetCount = $targetsByGroup[$key]['count'] ?? 0;
            $kelompokName = $targetsByGroup[$key]['name'] ?? $attendancesByGroup[$key]['name'];
            $groupAtts = collect($attendancesByGroup[$key]['items'] ?? []);
            $attSummary = $this->summarizeAttendances($groupAtts, $targetCount);

            if (!isset($groupAccum[$key])) {
                $groupAccum[$key] = [
                    'kelompok' => $kelompokName,
                    'target_total' => 0,
                    'hadir' => 0,
                    'terlambat' => 0,
                    'izin_sakit' => 0,
                    'alpa' => 0,
                ];
            }

            $groupAccum[$key]['target_total'] += $targetCount;
            $groupAccum[$key]['hadir'] += $attSummary['hadir'];
            $groupAccum[$key]['terlambat'] += $attSummary['terlambat'];
            $groupAccum[$key]['izin_sakit'] += $attSummary['izin_sakit'];
            $groupAccum[$key]['alpa'] += $attSummary['alpa'];
        }
    }

    protected function summarizeAttendances(Collection $attendances, int $targetTotal): array
    {
        $hadirAttendances = $attendances->filter(
            fn ($att) => strtolower((string) $att->status) === 'hadir'
        );
        $hadir = $hadirAttendances->count();
        $terlambat = $hadirAttendances->filter(fn ($att) => (bool) $att->is_late)->count();
        $izinSakit = $attendances->filter(
            fn ($att) => in_array(strtolower((string) $att->status), ['izin', 'sakit'], true)
        )->count();

        return [
            'hadir_attendances' => $hadirAttendances,
            'hadir' => $hadir,
            'terlambat' => $terlambat,
            'izin_sakit' => $izinSakit,
            'alpa' => max(0, $targetTotal - $hadir - $izinSakit),
        ];
    }

    protected function qualifyingEventsBaseQuery(
        string $todayDate,
        string $scope = 'all',
        ?string $kelompok = null
    ): Builder {
        $query = Event::query()
            ->whereNotNull('event_type_id')
            ->where('event_date', '<=', $todayDate)
            ->whereHas('attendances');

        if ($scope === 'desa') {
            $query->whereHas('eventType', fn (Builder $q) => $q->where('is_group_attendance', false));
        } elseif ($scope === 'kelompok') {
            $query->whereHas('eventType', fn (Builder $q) => $q->where('is_group_attendance', true));
        }

        $normalizedKelompok = $this->normalizeNullableString($kelompok);
        if ($normalizedKelompok !== null) {
            $kelompokLower = mb_strtolower($normalizedKelompok, 'UTF-8');
            $query->where(function (Builder $q) use ($kelompokLower) {
                $q->whereNull('kelompok')
                    ->orWhereRaw("TRIM(kelompok) = ''")
                    ->orWhereRaw('LOWER(TRIM(kelompok)) = ?', [$kelompokLower]);
            });
        }

        return $query;
    }

    protected function resolveAvailableYears(string $todayDate, int $currentYear): array
    {
        $dates = Event::query()
            ->whereNotNull('event_type_id')
            ->where('event_date', '<=', $todayDate)
            ->whereHas('attendances')
            ->distinct()
            ->pluck('event_date');

        $years = [$currentYear => true];
        foreach ($dates as $date) {
            $year = (int) substr((string) $date, 0, 4);
            if ($year > 0) {
                $years[$year] = true;
            }
        }

        $result = array_keys($years);
        rsort($result, SORT_NUMERIC);

        return array_values($result);
    }

    protected function extractKelompokOptions(Collection $allGenerus): array
    {
        $byLower = [];
        foreach ($allGenerus as $generus) {
            $kelompok = $this->normalizeNullableString($generus->kelompok);
            if ($kelompok === null) {
                continue;
            }
            $lower = mb_strtolower($kelompok, 'UTF-8');
            if (!isset($byLower[$lower])) {
                $byLower[$lower] = $kelompok;
            }
        }

        $options = array_values($byLower);
        usort($options, fn (string $a, string $b) => strcasecmp($a, $b));

        return $options;
    }

    protected function decodeTargetKategori(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(
                $value,
                fn ($item) => is_string($item) && trim($item) !== ''
            ));
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter(
                    $decoded,
                    fn ($item) => is_string($item) && trim($item) !== ''
                ));
            }
        }

        return [];
    }

    protected function formatPeriodLabel(string $startMonth, string $endMonth): string
    {
        if ($startMonth === $endMonth) {
            return $this->formatFullMonthLabel($startMonth);
        }

        return $this->formatShortMonthLabel($startMonth) . ' - ' . $this->formatShortMonthLabel($endMonth);
    }

    protected function formatShortMonthLabel(string $yearMonth): string
    {
        [$year, $month] = explode('-', $yearMonth);
        $monthNum = (int) $month;
        $short = self::MONTH_SHORT_NAMES[$monthNum] ?? $month;

        return $short . ' ' . $year;
    }

    protected function formatFullMonthLabel(string $yearMonth): string
    {
        [$year, $month] = explode('-', $yearMonth);
        $monthNum = (int) $month;
        $full = self::MONTH_FULL_NAMES[$monthNum] ?? $month;

        return $full . ' ' . $year;
    }

    protected function matchesKelompok(?string $candidate, string $expectedNormalized): bool
    {
        $normalizedCandidate = $this->normalizeNullableString($candidate);

        return $normalizedCandidate !== null
            && mb_strtolower($normalizedCandidate, 'UTF-8') === mb_strtolower($expectedNormalized, 'UTF-8');
    }

    protected function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function normalizeScope(?string $scope): string
    {
        $normalized = strtolower(trim((string) $scope));

        return in_array($normalized, ['desa', 'kelompok'], true) ? $normalized : 'all';
    }
}
