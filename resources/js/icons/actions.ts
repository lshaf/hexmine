/**
 * Action glyphs. Single stroked paths on a 24-grid, drawn from the subject's
 * own tools rather than generic UI symbols: a pick, a boot, scales, a hammer,
 * a millwheel.
 */
export const ACTION_PATHS: Record<string, string> = {
  mine: 'M4 20 15 9 M6 6q6-3 12 0-6-1.6-12 0Z M12 8l4 4',
  /* §5.5 -- the bow, because §8.0 makes it the hunting line's tool and the
     herd is the one thing on the map you bring it for. Stave, string, nocked
     arrow: drawn from the subject's own tool like every other glyph here. */
  hunt: 'M7 3.5a13 13 0 0 1 0 17 M7 3.5 18.5 12 7 20.5 M10.5 12h8.5 M16.5 9.6 19 12l-2.5 2.4',
  /* §4.0 -- bare hands, so the glyph is a hand: an open palm reaching down,
     with what it came up with above it. The one action on the dock drawn from
     the player rather than from a tool, because not having one is the whole
     point of it. */
  gather: 'M5.5 21v-5.5a2 2 0 0 1 4 0V10a1.6 1.6 0 0 1 3.2 0v3.5 M12.7 12.5a1.6 1.6 0 0 1 3.2 0v2 M15.9 13.6a1.6 1.6 0 0 1 3.1 0v3.9a4 4 0 0 1-1.7 3.5 M8.5 6.5 12 3l3.5 3.5',
  travel: 'M8 3v10l-3 8h11l-1-5 5-2-4-4V3Z M8 8h5',
  trade: 'M12 4v16 M5 8l7-2 7 2 M3 15a3 3 0 0 0 6 0L6 8Z M15 15a3 3 0 0 0 6 0l-3-7Z',
  craft: 'M3 21 12 12 M9 6l5-3 7 7-3 5-4-4 2-2-3-3Z',
  process: 'M12 8.5A3.5 3.5 0 1 0 12 15.5A3.5 3.5 0 1 0 12 8.5 M12 2v3 M12 19v3 M2 12h3 M19 12h3 M5 5l2 2 M17 17l2 2 M19 5l-2 2 M7 17l-2 2',
  claim: 'M4 9h15l-1.6 7H5.6Z M4 9 3 6H1 M9 20a1.6 1.6 0 1 0 0-3.2A1.6 1.6 0 0 0 9 20 M15.5 20a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2',
  drop: 'M12 3v10 M8 9.5 12 13.5 16 9.5 M4 19h16',
  bag: 'M4 8h16l-1.2 12H5.2Z M8.5 8V6a3.5 3.5 0 0 1 7 0v2',
  hero: 'M12 4.5 19 8v5c0 4-3 6.4-7 7.5-4-1.1-7-3.5-7-7.5V8Z M9.5 12l1.8 1.8 3.4-3.6',
  atlas: 'M3 6.5 9 4l6 2.5L21 4v13.5L15 20l-6-2.5L3 20Z M9 4v13.5 M15 6.5V20',
  recenter: 'M12 5a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M12 2v3 M12 19v3 M2 12h3 M19 12h3',
  zoomIn: 'M6 12h12 M6 10.4v3.2 M18 10.4v3.2 M12 6v12',
  zoomOut: 'M6 12h12 M6 10.4v3.2 M18 10.4v3.2',
  locate: 'M7 12 9.5 7.7h5L17 12l-2.5 4.3h-5Z M12 2.6v2.8 M12 18.6v2.8 M2.6 12h2.8 M18.6 12h2.8',
  close: 'M6 6l12 12 M18 6 6 18',
  /* §10 -- a pennant on a pole. The only glyph in the strip that is a piece of
     cloth, because a guild is the one thing here you can put your own mark on. */
  guild: 'M7 21V3 M7 4h11l-2.5 4L18 12H7',
  /* §9.5 -- crossed blade and guard. The only verb in the dock that is not
     work, and the only one whose glyph is two things meeting. */
  battle: 'M5 19.5 15.5 9 M12.5 6 18 4.5 16.5 10 M4.5 6 15 16.5 M18 15l1.5 4.5-4.5-1.5',
  /* §7.4 -- a trade branching into what it teaches. One root, two limbs: the
     shape every tree in the panel actually has. */
  skills: 'M12 20v-6 M12 14 7 9.5V4 M12 14l5-4.5V4 M12 20.5a1.6 1.6 0 1 0 0 3.2 1.6 1.6 0 0 0 0-3.2',

  /* §7.4.3 -- one glyph per effect kind. What a node *does* is the only thing
     worth encoding on 180 nodes; drawing 180 pictures would say less. */
  effectStat: 'M4 18h16 M7 14.5l4-5 3 3 4.5-6.5',
  /* §5.1 -- knowing the ground: two leaves off a cut stem. */
  effectSeam: 'M4 20h16 M12 20v-7 M12 13c-3 0-4.5-1.8-4.5-4.4C10.3 8.6 12 10.5 12 13Z M12 13c3 0 4.5-1.8 4.5-4.4C13.7 8.6 12 10.5 12 13Z',
  /* §6.2 -- somebody standing at a bench, which is the whole of presence. */
  effectPresence: 'M12 4.4a2 2 0 1 0 0 4 2 2 0 0 0 0-4 M12 8.4v6 M9 20l3-5.6 3 5.6 M4 12.4h4 M16 12.4h4',
  /* §6.1 -- a second run going beside the first. */
  effectRunSlot: 'M4 6.5h7v4H4Z M13 6.5h7v4h-7Z M4 14.5h16v4H4Z',
  /* §9.5.9 -- the three that sharpen a weapon's own skills. Drawn from the same
     vocabulary as the skill glyphs they upgrade (icons/skills.ts), so a node in
     the tree and the mark that flashes in a fight are recognisably about the
     same thing: a chevron for force, a turning arrow for sooner, a crossed ring
     for a turn taken away. */
  effectSkillPower: 'M6 17l5-5-5-5 M13 17l5-5-5-5 M4 20.5h16',
  effectSkillCooldown: 'M12 6.5A5.5 5.5 0 1 1 7 10 M7 4.5v5.5h5.5 M12 9.5v3l2 1.5',
  effectSkillStun: 'M12 4a8 8 0 1 1-.01 0 M4.5 12h15',
  /* §8.4 -- the consumable bench, which owns none of the other bench effects. */
  effectBrew: 'M10 3.8h4 M11 3.8v5.4L6.6 18a2 2 0 0 0 1.8 2.9h7.2A2 2 0 0 0 17.4 18L13 9.2V3.8 M8.5 14.2h7',
  /* §9.5.8 -- coin off a pack, which needs no strap to carry. */
  effectGold: 'M12 4.4a7.6 7.6 0 1 0 0 15.2 7.6 7.6 0 0 0 0-15.2 M12 7.6v8.8 M9.8 10a1.8 1.8 0 0 1 1.8-1.8h2.6 M9.8 10a1.8 1.8 0 0 0 1.8 1.8h1.2a1.8 1.8 0 0 1 0 3.6H9.8',
  effectCraftOption: 'M5 8h14 M5 13h11 M5 18h6 M17.5 15v5.5 M14.8 17.8h5.4',
  effectCraftDurability: 'M12 3.5 19 6.6v5.2c0 4.1-3 6.7-7 7.7-4-1-7-3.6-7-7.7V6.6Z',
  effectCostReduction: 'M4 7h16 M4 12.5h10 M4 18h5',
  effectBatch: 'M4 9h9v9H4Z M8 9V5h9v9h-4',
  // §7.5 -- sight: a horizon with the eye above it. Distance, not vision.
  effectSight: 'M3 17h18 M12 5.5c3.6 0 6 3.2 6 3.2s-2.4 3.2-6 3.2-6-3.2-6-3.2 2.4-3.2 6-3.2Z M12 7.6v2.2',
  /* §7.6 -- the two bag limits, told apart the way the game tells them apart:
     one pack filled to a line, and a stack of separate pockets. Same object,
     two different questions about it. */
  effectBagUnits: 'M6 9h12l1 11H5Z M9 9V6.5a3 3 0 0 1 6 0V9 M6.4 15h11.2',
  effectBagRows: 'M4 5.5h16v4H4Z M4 11h16v4H4Z M4 16.5h16v4H4Z',
  /* An arch with a doorway in it. Dungeons are the one place tier 4 comes
     from, so the almanac needs a glyph for them even though raiding is not
     built yet. */
  dungeon: 'M4 21V11a8 8 0 0 1 16 0v10 M9 21v-8a3 3 0 0 1 6 0v8',
  /* A specimen plate: one hex sample, its annotation lines, and the rules of
     the entry below it. Built from the map's own shape rather than a book,
     because what this catalogs is hexes and what comes off them. */
  // §12.1 -- a rolled writ with a seal on it. A ledger of work owed reads as a
  // document, not as an exclamation mark: nothing on this HUD shouts.
  quest: 'M6 3.5h9l3.5 3.5v10.5H6Z M15 3.5V7h3.5 M9 11h6 M9 14.5h6 M8.5 20.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
  almanac: 'M7 4 11 6.3v4.6L7 13.2 3 10.9V6.3Z M14.5 7h6.5 M14.5 11h6.5 M3 17.5h18 M3 21h12',

  /* §8 -- the three things you do to a piece of gear, drawn once so they read
     the same on the prospector sheet and in the pack.

     Equip and stow are ONE GESTURE REVERSED, so they are one drawing reversed:
     the slot is the game's own hexagon (§13 -- the shape the map tiles with, the
     shape an icon is framed in, and the shape an empty gear slot is already
     drawn as), and the chevron inside it points in or out. The direction is
     INSIDE the hex rather than an arrow beside it, because at fifteen pixels a
     glyph gets to be one object: two of them was a blob and a tick. */
  equip: 'M8.5 4h7L19.5 12 15.5 20h-7L4.5 12Z M8.5 13.5 12 10l3.5 3.5',
  stow: 'M8.5 4h7L19.5 12 15.5 20h-7L4.5 12Z M8.5 10.5 12 14l3.5-3.5',
  /* §8.2 -- an anvil, and deliberately not the hammer: `craft` is already the
     hammer, and mending a thing is not making one. The horn and the waist are
     what carry it at button size -- it is the one silhouette in the strip that
     could be nothing else. */
  repair: 'M4 7h11.5l4 2-4 2H4Z M8.5 11 7 15 5.5 19h13L17 15l-1.5-4',
  /* §8.2 -- scrap: a bin, and the one glyph in the strip that is a plain
     interface symbol rather than something off the subject's own bench. It
     earns the exception by being the only one of the four that does not give
     the piece back, and nothing drawn from a forge says "gone" as fast. */
  scrap: 'M3.5 7h17 M9.5 7V4.8h5V7 M6 7l1.1 13.2h9.8L18 7 M10 10.8v5.8 M14 10.8v5.8',
  /* §8.4 -- the slate, and it is drawn as one: a board with a corner cut off,
     which is the game's own chamfer (§13) rather than a borrowed ribbon. The
     mark is a chalk tick, so a line already written and one not yet differ by
     the thing that was actually done to it. */
  slate: 'M4.5 4h11l4 4v12h-15Z M15.5 4v4h4',
  slateOn: 'M4.5 4h11l4 4v12h-15Z M15.5 4v4h4 M8 13.6l2.6 2.6L16 11',
}

/**
 * §8.5 -- the action a drunk charge is waiting on, drawn as the implement that
 * takes it.
 *
 * The same argument as ACTION_PATHS above, one level finer: a charge is bought
 * for one line, so four of the five gathering lines need their own tool rather
 * than sharing the generic pick. Mining keeps `mine` and hunting keeps `hunt`,
 * because those two glyphs already ARE the pickaxe and the bow.
 */
export const SCOPE_PATHS: Record<string, string> = {
  /* An axe: haft up to the right, and a bit hung off its head. */
  woodcutting: 'M4 20.5 13.5 11 M11.5 4.2c3.9.6 6.7 3.4 7.3 7.3-3.9-.6-6.7-3.4-7.3-7.3Z M13.5 11l2.6-2.6',
  mining: ACTION_PATHS.mine!,
  hunting: ACTION_PATHS.hunt!,
  /* A sledge: banded head on a straight haft, §8.3's Banded Sledge. */
  quarrying: 'M6.5 6.5h11v5h-11Z M9.5 6.5v5 M14.5 6.5v5 M12 11.5V21',
  /* A sickle: the curve is the whole tell, so the haft is short and the blade
     sweeps most of the grid. */
  harvesting: 'M5.5 20.5 9 17 M9 17a9 9 0 0 1 9.5-9 9 9 0 0 1-6.6 7.2',
  travel: ACTION_PATHS.travel!,
  processing: ACTION_PATHS.process!,
  /* §9.5 -- the only scope here that is not a tool, because a fight is the
     only one that is not work. */
  battle: ACTION_PATHS.battle!,
  /* Everywhere: the hex itself, because that is the whole of the ground. */
  global: 'M8.5 4h7L19.5 12 15.5 20h-7L4.5 12Z',
}
