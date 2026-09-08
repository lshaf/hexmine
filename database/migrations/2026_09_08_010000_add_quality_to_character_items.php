<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8.0.2 -- how well this copy came out.
 *
 * A signed offset in permille, applied to every solid figure the piece carries
 * -- its attack, its defense and its durability ceiling, together. One roll
 * rather than three, because a piece being *a good one* is something a player
 * can hold in their head where good-attack-and-poor-defense is noise.
 *
 * Per instance like §8.0.1's options, and for the same reason: two Stone Axes
 * off one shelf are no longer the same object. Null is a piece made before this
 * existed, and it reads as the recipe exactly -- which is what it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_items', function (Blueprint $table) {
            $table->smallInteger('quality')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('character_items', function (Blueprint $table) {
            $table->dropColumn('quality');
        });
    }
};
