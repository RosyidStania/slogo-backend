<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('events', 'auto_alpa_at')) {
            Schema::table('events', function (Blueprint $table) {
                $table->timestamp('auto_alpa_at')->nullable()->after('auto_closed_at')->index();
            });
        }

        $todayWib = Carbon::now('Asia/Jakarta')->toDateString();

        DB::table('events')
            ->whereNull('auto_alpa_at')
            ->where(function ($query) use ($todayWib) {
                $query->where('is_closed', true)
                    ->orWhereNotNull('auto_closed_at')
                    ->orWhere('event_date', '<', $todayWib);
            })
            ->update([
                'auto_alpa_at' => DB::raw('COALESCE(auto_closed_at, updated_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('events', 'auto_alpa_at')) {
            Schema::table('events', function (Blueprint $table) {
                if (Schema::hasIndex('events', ['auto_alpa_at'])) {
                    $table->dropIndex(['auto_alpa_at']);
                }
                $table->dropColumn('auto_alpa_at');
            });
        }
    }
};
