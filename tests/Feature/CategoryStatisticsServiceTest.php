<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Generus;
use App\Models\PengurusType;
use App\Services\CategoryStatisticsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CategoryStatisticsServiceTest extends TestCase
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

    public function test_qualifying_events_filter_and_monthly_series_with_gaps_across_years(): void
    {
        $eventType = EventType::create([
            'name' => 'Muda-Mudi',
            'code' => 'MUDA_MUDI',
            'start_time' => '19:30:00',
            'target_kategori' => ['USMAN'],
            'is_group_attendance' => false,
        ]);

        $g1 = Generus::create([
            'nama_lengkap' => 'Ahmad',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'status' => 'aktif',
            'jenjang' => 'USMAN',
        ]);
        $g2 = Generus::create([
            'nama_lengkap' => 'Siti',
            'jenis_kelamin' => 'P',
            'kelompok' => 'Slogo',
            'status' => 'aktif',
            'jenjang' => 'USMAN',
        ]);
        $g3 = Generus::create([
            'nama_lengkap' => 'Budi',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Gabugan',
            'status' => 'pasif',
            'jenjang' => 'USMAN',
        ]);
        // Tidak aktif harus dikecualikan dari target maupun kelompok_options (jika tidak ada generus aktif/pasif lain)
        Generus::create([
            'nama_lengkap' => 'Nonaktif',
            'jenis_kelamin' => 'P',
            'kelompok' => 'Karangasem',
            'status' => 'tidak aktif',
            'jenjang' => 'USMAN',
        ]);

        // 1. 2025-11-10: open manual event (is_closed=false), 2 hadir (1 terlambat), 0 izin -> valid
        $novEvent = Event::create([
            'name' => 'Pengajian MM Nov',
            'event_date' => '2025-11-10',
            'start_time' => '19:30:00',
            'target_kategori' => json_encode(['USMAN']),
            'event_type_id' => $eventType->id,
            'is_closed' => false,
            'infaq' => 50000,
        ]);
        Attendance::create(['event_id' => $novEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'is_late' => true]);
        Attendance::create(['event_id' => $novEvent->id, 'generus_id' => $g2->id, 'status' => 'hadir', 'is_late' => false]);

        // 2. 2025-12 -> tidak ada event (gap month)

        // 3. 2026-01-10: closed dengan 0 attendances -> ignored (cancelled/libur)
        Event::create([
            'name' => 'Pengajian MM Jan (Libur)',
            'event_date' => '2026-01-10',
            'start_time' => '19:30:00',
            'target_kategori' => json_encode(['USMAN']),
            'event_type_id' => $eventType->id,
            'is_closed' => true,
            'infaq' => 99999,
        ]);

        // 4. 2026-02-10: is_closed=false, 3 hadir -> valid
        $febEvent = Event::create([
            'name' => 'Pengajian MM Feb',
            'event_date' => '2026-02-10',
            'start_time' => '19:30:00',
            'target_kategori' => json_encode(['USMAN']),
            'event_type_id' => $eventType->id,
            'is_closed' => false,
            'infaq' => 75000,
        ]);
        Attendance::create(['event_id' => $febEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'is_late' => false]);
        Attendance::create(['event_id' => $febEvent->id, 'generus_id' => $g2->id, 'status' => 'hadir', 'is_late' => false]);
        Attendance::create(['event_id' => $febEvent->id, 'generus_id' => $g3->id, 'status' => 'hadir', 'is_late' => false]);

        // 5. 2026-03-10: future date relative to todayWib='2026-02-20' -> ignored
        $futureEvent = Event::create([
            'name' => 'Pengajian MM Mar',
            'event_date' => '2026-03-10',
            'start_time' => '19:30:00',
            'target_kategori' => json_encode(['USMAN']),
            'event_type_id' => $eventType->id,
            'is_closed' => false,
        ]);
        Attendance::create(['event_id' => $futureEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'is_late' => false]);

        // 6. 2026-02-15: event_type_id=null -> ignored
        $adHocEvent = Event::create([
            'name' => 'Acara Ad-Hoc',
            'event_date' => '2026-02-15',
            'start_time' => '19:30:00',
            'target_kategori' => json_encode(['USMAN']),
            'event_type_id' => null,
            'is_closed' => false,
        ]);
        Attendance::create(['event_id' => $adHocEvent->id, 'generus_id' => $g1->id, 'status' => 'hadir', 'is_late' => false]);

        $service = app(CategoryStatisticsService::class);
        $res = $service->buildStatistics('2025-11', '2026-02', 'all', null, Carbon::parse('2026-02-20', 'Asia/Jakarta'));

        $this->assertSame([
            'start_month' => '2025-11',
            'end_month' => '2026-02',
            'label' => 'Nov 2025 - Feb 2026',
        ], $res['period']);
        $this->assertSame([2026, 2025], $res['available_years']);
        $this->assertSame(['Gabugan', 'Slogo'], $res['kelompok_options']);

        $cat = collect($res['categories'])->firstWhere('name', 'Muda-Mudi');
        $this->assertNotNull($cat);
        $this->assertEquals($eventType->id, $cat['event_type_id']);
        $this->assertEquals('MUDA_MUDI', $cat['code']);
        $this->assertFalse($cat['is_group_attendance']);

        $this->assertEquals(2, $cat['summary']['total_events']);
        $this->assertEquals(2, $cat['summary']['active_months']);
        $this->assertEquals(6, $cat['summary']['total_target']);
        $this->assertEquals(5, $cat['summary']['total_hadir']);
        $this->assertEquals(83, $cat['summary']['avg_percentage']); // (2+3)/(3+3) = 83%
        $this->assertEquals(33, $cat['summary']['trend_delta']); // 100% - 67% = +33
        $this->assertEquals(1, $cat['summary']['total_terlambat']);
        $this->assertEquals(0, $cat['summary']['total_izin_sakit']);
        $this->assertEquals(1, $cat['summary']['total_alpa']);
        $this->assertEquals(125000, $cat['summary']['total_infaq']);

        $this->assertSame(['2025-11', '2025-12', '2026-01', '2026-02'], array_column($cat['monthly_series'], 'month'));
        $this->assertSame(['Nov 2025', 'Des 2025', 'Jan 2026', 'Feb 2026'], array_column($cat['monthly_series'], 'label'));
        $this->assertTrue($cat['monthly_series'][0]['has_event']);
        $this->assertEquals(67, $cat['monthly_series'][0]['percentage']);
        $this->assertFalse($cat['monthly_series'][1]['has_event']);
        $this->assertNull($cat['monthly_series'][1]['percentage']);
        $this->assertFalse($cat['monthly_series'][2]['has_event']);
        $this->assertNull($cat['monthly_series'][2]['percentage']);
        $this->assertTrue($cat['monthly_series'][3]['has_event']);
        $this->assertEquals(100, $cat['monthly_series'][3]['percentage']);
        $this->assertSame([
            [
                'id' => $febEvent->id,
                'name' => 'Pengajian MM Feb',
                'event_date' => '2026-02-10',
                'kelompok' => null,
                'percentage' => 100,
            ],
        ], $cat['monthly_series'][3]['events']);

        // Group breakdown: Slogo (100%) then Gabugan (50%)
        $this->assertCount(2, $cat['group_breakdown']);
        $this->assertSame('Slogo', $cat['group_breakdown'][0]['kelompok']);
        $this->assertEquals(4, $cat['group_breakdown'][0]['target_total']);
        $this->assertEquals(4, $cat['group_breakdown'][0]['hadir']);
        $this->assertEquals(100, $cat['group_breakdown'][0]['percentage']);
        $this->assertSame('Gabugan', $cat['group_breakdown'][1]['kelompok']);
        $this->assertEquals(2, $cat['group_breakdown'][1]['target_total']);
        $this->assertEquals(1, $cat['group_breakdown'][1]['hadir']);
        $this->assertEquals(50, $cat['group_breakdown'][1]['percentage']);

        // Gender breakdown: Putra 3/4 (75%), Putri 2/2 (100%)
        $this->assertSame([
            'putra' => ['target' => 4, 'hadir' => 3, 'percentage' => 75],
            'putri' => ['target' => 2, 'hadir' => 2, 'percentage' => 100],
        ], $cat['gender_breakdown']);
    }

    public function test_target_kategori_fallback_to_event_type_and_empty_both_targets_all_active_passive(): void
    {
        $smpType = EventType::create([
            'name' => 'Bina Remaja',
            'code' => 'BINA_REMAJA',
            'start_time' => '18:30:00',
            'target_kategori' => ['1 SMP'],
            'is_group_attendance' => false,
        ]);

        $umumType = EventType::create([
            'name' => 'Pengajian Umum',
            'code' => 'UMUM',
            'start_time' => '19:00:00',
            'target_kategori' => [],
            'is_group_attendance' => false,
        ]);

        $pengurusType = PengurusType::where('code', 'PENGURUS_USMAN')->firstOrFail();
        $pengurusTypeEvent = EventType::create([
            'name' => 'Rapat Pengurus',
            'code' => 'RAPAT_PENGURUS',
            'start_time' => '20:00:00',
            'target_kategori' => [$pengurusType->name],
            'is_group_attendance' => false,
        ]);

        $smp1 = Generus::create([
            'nama_lengkap' => 'Dina',
            'jenis_kelamin' => 'P',
            'kelompok' => 'Slogo',
            'status' => 'aktif',
            'jenjang' => '1 SMP',
        ]);
        $usmanPengurus = Generus::create([
            'nama_lengkap' => 'Rizki',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'status' => 'aktif',
            'jenjang' => 'USMAN',
            'is_pengurus' => true,
        ]);
        $usmanPengurus->pengurusTypes()->attach($pengurusType->id);

        $pasifGenerus = Generus::create([
            'nama_lengkap' => 'Eko',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Gabugan',
            'status' => 'pasif',
            'jenjang' => '2 SMA/SMK',
        ]);
        // Tidak aktif harus selalu dikecualikan meski semua target kosong
        Generus::create([
            'nama_lengkap' => 'Alumni',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'status' => 'tidak aktif',
            'jenjang' => '1 SMP',
        ]);

        // Event A: target_kategori="[]" -> fallback ke EventType->target_kategori=['1 SMP'] (hanya $smp1)
        $eventA = Event::create([
            'name' => 'Bina Remaja Jan',
            'event_date' => '2026-01-12',
            'start_time' => '18:30:00',
            'target_kategori' => '[]',
            'event_type_id' => $smpType->id,
        ]);
        Attendance::create(['event_id' => $eventA->id, 'generus_id' => $smp1->id, 'status' => 'hadir', 'is_late' => false]);

        // Event B: event dan event_type sama-sama kosong -> target semua generus aktif/pasif (3 orang)
        $eventB = Event::create([
            'name' => 'Pengajian Umum Jan',
            'event_date' => '2026-01-15',
            'start_time' => '19:00:00',
            'target_kategori' => null,
            'event_type_id' => $umumType->id,
        ]);
        Attendance::create(['event_id' => $eventB->id, 'generus_id' => $smp1->id, 'status' => 'hadir', 'is_late' => false]);
        Attendance::create(['event_id' => $eventB->id, 'generus_id' => $pasifGenerus->id, 'status' => 'izin', 'is_late' => false]);

        // Event C: pengurus type match
        $eventC = Event::create([
            'name' => 'Rapat Pengurus Jan',
            'event_date' => '2026-01-20',
            'start_time' => '20:00:00',
            'target_kategori' => '[]',
            'event_type_id' => $pengurusTypeEvent->id,
        ]);
        Attendance::create(['event_id' => $eventC->id, 'generus_id' => $usmanPengurus->id, 'status' => 'hadir', 'is_late' => false]);

        $service = app(CategoryStatisticsService::class);
        $res = $service->buildStatistics('2026-01', '2026-01', 'all', null, Carbon::parse('2026-01-31', 'Asia/Jakarta'));

        $this->assertSame('Januari 2026', $res['period']['label']);

        $catA = collect($res['categories'])->firstWhere('name', 'Bina Remaja');
        $this->assertEquals(1, $catA['summary']['total_target']);
        $this->assertEquals(1, $catA['summary']['total_hadir']);
        $this->assertEquals(100, $catA['summary']['avg_percentage']);
        $this->assertNull($catA['summary']['trend_delta']); // < 2 active months

        $catB = collect($res['categories'])->firstWhere('name', 'Pengajian Umum');
        $this->assertEquals(3, $catB['summary']['total_target']);
        $this->assertEquals(1, $catB['summary']['total_hadir']);
        $this->assertEquals(1, $catB['summary']['total_izin_sakit']);
        $this->assertEquals(1, $catB['summary']['total_alpa']);
        $this->assertEquals(33, $catB['summary']['avg_percentage']);

        $catC = collect($res['categories'])->firstWhere('name', 'Rapat Pengurus');
        $this->assertEquals(1, $catC['summary']['total_target']);
        $this->assertEquals(1, $catC['summary']['total_hadir']);
        $this->assertEquals(100, $catC['summary']['avg_percentage']);
    }

    public function test_default_period_anchors_to_latest_qualifying_event_month(): void
    {
        $eventType = EventType::create([
            'name' => 'Pengajian Akbar',
            'code' => 'AKBAR',
            'start_time' => '19:00:00',
            'target_kategori' => ['USMAN'],
            'is_group_attendance' => false,
        ]);

        $kosongType = EventType::create([
            'name' => 'Kategori Kosong',
            'code' => 'KOSONG',
            'start_time' => '19:00:00',
            'target_kategori' => ['USMAN'],
            'is_group_attendance' => false,
        ]);

        $generus = Generus::create([
            'nama_lengkap' => 'Fajar',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'status' => 'aktif',
            'jenjang' => 'USMAN',
        ]);

        $todayWib = Carbon::parse('2026-03-10', 'Asia/Jakarta');

        // Qualifying event in 2026-01
        $janEvent = Event::create([
            'name' => 'Akbar Jan',
            'event_date' => '2026-01-18',
            'start_time' => '19:00:00',
            'target_kategori' => json_encode(['USMAN']),
            'event_type_id' => $eventType->id,
        ]);
        Attendance::create(['event_id' => $janEvent->id, 'generus_id' => $generus->id, 'status' => 'hadir', 'is_late' => false]);

        // Future event in 2026-05 (must NOT anchor default period)
        $mayEvent = Event::create([
            'name' => 'Akbar Mei',
            'event_date' => '2026-05-18',
            'start_time' => '19:00:00',
            'target_kategori' => json_encode(['USMAN']),
            'event_type_id' => $eventType->id,
        ]);
        Attendance::create(['event_id' => $mayEvent->id, 'generus_id' => $generus->id, 'status' => 'hadir', 'is_late' => false]);

        // Event in 2026-02 with 0 attendances (must NOT anchor default period)
        Event::create([
            'name' => 'Akbar Feb Libur',
            'event_date' => '2026-02-18',
            'start_time' => '19:00:00',
            'target_kategori' => json_encode(['USMAN']),
            'event_type_id' => $eventType->id,
        ]);

        $service = app(CategoryStatisticsService::class);
        $res = $service->buildStatistics(null, null, 'all', null, $todayWib);

        $this->assertSame('2025-08', $res['period']['start_month']);
        $this->assertSame('2026-01', $res['period']['end_month']);
        $this->assertSame('Agu 2025 - Jan 2026', $res['period']['label']);
        $this->assertSame([2026], $res['available_years']);

        // Kategori Kosong tetap dikembalikan dengan 0 event dan 6 bulan kosong
        $emptyCat = collect($res['categories'])->firstWhere('event_type_id', $kosongType->id);
        $this->assertNotNull($emptyCat);
        $this->assertEquals(0, $emptyCat['summary']['total_events']);
        $this->assertEquals(0, $emptyCat['summary']['avg_percentage']);
        $this->assertNull($emptyCat['summary']['trend_delta']);
        $this->assertCount(6, $emptyCat['monthly_series']);
        $this->assertFalse($emptyCat['monthly_series'][0]['has_event']);
        $this->assertNull($emptyCat['monthly_series'][0]['percentage']);

        // Jika belum ada acara qualifying di cakupan kelompok, fallback ke bulan $todayWib
        $fallbackRes = $service->buildStatistics(null, null, 'kelompok', null, $todayWib);
        $this->assertSame('2025-10', $fallbackRes['period']['start_month']);
        $this->assertSame('2026-03', $fallbackRes['period']['end_month']);
        $this->assertSame('Okt 2025 - Mar 2026', $fallbackRes['period']['label']);
    }
}
