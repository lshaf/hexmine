<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §9.5.7 -- what is left standing when a prospector dies.
 *
 * A pack is a hash (§9.5.1) and costs no storage because nothing about it is
 * anybody's decision. A carrier is the opposite: it exists because somebody
 * died on that hex, it holds a specific row out of their bag, and it is drawn
 * for EVERY player regardless of sight. None of that is derivable, so all of it
 * is a table.
 *
 * It is not in the cache with the cleared flag for the same reason: the flag is
 * worthless the moment its bucket ends, and this outlives twelve of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->integer('col');
            $table->integer('row');
            $table->string('monster_key');

            // Whose row it is holding. The owner is the ONLY wallet that can
            // take it back (§9.5.7): anybody else killing this destroys the row
            // outright, because an item another wallet can pick up is the
            // player-to-player transfer §2 closes.
            $table->foreignId('owner_character_id')->constrained('characters')->cascadeOnDelete();

            // The stolen row itself, whole: a material stack or an item with
            // its durability and rolled options. Stored rather than referenced
            // because the row it came from is deleted -- the bag really is one
            // lighter until somebody walks back for it.
            $table->json('loot');

            // What the glyph says it is holding, without a lookup.
            $table->string('label');

            $table->unsignedBigInteger('expires_at')->comment('unix ms the corpse crumbles and the row is gone for good');
            $table->timestamps();

            $table->index(['col', 'row']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carriers');
    }
};
