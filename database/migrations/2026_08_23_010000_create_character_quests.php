<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quests, §12.1 -- the §3.2 gold faucet, one row per character per quest.
 *
 * A row is written the first time a quest is progressed or claimed, not at
 * character creation: the catalog grows and a character minted before a quest
 * existed must still be able to do it. Absent means untouched, which is exactly
 * what a fresh character's standing is.
 *
 * There is no `completed_at`. Whether a goal is met is a comparison against the
 * quest's target, and storing the answer as well as the inputs would be a second
 * opinion about the same fact -- the same reason there is no `spent_points`
 * column on the nodes table. `claimed_at` is stored because it is a decision the
 * player made, not a comparison anything can redo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('quest_key');
            $table->unsignedBigInteger('progress')->default(0);
            // Server-clock milliseconds, like every other time in the schema.
            $table->unsignedBigInteger('claimed_at')->nullable();
            $table->timestamps();

            // One-shot, and the index is the guarantee rather than the code: a
            // doubled claim request cannot pay twice.
            $table->unique(['character_id', 'quest_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_quests');
    }
};
