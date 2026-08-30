<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dailies, §12.2 -- one row per character per day per task.
 *
 * The `day` column is what makes this a daily rather than a slower quest. A
 * tally is keyed to the day it was earned on, so yesterday's haul cannot credit
 * today's task and there is no reset job to run: the day turns and the old rows
 * simply stop being asked about.
 *
 * Written on first credit or first claim, never up front. Which three tasks a
 * character has is derived from `(character, day, lane)` (§12.2), so a day
 * nobody played leaves nothing behind -- the same shape as a pack (§9.5.1) and
 * a pocket (§5.7).
 *
 * No `completed_at`, for the reason §12.1's table gives: whether a goal is met
 * is a comparison against the task's target, and storing the answer beside the
 * inputs is a second opinion about one fact. `claimed_at` is stored because it
 * is a decision the player made, which nothing can recompute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_dailies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            // Server-clock milliseconds divided by a (scaled) day. An integer
            // rather than a date: the game's clock is milliseconds everywhere
            // else, and GAME_TIME_SCALE makes a day whatever it needs to be.
            $table->unsignedBigInteger('day');
            $table->string('task_key');
            $table->unsignedBigInteger('progress')->default(0);
            $table->unsignedBigInteger('claimed_at')->nullable();
            $table->timestamps();

            // One claim per task per day, and the index is the guarantee rather
            // than the code -- a doubled request cannot pay twice.
            $table->unique(['character_id', 'day', 'task_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_dailies');
    }
};
