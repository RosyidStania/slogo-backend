<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
    Schema::create('attendances', function (Blueprint $table) {
        $table->id();
        $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
        $table->foreignId('generus_id')->constrained('generus')->onDelete('cascade');
        $table->string('status')->default('alpa');
        $table->string('time_arrived')->nullable();
        $table->boolean('is_late')->default(false);
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
