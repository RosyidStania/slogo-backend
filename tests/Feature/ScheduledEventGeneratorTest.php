<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GroupSchedule;
use App\Services\ScheduledEventGenerator;
use Carbon\Carbon;
use Database\Seeders\GroupScheduleSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduledEventGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Jalankan hanya migrasi yang relevan dan kompatibel dengan SQLite in-memory
        $migrations = [
            'database/migrations/2026_05_16_101334_create_events_table.php',
            'database/migrations/2026_05_16_104305_add_target_kategori_to_events_table.php',
            'database/migrations/2026_05_17_174305_create_event_types_table.php',
            'database/migrations/2026_05_17_174338_add_event_type_id_to_events_table.php',
            'database/migrations/2026_05_17_175934_add_template_fields_to_event_types_table.php',
            'database/migrations/2026_05_17_181932_add_event_type_id_to_events_table.php',
            'database/migrations/2026_08_30_190132_add_is_closed_to_events_table.php',
            'database/migrations/2026_09_02_110324_add_allow_other_participants_to_events_table.php',
            'database/migrations/2026_10_03_131233_add_kelompok_to_users_and_events_table.php',
            'database/migrations/2026_10_03_135857_add_is_group_attendance_to_event_types_table.php',
            'database/migrations/2026_10_04_032139_add_infaq_to_events_table.php',
            'database/migrations/2026_11_07_224500_create_group_schedules_table.php',
            'database/migrations/2026_11_07_224501_add_schedule_fields_to_events_table.php',
            'database/migrations/2026_11_08_021500_add_generation_tracking_columns.php',
        ];

        Artisan::call('migrate', ['--path' => 'database/migrations/0001_01_01_000000_create_users_table.php']);
        foreach ($migrations as $path) {
            Artisan::call('migrate', ['--path' => $path]);
        }
    }

    public function test_seeder_populates_19_schedules(): void
    {
        $this->seed(GroupScheduleSeeder::class);

        $this->assertEquals(19, GroupSchedule::count());
    }

    public function test_generates_event_30_minutes_before_start_and_is_idempotent(): void
    {
        $this->seed(GroupScheduleSeeder::class);

        $generator = app(ScheduledEventGenerator::class);

        // Selasa (day_of_week = 2) -> Malam Rabu
        // Karangasem start_time = 18:30, jadi H-30 menit = 18:00 WIB
        // 1. Pukul 17:40 WIB -> Belum ada yang masuk H-30 (Sidomulyo & Jekani mulai 18:15 -> H-30 = 17:45)
        $beforeWindow = Carbon::parse('2026-11-10 17:40:00', 'Asia/Jakarta');
        $res1 = $generator->run($beforeWindow);
        $this->assertEquals(0, $res1['created']);
        $this->assertEquals(0, Event::count());

        // 2. Pukul 17:45 WIB -> Sidomulyo & Jekani (start 18:15) harus dibuat (2 acara)
        $at1745 = Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta');
        $res2 = $generator->run($at1745);
        $this->assertEquals(2, $res2['created']);
        $this->assertEquals(2, Event::count());

        // 3. Pukul 18:00 WIB -> Karangasem & Pengkruk (start 18:30) dibuat (+2 acara), Sidomulyo & Jekani tidak dobel
        $at1800 = Carbon::parse('2026-11-10 18:00:00', 'Asia/Jakarta');
        $res3 = $generator->run($at1800);
        $this->assertEquals(2, $res3['created']);
        $this->assertEquals(4, Event::count());

        // 4. Pukul 19:00 WIB -> Gabugan, Slogo, Gawan (start 19:30) dibuat (+3 acara)
        $at1900 = Carbon::parse('2026-11-10 19:00:00', 'Asia/Jakarta');
        $res4 = $generator->run($at1900);
        $this->assertEquals(3, $res4['created']);
        $this->assertEquals(7, Event::count());

        // Jalankan sekali lagi pada 19:05 -> tidak ada yang dobel
        $res5 = $generator->run(Carbon::parse('2026-11-10 19:05:00', 'Asia/Jakarta'));
        $this->assertEquals(0, $res5['created']);
        $this->assertEquals(7, Event::count());
    }

    public function test_auto_closes_event_2_hours_after_end_time(): void
    {
        $this->seed(GroupScheduleSeeder::class);

        $generator = app(ScheduledEventGenerator::class);

        // Buat acara Jekani hari Selasa (18:15 - 19:00) pada pukul 17:45
        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));

        $jekaniEvent = Event::where('kelompok', 'Jekani')->firstOrFail();
        $this->assertFalse((bool) $jekaniEvent->is_closed);

        // Pukul 20:59 WIB (1 menit sebelum 2 jam setelah 19:00) -> masih buka
        $generator->run(Carbon::parse('2026-11-10 20:59:00', 'Asia/Jakarta'));
        $this->assertFalse((bool) $jekaniEvent->fresh()->is_closed);

        // Pukul 21:00 WIB (tepat 2 jam setelah 19:00) -> otomatis ditutup!
        $resClose = $generator->run(Carbon::parse('2026-11-10 21:00:00', 'Asia/Jakarta'));
        $this->assertGreaterThanOrEqual(1, $resClose['closed']);
        $this->assertTrue((bool) $jekaniEvent->fresh()->is_closed);
    }

    public function test_inactive_schedule_does_not_generate_event(): void
    {
        $this->seed(GroupScheduleSeeder::class);

        GroupSchedule::where('kelompok', 'Jekani')->update(['is_active' => false]);

        $generator = app(ScheduledEventGenerator::class);
        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));

        $this->assertNull(Event::where('kelompok', 'Jekani')->first());
        $this->assertNotNull(Event::where('kelompok', 'Sidomulyo')->first());
    }

    public function test_deleted_event_is_not_recreated_on_same_day(): void
    {
        $this->seed(GroupScheduleSeeder::class);
        $generator = app(ScheduledEventGenerator::class);

        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));
        Event::where('kelompok', 'Jekani')->delete();

        $generator->run(Carbon::parse('2026-11-10 17:50:00', 'Asia/Jakarta'));
        $this->assertNull(Event::where('kelompok', 'Jekani')->first());

        // Minggu depannya tetap dibuat lagi
        $generator->run(Carbon::parse('2026-11-17 17:45:00', 'Asia/Jakarta'));
        $this->assertNotNull(Event::where('kelompok', 'Jekani')->where('event_date', '2026-11-17')->first());
    }

    public function test_manually_reopened_event_is_not_closed_again(): void
    {
        $this->seed(GroupScheduleSeeder::class);
        $generator = app(ScheduledEventGenerator::class);

        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));
        $generator->run(Carbon::parse('2026-11-10 21:00:00', 'Asia/Jakarta'));

        $event = Event::where('kelompok', 'Jekani')->firstOrFail();
        $this->assertTrue((bool) $event->fresh()->is_closed);

        // Operator membuka kembali absen
        $event->update(['is_closed' => false]);

        $generator->run(Carbon::parse('2026-11-10 21:05:00', 'Asia/Jakarta'));
        $this->assertFalse((bool) $event->fresh()->is_closed);
    }

    public function test_toggle_status_reopens_expired_event_and_it_stays_open(): void
    {
        $this->seed(GroupScheduleSeeder::class);
        $generator = app(ScheduledEventGenerator::class);

        // Generate events for 17:45 (Jekani: 18:15 - 19:00, auto-close at 21:00)
        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));
        $event = Event::where('kelompok', 'Jekani')->firstOrFail();

        // Operator closes it manually before auto-close (auto_closed_at is null)
        $event->update(['is_closed' => true, 'auto_closed_at' => null]);

        // Now time is 21:30 (past auto close time)
        // Operator re-opens the event via toggleStatus
        $admin = \App\Models\User::create([
            'name' => 'Admin Test',
            'username' => 'admintest_' . uniqid(),
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/events/{$event->id}/toggle-status")
            ->assertOk()
            ->assertJson(['success' => true, 'is_closed' => false]);

        $this->assertFalse((bool) $event->fresh()->is_closed);

        // Now generator runs again at 21:35
        $generator->run(Carbon::parse('2026-11-10 21:35:00', 'Asia/Jakarta'));

        // Event MUST stay open!
        $this->assertFalse((bool) $event->fresh()->is_closed);
    }

    public function test_two_schedules_same_kelompok_same_day_both_generate(): void
    {
        $this->seed(GroupScheduleSeeder::class);

        $base = GroupSchedule::where('kelompok', 'Jekani')->where('day_of_week', 2)->firstOrFail();
        GroupSchedule::create(array_merge($base->only([
            'kelompok', 'day_of_week', 'event_type_id', 'name', 'target_kategori',
        ]), [
            'start_time' => '20:00:00',
            'end_time' => '21:00:00',
        ]));

        $generator = app(ScheduledEventGenerator::class);
        $generator->run(Carbon::parse('2026-11-10 19:45:00', 'Asia/Jakarta'));

        $this->assertEquals(2, Event::where('kelompok', 'Jekani')->count());
    }
}
