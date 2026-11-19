<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Local id: sent as both Idempotency-Key and metadata.local_id.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images');

            // Money: USD, NUMERIC(20,9), never floats. Estimates are customer prices.
            $table->decimal('markup', 8, 4);
            $table->decimal('estimated_price_min', 20, 9);
            $table->decimal('estimated_price_max', 20, 9);
            // Actual amounts: written once, on the transition into `succeeded`.
            $table->decimal('provider_cost', 20, 9)->nullable();
            $table->decimal('price', 20, 9)->nullable();

            $table->jsonb('usage')->nullable();
            $table->jsonb('rates')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            // Provider completion time (UTC): the day Pixelforge bills usage on.
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
