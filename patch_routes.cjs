const fs = require('fs');
const filepath = 'routes/api.php';
let content = fs.readFileSync(filepath, 'utf8');

// Update first group
content = content.replace(
    "Route::middleware('role:admin,operator_absensi')->group(function () {",
    "Route::middleware('role:admin,operator_absensi,mt')->group(function () {"
);

// Remove event routes from admin group
content = content.replace("        Route::patch('admin/events/{id}/toggle-status', [EventController::class, 'toggleStatus']);\n", "");
content = content.replace("        Route::apiResource('admin/events', EventController::class)->except(['index', 'show']);\n", "");

// Add a new group for admin and mt
const adminGroupEnd = "        Route::apiResource('admin/event-types', EventTypeController::class)->except(['index']);\n    });";
const newBlock = `        Route::apiResource('admin/event-types', EventTypeController::class)->except(['index']);
    });

    // ------------------------------------------
    // KHUSUS ADMIN & MT (Membuat Acara)
    // ------------------------------------------
    Route::middleware('role:admin,mt')->group(function () {
        Route::patch('admin/events/{id}/toggle-status', [EventController::class, 'toggleStatus']);
        Route::apiResource('admin/events', EventController::class)->except(['index', 'show']);
    });`;

content = content.replace(adminGroupEnd, newBlock);

fs.writeFileSync(filepath, content, 'utf8');
console.log("Routes updated!");
