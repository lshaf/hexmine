<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Balance;
use App\Game\Dungeons;
use App\Game\DungeonService;
use App\Game\GameService;
use App\Game\HexGeometry;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §9.6 -- the dungeon endpoints.
 *
 * Every one of these answers with the same envelope the rest of the game uses,
 * plus a `dungeon` block describing where you are standing inside it. **That
 * block is bounded by sight** (§5.6), and the bound is the second half of what
 * keeps the fog a fog: the session's secret stops a client DERIVING the layout,
 * and this stops it simply being told.
 */
class DungeonController extends GameController
{
    public function __construct(GameService $game, private readonly DungeonService $dungeons)
    {
        parent::__construct($game);
    }

    /** What is on offer at the mouth under your feet, and what you are in. */
    public function show(Request $request): JsonResponse
    {
        $character = $this->character($request);

        return response()->json([
            'data' => $this->view($character),
            'state' => $this->game->playerState($character->fresh()),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dungeon' => ['required', 'string', 'max:32'],
            'category' => ['required', 'string', 'max:16'],
            'difficulty' => ['required', 'string', 'max:16'],
        ]);

        $character = $this->character($request);
        $session = $this->dungeons->open($character, $data['dungeon'], $data['category'], $data['difficulty']);

        return $this->respond($character, $this->view($character), "Session open. The code is {$session->code}.");
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $character = $this->character($request);
        $this->dungeons->join($character, $data['code']);

        return $this->respond($character, $this->view($character), 'You are on the roster.');
    }

    public function enter(Request $request): JsonResponse
    {
        $character = $this->character($request);
        $this->dungeons->enter($character);

        return $this->respond($character, $this->view($character), 'Down you go.');
    }

    public function walk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'col' => ['required', 'integer'],
            'row' => ['required', 'integer'],
        ]);

        $character = $this->character($request);
        $this->dungeons->walk($character, $data['col'], $data['row']);

        return $this->respond($character, $this->view($character));
    }

    /** §5.6 -- stop where you are, which is a hex rather than a fraction of one. */
    public function stop(Request $request): JsonResponse
    {
        $character = $this->character($request);
        $this->dungeons->stopWalk($character);

        return $this->respond($character, $this->view($character));
    }

    public function fight(Request $request): JsonResponse
    {
        $character = $this->character($request);
        $result = $this->dungeons->fight($character);

        return $this->respond(
            $character,
            ['fight' => $result, 'dungeon' => $this->view($character)],
            $result['won'] ? 'It goes down.' : 'You wake at the landing.',
        );
    }

    public function descend(Request $request): JsonResponse
    {
        $character = $this->character($request);
        $member = $this->dungeons->descend($character);

        return $this->respond($character, $this->view($character), "Floor {$member->floor}.");
    }

    public function leave(Request $request): JsonResponse
    {
        $character = $this->character($request);
        $this->dungeons->leave($character);

        return $this->respond($character, $this->view($character), 'You walk out.');
    }

    /**
     * Where you are, and the disc you can see from it.
     *
     * §5.6's radius exactly, because a dungeon is not an excuse for a wider eye:
     * the whole of §9.6.2's design is that the creatures are the fog, and a
     * payload carrying the floor would hand over what the secret is there to
     * withhold.
     */
    private function view(Character $character): ?array
    {
        $now = $this->game->now();
        $member = $this->dungeons->memberFor($character, $now);

        if ($member === null) {
            return null;
        }

        $session = $member->session;

        $out = [
            'code' => $session->code,
            'dungeon' => $session->dungeon,
            'category' => $session->category,
            'difficulty' => $session->difficulty,
            'expiresAt' => $session->expires_at_ms,
            'locked' => $session->locked_at_ms !== null,
            'owner' => (int) $session->owner_character_id === (int) $character->id,
            'roster' => $session->members()->count(),
            'inside' => $member->isInside(),
            'floor' => $member->floor,
            'floors' => Balance::DUNGEON_FLOORS,
            // §9.6.2 -- how big a floor is, so the client can draw the ground
            // AROUND the disc as fog rather than as nothing. The hexes are
            // still only described inside sight; what this buys is knowing
            // there is floor out there at all.
            'size' => Balance::DUNGEON_FLOOR_SIZE,
            'col' => $member->col,
            'row' => $member->row,
            'busyUntil' => $member->busy_until_ms,
        ];

        if (! $member->isInside()) {
            return $out;
        }

        // §5.6 -- the disc follows the WALKER, so everything below is costed
        // from where they are rather than from where they set off.
        [$atCol, $atRow] = $this->dungeons->walkingAt($member, $now);
        $out['col'] = $atCol;
        $out['row'] = $atRow;

        if ($member->walk_ends_ms !== null) {
            $path = HexGeometry::line($member->col, $member->row, $member->walk_to_col, $member->walk_to_row);

            // Shaped as the overworld's own TravelState, because the marker
            // that animates it is the overworld's own marker.
            $out['walk'] = [
                'toCol' => (int) $member->walk_to_col,
                'toRow' => (int) $member->walk_to_row,
                'startedAt' => (int) $member->walk_started_ms,
                'endsAt' => (int) $member->walk_ends_ms,
                'perHexMs' => Balance::scaled(Balance::TRAVEL_MS_PER_HEX),
                'hexes' => count($path) - 1,
                'path' => array_map(static fn (array $h): array => [$h['col'], $h['row']], $path),
                'destinationName' => null,
                'stopsAt' => null,
            ];
        }

        $kills = $session->killsOn($member->floor);
        $seed = $session->floorSeed($member->floor);
        [$sc, $sr] = Dungeons::stair($seed);

        $out['kills'] = $kills;
        $out['killsNeeded'] = Balance::DUNGEON_FLOOR_KILLS;
        $out['floorOpen'] = Dungeons::floorOpen($kills);
        $out['guardianRoused'] = Dungeons::guardianRoused($kills);

        // §9.6.2 -- the stair is SHOWN from the landing. Where it is, is random;
        // that you know where it is, is not.
        $out['stair'] = ['col' => $sc, 'row' => $sr];

        $radius = $this->game->sightRadius($character);
        $tiles = [];

        for ($col = $member->col - $radius; $col <= $member->col + $radius; $col++) {
            for ($row = $member->row - $radius; $row <= $member->row + $radius; $row++) {
                if (! Dungeons::inBounds($col, $row)) {
                    continue;
                }

                if (HexGeometry::distance($member->col, $member->row, $col, $row) > $radius) {
                    continue;
                }

                $monster = $this->dungeons->standingOn($session, $member->floor, $col, $row);

                $tiles[] = [
                    'col' => $col,
                    'row' => $row,
                    'monster' => $monster === null ? null : [
                        'key' => $monster['key'],
                        'name' => $monster['name'],
                        'tier' => $monster['tier'],
                        'profile' => $monster['profile'],
                        'guardian' => (bool) ($monster['guardian'] ?? false),
                    ],
                ];
            }
        }

        $out['sight'] = $radius;
        $out['tiles'] = $tiles;

        return $out;
    }
}
