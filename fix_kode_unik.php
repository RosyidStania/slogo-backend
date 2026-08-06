<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$generus = App\Models\Generus::whereNull('kode_unik')->get();
foreach ($generus as $g) {
    $g->kode_unik = 'GEN-' . strtoupper(Illuminate\Support\Str::random(8));
    $g->save();
}
echo "Done updating " . $generus->count() . " records.\n";
