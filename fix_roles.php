<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$generuses = App\Models\Generus::whereNotNull('user_id')->get();
$count = 0;
foreach ($generuses as $g) {
    $u = App\Models\User::find($g->user_id);
    if ($u && $u->role !== 'admin') {
        $expectedRole = strtoupper($g->jenjang ?? '') === 'MT' ? 'mt' : 'user';
        if ($u->role !== $expectedRole) {
            $u->role = $expectedRole;
            $u->save();
            $count++;
            echo "Updated {$u->username} to $expectedRole\n";
        }
    }
}
echo "Fixed $count users.\n";
