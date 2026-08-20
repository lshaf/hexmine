# HexMine

Laravel + Vue implementation of the hex mining idle game. Game design lives in
[CLAUDE.md](CLAUDE.md); this file covers how the code is put together.

One application: Laravel serves the page **and** the API, Vite builds the Vue
client into it. Same origin, so the API is cookie-authenticated and there is no
proxy and no CORS configuration anywhere.

## Running it

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate

composer dev          # php artisan serve + vite, together  -> http://localhost:8000
```

Node comes from `fnm` on this machine — have the default v22 active before npm.

```bash
npm run build         # typecheck + production assets into public/build
npm run typecheck     # vue-tsc, no emit
php artisan test      # 23 tests
npm run parity        # the TS generator against the same frozen fixture
composer parity       # re-verify world generation against the frozen fixture
```

Database is SQLite (`database/database.sqlite`) because it needs no setup.
Nothing depends on the driver — switching to MySQL is a `.env` change.

## Structure

```
app/Game/           the domain. No framework, no I/O, no HTTP.
  Balance.php         EVERY tunable number. A balance pass touches only this.
  Catalog.php         20 materials, 5 skills, recipes, items
  Formulas.php        trip time, stat stacking, XP, AP regen
  WorldGen.php        deterministic map generation
  Hash.php            32-bit hashing, pinned by fixture to the JS semantics
  GameService.php     all authoritative game logic
app/Http/           controllers, and the middleware that resolves the character
resources/js/       the Vue client
  game/               catalog + formulas, mirrored for *prediction only*
  api/                the typed client for the API below
  map/                hex geometry, procedural props, the map component
  icons/              procedural SVG generator (there are no art assets)
  shell/              the HUD: gauges, dock, panels, trips
  stores/game.ts      single Pinia store; caches server state, holds no authority
```

## The interface

The screen is the map, full bleed. Everything else floats over it as cut plates
anchored to corners, so nothing takes area from the thing the game is about.

```
                       ⬡ toast
⬡AP ⬡STORE                          [⬡Atlas ⬡Bag ⬡Hero]
   ⬡LVL                                        tutorial
 ▸ village work
                        ( map )
                     ┌ selected hex ┐
                     │  + travel    │
                     │ what I can   │
                     │  do here     │
```

**The play map does not pan.** It is locked to the character: same window size,
always centred on where you are. That bounds every per-viewport cost — tiles
generated, mutations fetched, and whatever a realtime feed later has to
subscribe to — to a constant, rather than to wherever somebody dragged. A
1440×900 window is 1365 tiles, every time. There is no recentre control because
there is no camera to put back.

Nothing has a border-radius. Gauges and action cells are flat-top hexagons on
the same lattice as the map; panels are chamfered plates. The signature is the
gauges: progress travels around the six edges of a hexagon rather than along a
bar, which is cheap to draw (side length is exactly 50, so the perimeter is 300
units and the dash maths needs no measurement) and reads as an instrument.

The cells tile exactly, as the map's own hexes do, so each gauge pads its viewBox
rather than shrinking its hexagon: a stroke centred on a shared honeycomb edge
puts half of itself in the neighbouring cell, which then paints over it. The gap
that padding leaves is the wall between cells.

Type is a slab serif (Bitter) for display and gauge numerals, and Archivo set in
wide-tracked small caps for every label — the convention of survey-map
annotation, which is what a HUD over a map is.

The dock reads the hex **under the character's feet**, never the one that
happens to be selected, and changes shape as well as content: Mine in the field,
Claim and Drop while a trip runs, Trade, Craft and Process at a settlement. Those
last three are **absent** in open country rather than greyed out — the point is
that those people are not out there. A trip's countdown sits in the dock's left
column, under the place name, because a trip locks you to that hex and the wait
is the main thing you want to see.

Travel lives on the **tile card** instead, next to that hex's haul and trip time.
It is the one action that is about somewhere else, and putting it beside the
numbers is what turns them into a decision. The card answers "what am I pointing
at, and is it worth walking to"; the dock answers "what can I do here".

Toasts live at the top, never over the dock — news should not stand in front of
the controls it is reporting on. Each carries a hexagon marker that drains its
own perimeter over the toast's lifetime, so a message that vanishes mid-read was
visibly on its way out; colour says how the news landed. The drain is a CSS
animation rather than the store clock, which only ticks once a second.

They also outrank the panels. Most failures happen *inside* a panel — the trader
refusing a sale, a craft short a material — so a toast that opens behind the
scrim reports nothing. The whole ladder is three custom properties in `app.css`
(`--z-hud`, `--z-panel`, `--z-toast`); nothing declares a bare z-index.

Phones get the same HUD, not a different layout, and the dock keeps its shape
rather than stacking — three cells is the most it ever shows at once, since a
settlement has no seam to mine and a trip can only run out in the field, so
where-you-are and what-you-can-do still fit on one line. The tutorial prompt
moves into the bottom stack and toasts tuck under the screen buttons on the
right, since the gauge cluster owns the top-left.

Travel is a secondary cell — smaller than the dock's own — because the tile card
supports a decision rather than being where the acting happens.

The quest prompt folds, and the choice sticks (`localStorage`). Folded it keeps
the step number and title on one line, so a player who hides it never loses the
thread; a "hide" that unhides itself on the next step is not hiding.

## The atlas

Free exploration moved to its own view, and it costs nothing to run: terrain is
a pure function of `(col, row, seed)`, so the atlas charts any part of the world
without a request, a database, or a tile store. Drag it as far as you like —
nothing is fetched.

Two things make it cheap:

**Settlements are enumerated by lattice, not by search.** Sites sit one per cell
per tier, so `settlementMarksIn()` walks cells instead of the 25 million tiles
they are scattered across. A screen covering 600 columns costs a few thousand
hashes. `npm run parity` checks it returns exactly what `settlementAt()` finds by
brute force.

**Terrain is a cached raster.** About 35,000 samples whatever the zoom, drawn
into an offscreen canvas with a margin, so a pan is one `drawImage` and only
leaving the margin rebuilds.

The wheel zooms about the cursor — the hex you are pointing at stays under the
pointer, so zooming reads as moving through the sheet rather than jumping to a
different one. The buttons zoom about the centre, because pressing a button
implies no position.

Past the point where one biome cell covers ten screen pixels the chart
generalises to the coarse layer instead of point-sampling the fine one — the
same data at a coarser level of detail, which is what map generalisation is.
Point-sampling a mixture below one cell per pixel renders as static. The readout
says which you are looking at. Villages drop out as you zoom past them and only
capitals and cities are ever labelled, with collision testing, because capitals
cluster by design (§5.2) and a blob of overlapping names is worse than none.

## The API

Server-authoritative (§16). The client sends intent — "mine this tile" — and is
told what happened. It never sends a duration, an elapsed time, a yield or a
drop.

```
GET    /api/state
GET    /api/world                                 generation parameters, once
GET    /api/map?col&row&w&h                       viewport mutations only
GET    /api/tiles/{col}/{row}/preview
POST   /api/mining                                {col,row}
POST   /api/jobs/{job}/collect
DELETE /api/jobs/{job}
GET    /api/settlements/{settlement}
POST   /api/settlements/{settlement}/processing   {recipe,batches}
POST   /api/travel                                {col,row}
POST   /api/shop/purchases                        {item}
POST   /api/shop/sales                            {material,quantity}
POST   /api/crafting                              {item}
POST   /api/equipment/{item}/equip|unequip|repair
DELETE /api/equipment/{item}
```

Every mutating call answers with the **complete** fresh player state:

```json
{ "data": { }, "state": { }, "message": "Collected 8 Wood." }
```

Partial patches were rejected deliberately: an idle game with hour-long timers
cannot afford client/server drift, and full-state responses make a whole class of
desync bugs impossible.

A rule saying no — "both slots are taken", "not enough gold" — is a
`GameException`, rendered as 422 with player-facing copy the client shows
verbatim. It is an expected outcome, not a fault.

## Things that are load-bearing

Reversing any of these reintroduces a bug the design already paid for.

**The map is never stored.** 5000×5000 is 25 million tiles. `WorldGen` derives
every tile from `(col, row, seed)`, so the only rows that exist are *mutations* —
`tile_states` holds regrowth timers for tiles someone actually worked, and slot
occupancy is a `COUNT` over live jobs. That count is also why contention is real:
two players racing for the second slot on a hex genuinely race.

**Generation output is frozen.** Because tiles are derived rather than stored,
changing the generator does not corrupt a table — it silently rewrites the world
under characters already living in it. Depleted tiles regrow into a different
biome and settlements move, with nothing in the database looking wrong.
`WorldParityTest` pins the output to a golden fixture. Change it deliberately:
`php artisan game:worldgen-fixture`, then read the diff.

`Hash::hash2` is a bit-exact port of JavaScript's `Math.imul`/`>>>` semantics,
which is why it is full of explicit 32-bit masking that looks like it could be
simplified. It cannot — the fixtures in the test pin it.

**What you can do depends on where you stand.** Trading, crafting and processing
all require standing on a settlement (§6) — there is no trader waiting in the
middle of a forest, and shop stock is gated by settlement tier on top of that.
Mining is the same rule pointed outward: you work the hex under your feet, so
reaching a seam means travelling to it first. Travel range bounds the walk, not
the pick.

The server gates every one of these in `GameService::requireSettlement()` and in
`previewTile()`, and publishes `standingAt` so the UI can grey the right buttons
and say why — the card and the rule read the same value. A preview still returns
haul and trip time for a hex you are only scouting; being somewhere else changes
`canMine`, never the numbers, so the tile card doubles as a survey report.

**One trip at a time, and it pins you in place.** A character may have exactly
one mining job. It runs on the hex they are standing on, and until it is claimed
or dropped they cannot travel — `miningTrip()` gates `previewTile()` and
`travelTo()` alike. Dropping forfeits the haul (§11.1), which is the only way
out. That single rule is what lets the dock be "what I can do here" rather than a
queue manager: there is one mining thing to do and it is on this tile.

**Processing is the exception, because an NPC does it.** The line runs whether
the player stays or not; staying only helps. So a processing job does not pin
anyone, but a character may only help with one at a time — helping is standing
there, and a person stands in one place.

**The presence bonus is symmetric.** Arriving shortens what is left of the work
by `PRESENCE_SPEED_BONUS`; leaving lengthens it again by the same factor, so the
discount only ever covers the time actually spent on site. Without the second
half, "stay at the village" would mean walk in, walk out, keep the bonus. There
is no presence toggle for the same reason — presence is where you are, which is
the whole of §6.2.

**The trip-time clamp is mandatory (§7.3).**

```
trip_time = clamp(base − skill_reduction − equipment_reduction, 30min, 60min)
```

Without the floor, any future buff or gear tier opens a sub-30-minute exploit.
Bonuses are never applied after the clamp. The tile sheet shows the whole
breakdown so a clamped result reads as a rule rather than a bug.

**Stat stacking diminishes, then caps (§8.1).** Sorted strongest-first, the nth
item of a stat is worth `value × 0.5^(n−1)`, and the total is capped by tier.
Three of the same bundle does not scale linearly, and NFT gear sits on the same
power ceiling as crafted — it differs in durability and acquisition speed.

**The map renderer (§13.2).** The 3/4 tilt is baked into the hex geometry —
squashed 58×34 hexes with extruded side faces. CSS `perspective`/`rotateX` was
tried and distorts the hex shape. Everything renders into *one* SVG as translated
groups, painter-sorted by screen Y so tall props occlude correctly. No alpha
anywhere: "faded" states are precomputed solid colours, because transparency
ghosts hexes through their neighbours.

**Tile selection is coordinate hit-testing, not click listeners.** The map sets
pointer capture for panning, which retargets pointer events to the `<svg>` and
means a derived `click` never reaches a tile group. `pickTile()` resolves the hex
from coordinates instead — and drops several hundred DOM listeners on the way.

**The play map is a fixed window on the character.** It does not pan, so the
number of tiles generated, the size of a mutation fetch, and the area a future
realtime feed would have to cover are all bounded by the screen. `view` is a
computed of the character's position and the measured viewport — not state
anyone can set — and a watcher on the position rebuilds tiles, so the drawn
window can never disagree with where the server says you are. Panning lives in
the atlas, which asks for nothing.

**The client generates the terrain; the server sends the differences.** Both
sides derive tiles from `(col, row, seed)`, so a viewport request carries only
what cannot be derived — depletion timers and miner occupancy — as compact
tuples. That is **29 bytes for a typical window** against roughly 200KB when the
server shipped generated tiles, and panning now redraws locally with no network
at all.

The generation *constants* are not duplicated: `GET /api/world` publishes the
seed, lattice, ring bounds and name pools, and `configureWorld()` installs them.
Only the algorithm is mirrored, and `tests/Fixtures/worldgen.txt` pins it.
`GameLoopTest::test_the_map_endpoint_sends_mutations_only` guards the payload so
tiles cannot creep back onto the wire.

**Time is published, not configured twice.** `GAME_TIME_SCALE` compresses timers
for development (60 = one real hour becomes one minute; 1 = production). The
client does not have its own copy — the server reports `timeScale` in every
state response. The §7.3 breakdown still displays honest game-time, since it
exists to explain the rule; only countdowns use the compressed clock.

## Identity — read before deploying

There is no wallet connect flow yet. `GAME_AUTO_PROVISION=true` mints a character
per browser session so the game is playable end to end.

**That flag must be `false` in production.** With it on, anyone can create
unlimited characters by clearing cookies — precisely the sybil vector §2 exists to
close. The seams are `App\Http\Middleware\ResolveCharacter` (swap the session
lookup for a verified wallet) and `Player::isEligible()` (already enforces the §2
seven-day rule once `eligible_since` is populated from chain history). Nothing
downstream changes.

## Status

Playable end to end on desktop and mobile: the §12 tutorial loop — mine, collect,
walk to a settlement, sell, buy, equip, mine, process, craft, mine again. That
slice covers mining, processing, gold, the NPC shop, crafting, skills, equipment
durability, storage decay, per-wallet caps, location gating and the tutorial
tracker.

Not built: dungeons (§9), guilds (§10), championship (§11.3), the marketplace,
hunting as a playable action, and the PvP-ring rare-material economy beyond
generation and wallet caps.

Open tuning questions worth a decision:

- Travel range limits each *move*, but hopping tile-to-tile is unlimited and
  free, so it is a per-move limit rather than a real operating radius. If it is
  meant to bind, hops need an AP or time cost.
- §8.2's repair-vs-craft ratio is a placeholder at 0.6× craft cost.
