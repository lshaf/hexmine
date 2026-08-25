<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A prospector may take a name, and no two may hold the same one.
 *
 * The column becomes NULLABLE, and that is the whole design rather than a
 * convenience. Every character was born called "Prospector", so a unique index
 * over a non-null column could never have been added without inventing a name
 * for everybody first -- and an invented name is not a name, it is a serial
 * number with a friendly font.
 *
 * NULL says the honest thing instead: this character has not been named. It
 * displays as "Prospector", which is a LABEL for the unnamed state rather than
 * a name anybody holds, and MySQL permits as many NULLs in a unique index as
 * there are unnamed prospectors. The moment somebody claims a name it is theirs
 * alone, enforced by the schema rather than by a check that has to be
 * remembered at every call site.
 *
 * Case is the collation's business: the database is utf8mb4_0900_ai_ci, so the
 * index already refuses "Shaf" to somebody while "shaf" stands. The rename
 * checks it too, but only to say so in words a player can act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('name', 16)->nullable()->change();
        });

        // Everyone currently carries the label rather than a name, so it is
        // cleared before the index goes on -- otherwise the second character
        // ever created would collide with the first.
        DB::table('characters')->where('name', 'Prospector')->update(['name' => null]);

        Schema::table('characters', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique('characters_name_unique');
        });

        // Back to a column that cannot be empty, so the label has to be
        // written out again for everyone who never claimed a name.
        DB::table('characters')->whereNull('name')->update(['name' => 'Prospector']);

        Schema::table('characters', function (Blueprint $table) {
            $table->string('name')->nullable(false)->default('Prospector')->change();
        });
    }
};
