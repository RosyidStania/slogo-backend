<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Generus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerformanceOptimizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migrations = [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_05_15_123233_create_generuses_table.php',
            'database/migrations/2026_07_29_195225_add_user_id_to_generus_table.php',
            'database/migrations/2026_08_06_104335_add_kode_unik_to_generus_table.php',
            'database/migrations/2026_08_18_203140_add_is_pengurus_to_generus_table.php',
            'database/migrations/2026_09_02_082219_add_is_pengurus_muda_mudi_to_generus_table.php',
            'database/migrations/2026_09_02_111505_add_is_ketua_wakil_kelompok_to_generus_table.php',
            'database/migrations/2026_05_16_101334_create_events_table.php',
            'database/migrations/2026_05_16_101335_create_attendances_table.php',
            'database/migrations/2026_05_16_104305_add_target_kategori_to_events_table.php',
            'database/migrations/2026_05_17_174305_create_event_types_table.php',
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
            'database/migrations/2026_11_09_073527_create_meeting_notes_table.php',
            'database/migrations/2026_11_09_161500_create_pengurus_tables_and_migrate_data.php',
            'database/migrations/2026_11_12_140000_add_performance_indexes.php',
        ];

        foreach ($migrations as $path) {
            Artisan::call('migrate', ['--path' => $path]);
        }
    }

    private function createUser(string $role, ?string $kelompok = null): User
    {
        return User::create([
            'name' => ucfirst($role) . ' Test',
            'username' => $role . '_' . uniqid(),
            'password' => Hash::make('password'),
            'role' => $role,
            'kelompok' => $kelompok,
        ]);
    }

    public function test_performance_indexes_migration_creates_expected_indexes(): void
    {
        $generusIndexes = collect(Schema::getIndexes('generus'))->pluck('name')->all();
        $eventIndexes = collect(Schema::getIndexes('events'))->pluck('name')->all();
        $attendanceIndexes = collect(Schema::getIndexes('attendances'))->pluck('name')->all();

        $this->assertContains('generus_user_id_index', $generusIndexes);
        $this->assertContains('generus_status_kelompok_index', $generusIndexes);
        $this->assertContains('events_event_type_date_index', $eventIndexes);
        $this->assertContains('attendances_event_generus_index', $attendanceIndexes);
        $this->assertContains('attendances_generus_status_index', $attendanceIndexes);
    }

    public function test_user_report_avoids_n_plus_one_and_preserves_exact_stats(): void
    {
        $user = $this->createUser('user');
        $generus = Generus::create([
            'user_id' => $user->id,
            'kode_unik' => 'KODE-U1',
            'nama_lengkap' => 'Ahmad User',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'jenjang' => '1 SMP',
            'status' => 'aktif',
        ]);

        $types = [];
        for ($i = 1; $i <= 8; $i++) {
            $types[] = EventType::create(['name' => "Tipe {$i}", 'code' => "TIPE_{$i}"]);
        }

        $today = Carbon::now();
        $event1 = Event::create([
            'event_type_id' => $types[0]->id,
            'name' => 'Pengajian Lama',
            'event_date' => $today->copy()->subDays(5)->toDateString(),
            'start_time' => '19:00:00',
        ]);
        $event2 = Event::create([
            'event_type_id' => $types[0]->id,
            'name' => 'Pengajian Baru',
            'event_date' => $today->copy()->subDays(1)->toDateString(),
            'start_time' => '19:00:00',
        ]);
        $event3 = Event::create([
            'event_type_id' => $types[1]->id,
            'name' => 'Kegiatan 2',
            'event_date' => $today->copy()->subDays(3)->toDateString(),
            'start_time' => '19:00:00',
        ]);

        Attendance::create(['event_id' => $event1->id, 'generus_id' => $generus->id, 'status' => 'hadir', 'time_arrived' => '18:55', 'is_late' => false]);
        Attendance::create(['event_id' => $event2->id, 'generus_id' => $generus->id, 'status' => 'izin', 'is_late' => false]);
        Attendance::create(['event_id' => $event3->id, 'generus_id' => $generus->id, 'status' => 'hadir', 'time_arrived' => '19:05', 'is_late' => true]);

        Sanctum::actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->getJson('/api/user/report');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 3)
            ->assertJsonPath('data.summary.hadir', 2)
            ->assertJsonPath('data.summary.izin', 1)
            ->assertJsonPath('data.summary.percentage', 66.7)
            ->assertJsonCount(2, 'data.details')
            ->assertJsonPath('data.details.0.event_type', 'Tipe 1')
            ->assertJsonPath('data.details.0.hadir', 1)
            ->assertJsonPath('data.details.0.izin', 1)
            ->assertJsonPath('data.details.0.percentage', 50)
            ->assertJsonPath('data.history.0.event_name', 'Pengajian Baru')
            ->assertJsonPath('data.history.1.event_name', 'Kegiatan 2')
            ->assertJsonPath('data.history.2.event_name', 'Pengajian Lama');

        // Constant query count (previously 5 + 4*8 + 3 = 40+ queries)
        $this->assertLessThanOrEqual(8, count($queries));
    }

    public function test_dashboard_and_report_and_attendance_endpoints_match_expected_outputs(): void
    {
        $admin = $this->createUser('admin');
        $mt = $this->createUser('user', 'Slogo');
        $mt->role = 'mt';
        $year = Carbon::now()->year;

        $aktif1 = Generus::create([
            'kode_unik' => 'KODE-A1',
            'nama_lengkap' => 'Aktif Satu',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'jenjang' => '1 SMP',
            'status' => 'aktif',
        ]);
        $pasif1 = Generus::create([
            'kode_unik' => 'KODE-P1',
            'nama_lengkap' => 'Pasif Satu',
            'jenis_kelamin' => 'P',
            'kelompok' => 'Slogo',
            'jenjang' => '1 SMP',
            'status' => 'pasif',
        ]);
        $mtGenerus = Generus::create([
            'kode_unik' => 'KODE-M1',
            'nama_lengkap' => 'MT Satu',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'jenjang' => 'MT',
            'status' => 'aktif',
        ]);

        $type = EventType::create(['name' => 'Pengajian Desa', 'code' => 'PENGAJIAN_DESA', 'target_kategori' => ['1 SMP']]);

        $eventPast = Event::create([
            'event_type_id' => $type->id,
            'name' => 'Pengajian Awal',
            'event_date' => Carbon::now()->startOfMonth()->toDateString(),
            'start_time' => '19:00:00',
            'kelompok' => 'Slogo',
            'target_kategori' => json_encode(['1 SMP']),
        ]);
        $eventToday = Event::create([
            'event_type_id' => $type->id,
            'name' => 'Pengajian Hari Ini',
            'event_date' => Carbon::now()->toDateString(),
            'start_time' => '19:00:00',
            'kelompok' => 'Slogo',
            'target_kategori' => json_encode(['1 SMP']),
        ]);

        // Bulk attendance via mixed generus_id and kode_unik
        Sanctum::actingAs($admin);
        $bulkRes = $this->postJson('/api/admin/attendance/bulk', [
            'attendances' => [
                ['event_id' => $eventPast->id, 'generus_id' => $aktif1->id, 'status' => 'hadir', 'time_arrived' => '18:50', 'is_late' => false],
                ['event_id' => $eventToday->id, 'kode_unik' => 'KODE-A1', 'status' => 'alpa', 'time_arrived' => null, 'is_late' => false],
                ['event_id' => $eventPast->id, 'generus_id' => $pasif1->id, 'status' => 'hadir', 'time_arrived' => '18:55', 'is_late' => false],
                ['event_id' => $eventToday->id, 'generus_id' => $pasif1->id, 'status' => 'alpa', 'time_arrived' => null, 'is_late' => false],
                ['event_id' => $eventPast->id, 'generus_id' => $mtGenerus->id, 'status' => 'hadir', 'time_arrived' => '18:55', 'is_late' => false],
                ['event_id' => $eventToday->id, 'generus_id' => $mtGenerus->id, 'status' => 'hadir', 'time_arrived' => '18:55', 'is_late' => false],
            ],
        ]);
        $bulkRes->assertOk()->assertJsonPath('success', true);

        // Dashboard: valid absensi = 4 aktif (aktif1 x2, mt x2) + 1 passive hadir = 5; total hadir = 4 => 80%
        $dashRes = $this->getJson('/api/admin/dashboard-stats?exclude_mt=1');
        $dashRes->assertOk()
            ->assertJsonPath('stats.totalGenerus', 3)
            ->assertJsonPath('stats.aktifGenerus', 2)
            ->assertJsonPath('stats.rataKehadiran', 80)
            ->assertJsonPath('topAttendees.0.nama_lengkap', 'Aktif Satu');

        // Event show & summary
        $showRes = $this->getJson("/api/admin/events/{$eventToday->id}");
        $showRes->assertOk()
            ->assertJsonPath('data.target_count', 2);

        $summaryRes = $this->getJson("/api/admin/events/{$eventToday->id}/summary");
        $summaryRes->assertOk()
            ->assertJsonCount(2, 'target_generus')
            ->assertJsonPath('previous_event.id', $eventPast->id);

        // Report by type
        $reportRes = $this->getJson("/api/admin/reports/attendance-by-type?event_type_id={$type->id}&year={$year}");
        $reportRes->assertOk()
            ->assertJsonCount(2, 'data');

        // MT endpoints
        Sanctum::actingAs($mt);
        $mtAttRes = $this->getJson("/api/mt/group-attendance?event_type_id={$type->id}&year={$year}");
        $mtAttRes->assertOk()->assertJsonCount(2, 'data');

        $mtStatsRes = $this->getJson("/api/mt/group-statistics?year={$year}");
        $mtStatsRes->assertOk()
            ->assertJsonPath('stats.totalAnggota', 3)
            ->assertJsonPath('stats.anggotaAktif', 2)
            ->assertJsonPath('stats.totalAcara', 2);
    }
}
