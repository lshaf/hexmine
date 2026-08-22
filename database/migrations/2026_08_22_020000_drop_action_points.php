<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Action points are gone.
 *
 * AP gated a trip on a pool that refilled on a clock, which meant the limiter
 * on play was a second timer running underneath the one the trip already has.
 * A limit will come back, but it is not going to be this one, so the columns go
 * rather than sit dormant and half-true.
 *
 * The regeneration itself was never broken -- it was lazy, derived from a
 * deadline, and correct across arbitrary offline gaps. What it was, was the
 * wrong lever. Formulas::regenerateAp() went with it; git history has it if a
 * future limiter wants the same shape.
 *
 * Rolling back restores the columns but not the values, which are not
 * recoverable. Everyone comes back with a full pool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['ap', 'ap_updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('ap')->default(20);
            $table->unsignedBigInteger('ap_updated_at')->default(0);
        });
    }
};
