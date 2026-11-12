<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Generus;
use App\Models\GroupSchedule;
use App\Models\User;
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
            'database/migrations/2026_05_15_123233_create_generuses_table.php',
            'database/migrations/2026_05_16_101334_create_events_table.php',
            'database/migrations/2026_05_16_101335_create_attendances_table.php',
            'database/migrations/2026_05_16_104305_add_target_kategori_to_events_table.php',
            'database/migrations/2026_05_17_174305_create_event_types_table.php',
            'database/migrations/2026_05_17_174338_add_event_type_id_to_events_table.php',
            'database/migrations/2026_05_17_175934_add_template_fields_to_event_types_table.php',
            'database/migrations/2026_05_17_181932_add_event_type_id_to_events_table.php',
            'database/migrations/2026_07_29_195225_add_user_id_to_generus_table.php',
            'database/migrations/2026_08_06_104335_add_kode_unik_to_generus_table.php',
            'database/migrations/2026_08_18_203140_add_is_pengurus_to_generus_table.php',
            'database/migrations/2026_08_30_190132_add_is_closed_to_events_table.php',
            'database/migrations/2026_09_02_082219_add_is_pengurus_muda_mudi_to_generus_table.php',
            'database/migrations/2026_09_02_110324_add_allow_other_participants_to_events_table.php',
            'database/migrations/2026_09_02_111505_add_is_ketua_wakil_kelompok_to_generus_table.php',
            'database/migrations/2026_10_03_131233_add_kelompok_to_users_and_events_table.php',
            'database/migrations/2026_10_03_135857_add_is_group_attendance_to_event_types_table.php',
            'database/migrations/2026_10_04_032139_add_infaq_to_events_table.php',
            'database/migrations/2026_11_07_224500_create_group_schedules_table.php',
            'database/migrations/2026_11_07_224501_add_schedule_fields_to_events_table.php',
            'database/migrations/2026_11_08_021500_add_generation_tracking_columns.php',
            'database/migrations/2026_11_09_161500_create_pengurus_tables_and_migrate_data.php',
            'database/migrations/2026_11_12_150000_add_auto_alpa_at_to_events_table.php',
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

    public function test_migration_backfills_auto_alpa_at_only_for_closed_or_past_events(): void
    {
        $migrationPath = database_path('migrations/2026_11_12_150000_add_auto_alpa_at_to_events_table.php');
        $migration = require $migrationPath;
        $migration->down();

        $todayWib = Carbon::now('Asia/Jakarta')->toDateString();
        $yesterdayWib = Carbon::now('Asia/Jakarta')->subDay()->toDateString();

        $pastOpenId = \Illuminate\Support\Facades\DB::table('events')->insertGetId([
            'name' => 'Acara Kemarin',
            'event_date' => $yesterdayWib,
            'start_time' => '18:30:00',
            'end_time' => '20:00:00',
            'is_closed' => false,
            'auto_closed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $todayClosedId = \Illuminate\Support\Facades\DB::table('events')->insertGetId([
            'name' => 'Acara Hari Ini Ditutup',
            'event_date' => $todayWib,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'is_closed' => true,
            'auto_closed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $todayReopenedId = \Illuminate\Support\Facades\DB::table('events')->insertGetId([
            'name' => 'Acara Hari Ini Dibuka Ulang',
            'event_date' => $todayWib,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'is_closed' => false,
            'auto_closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $todayOpenId = \Illuminate\Support\Facades\DB::table('events')->insertGetId([
            'name' => 'Acara Hari Ini Aktif',
            'event_date' => $todayWib,
            'start_time' => '19:30:00',
            'end_time' => '21:00:00',
            'is_closed' => false,
            'auto_closed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('events', 'auto_alpa_at'));
        $hasAutoAlpaIndex = collect(\Illuminate\Support\Facades\Schema::getIndexes('events'))
            ->contains(fn (array $index): bool => in_array('auto_alpa_at', $index['columns'] ?? [], true));
        $this->assertTrue($hasAutoAlpaIndex);
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('events')->where('id', $pastOpenId)->value('auto_alpa_at'));
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('events')->where('id', $todayClosedId)->value('auto_alpa_at'));
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('events')->where('id', $todayReopenedId)->value('auto_alpa_at'));
        $this->assertNull(\Illuminate\Support\Facades\DB::table('events')->where('id', $todayOpenId)->value('auto_alpa_at'));

        $eventModel = Event::findOrFail($todayOpenId);
        $this->assertContains('auto_alpa_at', $eventModel->getFillable());

        $reopenedModel = Event::findOrFail($todayReopenedId);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $reopenedModel->auto_closed_at);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $reopenedModel->auto_alpa_at);
    }

    private function createGenerus(array $overrides = []): Generus
    {
        return Generus::create(array_merge([
            'nama_lengkap' => 'Generus ' . uniqid(),
            'jenis_kelamin' => 'L',
            'kelompok' => 'Jekani',
            'jenjang' => '1 SMP',
            'status' => 'aktif',
            'is_pengurus' => false,
            'is_pengurus_muda_mudi' => false,
            'is_ketua_wakil_kelompok' => false,
        ], $overrides));
    }

    public function test_auto_alpa_marks_unrecorded_targets_exactly_at_2_hours_after_end_time(): void
    {
        $this->seed(GroupScheduleSeeder::class);
        $generator = app(ScheduledEventGenerator::class);

        $gHadir = $this->createGenerus(['nama_lengkap' => 'Hadir', 'jenjang' => '1 SMP', 'status' => 'aktif']);
        $gIzin = $this->createGenerus(['nama_lengkap' => 'Izin', 'jenjang' => 'USMAN', 'status' => 'aktif']);
        $gAlpaAktif = $this->createGenerus(['nama_lengkap' => 'Alpa Aktif', 'jenjang' => '2 SMP', 'status' => 'aktif']);
        $gAlpaPasif = $this->createGenerus(['nama_lengkap' => 'Alpa Pasif', 'jenjang' => 'MT', 'status' => 'pasif']);
        $gTidakAktif = $this->createGenerus(['nama_lengkap' => 'Nonaktif', 'jenjang' => '1 SMP', 'status' => 'tidak aktif']);
        $gBedaJenjang = $this->createGenerus(['nama_lengkap' => 'SD', 'jenjang' => '6 SD', 'status' => 'aktif']);
        $gBedaKelompok = $this->createGenerus(['nama_lengkap' => 'Slogo', 'kelompok' => 'Slogo', 'jenjang' => '1 SMP', 'status' => 'aktif']);

        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));
        $jekaniEvent = Event::where('kelompok', 'Jekani')->firstOrFail();

        Attendance::create(['event_id' => $jekaniEvent->id, 'generus_id' => $gHadir->id, 'status' => 'hadir', 'time_arrived' => '18:10', 'is_late' => false]);
        Attendance::create(['event_id' => $jekaniEvent->id, 'generus_id' => $gIzin->id, 'status' => 'izin', 'time_arrived' => null, 'is_late' => false]);

        $resBefore = $generator->run(Carbon::parse('2026-11-10 20:59:00', 'Asia/Jakarta'));
        $this->assertEquals(0, $resBefore['alpa_marked']);
        $this->assertNull($jekaniEvent->fresh()->auto_alpa_at);
        $this->assertEquals(2, Attendance::where('event_id', $jekaniEvent->id)->count());

        $resAt = $generator->run(Carbon::parse('2026-11-10 21:00:00', 'Asia/Jakarta'));
        $this->assertEquals(2, $resAt['alpa_marked']);
        $this->assertNotNull($jekaniEvent->fresh()->auto_alpa_at);
        $this->assertDatabaseHas('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $gAlpaAktif->id, 'status' => 'alpa', 'time_arrived' => null, 'is_late' => false]);
        $this->assertDatabaseHas('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $gAlpaPasif->id, 'status' => 'alpa', 'time_arrived' => null, 'is_late' => false]);
        $this->assertDatabaseHas('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $gHadir->id, 'status' => 'hadir']);
        $this->assertDatabaseHas('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $gIzin->id, 'status' => 'izin']);
        $this->assertDatabaseMissing('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $gTidakAktif->id]);
        $this->assertDatabaseMissing('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $gBedaJenjang->id]);
        $this->assertDatabaseMissing('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $gBedaKelompok->id]);
    }

    public function test_auto_alpa_runs_only_once_and_ignores_generus_added_5_hours_later(): void
    {
        $this->seed(GroupScheduleSeeder::class);
        $generator = app(ScheduledEventGenerator::class);

        $g1 = $this->createGenerus(['nama_lengkap' => 'Peserta Satu', 'jenjang' => '1 SMP']);
        $g2 = $this->createGenerus(['nama_lengkap' => 'Peserta Dua', 'jenjang' => '1 SMP']);

        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));
        $jekaniEvent = Event::where('kelompok', 'Jekani')->firstOrFail();
        Attendance::create(['event_id' => $jekaniEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'time_arrived' => '18:10', 'is_late' => false]);

        $res1 = $generator->run(Carbon::parse('2026-11-10 21:00:00', 'Asia/Jakarta'));
        $this->assertEquals(1, $res1['alpa_marked']);

        try {
            Carbon::setTestNow(Carbon::parse('2026-11-11 02:00:00', 'Asia/Jakarta'));
            $gBaru = $this->createGenerus(['nama_lengkap' => 'Generus Baru', 'jenjang' => '1 SMP']);

            $res2 = $generator->run(Carbon::parse('2026-11-11 02:00:00', 'Asia/Jakarta'));
            $this->assertEquals(0, $res2['alpa_marked']);

            $admin = User::create(['name' => 'Admin', 'username' => 'adm_' . uniqid(), 'password' => bcrypt('password'), 'role' => 'admin']);
            $this->actingAs($admin)->getJson('/api/admin/events')->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseMissing('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $gBaru->id]);
        $this->assertEquals(1, Attendance::where('event_id', $jekaniEvent->id)->where('generus_id', $g2->id)->count());
    }

    public function test_auto_alpa_skips_events_with_zero_attendances_and_does_not_retrigger_if_reopened(): void
    {
        $this->seed(GroupScheduleSeeder::class);
        $generator = app(ScheduledEventGenerator::class);

        $g1 = $this->createGenerus(['nama_lengkap' => 'Peserta Satu', 'jenjang' => '1 SMP']);
        $g2 = $this->createGenerus(['nama_lengkap' => 'Peserta Dua', 'jenjang' => '1 SMP']);

        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));
        $jekaniEvent = Event::where('kelompok', 'Jekani')->firstOrFail();

        $res = $generator->run(Carbon::parse('2026-11-10 21:00:00', 'Asia/Jakarta'));
        $this->assertEquals(0, $res['alpa_marked']);
        $this->assertTrue((bool) $jekaniEvent->fresh()->is_closed);
        $this->assertNotNull($jekaniEvent->fresh()->auto_alpa_at);
        $this->assertEquals(0, Attendance::where('event_id', $jekaniEvent->id)->count());

        $admin = User::create(['name' => 'Admin', 'username' => 'adm_' . uniqid(), 'password' => bcrypt('password'), 'role' => 'admin']);
        $this->actingAs($admin)->patchJson("/api/admin/events/{$jekaniEvent->id}/toggle-status")->assertOk();
        Attendance::create(['event_id' => $jekaniEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'time_arrived' => '21:31', 'is_late' => true]);

        $resLater = $generator->run(Carbon::parse('2026-11-10 21:35:00', 'Asia/Jakarta'));
        $this->assertEquals(0, $resLater['alpa_marked']);
        $this->assertDatabaseMissing('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $g2->id]);
    }

    public function test_early_manual_close_before_2h_still_auto_alpas_once_and_post_2h_reopen_correction_stays_safe(): void
    {
        $this->seed(GroupScheduleSeeder::class);
        $generator = app(ScheduledEventGenerator::class);

        $g1 = $this->createGenerus(['nama_lengkap' => 'Peserta Satu', 'jenjang' => '1 SMP']);
        $g2 = $this->createGenerus(['nama_lengkap' => 'Peserta Dua', 'jenjang' => '1 SMP']);

        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));
        $jekaniEvent = Event::where('kelompok', 'Jekani')->firstOrFail();
        Attendance::create(['event_id' => $jekaniEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'time_arrived' => '18:10', 'is_late' => false]);

        $admin = User::create(['name' => 'Admin', 'username' => 'adm_' . uniqid(), 'password' => bcrypt('password'), 'role' => 'admin']);
        $this->actingAs($admin)->patchJson("/api/admin/events/{$jekaniEvent->id}/toggle-status")->assertOk()->assertJson(['is_closed' => true]);

        $res = $generator->run(Carbon::parse('2026-11-10 21:00:00', 'Asia/Jakarta'));
        $this->assertEquals(1, $res['alpa_marked']);
        $this->assertDatabaseHas('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $g2->id, 'status' => 'alpa']);

        $this->actingAs($admin)->patchJson("/api/admin/events/{$jekaniEvent->id}/toggle-status")->assertOk()->assertJson(['is_closed' => false]);
        Attendance::where('event_id', $jekaniEvent->id)->where('generus_id', $g2->id)->delete();

        $resAfter = $generator->run(Carbon::parse('2026-11-10 21:35:00', 'Asia/Jakarta'));
        $this->assertEquals(0, $resAfter['alpa_marked']);
        $this->assertFalse((bool) $jekaniEvent->fresh()->is_closed);
        $this->assertDatabaseMissing('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $g2->id]);
    }

    public function test_auto_alpa_handles_cross_midnight_event_and_event_type_fallback_with_pengurus(): void
    {
        $eventType = EventType::create([
            'name' => 'Malam Keakraban',
            'code' => 'MAKRAB',
            'start_time' => '22:30:00',
            'target_kategori' => ['PENGURUS USMAN', '1 SMP'],
            'is_group_attendance' => false,
        ]);

        $event = Event::create([
            'name' => 'Malam Keakraban',
            'event_date' => '2026-11-10',
            'start_time' => '22:30:00',
            'end_time' => '00:30:00',
            'target_kategori' => json_encode([]),
            'event_type_id' => $eventType->id,
            'kelompok' => null,
            'is_closed' => false,
        ]);

        $gHadir = $this->createGenerus(['nama_lengkap' => 'Hadir SMP', 'kelompok' => 'Slogo', 'jenjang' => '1 SMP']);
        $gPengurus = $this->createGenerus(['nama_lengkap' => 'Pengurus Usman', 'kelompok' => 'Gawan', 'jenjang' => 'USMAN']);
        $usmanType = \App\Models\PengurusType::where('code', 'PENGURUS_USMAN')->firstOrFail();
        $gPengurus->syncPengurusTypes([$usmanType->id]);
        $gNonTarget = $this->createGenerus(['nama_lengkap' => 'Bukan Pengurus', 'kelompok' => 'Gawan', 'jenjang' => 'USMAN']);

        Attendance::create(['event_id' => $event->id, 'generus_id' => $gHadir->id, 'status' => 'hadir', 'time_arrived' => '22:25', 'is_late' => false]);

        $generator = app(ScheduledEventGenerator::class);
        $resBefore = $generator->run(Carbon::parse('2026-11-11 02:29:00', 'Asia/Jakarta'));
        $this->assertEquals(0, $resBefore['closed']);
        $this->assertEquals(0, $resBefore['alpa_marked']);

        $resAt = $generator->run(Carbon::parse('2026-11-11 02:30:00', 'Asia/Jakarta'));
        $this->assertEquals(1, $resAt['closed']);
        $this->assertEquals(1, $resAt['alpa_marked']);
        $this->assertDatabaseHas('attendances', ['event_id' => $event->id, 'generus_id' => $gPengurus->id, 'status' => 'alpa']);
        $this->assertDatabaseMissing('attendances', ['event_id' => $event->id, 'generus_id' => $gNonTarget->id]);
    }

    public function test_atomic_claim_and_artisan_command_output_when_already_claimed(): void
    {
        $this->seed(GroupScheduleSeeder::class);
        $generator = app(ScheduledEventGenerator::class);

        $g1 = $this->createGenerus(['nama_lengkap' => 'Peserta Satu', 'jenjang' => '1 SMP']);
        $g2 = $this->createGenerus(['nama_lengkap' => 'Peserta Dua', 'jenjang' => '1 SMP']);

        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));
        $jekaniEvent = Event::where('kelompok', 'Jekani')->firstOrFail();
        Attendance::create(['event_id' => $jekaniEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'time_arrived' => '18:10', 'is_late' => false]);

        $jekaniEvent->update(['auto_alpa_at' => Carbon::now()]);

        try {
            Carbon::setTestNow(Carbon::parse('2026-11-10 21:00:00', 'Asia/Jakarta'));
            $this->artisan('events:generate-scheduled')
                ->expectsOutputToContain('0 peserta dialpakan')
                ->assertExitCode(0);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue((bool) $jekaniEvent->fresh()->is_closed);
        $this->assertDatabaseMissing('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $g2->id]);
    }

    public function test_auto_alpa_matches_pengurus_via_pivot_relation_and_trims_whitespace_targets(): void
    {
        $eventType = EventType::create([
            'name' => 'Musyawarah Pengurus',
            'code' => 'MUSYAWARAH',
            'start_time' => '18:30:00',
            'target_kategori' => ['  PENGURUS USMAN  ', ' 1 SMP '],
            'is_group_attendance' => false,
        ]);

        $event = Event::create([
            'name' => 'Musyawarah Pengurus',
            'event_date' => '2026-11-10',
            'start_time' => '18:30:00',
            'end_time' => '19:30:00',
            'target_kategori' => json_encode(['  ', '']),
            'event_type_id' => $eventType->id,
            'kelompok' => 'Jekani',
            'is_closed' => false,
        ]);

        $gHadir = $this->createGenerus(['nama_lengkap' => 'Hadir SMP', 'jenjang' => '1 SMP']);
        $gPivotOnly = $this->createGenerus(['nama_lengkap' => 'Pivot Usman Only', 'jenjang' => 'USMAN', 'is_pengurus' => false]);
        $usmanType = \App\Models\PengurusType::where('code', 'PENGURUS_USMAN')->firstOrFail();
        // Attach langsung ke pivot tanpa mengaktifkan kolom boolean legacy is_pengurus.
        $gPivotOnly->pengurusTypes()->attach($usmanType->id);
        $gNonTarget = $this->createGenerus(['nama_lengkap' => 'Bukan Pengurus', 'jenjang' => 'USMAN']);

        Attendance::create(['event_id' => $event->id, 'generus_id' => $gHadir->id, 'status' => 'hadir', 'time_arrived' => '18:25', 'is_late' => false]);

        $res = app(ScheduledEventGenerator::class)->run(Carbon::parse('2026-11-10 21:30:00', 'Asia/Jakarta'));

        $this->assertEquals(1, $res['alpa_marked']);
        $this->assertDatabaseHas('attendances', ['event_id' => $event->id, 'generus_id' => $gPivotOnly->id, 'status' => 'alpa']);
        $this->assertDatabaseMissing('attendances', ['event_id' => $event->id, 'generus_id' => $gNonTarget->id]);
    }

    public function test_inner_atomic_claim_skips_when_already_claimed_in_race_and_artisan_reports_exact_alpa_count(): void
    {
        $this->seed(GroupScheduleSeeder::class);
        $generator = app(ScheduledEventGenerator::class);

        $g1 = $this->createGenerus(['nama_lengkap' => 'Peserta Satu', 'jenjang' => '1 SMP']);
        $g2 = $this->createGenerus(['nama_lengkap' => 'Peserta Dua', 'jenjang' => '1 SMP']);

        $generator->run(Carbon::parse('2026-11-10 17:45:00', 'Asia/Jakarta'));
        $jekaniEvent = Event::where('kelompok', 'Jekani')->firstOrFail();
        Attendance::create(['event_id' => $jekaniEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'time_arrived' => '18:10', 'is_late' => false]);

        // Simulasikan dua kandidat acara: satu untuk race inner claim, satu via artisan alpa > 0.
        $staleEvent = Event::with(['groupSchedule', 'eventType'])->create([
            'name' => 'Acara Konkuren',
            'event_date' => '2026-11-10',
            'start_time' => '18:00:00',
            'end_time' => '18:30:00',
            'target_kategori' => json_encode(['1 SMP']),
            'kelompok' => 'Jekani',
            'is_closed' => false,
        ]);
        Attendance::create(['event_id' => $staleEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'time_arrived' => '18:05', 'is_late' => false]);

        // Proses lain mengklaim staleEvent setelah query awal membaca auto_alpa_at = null.
        Event::where('id', $staleEvent->id)->update(['auto_alpa_at' => Carbon::now()]);

        $method = new \ReflectionMethod(ScheduledEventGenerator::class, 'markRemainingTargetsAlpa');
        $method->setAccessible(true);
        $raceResult = $method->invoke($generator, $staleEvent);

        $this->assertSame(0, $raceResult);
        $this->assertDatabaseMissing('attendances', ['event_id' => $staleEvent->id, 'generus_id' => $g2->id]);

        // Sekarang jalankan command artisan saat jekaniEvent jatuh tempo 2 jam setelah selesai.
        try {
            Carbon::setTestNow(Carbon::parse('2026-11-10 21:00:00', 'Asia/Jakarta'));
            $this->artisan('events:generate-scheduled')
                ->expectsOutputToContain('1 peserta dialpakan')
                ->assertExitCode(0);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('attendances', ['event_id' => $jekaniEvent->id, 'generus_id' => $g2->id, 'status' => 'alpa']);
    }
}
