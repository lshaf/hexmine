<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8.5 -- consumables and the buffs they start.
 *
 * Consumables are stackable and are never equipped, so they do not belong in
 * `character_items` (one row per object, with durability and a slot). They are
 * closer to materials: a key and a count.
 *
 * Buffs are stored with an absolute expiry and never ticked, exactly like every
 * other timer in the game (§16). An hour offline and an hour idle must produce
 * the same result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_consumables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('item_key');
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['character_id', 'item_key']);
        });

        Schema::create('character_buffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('item_key');
            $table->string('stat');
            $table->float('value');
            // Server clock, milliseconds. Expiry is derived, never ticked.
            $table->unsignedBigInteger('expires_at');
            $table->timestamps();

            // One buff per stat: drinking two of the same does not stack, it
            // refreshes. Enforced here so no code path can produce a stack.
            $table->unique(['character_id', 'stat']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_buffs');
        Schema::dropIfExists('character_consumables');
    }
};
