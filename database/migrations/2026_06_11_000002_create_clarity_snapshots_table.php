<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Denné snapshoty z Clarity Data Export API (vracia len 1–3 dni dozadu,
        // limit 10 requestov/projekt/deň — preto sa výsledky archivujú lokálne)
        Schema::create('ana_clarity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->enum('segment', ['b2c', 'b2b'])->index();
            $table->date('captured_on');
            $table->unsignedTinyInteger('num_days')->default(3);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('bot_sessions')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->decimal('pages_per_session', 6, 2)->nullable();
            $table->decimal('engagement_avg_seconds', 8, 1)->nullable();
            $table->decimal('active_avg_seconds', 8, 1)->nullable();
            $table->decimal('scroll_depth', 5, 2)->nullable();
            $table->unsignedInteger('dead_clicks')->default(0);
            $table->unsignedInteger('rage_clicks')->default(0);
            $table->unsignedInteger('quick_backs')->default(0);
            $table->unsignedInteger('excessive_scrolls')->default(0);
            $table->unsignedInteger('script_errors')->default(0);
            $table->unsignedInteger('error_clicks')->default(0);
            $table->json('devices')->nullable();
            $table->json('top_pages')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['segment', 'captured_on', 'num_days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ana_clarity_snapshots');
    }
};
