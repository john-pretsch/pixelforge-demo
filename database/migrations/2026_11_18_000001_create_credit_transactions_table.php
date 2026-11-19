<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only credit ledger. A user's balance is SUM(amount).
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type'); // purchase | hold | release | debit
            // Signed USD, NUMERIC(20,9): credits positive, holds/debits negative.
            $table->decimal('amount', 20, 9);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('user_id');
            // One hold, one release, one debit per generation: settling twice is impossible.
            // NULL generation_ids (purchases) are distinct in Postgres, so they're unrestricted.
            $table->unique(['generation_id', 'type']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
