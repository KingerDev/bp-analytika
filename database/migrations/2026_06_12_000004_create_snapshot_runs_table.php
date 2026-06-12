<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log každého pokusu o stiahnutie Clarity snapshotu — na kontrolu,
        // či automatizácia (cron na hostingu) beží a či úspešne
        Schema::create('ana_snapshot_runs', function (Blueprint $table) {
            $table->id();
            $table->enum('segment', ['b2c', 'b2b']);
            $table->enum('status', ['success', 'failed'])->index();
            $table->unsignedInteger('sessions')->nullable();
            $table->text('message')->nullable();
            $table->dateTime('ran_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ana_snapshot_runs');
    }
};
