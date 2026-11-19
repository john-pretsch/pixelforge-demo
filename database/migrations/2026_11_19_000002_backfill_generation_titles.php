<?php

use App\Models\Generation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Give generations created before titles existed the same default new ones get. */
    public function up(): void
    {
        DB::table('generations')->whereNull('title')->orderBy('id')->select('id', 'prompt')
            ->each(fn (object $row) => DB::table('generations')->where('id', $row->id)
                ->update(['title' => Generation::titleFromPrompt((string) $row->prompt)]));
    }

    public function down(): void
    {
        // Data-only backfill: nothing to undo structurally.
    }
};
