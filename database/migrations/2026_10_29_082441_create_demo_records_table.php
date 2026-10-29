<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every row the demo seeder (php artisan demo:seed) creates, so demo:purge
 * can remove exactly those rows and nothing else. Additive only: no other
 * table is touched.
 *
 *  - record_table / record_id : a row created by the seeder (any table);
 *  - demo_key                 : the seeder's logical key of an item, so a
 *                               re-run never creates it twice;
 *  - original / applied       : a pre-existing demo row the seeder filled in
 *                               (purge puts the original values back);
 *  - phase                    : staff (people, departments) or activity
 *                               (attendance, leave, requests, documents,
 *                               recruitment), so activity can be redone alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('phase', 20)->default('activity');
            $table->string('record_table', 100);
            $table->string('record_id', 191);
            $table->string('demo_key', 191)->nullable()->unique();
            $table->json('original')->nullable();
            $table->json('applied')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['record_table', 'record_id']);
            $table->index(['company_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_records');
    }
};
