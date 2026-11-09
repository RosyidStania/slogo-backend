<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Generus;
use App\Models\PengurusType;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class PengurusTypeTest extends TestCase
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
            'database/migrations/2026_11_09_161500_create_pengurus_tables_and_migrate_data.php',
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

    public function test_unauthenticated_user_cannot_access_pengurus_types(): void
    {
        $response = $this->getJson('/api/admin/pengurus-types');
        $response->assertStatus(401);
    }

    public function test_admin_can_list_default_pengurus_types(): void
    {
        $admin = $this->getAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/pengurus-types');
        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        $codes = collect($response->json('data'))->pluck('code')->toArray();
        $this->assertContains('PENGURUS_USMAN', $codes);
        $this->assertContains('PENGURUS_MUDA_MUDI', $codes);
        $this->assertContains('KETUA_WAKIL_KELOMPOK', $codes);
    }

    public function test_admin_can_create_custom_pengurus_type(): void
    {
        $admin = $this->getAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/pengurus-types', [
            'name' => 'Pengurus Remaja Desa',
            'badge_color' => 'blue',
            'description' => 'Khusus mengelola remaja tingkat desa',
        ]);

        $response->assertStatus(201)
                 ->assertJson(['success' => true]);

        $this->assertDatabaseHas('pengurus_types', [
            'name' => 'Pengurus Remaja Desa',
            'code' => 'PENGURUS_REMAJA_DESA',
        ]);
    }

    public function test_system_core_pengurus_types_cannot_be_deleted(): void
    {
        $admin = $this->getAdminUser();
        Sanctum::actingAs($admin);

        $usmanType = PengurusType::where('code', 'PENGURUS_USMAN')->first();
        $this->assertNotNull($usmanType);

        $response = $this->deleteJson("/api/admin/pengurus-types/{$usmanType->id}");
        $response->assertStatus(422)
                 ->assertJson(['success' => false]);

        $this->assertDatabaseHas('pengurus_types', [
            'id' => $usmanType->id,
            'code' => 'PENGURUS_USMAN',
        ]);
    }

    public function test_bulk_assign_and_remove_members_syncs_legacy_flags(): void
    {
        $admin = $this->getAdminUser();
        Sanctum::actingAs($admin);

        $generus = Generus::create([
            'nama_lengkap' => 'Budi Santoso',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'status' => 'aktif',
            'jenjang' => 'USMAN',
            'is_pengurus' => false,
        ]);

        $usmanType = PengurusType::where('code', 'PENGURUS_USMAN')->first();

        // 1. Bulk assign to Pengurus Usman
        $assignResponse = $this->postJson("/api/admin/pengurus-types/{$usmanType->id}/bulk-assign", [
            'generus_ids' => [$generus->id],
        ]);
        $assignResponse->assertStatus(200);

        $generus->refresh();
        $this->assertEquals(1, $generus->is_pengurus);
        $this->assertTrue($generus->pengurusTypes()->where('pengurus_types.id', $usmanType->id)->exists());

        // 2. Remove member from Pengurus Usman
        $removeResponse = $this->postJson("/api/admin/pengurus-types/{$usmanType->id}/remove-members", [
            'generus_ids' => [$generus->id],
        ]);
        $removeResponse->assertStatus(200);

        $generus->refresh();
        $this->assertEquals(0, $generus->is_pengurus);
        $this->assertFalse($generus->pengurusTypes()->where('pengurus_types.id', $usmanType->id)->exists());
    }

    public function test_candidate_members_excludes_assigned_and_includes_pasif(): void
    {
        $admin = $this->getAdminUser();
        Sanctum::actingAs($admin);

        $aktif = Generus::create([
            'nama_lengkap' => 'Calon Aktif',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'status' => 'aktif',
        ]);

        $pasif = Generus::create([
            'nama_lengkap' => 'Calon Pasif',
            'jenis_kelamin' => 'P',
            'kelompok' => 'Slogo',
            'status' => 'pasif',
        ]);

        $tidakAktif = Generus::create([
            'nama_lengkap' => 'Nonaktif Alum',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'status' => 'tidak aktif',
        ]);

        $usmanType = PengurusType::where('code', 'PENGURUS_USMAN')->first();

        // Assign 'aktif' member
        $usmanType->generus()->attach($aktif->id);

        $response = $this->getJson("/api/admin/pengurus-types/{$usmanType->id}/candidates");
        $response->assertStatus(200);

        $candidateIds = collect($response->json('data'))->pluck('id')->toArray();

        // Assigned member must NOT be in candidates
        $this->assertNotContains($aktif->id, $candidateIds);
        // Pasif member MUST be in candidates
        $this->assertContains($pasif->id, $candidateIds);
        // 'tidak aktif' member must NOT be in candidates
        $this->assertNotContains($tidakAktif->id, $candidateIds);
    }
}
