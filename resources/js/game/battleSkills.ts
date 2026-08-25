/**
 * §9.5.9 -- the nine battle skills, mirrored from app/Game/BattleSkills.php.
 *
 * Mirrored rather than served, for the same reason `monsters.ts` is: the battle
 * modal has to name a skill the instant a stored round mentions one, and a
 * fetch that has not landed yet would draw a key where a name goes. A parity
 * test compares this table to the PHP one, so the two cannot drift.
 *
 * What is NOT here is anything the fight actually reads -- no multipliers, no
 * stun lengths, no ticks. Those are the server's alone (§16), and the client
 * has no business holding a second opinion about them. The cooldown is here
 * because the fight preview prints it, and it is the one number a player uses
 * to decide whether a long fight is worth taking.
 */
export interface BattleSkillDef {
  key: string
  family: 'shield' | 'sword' | 'focus'
  name: string
  glyph: string
  cooldown: number
  /**
   * Flavour, and flavour only.
   *
   * What the skill DOES is a sentence with the player's own figures in it, and
   * it is generated server-side per player (BattleSkills::sentence) because
   * `skillStun` and `skillPower` move those figures. A copy here could only
   * ever be one character's numbers frozen into everybody's bundle, so the
   * mirror holds identity -- name, glyph, family, cooldown -- and nothing that
   * a skill point can change.
   */
  description: string
}

export const BATTLE_SKILLS: Record<string, BattleSkillDef> = {
  shield_bash: { key: "shield_bash", family: "shield", name: "Shield Bash", glyph: "bash", cooldown: 11, description: "They teach you early that the rim is a weapon. Most people find out later." },
  anvil_stance: { key: "anvil_stance", family: "shield", name: "Anvil Stance", glyph: "anvil", cooldown: 14, description: "Feet planted, shoulder set. Let it come." },
  wardens_toll: { key: "wardens_toll", family: "shield", name: "Warden's Toll", glyph: "toll", cooldown: 12, description: "Everything the smith gave you, given back at once." },
  onslaught: { key: "onslaught", family: "sword", name: "Onslaught", glyph: "onslaught", cooldown: 10, description: "Never give it a round to think in." },
  sunder: { key: "sunder", family: "sword", name: "Sunder", glyph: "sunder", cooldown: 12, description: "Armor was only ever a delay." },
  riposte: { key: "riposte", family: "sword", name: "Riposte", glyph: "riposte", cooldown: 13, description: "The blade was already going back before you decided to send it." },
  ember_bolt: { key: "ember_bolt", family: "focus", name: "Ember Bolt", glyph: "ember", cooldown: 11, description: "It goes in cold and it does not come out." },
  chain_arc: { key: "chain_arc", family: "focus", name: "Chain Arc", glyph: "arc", cooldown: 10, description: "You have been holding this since the first round." },
  rune_of_binding: { key: "rune_of_binding", family: "focus", name: "Rune of Binding", glyph: "bind", cooldown: 15, description: "One syllable, and it forgets what it was doing." },
}

/*
 * Reading the three off a family is `skillsOfFamily` in `game/battle.ts`, with
 * every other derivation the client makes about a fight. There was a second
 * copy here and nothing ever called it.
 */
