<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The slate, §8.4 -- ten things a prospector means to make.
 *
 * A shopping list rather than a second catalog. What it is FOR is the walk: a
 * recipe you cannot afford yet names materials you have to go and get, and the
 * bench that would tell you is four days away. Ten because §6.3 already fixed
 * the number of things a person can plan a route around; a hundred saved
 * recipes is a spreadsheet, which is what the north star is against.
 *
 * There is no `kind` column. A key is either a processing recipe or a craftable
 * item and the catalog knows which, exactly as §8.4 derives a bench category
 * from the slot rather than storing it -- a stored kind is only somewhere for
 * the two to disagree.
 *
 * There is no `position` either. The order is the order they were written down,
 * which `id` already says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('recipe_key', 64);
            $table->timestamps();

            // One line per recipe, and the index is the guarantee rather than
            // the code: a doubled tap cannot spend two of the ten on one thing.
            $table->unique(['character_id', 'recipe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_bookmarks');
    }
};
