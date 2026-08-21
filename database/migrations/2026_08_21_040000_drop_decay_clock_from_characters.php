<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §7.6 -- the bag replaces storage-overflow decay, so the clock it ticked
 * against has nothing left to measure.
 *
 * Over-cap raw materials used to rot every ten minutes. Now being over the cap
 * stops you leaving the hex instead, which is a decision rather than a slow
 * leak, and there is no second penalty on top of it. `last_decay_at` was the
 * timestamp that pass derived from; a column nothing reads is a column that
 * will eventually be read by mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('last_decay_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Defaulted, because the rows that exist by then have no decay
            // history to restore and the old pass only ever read it forwards.
            $table->unsignedBigInteger('last_decay_at')->default(0);
        });
    }
};
