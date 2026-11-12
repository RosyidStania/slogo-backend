<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Generus;
use App\Models\GroupSchedule;
use App\Support\TargetKategoriFilter;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ScheduledEventGenerator
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const DEFAULT_TARGET_KATEGORI = [
        '1 SMP', '2 SMP', '3 SMP',
        '1 SMA/SMK', '2 SMA/SMK', '3 SMA/SMK',
        'USMAN', 'MT',
    ];

    /**
     * Generate scheduled events that are due, auto-close expired events,
     * and auto-mark remaining unrecorded target generus as alpa once.
     *
     * @param Carbon|null $now
     * @return array{created: int, closed: int, alpa_marked: int}
     */
    public function run(?Carbon $now = null): array
    {
        $nowWib = ($now ? $now->copy() : Carbon::now())->setTimezone(self::TIMEZONE);

        $created = $this->generateDueEvents($nowWib);
        $closed = $this->autoCloseExpiredEvents($nowWib);
        $alpaMarked = $this->autoAlpaExpiredEvents($nowWib);

        return [
            'created' => $created,
            'closed' => $closed,
            'alpa_marked' => $alpaMarked,
        ];
    }

    protected function generateDueEvents(Carbon $nowWib): int
    {
        $todayDate = $nowWib->toDateString();
        $dayOfWeek = $nowWib->dayOfWeek; // 0 (Sunday) .. 6 (Saturday)

        $schedules = GroupSchedule::with('eventType')
            ->where('is_active', true)
            ->where('day_of_week', $dayOfWeek)
            ->where(function ($q) use ($todayDate) {
                $q->whereNull('last_generated_date')
                  ->orWhere('last_generated_date', '<>', $todayDate);
            })
            ->get();

        $createdCount = 0;

        foreach ($schedules as $schedule) {
            $startAt = Carbon::parse($todayDate . ' ' . $schedule->start_time, self::TIMEZONE);
            $endAt = Carbon::parse($todayDate . ' ' . $schedule->end_time, self::TIMEZONE);
            if ($endAt->lessThanOrEqualTo($startAt)) {
                $endAt->addDay();
            }

            $leadMinutes = $schedule->lead_minutes ?? 30;
            $autoCloseMinutes = $schedule->auto_close_minutes ?? 120;

            $openWindowStart = $startAt->copy()->subMinutes($leadMinutes);
            $closeWindowEnd = $endAt->copy()->addMinutes($autoCloseMinutes);

            // Hanya buat jika sudah masuk H - lead_minutes dan belum lewat waktu auto-close
            if ($nowWib->lessThan($openWindowStart) || $nowWib->greaterThanOrEqualTo($closeWindowEnd)) {
                continue;
            }

            $alreadyExists = Event::where('group_schedule_id', $schedule->id)
                ->where('event_date', $todayDate)
                ->exists();

            // Acara manual (bukan dari jadwal) untuk kelompok + tanggal + jenis acara yang sama
            $manualQuery = Event::whereNull('group_schedule_id')
                ->where('event_date', $todayDate)
                ->whereRaw('LOWER(kelompok) = ?', [strtolower($schedule->kelompok)]);
            if ($schedule->event_type_id) {
                $manualQuery->where('event_type_id', $schedule->event_type_id);
            }

            if ($alreadyExists || $manualQuery->exists()) {
                $this->markGenerated($schedule, $todayDate);
                continue;
            }

            try {
                Event::create([
                    'name' => $schedule->name,
                    'event_date' => $todayDate,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'target_kategori' => json_encode($this->resolveTargetKategori($schedule)),
                    'event_type_id' => $schedule->event_type_id,
                    'group_schedule_id' => $schedule->id,
                    'allow_other_participants' => (bool) $schedule->allow_other_participants,
                    'kelompok' => $schedule->kelompok,
                    'is_closed' => false,
                ]);
                $createdCount++;
            } catch (UniqueConstraintViolationException $e) {
                // Request lain (cron / halaman acara) sudah membuatnya lebih dulu
            }

            $this->markGenerated($schedule, $todayDate);
        }

        return $createdCount;
    }

    protected function markGenerated(GroupSchedule $schedule, string $date): void
    {
        $schedule->last_generated_date = $date;
        $schedule->saveQuietly();
    }

    protected function resolveTargetKategori(GroupSchedule $schedule): array
    {
        // Prioritaskan template acara terbaru jika schedule terhubung ke eventType
        if ($schedule->eventType && !empty($schedule->eventType->target_kategori)) {
            $target = $schedule->eventType->target_kategori;
        } else {
            $target = $schedule->target_kategori;
        }

        if (is_string($target)) {
            $target = json_decode($target, true) ?: [];
        }

        return !empty($target) ? array_values($target) : self::DEFAULT_TARGET_KATEGORI;
    }

    protected function autoCloseExpiredEvents(Carbon $nowWib): int
    {
        // Hanya acara yang belum pernah ditutup otomatis, supaya acara yang dibuka
        // kembali secara manual oleh operator tidak langsung ditutup lagi.
        $openEvents = Event::with('groupSchedule')
            ->where('is_closed', false)
            ->whereNull('auto_closed_at')
            ->whereNotNull('end_time')
            ->get();

        $closedCount = 0;

        foreach ($openEvents as $event) {
            if ($nowWib->greaterThanOrEqualTo($this->resolveEventCloseAt($event))) {
                $event->is_closed = true;
                $event->auto_closed_at = Carbon::now();
                $event->save();
                $closedCount++;
            }
        }

        return $closedCount;
    }

    /**
     * Hitung waktu kedaluwarsa acara (end_time + auto_close_minutes) dalam zona WIB.
     */
    protected function resolveEventCloseAt(Event $event): Carbon
    {
        $eventDate = Carbon::parse($event->event_date)->toDateString();
        $endAt = Carbon::parse($eventDate . ' ' . $event->end_time, self::TIMEZONE);

        if (! empty($event->start_time)) {
            $startAt = Carbon::parse($eventDate . ' ' . $event->start_time, self::TIMEZONE);
            if ($endAt->lessThanOrEqualTo($startAt)) {
                $endAt->addDay();
            }
        }

        $autoCloseMinutes = $event->groupSchedule?->auto_close_minutes ?? 120;

        return $endAt->copy()->addMinutes($autoCloseMinutes);
    }

    /**
     * Evaluasi satu kali acara yang telah melewati batas waktu auto-close untuk
     * menandai sisa target generus yang belum memiliki data presensi sebagai alpa.
     */
    protected function autoAlpaExpiredEvents(Carbon $nowWib): int
    {
        $pendingEvents = Event::with(['groupSchedule', 'eventType'])
            ->whereNull('auto_alpa_at')
            ->whereNotNull('end_time')
            ->get();

        $alpaMarkedCount = 0;

        foreach ($pendingEvents as $event) {
            if ($nowWib->greaterThanOrEqualTo($this->resolveEventCloseAt($event))) {
                $alpaMarkedCount += $this->markRemainingTargetsAlpa($event);
            }
        }

        return $alpaMarkedCount;
    }

    /**
     * Klaim acara secara atomik dan sisipkan status alpa bagi sisa target yang belum absen.
     */
    protected function markRemainingTargetsAlpa(Event $event): int
    {
        return DB::transaction(function () use ($event): int {
            $nowTimestamp = Carbon::now();

            // Klaim satu kali secara atomik untuk mencegah duplikasi pada eksekusi konkuren.
            $claimed = Event::where('id', $event->id)
                ->whereNull('auto_alpa_at')
                ->update(['auto_alpa_at' => $nowTimestamp]);

            if ($claimed !== 1) {
                return 0;
            }

            $existingGenerusIds = Attendance::where('event_id', $event->id)
                ->pluck('generus_id')
                ->all();

            // Acara tanpa presensi sama sekali (mis. libur/dibatalkan) tidak dibuatkan alpa.
            if (empty($existingGenerusIds)) {
                return 0;
            }

            $recordedLookup = array_flip(array_filter(
                $existingGenerusIds,
                fn ($id) => is_int($id) || is_string($id)
            ));

            $query = Generus::select([
                'id',
                'kelompok',
                'jenjang',
                'status',
                'is_pengurus',
                'is_pengurus_muda_mudi',
                'is_ketua_wakil_kelompok',
            ])
                ->with('pengurusTypes:id,name,code')
                ->whereRaw("LOWER(status) IN ('aktif', 'pasif')");

            if (trim((string) $event->kelompok) !== '') {
                $normalizedKelompok = mb_strtolower(trim((string) $event->kelompok), 'UTF-8');
                $query->whereRaw('LOWER(TRIM(kelompok)) = ?', [$normalizedKelompok]);
            }

            $candidates = $query->get()
                ->reject(fn (Generus $generus) => isset($recordedLookup[$generus->id]))
                ->values();

            $targets = TargetKategoriFilter::filter($candidates, $this->resolveEventTargetKategori($event));

            if ($targets->isEmpty()) {
                return 0;
            }

            $rows = $targets->map(fn (Generus $generus): array => [
                'event_id' => $event->id,
                'generus_id' => $generus->id,
                'status' => 'alpa',
                'time_arrived' => null,
                'is_late' => false,
                'created_at' => $nowTimestamp,
                'updated_at' => $nowTimestamp,
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                Attendance::insert($chunk);
            }

            return count($rows);
        });
    }

    /**
     * Resolve daftar target_kategori acara dengan fallback ke template eventType.
     *
     * @return array<int, mixed>
     */
    protected function resolveEventTargetKategori(Event $event): array
    {
        $rawTarget = $event->target_kategori;
        $targetKategori = is_string($rawTarget)
            ? (json_decode($rawTarget, true) ?? [])
            : (is_array($rawTarget) ? $rawTarget : []);

        if (empty($targetKategori) && $event->eventType) {
            $typeTarget = $event->eventType->target_kategori;
            $targetKategori = is_string($typeTarget)
                ? (json_decode($typeTarget, true) ?? [])
                : (is_array($typeTarget) ? $typeTarget : []);
        }

        return is_array($targetKategori) ? $targetKategori : [];
    }
}
