<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Game\Balance;
use App\Game\Catalog;
use App\Models\Carrier;
use App\Models\Character;
use App\Models\CharacterConsumable;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\GameJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * §16 -- what is actually in the world, counted.
 *
 * "Build the economic telemetry dashboard early. Ship it before launch, not
 * after the first inflation crisis." This is the first half of that: STOCKS,
 * which are a straight count of what every wallet is holding right now.
 *
 * Flows -- faucet and sink RATES -- are the other half and are not here,
 * because nothing in the game writes a ledger yet: a haul, a repair bill and a
 * destroyed axe all move a number without recording that they did. Adding that
 * is a table and a write on eleven call sites, and it is the honest next step.
 * Two consecutive runs of this command are a crude stand-in in the meantime,
 * and the command says so rather than implying it measures something it does
 * not.
 *
 * §11's balance question is "does every system have a sink", and the numbers
 * that answer it are here: gold per wallet, materials by tier, equipment in
 * circulation and how worn it is.
 */
class EconomyTelemetry extends Command
{
    protected $signature = 'game:telemetry {--json : Emit machine-readable output instead of tables}';

    protected $description = 'Count what the economy is holding: gold, materials by tier, gear and its wear';

    public function handle(): int
    {
        $report = [
            'characters' => $this->characters(),
            'gold' => $this->gold(),
            'materials' => $this->materials(),
            'equipment' => $this->equipment(),
            'consumables' => $this->consumables(),
            'world' => $this->world(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function characters(): array
    {
        $total = Character::count();

        return [
            'total' => $total,
            'averageLevel' => $total === 0 ? 0.0 : round((float) Character::avg('level'), 2),
            'maxLevel' => (int) (Character::max('level') ?? 0),
        ];
    }

    /**
     * §3.2 -- gold is inflatable by design, which is exactly why it is watched.
     * It bridges to nothing external (§2), so the only thing that can go wrong
     * is the number climbing until the NPC shop stops meaning anything.
     *
     * @return array<string,mixed>
     */
    private function gold(): array
    {
        $total = (int) Character::sum('gold');
        $wallets = max(1, Character::count());

        return [
            'total' => $total,
            'perWallet' => round($total / $wallets, 1),
            'richest' => (int) (Character::max('gold') ?? 0),
        ];
    }

    /**
     * §4 -- by tier, because that is the axis the sinks are balanced on.
     *
     * Tier 3 carries a per-wallet cap (§2) and is the one to watch: a tier that
     * is climbing while its cap is untouched means the cap is not the thing
     * limiting it.
     *
     * @return array<string,mixed>
     */
    private function materials(): array
    {
        $held = CharacterMaterial::where('quantity', '>', 0)
            ->selectRaw('material_key, SUM(quantity) as units, COUNT(*) as holders')
            ->groupBy('material_key')
            ->get();

        $byTier = [];
        $top = [];

        foreach ($held as $row) {
            $def = Catalog::material($row->material_key);
            $tier = (int) ($def['tier'] ?? 1);

            $byTier[$tier]['units'] = ($byTier[$tier]['units'] ?? 0) + (int) $row->units;
            $byTier[$tier]['kinds'] = ($byTier[$tier]['kinds'] ?? 0) + 1;

            $top[$row->material_key] = (int) $row->units;
        }

        ksort($byTier);
        arsort($top);

        return [
            'byTier' => $byTier,
            'top' => array_slice($top, 0, 10, true),
            'rareCap' => Balance::RARE_WALLET_CAP,
        ];
    }

    /**
     * §8.2 -- how much gear exists, and how close it is to being gone.
     *
     * Destruction is the largest continuous sink in the game (§11.1), so the
     * figure that matters is not the count, it is the WEAR: a world where
     * everything sits near full durability is a world where the sink is not
     * collecting, whatever the recipes say.
     *
     * @return array<string,mixed>
     */
    private function equipment(): array
    {
        $rows = CharacterItem::selectRaw('item_key, COUNT(*) as pieces, SUM(durability) as durability, SUM(equipped) as worn')
            ->groupBy('item_key')
            ->get();

        $byRarity = [];
        $pieces = 0;
        $durability = 0;
        $capacity = 0;

        foreach ($rows as $row) {
            $def = Catalog::item($row->item_key);
            if ($def === null) {
                continue;
            }

            $rarity = (string) ($def['rarity'] ?? 'common');
            $max = (int) ($def['maxDurability'] ?? 1);

            $byRarity[$rarity]['pieces'] = ($byRarity[$rarity]['pieces'] ?? 0) + (int) $row->pieces;
            $byRarity[$rarity]['worn'] = ($byRarity[$rarity]['worn'] ?? 0) + (int) $row->worn;

            $pieces += (int) $row->pieces;
            $durability += (int) $row->durability;
            $capacity += $max * (int) $row->pieces;
        }

        return [
            'pieces' => $pieces,
            'byRarity' => $byRarity,
            // The one number §11.1 is actually about. Near 1.0 means nothing is
            // wearing out and the repair bill is not being paid by anybody.
            'averageCondition' => $capacity === 0 ? 0.0 : round($durability / $capacity, 3),
        ];
    }

    /** §8.5 -- a cellar is a sink that has not been spent yet. */
    private function consumables(): array
    {
        return [
            'units' => (int) CharacterConsumable::sum('quantity'),
            'stacks' => CharacterConsumable::where('quantity', '>', 0)->count(),
        ];
    }

    /** What the world itself is doing right now. */
    private function world(): array
    {
        return [
            'jobs' => GameJob::where('status', 'active')
                ->selectRaw('kind, COUNT(*) as total')
                ->groupBy('kind')
                ->pluck('total', 'kind')
                ->map(fn ($n) => (int) $n)
                ->all(),
            'carriers' => Carrier::where('expires_at', '>', now()->getTimestampMs())->count(),
            'travelling' => Character::whereNotNull('travel_ends_at')->count(),
        ];
    }

    /** @param  array<string,mixed>  $report */
    private function render(array $report): void
    {
        $this->info('Characters');
        $this->line("  {$report['characters']['total']} wallets · average level {$report['characters']['averageLevel']} · highest {$report['characters']['maxLevel']}");

        $this->newLine();
        $this->info('Gold (§3.2 — inflatable by design, watched for that reason)');
        $this->line("  {$report['gold']['total']} total · {$report['gold']['perWallet']} per wallet · richest {$report['gold']['richest']}");

        $this->newLine();
        $this->info('Materials by tier (§4)');
        $rows = [];
        foreach ($report['materials']['byTier'] as $tier => $figures) {
            $rows[] = ["Tier {$tier}", $figures['units'], $figures['kinds']];
        }
        $this->table(['Tier', 'Units', 'Kinds held'], $rows);

        if ($report['materials']['top'] !== []) {
            $this->line('  Deepest stacks: '.implode(', ', array_map(
                static fn (string $key, int $units) => "{$key} {$units}",
                array_keys($report['materials']['top']),
                array_values($report['materials']['top']),
            )));
        }

        $this->newLine();
        $this->info('Equipment (§8.2 — condition is the sink, not the count)');
        $rows = [];
        foreach ($report['equipment']['byRarity'] as $rarity => $figures) {
            $rows[] = [$rarity, $figures['pieces'], $figures['worn'] ?? 0];
        }
        $this->table(['Rarity', 'Pieces', 'Worn'], $rows);
        $this->line("  {$report['equipment']['pieces']} pieces · average condition {$report['equipment']['averageCondition']} of 1.0");

        if ($report['equipment']['averageCondition'] > 0.9 && $report['equipment']['pieces'] > 0) {
            $this->warn('  Nothing is wearing out. §11.1 says destruction is the largest sink — it is not collecting.');
        }

        $this->newLine();
        $this->info('World');
        $this->line("  {$report['world']['carriers']} corpses standing · {$report['world']['travelling']} on the road");
        foreach ($report['world']['jobs'] as $kind => $total) {
            $this->line("  {$total} × {$kind}");
        }

        $this->newLine();
        $this->comment('Stocks only. Faucet and sink RATES need a ledger and nothing writes one yet —');
        $this->comment('two runs of this a day apart is the crude stand-in until that lands.');
    }
}
