<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Alter enum to include 'operator_absensi'
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'user', 'mt', 'operator_absensi') DEFAULT 'user'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert enum back (warning: might fail if there are users with 'operator_absensi' role)
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'user', 'mt') DEFAULT 'user'");
    }
};
