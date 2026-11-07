<?php

namespace App\Services;

use App\Models\Event;
use App\Models\GroupSchedule;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

class ScheduledEventGenerator
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const DEFAULT_TARGET_KATEGORI = [
        '1 SMP', '2 SMP', '3 SMP',
        '1 SMA/SMK', '2 SMA/SMK', '3 SMA/SMK',
        'USMAN', 'MT',
    ];

    /**
     * Generate scheduled events that are due and auto-close events that have ended.
     *
     * @param Carbon|null $now
     * @return array{created: int, closed: int}
     */
    public function run(?Carbon $now = null): array
    {
        $nowWib = ($now ? $now->copy() : Carbon::now())->setTimezone(self::TIMEZONE);

        $created = $this->generateDueEvents($nowWib);
        $closed = $this->autoCloseExpiredEvents($nowWib);

        return [
            'created' => $created,
            'closed' => $closed,
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
        $target = $schedule->target_kategori;
        if (empty($target) && $schedule->eventType && !empty($schedule->eventType->target_kategori)) {
            $target = $schedule->eventType->target_kategori;
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
            $eventDate = Carbon::parse($event->event_date)->toDateString();
            $startAt = Carbon::parse($eventDate . ' ' . $event->start_time, self::TIMEZONE);
            $endAt = Carbon::parse($eventDate . ' ' . $event->end_time, self::TIMEZONE);
            if ($endAt->lessThanOrEqualTo($startAt)) {
                $endAt->addDay();
            }

            $autoCloseMinutes = $event->groupSchedule?->auto_close_minutes ?? 120;
            $closeAt = $endAt->copy()->addMinutes($autoCloseMinutes);

            if ($nowWib->greaterThanOrEqualTo($closeAt)) {
                $event->is_closed = true;
                $event->auto_closed_at = Carbon::now();
                $event->save();
                $closedCount++;
            }
        }

        return $closedCount;
    }
}
