/**
 * Onboarding, §12.
 *
 * The tutorial is the ACTUAL game loop -- there is nothing scripted here to
 * unlearn later. Each step watches for a real gameplay event and advances when
 * the player does the real thing, so a player who already knows what they are
 * doing simply completes it by playing.
 */

export type TutorialEvent =
  | 'mine_start'
  | 'collect'
  | 'travel'
  | 'sell'
  | 'buy'
  | 'equip'
  | 'process_start'
  | 'process_collect'
  | 'craft'

export interface TutorialStep {
  title: string
  body: string
  event: TutorialEvent
  /** Extra condition on the event payload, when the event alone is too loose. */
  match?: (payload: Record<string, unknown>) => boolean
  /** Which tab to nudge the player toward. */
  tab?: 'map' | 'bag' | 'craft' | 'shop' | 'hero'
}

export const TUTORIAL: TutorialStep[] = [
  {
    title: 'Work a forest hex',
    body: 'You are standing in forest. Tap the hex under your prospector and start mining — you work the ground you stand on, and wood only comes from forest.',
    event: 'mine_start',
    tab: 'map',
  },
  {
    title: 'Collect the branches',
    body: 'Mining runs on a timer whether the app is open or not. When it finishes, collect what you brought back — bare hands only get you branches.',
    event: 'collect',
    tab: 'map',
  },
  {
    title: 'Walk to the settlement',
    body: 'Nobody out here will buy a sack of branches. Traders, workshops and processing lines all live at settlements — tap the nearest one on the map and travel there.',
    event: 'travel',
    tab: 'map',
  },
  {
    title: 'Sell it to the trader',
    body: 'A copper a branch, and the trader is doing you a favour. Gold is for basic gear and nothing more — it never buys anything tradeable.',
    event: 'sell',
    tab: 'shop',
  },
  {
    title: 'Buy a Stone Axe',
    body: 'The trader stocks a tool for every line. Take the axe — it is the one that cuts wood, and it does nothing anywhere else.',
    event: 'buy',
    tab: 'shop',
  },
  {
    title: 'Equip the axe',
    body: 'Gear does nothing sitting in your bag. Equip it from your hero sheet.',
    event: 'equip',
    tab: 'hero',
  },
  {
    title: 'Cut wood with the axe',
    body: 'Travel back out to a forest hex and cut again. Branches no longer — with an axe the same hex gives up real wood. That is what the 12 gold bought you.',
    event: 'collect',
    tab: 'map',
  },
  {
    title: 'Process wood into planks',
    body: 'Queue the wood line here. Settlements are shared — you do not own them, and those five slots are for everyone.',
    event: 'process_start',
    tab: 'map',
  },
  {
    title: 'Collect your planks',
    body: 'Staying at the settlement while it works speeds the job and trains the skill. That is the presence bonus.',
    event: 'process_collect',
    tab: 'map',
  },
  {
    title: 'Craft a Hewn Axe',
    body: 'Made from your own planks, no gold involved — and the crafted ladder climbs far past anything the trader stocks. Note it is an axe: every line has its own tool, and each only works its own ground.',
    event: 'craft',
    tab: 'craft',
  },
  {
    title: 'Put the axe to work',
    body: 'Mine one more time. Then decide what to sell and what to process — that decision never stops mattering.',
    event: 'collect',
    tab: 'map',
  },
]

/** Shown once the script is finished, §12 -- signpost depth, do not explain it. */
export const TUTORIAL_OUTRO = {
  title: 'The forest edge',
  body: 'Deeper in, the forest holds rarer wood — but those rings are contested, and every trip there is a decision.',
}

export const tutorialStep = (index: number): TutorialStep | null =>
  index >= 0 && index < TUTORIAL.length ? TUTORIAL[index]! : null
