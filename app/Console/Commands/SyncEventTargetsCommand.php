<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventType;
use App\Models\GroupSchedule;
use App\Services\ScheduledEventGenerator;
use Illuminate\Console\Command;

class SyncEventTargetsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:sync-targets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinkronisasi target peserta (termasuk USMAN) dari Template Acara ke Jadwal Kelompok dan Acara aktif';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Memulai sinkronisasi target peserta...');

        // 1. Cari Template Acara Kelompok
        $eventType = EventType::where('code', '8')
            ->orWhere('name', 'Pengajian Muda Mudi Kelompok')
            ->orWhere('is_group_attendance', true)
            ->first();

        $targetKategori = ScheduledEventGenerator::DEFAULT_TARGET_KATEGORI;

        if ($eventType) {
            $cats = $eventType->target_kategori ?: [];
            if (!in_array('USMAN', $cats)) {
                $cats[] = 'USMAN';
            }
            if (!in_array('MT', $cats)) {
                $cats[] = 'MT';
            }
            $targetKategori = array_values(array_unique($cats));
            $eventType->update(['target_kategori' => $targetKategori]);
            $this->info("Template '{$eventType->name}' diperbarui dengan " . count($targetKategori) . " target kategori.");
        } else {
            $this->warn('Template Pengajian Muda Mudi Kelompok tidak ditemukan, menggunakan default.');
        }

        // 2. Perbarui semua GroupSchedule
        $scheduleQuery = GroupSchedule::query();
        if ($eventType) {
            $scheduleQuery->where('event_type_id', $eventType->id)->orWhereNull('event_type_id');
        }
        $updatedSchedules = $scheduleQuery->update([
            'target_kategori' => $targetKategori,
            'event_type_id' => $eventType?->id,
        ]);
        $this->info("{$updatedSchedules} jadwal kelompok berhasil diperbarui.");

        // 3. Perbarui Acara yang masih terbuka (is_closed = false)
        $events = Event::where(function ($q) use ($eventType) {
            $q->whereNotNull('group_schedule_id');
            if ($eventType) {
                $q->orWhere('event_type_id', $eventType->id);
            }
            $q->orWhere('name', 'like', '%Kelompok%');
        })
        ->where('is_closed', false)
        ->get();

        $updatedEventsCount = 0;
        foreach ($events as $event) {
            $current = json_decode($event->target_kategori, true) ?: [];
            if (!in_array('USMAN', $current)) {
                $current[] = 'USMAN';
                $event->update(['target_kategori' => json_encode(array_values(array_unique($current)))]);
                $updatedEventsCount++;
            }
        }
        $this->info("{$updatedEventsCount} acara aktif hari ini berhasil ditambahkan USMAN.");

        $this->info('Sinkronisasi selesai! Semua jadwal kelompok dan acara aktif kini menyertakan USMAN.');

        return self::SUCCESS;
    }
}
