<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manuálne exporty heatmáp z Clarity UI (PNG obrázok + CSV dáta) —
        // API ich neposkytuje, preto sa nahrávajú cez formulár na /heatmapy
        Schema::create('ana_heatmaps', function (Blueprint $table) {
            $table->id();
            $table->enum('segment', ['b2c', 'b2b'])->index();
            $table->string('page_label', 100)->index(); // napr. "Homepage", "Košík"
            $table->enum('type', ['click', 'scroll', 'area']);
            $table->string('device', 20)->nullable();   // PC / Mobile / Tablet
            $table->string('period_label', 50)->nullable();
            $table->json('csv_data')->nullable();       // {headers: [], rows: []}
            $table->string('png_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ana_heatmaps');
    }
};
