<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Contoh: Rutinan Semalaman, Keakraban, Festival
            $table->string('code')->unique(); // Contoh: SEMALAMAN, KEAKRABAN, FESTIVAL
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('event_types');
    }
};
