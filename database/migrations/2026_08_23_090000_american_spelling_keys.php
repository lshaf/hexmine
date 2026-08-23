<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * American spelling, which reaches further than the source.
 *
 * Some of the renamed words were not only prose: `defence` is a StatKey stored
 * on a buff and inside an item's rolled options, `*_draught` is a consumable
 * key, and two skill-tree nodes were spelled `mould`. A rename that stopped at
 * the source would leave those rows pointing at things the catalog no longer
 * has -- a potion that cannot be drunk, a bought node that is not in its tree.
 *
 * Every one of these is a straight substitution, and none of them can collide:
 * no American spelling was already in use as a key.
 */
return new class extends Migration
{
    /** old key => new key. */
    private const KEYS = [
        'forest_draught' => 'forest_draft',
        'deepseam_draught' => 'deepseam_draft',
        'beastcall_draught' => 'beastcall_draft',
        'quarry_draught' => 'quarry_draft',
        'fieldwise_draught' => 'fieldwise_draft',
        'swiftaxe_draught' => 'swiftaxe_draft',
        'quickpick_draught' => 'quickpick_draft',
        'lightfoot_draught' => 'lightfoot_draft',
        'stonecut_draught' => 'stonecut_draft',
        'sicklehand_draught' => 'sicklehand_draft',
        'ironhide_draught' => 'ironhide_draft',
        'warcry_draught' => 'warcry_draft',
        'guild_draught' => 'guild_draft',
    ];

    private const NODES = [
        'alchemist.perfect_draught' => 'alchemist.perfect_draft',
        'mason.moulded_work' => 'mason.molded_work',
        'smelter.ingot_moulds' => 'smelter.ingot_molds',
    ];

    public function up(): void
    {
        foreach (self::KEYS as $old => $new) {
            DB::table('character_consumables')->where('item_key', $old)->update(['item_key' => $new]);
            DB::table('character_items')->where('item_key', $old)->update(['item_key' => $new]);
        }

        foreach (self::NODES as $old => $new) {
            DB::table('character_nodes')->where('node_key', $old)->update(['node_key' => $new]);
        }

        // §9.5.4 -- the StatKey. On a buff as a column, and inside an item's
        // rolled options and a running fight's payload as JSON.
        DB::table('character_buffs')->where('stat', 'defence')->update(['stat' => 'defense']);

        $this->retext('character_items', 'options');
        $this->retext('jobs_queue', 'payload');
    }

    public function down(): void
    {
        foreach (self::KEYS as $old => $new) {
            DB::table('character_consumables')->where('item_key', $new)->update(['item_key' => $old]);
            DB::table('character_items')->where('item_key', $new)->update(['item_key' => $old]);
        }

        foreach (self::NODES as $old => $new) {
            DB::table('character_nodes')->where('node_key', $new)->update(['node_key' => $old]);
        }

        DB::table('character_buffs')->where('stat', 'defense')->update(['stat' => 'defence']);

        $this->retext('character_items', 'options', true);
        $this->retext('jobs_queue', 'payload', true);
    }

    /** Rewrite the stat name inside a JSON column, row by row. */
    private function retext(string $table, string $column, bool $back = false): void
    {
        [$from, $to] = $back ? ['"defense"', '"defence"'] : ['"defence"', '"defense"'];

        DB::table($table)
            ->whereNotNull($column)
            ->where($column, 'like', '%'.trim($from, '"').'%')
            ->orderBy('id')
            ->each(function (object $row) use ($table, $column, $from, $to) {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update([$column => str_replace($from, $to, (string) $row->{$column})]);
            });
    }
};
