<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Event;
use App\Models\MeetingNote;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class MeetingNoteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migrations = [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_05_16_101334_create_events_table.php',
            'database/migrations/2026_05_16_101335_create_attendances_table.php',
            'database/migrations/2026_05_16_104305_add_target_kategori_to_events_table.php',
            'database/migrations/2026_05_17_174305_create_event_types_table.php',
            'database/migrations/2026_05_17_181932_add_event_type_id_to_events_table.php',
            'database/migrations/2026_10_03_135857_add_is_group_attendance_to_event_types_table.php',
            'database/migrations/2026_11_09_073527_create_meeting_notes_table.php',
        ];

        foreach ($migrations as $path) {
            Artisan::call('migrate', ['--path' => $path]);
        }
    }

    protected function getAdminUser()
    {
        return User::create([
            'name' => 'Admin Testing',
            'username' => 'admin_test_' . uniqid(),
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    protected function getSampleEvent()
    {
        return Event::create([
            'name' => 'Musyawarah MD MD desa',
            'event_date' => '2026-11-09',
            'start_time' => '19:30:00',
            'target_kategori' => json_encode(['MT', 'PENGURUS MUDA MUDI']),
        ]);
    }

    public function test_unauthenticated_user_cannot_access_notulen(): void
    {
        $response = $this->getJson('/api/admin/notulen');
        $response->assertStatus(401);
    }

    public function test_admin_can_crud_meeting_notes(): void
    {
        $admin = $this->getAdminUser();
        Sanctum::actingAs($admin);

        $event = $this->getSampleEvent();

        // 1. Create Notulen
        $payload = [
            'judul' => 'Musyawarah MD MD Evaluasi Bulanan',
            'tanggal' => '2026-11-09',
            'waktu' => '19:30 WIB',
            'lokasi' => 'Masjid Desa Slogo',
            'pemimpin' => 'Bpk. Ketua',
            'notulis' => 'Sekretaris',
            'agenda' => 'Evaluasi program dan persiapan acara tahunan',
            'pembahasan' => 'Poin evaluasi berjalan tertib dan lancar.',
            'keputusan' => [
                ['id' => 1, 'poin' => 'Jadwal pengajian dipercepat 15 menit', 'keterangan' => 'Mulai pekan depan', 'status' => 'Disepakati']
            ],
            'event_id' => $event->id,
            'peserta_hadir_count' => 30,
        ];

        $createRes = $this->postJson('/api/admin/notulen', $payload);
        $createRes->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.judul', 'Musyawarah MD MD Evaluasi Bulanan');

        $noteId = $createRes->json('data.id');

        // 2. Read Notulen list & show
        $listRes = $this->getJson('/api/admin/notulen');
        $listRes->assertStatus(200)
            ->assertJsonPath('success', true);

        $showRes = $this->getJson("/api/admin/notulen/{$noteId}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.id', $noteId);

        // 3. Events List for picker
        $eventsListRes = $this->getJson('/api/admin/notulen/events-list');
        $eventsListRes->assertStatus(200)
            ->assertJsonPath('success', true);

        // 4. Update Notulen
        $updatePayload = array_merge($payload, [
            'judul' => 'Musyawarah MD MD Hasil Revisi'
        ]);
        $updateRes = $this->putJson("/api/admin/notulen/{$noteId}", $updatePayload);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.judul', 'Musyawarah MD MD Hasil Revisi');

        // 5. Delete Notulen
        $deleteRes = $this->deleteJson("/api/admin/notulen/{$noteId}");
        $deleteRes->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('meeting_notes', ['id' => $noteId]);
    }
}
