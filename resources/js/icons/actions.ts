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
  /* §7.4 -- a trade branching into what it teaches. One root, two limbs: the
     shape every tree in the panel actually has. */
  skills: 'M12 20v-6 M12 14 7 9.5V4 M12 14l5-4.5V4 M12 20.5a1.6 1.6 0 1 0 0 3.2 1.6 1.6 0 0 0 0-3.2',

  /* §7.4.3 -- one glyph per effect kind. What a node *does* is the only thing
     worth encoding on 180 nodes; drawing 180 pictures would say less. */
  effectStat: 'M4 18h16 M7 14.5l4-5 3 3 4.5-6.5',
  effectUnlock: 'M8 10.5V7a4 4 0 0 1 7.4-2 M5 10.5h14V20H5Z',
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
     because what this catalogues is hexes and what comes off them. */
  almanac: 'M7 4 11 6.3v4.6L7 13.2 3 10.9V6.3Z M14.5 7h6.5 M14.5 11h6.5 M3 17.5h18 M3 21h12',
}
