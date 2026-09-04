/**
 * §7.4 -- the real tree, collapsed, for the /skilltree proposal page.
 *
 * Baked from Jobs::NODES rather than invented, because the whole argument of
 * the proposal is arithmetic about the tree that exists: Explorer's fifteen
 * nodes really are two effects repeated, and no mock-up made of plausible
 * names could show that.
 *
 * A `battleSkill` is kept as its own one-rank entry (§9.5.9 -- owning it IS
 * the effect), which is why Swordhand comes out with three of them beside its
 * levelled ones.
 */
export interface Rank { level: number; value: number }
export interface Skill { kind: string; name: string; what: string; fmt: string; ranks: Rank[] }

export const SAMPLE: Record<string, Skill[]> = {
  "explorer": [
    {
      "kind": "bagSlots",
      "name": "Straps",
      "what": "straps on the bag",
      "ranks": [
        {
          "level": 2,
          "value": 2
        },
        {
          "level": 4,
          "value": 2
        },
        {
          "level": 6,
          "value": 2
        },
        {
          "level": 8,
          "value": 2
        },
        {
          "level": 10,
          "value": 2
        },
        {
          "level": 14,
          "value": 2
        },
        {
          "level": 16,
          "value": 2
        },
        {
          "level": 18,
          "value": 2
        },
        {
          "level": 20,
          "value": 2
        },
        {
          "level": 22,
          "value": 2
        },
        {
          "level": 24,
          "value": 2
        },
        {
          "level": 26,
          "value": 4
        },
        {
          "level": 28,
          "value": 4
        }
      ],
      "fmt": "+%d"
    },
    {
      "kind": "sight",
      "name": "Sight",
      "what": "hexes you can see",
      "ranks": [
        {
          "level": 12,
          "value": 1
        },
        {
          "level": 30,
          "value": 1
        }
      ],
      "fmt": "+%d"
    }
  ],
  "woodcutting": [
    {
      "kind": "stat",
      "name": "Yield",
      "what": "bigger haul on this line",
      "ranks": [
        {
          "level": 1,
          "value": 0.01
        },
        {
          "level": 1,
          "value": 0.01
        },
        {
          "level": 1,
          "value": 0.01
        },
        {
          "level": 5,
          "value": 0.01
        },
        {
          "level": 5,
          "value": 0.01
        },
        {
          "level": 5,
          "value": 0.01
        },
        {
          "level": 12,
          "value": 0.015
        },
        {
          "level": 12,
          "value": 0.015
        },
        {
          "level": 20,
          "value": 0.015
        },
        {
          "level": 20,
          "value": 0.015
        }
      ],
      "fmt": "+%s%%"
    },
    {
      "kind": "bite",
      "name": "Bite",
      "what": "attack against the hex",
      "ranks": [
        {
          "level": 1,
          "value": 1
        },
        {
          "level": 5,
          "value": 1
        },
        {
          "level": 12,
          "value": 1
        },
        {
          "level": 20,
          "value": 2
        }
      ],
      "fmt": "+%d"
    },
    {
      "kind": "toolWear",
      "name": "Tool Care",
      "what": "mines that leave the tool untouched",
      "ranks": [
        {
          "level": 1,
          "value": 0.01
        },
        {
          "level": 5,
          "value": 0.015
        },
        {
          "level": 5,
          "value": 0.015
        },
        {
          "level": 12,
          "value": 0.02
        },
        {
          "level": 12,
          "value": 0.02
        },
        {
          "level": 20,
          "value": 0.03
        },
        {
          "level": 20,
          "value": 0.03
        },
        {
          "level": 20,
          "value": 0.03
        },
        {
          "level": 28,
          "value": 0.04
        }
      ],
      "fmt": "+%s%%"
    },
    {
      "kind": "seamGrade",
      "name": "Seam Eye",
      "what": "mines that come up a grade better",
      "ranks": [
        {
          "level": 1,
          "value": 0.01
        },
        {
          "level": 5,
          "value": 0.015
        },
        {
          "level": 5,
          "value": 0.015
        },
        {
          "level": 12,
          "value": 0.015
        },
        {
          "level": 12,
          "value": 0.015
        },
        {
          "level": 12,
          "value": 0.015
        },
        {
          "level": 28,
          "value": 0.025
        }
      ],
      "fmt": "+%s%%"
    }
  ],
  "swordhand": [
    {
      "kind": "pair",
      "name": "Guard and Edge",
      "what": "solid points of attack or defense",
      "ranks": [
        {
          "level": 1,
          "value": 1
        },
        {
          "level": 1,
          "value": 3
        },
        {
          "level": 1,
          "value": 1
        },
        {
          "level": 1,
          "value": 1
        },
        {
          "level": 5,
          "value": 1
        },
        {
          "level": 5,
          "value": 1
        },
        {
          "level": 5,
          "value": 1
        },
        {
          "level": 5,
          "value": 1
        },
        {
          "level": 12,
          "value": 2
        },
        {
          "level": 12,
          "value": 2
        },
        {
          "level": 12,
          "value": 2
        },
        {
          "level": 20,
          "value": 2
        },
        {
          "level": 20,
          "value": 2
        }
      ],
      "fmt": "+%d"
    },
    {
      "kind": "battleWear",
      "name": "Kit Care",
      "what": "of a fight spared from the worn kit",
      "ranks": [
        {
          "level": 1,
          "value": 0.025
        },
        {
          "level": 20,
          "value": 0.02
        },
        {
          "level": 28,
          "value": 0.025
        }
      ],
      "fmt": "+%s%%"
    },
    {
      "kind": "battleSkill",
      "name": "Onslaught",
      "what": "a skill your weapon can use",
      "ranks": [
        {
          "level": 1,
          "value": 0
        }
      ],
      "fmt": ""
    },
    {
      "kind": "goldFind",
      "name": "Purse",
      "what": "more of what a pack pays",
      "ranks": [
        {
          "level": 5,
          "value": 0.03
        },
        {
          "level": 20,
          "value": 0.04
        },
        {
          "level": 28,
          "value": 0.05
        }
      ],
      "fmt": "+%s%%"
    },
    {
      "kind": "skillCooldown",
      "name": "Skill Tempo",
      "what": "rounds off every cooldown",
      "ranks": [
        {
          "level": 5,
          "value": 1
        },
        {
          "level": 12,
          "value": 1
        }
      ],
      "fmt": "+%d"
    },
    {
      "kind": "weaponWear",
      "name": "Blade Care",
      "what": "of a fight spared from the blade",
      "ranks": [
        {
          "level": 5,
          "value": 0.015
        },
        {
          "level": 12,
          "value": 0.015
        }
      ],
      "fmt": "+%s%%"
    },
    {
      "kind": "battleSkill",
      "name": "Sunder",
      "what": "a skill your weapon can use",
      "ranks": [
        {
          "level": 5,
          "value": 0
        }
      ],
      "fmt": ""
    },
    {
      "kind": "battleSkill",
      "name": "Riposte",
      "what": "a skill your weapon can use",
      "ranks": [
        {
          "level": 12,
          "value": 0
        }
      ],
      "fmt": ""
    },
    {
      "kind": "skillPower",
      "name": "Skill Power",
      "what": "more of the extra on your three skills",
      "ranks": [
        {
          "level": 12,
          "value": 0.08
        },
        {
          "level": 20,
          "value": 0.06
        }
      ],
      "fmt": "+%s%%"
    },
    {
      "kind": "lootOption",
      "name": "Scavenger",
      "what": "chance of an extra rolled line on loot",
      "ranks": [
        {
          "level": 12,
          "value": 0.04
        }
      ],
      "fmt": "+%s%%"
    },
    {
      "kind": "skillStun",
      "name": "Heavy Hand",
      "what": "round longer on a stun",
      "ranks": [
        {
          "level": 20,
          "value": 1
        }
      ],
      "fmt": "+%d"
    }
  ]
}
