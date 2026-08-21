<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8.0.1 -- rolled bonus lines.
 *
 * Options are per *instance*, not per definition: two Iron Pickaxes off the same
 * recipe are no longer the same object. That is the whole point, and it is why
 * this lands on the row rather than in the catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_items', function (Blueprint $table) {
            $table->json('options')->nullable()->after('durability');
        });
    }

    public function down(): void
    {
        Schema::table('character_items', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
