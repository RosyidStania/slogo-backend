<?php

namespace Database\Seeders;

use App\Models\EventType;
use App\Models\GroupSchedule;
use Illuminate\Database\Seeder;

class GroupScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultKategori = [
            '1 SMP', '2 SMP', '3 SMP',
            '1 SMA/SMK', '2 SMA/SMK', '3 SMA/SMK',
            'USMAN', 'MT',
        ];

        $eventType = EventType::where('code', '8')
            ->orWhere('name', 'Pengajian Muda Mudi Kelompok')
            ->orWhere(function ($q) {
                $q->where('is_group_attendance', true)
                  ->where('name', 'like', '%Muda Mudi%');
            })
            ->first();

        if (!$eventType) {
            $eventType = EventType::create([
                'name' => 'Pengajian Muda Mudi Kelompok',
                'code' => '8',
                'description' => 'Jadwal rutin pengajian muda mudi per kelompok',
                'start_time' => '19:30:00',
                'target_kategori' => $defaultKategori,
                'is_group_attendance' => true,
            ]);
        }

        $targetKategori = !empty($eventType->target_kategori)
            ? $eventType->target_kategori
            : $defaultKategori;

        // Carbon dayOfWeek: 2 = Selasa (Malam Rabu), 3 = Rabu (Malam Kamis), 5 = Jumat (Malam Sabtu)
        $schedules = [
            // 1. Karangasem
            ['kelompok' => 'Karangasem', 'day_of_week' => 2, 'start_time' => '18:30:00', 'end_time' => '20:00:00'],
            ['kelompok' => 'Karangasem', 'day_of_week' => 3, 'start_time' => '19:30:00', 'end_time' => '21:00:00'],
            ['kelompok' => 'Karangasem', 'day_of_week' => 5, 'start_time' => '19:30:00', 'end_time' => '21:00:00'],

            // 2. Gabugan
            ['kelompok' => 'Gabugan', 'day_of_week' => 2, 'start_time' => '19:30:00', 'end_time' => '20:30:00'],
            ['kelompok' => 'Gabugan', 'day_of_week' => 3, 'start_time' => '19:30:00', 'end_time' => '20:30:00'],
            ['kelompok' => 'Gabugan', 'day_of_week' => 5, 'start_time' => '19:30:00', 'end_time' => '20:30:00'],

            // 3. Sidomulyo (di catatan user: Sidomulya)
            ['kelompok' => 'Sidomulyo', 'day_of_week' => 2, 'start_time' => '18:15:00', 'end_time' => '20:00:00'],
            ['kelompok' => 'Sidomulyo', 'day_of_week' => 3, 'start_time' => '18:15:00', 'end_time' => '20:00:00'],
            ['kelompok' => 'Sidomulyo', 'day_of_week' => 5, 'start_time' => '18:15:00', 'end_time' => '20:00:00'],

            // 4. Pengkruk
            ['kelompok' => 'Pengkruk', 'day_of_week' => 2, 'start_time' => '18:30:00', 'end_time' => '20:15:00'],
            ['kelompok' => 'Pengkruk', 'day_of_week' => 5, 'start_time' => '18:30:00', 'end_time' => '20:15:00'],

            // 5. Slogo
            ['kelompok' => 'Slogo', 'day_of_week' => 2, 'start_time' => '19:30:00', 'end_time' => '21:00:00'],
            ['kelompok' => 'Slogo', 'day_of_week' => 5, 'start_time' => '19:30:00', 'end_time' => '21:00:00'],

            // 6. Jekani
            ['kelompok' => 'Jekani', 'day_of_week' => 2, 'start_time' => '18:15:00', 'end_time' => '19:00:00'],
            ['kelompok' => 'Jekani', 'day_of_week' => 3, 'start_time' => '18:15:00', 'end_time' => '19:00:00'],
            ['kelompok' => 'Jekani', 'day_of_week' => 5, 'start_time' => '18:15:00', 'end_time' => '19:00:00'],

            // 7. Gawan
            ['kelompok' => 'Gawan', 'day_of_week' => 2, 'start_time' => '19:30:00', 'end_time' => '21:00:00'],
            ['kelompok' => 'Gawan', 'day_of_week' => 3, 'start_time' => '18:30:00', 'end_time' => '20:25:00'],
            ['kelompok' => 'Gawan', 'day_of_week' => 5, 'start_time' => '19:30:00', 'end_time' => '21:00:00'],
        ];

        foreach ($schedules as $item) {
            GroupSchedule::updateOrCreate(
                [
                    'kelompok' => $item['kelompok'],
                    'day_of_week' => $item['day_of_week'],
                ],
                [
                    'start_time' => $item['start_time'],
                    'end_time' => $item['end_time'],
                    'event_type_id' => $eventType->id,
                    'name' => $eventType->name,
                    'target_kategori' => $targetKategori,
                    'allow_other_participants' => false,
                    'lead_minutes' => 30,
                    'auto_close_minutes' => 120,
                    'is_active' => true,
                ]
            );
        }
    }
}
