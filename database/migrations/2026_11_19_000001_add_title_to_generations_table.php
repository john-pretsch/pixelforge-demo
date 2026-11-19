<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // User-facing label only: never sent to Pixelforge. Nullable for rows created before titles existed.
            $table->string('title', 25)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
