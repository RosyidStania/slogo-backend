<?php
// tes auto deploy
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\GenerusController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\AttendanceController; 
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EventTypeController;
use App\Http\Controllers\Api\ReportController;
// ==========================================
// PUBLIC ROUTES (Tidak Perlu Login)
// ==========================================
Route::post('/login', [AuthController::class, 'login']);
// Rute penyelamat jika token kadaluarsa
Route::get('/login', function () {
    return response()->json(['success' => false, 'message' => 'Sesi Anda telah habis. Silakan login ulang.'], 401);
})->name('login');
// ==========================================
// PROTECTED ROUTES (Wajib Bawa Token Valid)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {
    
    // Bisa diakses oleh Admin maupun User
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Fitur Profil & Reset Password
    Route::post('/reset-password', [AuthController::class, 'resetPassword']); 

    // ------------------------------------------
    // KHUSUS ADMIN
    // ------------------------------------------
    Route::middleware('role:admin')->group(function () {
        // Otomatis membuat rute: GET, POST, PUT, DELETE untuk /admin/users
        // Letakkan baris ini DI ATAS apiResource generus
        Route::post('admin/generus/promote', [\App\Http\Controllers\Api\GenerusController::class, 'promoteAll']);
        Route::post('admin/generus/demote', [\App\Http\Controllers\Api\GenerusController::class, 'demoteAll']);
        Route::post('admin/generus/import', [\App\Http\Controllers\Api\GenerusController::class, 'import']);
        Route::get('admin/generus/export', [\App\Http\Controllers\Api\GenerusController::class, 'exportCsv']);
        Route::delete('admin/generus/destroy-all', [\App\Http\Controllers\Api\GenerusController::class, 'destroyAll']);
        Route::apiResource('admin/generus', \App\Http\Controllers\Api\GenerusController::class);
        Route::get('admin/reports/available-years', [ReportController::class, 'availableYears']);
        Route::get('admin/reports/attendance-by-type', [ReportController::class, 'attendanceByType']);
        Route::delete('admin/users/destroy-all', [AdminUserController::class, 'destroyAllUsers']);
        Route::post('admin/users/generate-from-generus', [AdminUserController::class, 'generateFromGenerus']);
        Route::apiResource('admin/users', AdminUserController::class);
        Route::get('admin/events/{id}/summary', [AttendanceController::class, 'summary']);
        Route::post('admin/attendance', [AttendanceController::class, 'store']);
        Route::post('admin/attendance/bulk', [AttendanceController::class, 'bulkStore']);
        Route::delete('admin/attendance/{eventId}/{generusId}', [AttendanceController::class, 'destroy']);
        Route::get('admin/dashboard-stats', [DashboardController::class, 'index']);
        // Otomatis membuat rute: GET, POST, PUT, DELETE untuk /admin/events
        Route::apiResource('admin/events', EventController::class);
        Route::apiResource('admin/event-types', EventTypeController::class);
    });

    // ------------------------------------------
    // KHUSUS USER (Generus)
    // ------------------------------------------
    Route::middleware('role:user')->group(function () {
        Route::get('/user/profile', [AuthController::class, 'profile']);
        Route::put('/user/profile', [AuthController::class, 'updateProfile']);
        Route::get('/user/report', [\App\Http\Controllers\Api\UserDashboardController::class, 'report']);
    });
});