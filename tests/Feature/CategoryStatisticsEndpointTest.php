<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Generus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryStatisticsEndpointTest extends TestCase
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
            'database/migrations/2026_11_09_161500_create_pengurus_tables_and_migrate_data.php',
        ];

        foreach ($migrations as $path) {
            Artisan::call('migrate', ['--path' => $path]);
        }
    }

    protected function createUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' Testing',
            'username' => $role . '_test_' . uniqid(),
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_unauthenticated_and_non_admin_are_rejected(): void
    {
        $this->getJson('/api/admin/reports/category-statistics')
            ->assertStatus(401);

        $regularUser = $this->createUser('user');
        Sanctum::actingAs($regularUser);

        $this->getJson('/api/admin/reports/category-statistics')
            ->assertStatus(403);
    }

    public function test_invalid_month_format_or_reversed_range_returns_422(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00', 'Asia/Jakarta'));

        try {
            $admin = $this->createUser('admin');
            Sanctum::actingAs($admin);

            $eventType = EventType::create([
                'name' => 'Muda-Mudi',
                'code' => 'MUDA_MUDI',
                'start_time' => '19:30:00',
                'target_kategori' => ['USMAN'],
                'is_group_attendance' => false,
            ]);
            $generus = Generus::create([
                'nama_lengkap' => 'Ahmad',
                'jenis_kelamin' => 'L',
                'kelompok' => 'Slogo',
                'status' => 'aktif',
                'jenjang' => 'USMAN',
            ]);
            $event = Event::create([
                'name' => 'Pengajian Jan',
                'event_date' => '2026-01-10',
                'start_time' => '19:30:00',
                'target_kategori' => json_encode(['USMAN']),
                'event_type_id' => $eventType->id,
            ]);
            Attendance::create([
                'event_id' => $event->id,
                'generus_id' => $generus->id,
                'status' => 'hadir',
                'is_late' => false,
            ]);

            // Invalid month format
            $this->getJson('/api/admin/reports/category-statistics?start_month=2026-13')
                ->assertStatus(422)
                ->assertJsonValidationErrors(['start_month']);

            $this->getJson('/api/admin/reports/category-statistics?end_month=2026-00')
                ->assertStatus(422)
                ->assertJsonValidationErrors(['end_month']);

            $this->getJson('/api/admin/reports/category-statistics?scope=invalid')
                ->assertStatus(422)
                ->assertJsonValidationErrors(['scope']);

            // Explicit reversed range
            $this->getJson('/api/admin/reports/category-statistics?start_month=2026-06&end_month=2026-01')
                ->assertStatus(422)
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Bulan mulai tidak boleh lebih besar dari bulan akhir.',
                ]);

            // Single-sided start_month later than resolved default end_month (2026-01)
            $this->getJson('/api/admin/reports/category-statistics?start_month=2026-02')
                ->assertStatus(422)
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Bulan mulai tidak boleh lebih besar dari bulan akhir.',
                ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_receives_category_statistics_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00', 'Asia/Jakarta'));

        try {
            $admin = $this->createUser('admin');
            Sanctum::actingAs($admin);

            $desaType = EventType::create([
                'name' => 'Pengajian Desa',
                'code' => 'DESA',
                'start_time' => '19:30:00',
                'target_kategori' => ['USMAN'],
                'is_group_attendance' => false,
            ]);
            $kelompokType = EventType::create([
                'name' => 'Pengajian Kelompok',
                'code' => 'KELOMPOK',
                'start_time' => '19:30:00',
                'target_kategori' => ['USMAN'],
                'is_group_attendance' => true,
            ]);

            $g1 = Generus::create([
                'nama_lengkap' => 'Ahmad',
                'jenis_kelamin' => 'L',
                'kelompok' => 'Slogo',
                'status' => 'aktif',
                'jenjang' => 'USMAN',
            ]);
            $g2 = Generus::create([
                'nama_lengkap' => 'Budi',
                'jenis_kelamin' => 'L',
                'kelompok' => 'Gabugan',
                'status' => 'aktif',
                'jenjang' => 'USMAN',
            ]);

            $e1 = Event::create([
                'name' => 'Pengajian Desa Jan',
                'event_date' => '2026-01-15',
                'start_time' => '19:30:00',
                'target_kategori' => json_encode(['USMAN']),
                'event_type_id' => $desaType->id,
                'infaq' => 250000,
            ]);
            Attendance::create(['event_id' => $e1->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'is_late' => false]);

            $e2 = Event::create([
                'name' => 'Pengajian Slogo Feb',
                'event_date' => '2026-02-15',
                'start_time' => '19:30:00',
                'target_kategori' => json_encode(['USMAN']),
                'event_type_id' => $kelompokType->id,
                'kelompok' => 'Slogo',
                'infaq' => 75000,
            ]);
            Attendance::create(['event_id' => $e2->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'is_late' => true]);
            Attendance::create(['event_id' => $e2->id, 'generus_id' => $g2->id, 'status' => 'hadir', 'is_late' => false]);

            $response = $this->getJson('/api/admin/reports/category-statistics?start_month=2026-01&end_month=2026-02&scope=all');

            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('period.start_month', '2026-01')
                ->assertJsonPath('period.end_month', '2026-02')
                ->assertJsonPath('period.label', 'Jan 2026 - Feb 2026')
                ->assertJsonStructure([
                    'success',
                    'period' => ['start_month', 'end_month', 'label'],
                    'available_years',
                    'kelompok_options',
                    'categories' => [
                        '*' => [
                            'event_type_id',
                            'name',
                            'code',
                            'is_group_attendance',
                            'summary' => [
                                'avg_percentage',
                                'trend_delta',
                                'total_events',
                                'active_months',
                                'total_target',
                                'total_hadir',
                                'total_terlambat',
                                'total_izin_sakit',
                                'total_alpa',
                                'total_infaq',
                            ],
                            'monthly_series' => [
                                '*' => [
                                    'month',
                                    'label',
                                    'has_event',
                                    'events_count',
                                    'events',
                                    'target_total',
                                    'hadir',
                                    'terlambat',
                                    'izin_sakit',
                                    'alpa',
                                    'infaq',
                                    'percentage',
                                ],
                            ],
                            'group_breakdown',
                            'gender_breakdown' => [
                                'putra' => ['target', 'hadir', 'percentage'],
                                'putri' => ['target', 'hadir', 'percentage'],
                            ],
                        ],
                    ],
                ]);

            // Default call without params resolves 6 months ending at 2026-02
            $defaultRes = $this->getJson('/api/admin/reports/category-statistics');
            $defaultRes->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('period.start_month', '2025-09')
                ->assertJsonPath('period.end_month', '2026-02');

            // Single-sided end_month resolves start_month 5 months prior
            $endOnlyRes = $this->getJson('/api/admin/reports/category-statistics?end_month=2026-01');
            $endOnlyRes->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonPath('period.start_month', '2025-08')
                ->assertJsonPath('period.end_month', '2026-01');

            // Scope + kelompok filter
            $filteredRes = $this->getJson('/api/admin/reports/category-statistics?start_month=2026-01&end_month=2026-02&scope=desa&kelompok=Slogo');
            $filteredRes->assertStatus(200)
                ->assertJsonCount(1, 'categories')
                ->assertJsonPath('categories.0.event_type_id', $desaType->id)
                ->assertJsonPath('categories.0.summary.total_infaq', null);
        } finally {
            Carbon::setTestNow();
        }
    }
}
