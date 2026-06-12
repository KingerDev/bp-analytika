<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Zjednotená, anonymizovaná schéma pre oba segmenty (b2c = maloobchod/titi, b2b = veľkoobchod/tsv)

        Schema::create('ana_customers', function (Blueprint $table) {
            $table->id();
            $table->enum('segment', ['b2c', 'b2b'])->index();
            $table->unsignedBigInteger('source_id');
            $table->string('code', 20); // anonymný identifikátor, napr. B2C-000123
            $table->dateTime('registered_at')->nullable();
            $table->string('city', 128)->nullable();
            // B2B atribúty organizácie
            $table->unsignedBigInteger('org_source_id')->nullable();
            $table->string('org_size', 30)->nullable();
            $table->boolean('is_public_sector')->default(false);
            $table->string('pricing_tier', 20)->nullable();
            // roly v nákupnom centre (B2B rozhodovací proces)
            $table->boolean('is_approver')->default(false);
            $table->boolean('is_decision_maker')->default(false);
            $table->boolean('is_influencer')->default(false);
            // B2C vernostný program
            $table->integer('loyalty_points')->nullable();
            $table->timestamps();
            $table->unique(['segment', 'source_id']);
        });

        Schema::create('ana_products', function (Blueprint $table) {
            $table->id();
            $table->enum('segment', ['b2c', 'b2b'])->index();
            $table->unsignedBigInteger('source_id');
            $table->string('name', 300)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('category_name', 200)->nullable()->index(); // koreňová kategória (denormalizované)
            $table->timestamps();
            $table->unique(['segment', 'source_id']);
        });

        Schema::create('ana_orders', function (Blueprint $table) {
            $table->id();
            $table->enum('segment', ['b2c', 'b2b'])->index();
            $table->unsignedBigInteger('source_id');
            $table->foreignId('customer_id')->nullable()->constrained('ana_customers')->nullOnDelete();
            $table->dateTime('ordered_at')->index();
            $table->dateTime('approved_at')->nullable(); // B2B: kedy schválené schvaľovateľom
            $table->decimal('approval_hours', 10, 2)->nullable(); // dĺžka rozhodovacieho procesu
            $table->unsignedInteger('status_id')->nullable();
            $table->string('status_name', 100)->nullable();
            $table->boolean('is_cancelled')->default(false)->index();
            $table->decimal('total_net', 12, 2)->default(0);   // bez DPH
            $table->decimal('total_gross', 12, 2)->default(0); // s DPH
            $table->decimal('shipping_price', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('profit_net', 12, 2)->nullable();
            $table->string('payment_method', 128)->nullable();
            $table->string('shipping_method', 128)->nullable();
            $table->string('channel', 10)->default('web'); // web | app (B2C)
            $table->unsignedInteger('items_count')->default(0); // počet riadkov
            $table->unsignedInteger('units_count')->default(0); // počet kusov
            $table->integer('points_earned')->nullable();
            $table->string('city', 128)->nullable();
            $table->timestamps();
            $table->unique(['segment', 'source_id']);
        });

        Schema::create('ana_order_items', function (Blueprint $table) {
            $table->id();
            $table->enum('segment', ['b2c', 'b2b'])->index();
            $table->foreignId('order_id')->constrained('ana_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('ana_products')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price_net', 12, 4)->default(0);
            $table->decimal('unit_price_gross', 12, 2)->nullable();
            $table->decimal('total_net', 12, 2)->default(0);
            $table->unsignedTinyInteger('vat_rate')->nullable();
            $table->boolean('is_gift')->default(false);
            $table->timestamps();
        });

        Schema::create('ana_cart_items', function (Blueprint $table) {
            $table->id();
            $table->enum('segment', ['b2c', 'b2b'])->index();
            $table->unsignedBigInteger('customer_source_id')->nullable();
            $table->unsignedBigInteger('product_source_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->dateTime('added_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ana_cart_items');
        Schema::dropIfExists('ana_order_items');
        Schema::dropIfExists('ana_orders');
        Schema::dropIfExists('ana_products');
        Schema::dropIfExists('ana_customers');
    }
};
