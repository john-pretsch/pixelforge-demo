<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // Dedupe key: an event is processed at most once.
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('provider_generation_id')->nullable()->index();
            $table->unsignedInteger('delivery_attempt')->nullable();
            $table->jsonb('payload');
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
