"""Emit app/Game/Jobs.php from a compact spec.

Tier shape and the prerequisite graph are derived, not typed 180 times: every
tree is 6/8/8/6/2 and the parent wiring is the same everywhere, so the only
hand-written part is what each node is called and what it does.
"""
import io

# effect helpers -------------------------------------------------------------
def stat(key, v): return ('stat', key, v)
def cost(v): return ('costReduction', None, v)
def dur(v): return ('craftDurability', None, v)
def opt(v): return ('craftOption', None, v)
def batch(n): return ('batch', None, n)
def unlock(k): return ('unlock', k, 0)
def sight(n): return ('sight', None, n)
def bag_units(n): return ('bagUnits', None, n)
def bag_rows(n): return ('bagRows', None, n)

# ---------------------------------------------------------------- craft trees
SMITH = [
 ('whetstone_round','Whetstone Round',stat('yield',.01),'Ten minutes on the stone before every shift.'),
 ('cold_shut_eye','Cold-Shut Eye',cost(.03),'Spot the bad weld before the billet is wasted.'),
 ('dry_seated_haft','Dry-Seated Haft',dur(.05),'A haft seated dry outlives one seated green.'),
 ('bellows_rhythm','Bellows Rhythm',stat('processingSpeed',.01),'Air on the coals in time, not in a panic.'),
 ('offcut_ledger','Offcut Ledger',cost(.03),'Nothing leaves the shop as scrap that could be stock.'),
 ('hammer_sense','Hammer Sense',opt(.07),'You can hear when a piece has come out right.'),

 ('drawn_temper','Drawn Temper',dur(.05),'Colour the steel back down and it stops chipping.'),
 ('quench_oil','Quench Oil',dur(.05),'Oil where water would crack it.'),
 ('edge_geometry','Edge Geometry',stat('yield',.02),'The angle matters more than the arm behind it.'),
 ('charcoal_husbandry','Charcoal Husbandry',cost(.03),'A hotter fire from less fuel.'),
 ('billet_folding','Billet Folding',opt(.07),'Fold it again and the flaws fold out.'),
 ('anvil_song','Anvil Song',stat('processingSpeed',.02),'The pitch tells you when to stop.'),
 ('twin_stroke','Twin Stroke',batch(1),'Two blanks under the hammer in one heat.'),
 ('shoulder_fit','Shoulder Fit',dur(.05),'Seat the shoulder and the head never works loose.'),

 ('differential_temper','Differential Temper',dur(.05),'Hard at the edge, soft at the spine.'),
 ('pattern_weld','Pattern Weld',opt(.07),'Layers that show, and hold.'),
 ('hollow_grind','Hollow Grind',stat('yield',.01),'Less steel behind the edge, more bite in front of it.'),
 ('forge_economy','Forge Economy',cost(.03),'One heat where an apprentice takes three.'),
 ('striker_pair','Striker Pair',stat('processingSpeed',.01),'A second hammer, timed to yours.'),
 ('crucible_steel','Crucible Steel',opt(.07),'Melt it properly and the grain comes out even.'),
 ('blank_stamping','Blank Stamping',batch(1),'Cut the blanks first, finish them together.'),
 ('flux_reading','Flux Reading',cost(.03),'Read the flux and you waste no metal to scale.'),

 ('mastersmith_eye','Mastersmith Eye',opt(.07),'You know what it will be before it is.'),
 ('socket_and_wedge','Socket and Wedge',stat('yield',.02),'A head that cannot fly off is a head you can swing hard.'),
 ('hearth_discipline','Hearth Discipline',stat('processingSpeed',.02),'The fire is never waiting on you.'),
 ('tang_extension','Tang Extension',stat('yield',.01),'Steel all the way through the grip.'),
 ('scale_hammering','Scale Hammering',stat('processingSpeed',.01),'Knock the scale off while it is still cheap to.'),
 ('heat_ledger','Heat Ledger',stat('processingSpeed',.01),'Every heat written down, every heat learned from.'),

 ('the_named_blade','The Named Blade',stat('yield',.01),'The shop is known for one thing, and this is it.'),
 ('shop_of_record','Shop of Record',stat('processingSpeed',.01),'Every settlement on the ring sends work to you.'),
]

ARMORER = [
 ('measured_cut','Measured Cut',stat('tripReduction',.01),'Cut once, to the wearer, not to the pattern.'),
 ('hide_grading','Hide Grading',cost(.03),'The bad half of a hide never reaches the bench.'),
 ('doubled_seam','Doubled Seam',dur(.05),'The seam gives out long before the plate does.'),
 ('boot_last','Boot Last',stat('travelSpeed',.01),'Built on a last, not guessed at.'),
 ('scrap_leather','Scrap Leather',cost(.03),'Offcuts become straps, gussets and lining.'),
 ('fit_by_eye','Fit by Eye',opt(.07),'A glance and you know where it will chafe.'),

 ('waxed_thread','Waxed Thread',dur(.05),'Wax it and the wet stops rotting the stitch.'),
 ('riveted_lap','Riveted Lap',dur(.05),'Rivets where thread would fail.'),
 ('weight_balance','Weight Balance',stat('tripReduction',.02),'Carried on the hips, not hung off the shoulders.'),
 ('tannin_economy','Tannin Economy',cost(.03),'The same bark liquor, three times over.'),
 ('padded_arming','Padded Arming',opt(.07),'What goes under the plate decides whether it is bearable.'),
 ('flex_panel','Flex Panel',stat('travelSpeed',.02),'Rigid where it must be, and nowhere else.'),
 ('paired_cutting','Paired Cutting',batch(1),'Two sets nested from one hide.'),
 ('edge_binding','Edge Binding',dur(.05),'Bound edges do not fray, and fraying is how armor dies.'),

 ('hardened_boss','Hardened Boss',dur(.05),'One thick place where the blows land.'),
 ('articulation','Articulation',opt(.07),'Plates that move with the joint instead of against it.'),
 ('sole_stitching','Sole Stitching',stat('travelSpeed',.01),'Stitched, not glued. Glue is for indoors.'),
 ('pattern_nesting','Pattern Nesting',cost(.03),'Every scrap of the hide accounted for before the knife.'),
 ('breathable_lining','Breathable Lining',stat('tripReduction',.01),'You can wear it all day, which is the whole point.'),
 ('lamellar_lacing','Lamellar Lacing',opt(.07),'Laced so a broken plate is replaced, not the suit.'),
 ('bulk_tanning','Bulk Tanning',batch(1),'The pit holds more than one hide at a time.'),
 ('hide_yield','Hide Yield',cost(.03),'Nothing left on the beam but hair and lime.'),

 ('bespoke_fitting','Bespoke Fitting',opt(.07),'Made for one prospector, and it shows.'),
 ('load_transfer','Load Transfer',stat('tripReduction',.02),'The weight goes into the ground, not into you.'),
 ('road_sole','Road Sole',stat('travelSpeed',.02),'Built for the hexes between rings.'),
 ('gusset_work','Gusset Work',stat('tripReduction',.01),'Room to move exactly where you need it.'),
 ('ankle_support','Ankle Support',stat('travelSpeed',.01),'The difference over ten hexes is an hour.'),
 ('cold_weather_lining','Cold-Weather Lining',stat('travelSpeed',.01),'Tundra stops being a reason to turn back.'),

 ('the_second_skin','The Second Skin',stat('tripReduction',.01),'You stop noticing you are wearing it.'),
 ('outfitter_of_the_ring','Outfitter of the Ring',stat('travelSpeed',.01),'Every capital keeps a set of yours in stock.'),
]

ALCHEMIST = [
 ('clean_glass','Clean Glass',stat('processingSpeed',.01),'A dirty flask ruins a good draught.'),
 ('measured_pour','Measured Pour',cost(.03),'Weighed, not eyeballed.'),
 ('sealed_stopper','Sealed Stopper',dur(.05),'What does not evaporate does not need remaking.'),
 ('cold_steep','Cold Steep',stat('yield',.01),'Slower, and it keeps what heat would burn off.'),
 ('spent_mash','Spent Mash',cost(.03),'The second pressing is weaker, not worthless.'),
 ('nose_for_it','Nose for It',opt(.07),'You can smell a batch going wrong.'),

 ('double_boiler','Double Boiler',dur(.05),'Nothing scorches at the bottom of the pot.'),
 ('resin_binding','Resin Binding',dur(.05),'Resin holds the mixture together in the flask.'),
 ('potency_titration','Potency Titration',stat('yield',.02),'Strong enough to work, weak enough to drink.'),
 ('reflux_still','Reflux Still',cost(.03),'The vapour comes back down instead of out the window.'),
 ('sediment_reading','Sediment Reading',opt(.07),'What settles tells you what you made.'),
 ('warm_room','Warm Room',stat('processingSpeed',.02),'A steady room is half the recipe.'),
 ('batch_kettle','Batch Kettle',batch(1),'One fire under a bigger pot.'),
 ('wax_seal','Wax Seal',dur(.05),'A waxed flask survives a fall down a shaft.'),

 ('fractional_draw','Fractional Draw',dur(.05),'Take only the middle of the run.'),
 ('mineral_mordant','Mineral Mordant',opt(.07),'Ore dust fixes what plant matter will not.'),
 ('slow_infusion','Slow Infusion',stat('yield',.01),'Two days steeping beats two hours boiling.'),
 ('solvent_recovery','Solvent Recovery',cost(.03),'The spirit is worth more than what it dissolved.'),
 ('bench_order','Bench Order',stat('processingSpeed',.01),'Everything within reach, nothing in the way.'),
 ('crystal_seeding','Crystal Seeding',opt(.07),'Drop one crystal in and the rest follow.'),
 ('rack_brewing','Rack Brewing',batch(1),'Six flasks at a time, all the same.'),
 ('lees_reclaim','Lees Reclaim',cost(.03),'What sinks to the bottom goes back in the next pot.'),

 ('perfect_draught','Perfect Draught',opt(.07),'Every flask off the rack is the good one.'),
 ('deep_reserve','Deep Reserve',stat('yield',.02),'Kept properly, it is as good in a month.'),
 ('steady_hand','Steady Hand',stat('processingSpeed',.02),'No spills, no waste, no second attempt.'),
 ('bitter_tincture','Bitter Tincture',stat('yield',.01),'Nobody enjoys it. Everybody finishes it.'),
 ('kiln_dried_stock','Kiln-Dried Stock',stat('processingSpeed',.01),'Dry ingredients keep, and keep their strength.'),
 ('shelf_life','Shelf Life',stat('processingSpeed',.01),'Nothing on the shelf is ever wasted.'),

 ('the_house_blend','The House Blend',stat('yield',.01),'Copied everywhere, matched nowhere.'),
 ('capital_supplier','Capital Supplier',stat('processingSpeed',.01),'The bazaar buys everything you can make.'),
]

# --------------------------------------------------------------- battle trees
def battle(prefix, names, primary, secondary, n_primary, v_primary, v_secondary):
    """22 stat nodes then 8 dormant ability unlocks, in tier order.

    No tree reaches the +15% ceiling on its own: a full Shieldbearer lands at
    12% defence, leaving gear a reason to exist for a character who took it.
    The primary/secondary split is what gives each battle job its shape --
    defensive, even, offensive -- rather than the node count alone.
    """
    out = []
    for i, (key, name, desc) in enumerate(names[:22]):
        s, v = (primary, v_primary) if i < n_primary else (secondary, v_secondary)
        out.append((key, name, stat(s, v), desc))
    for key, name, desc in names[22:]:
        out.append((key, name, unlock(f'{prefix}.{key}'), desc))
    return out

SHIELD_NAMES = [
 ('braced_stance','Braced Stance','Feet set before the blow lands.'),
 ('rim_work','Rim Work','The edge of a shield is a weapon.'),
 ('shoulder_behind','Shoulder Behind It','The arm holds it; the body stops it.'),
 ('short_steps','Short Steps','Never cross your feet under pressure.'),
 ('boss_punch','Boss Punch','Close range, and it buys you a yard.'),
 ('shield_wall','Shield Wall','Nobody in a line fights alone.'),
 ('angled_deflection','Angled Deflection','Turn it aside rather than take it.'),
 ('low_guard','Low Guard','Everything below the knee is still a wound.'),
 ('strap_discipline','Strap Discipline','A loose strap is a lost fight.'),
 ('recovery_step','Recovery Step','Back on guard before the second swing.'),
 ('weight_forward','Weight Forward','Give ground slowly or not at all.'),
 ('helm_awareness','Helm Awareness','You cannot see much. Learn what little you can.'),
 ('breath_control','Breath Control','A shield is heavy for exactly as long as you panic.'),
 ('planted_heel','Planted Heel','The floor takes the force, not your spine.'),
 ('overlap_cover','Overlap Cover','Cover the one beside you and they cover you.'),
 ('counter_shove','Counter-Shove','Answer a push with a push.'),
 ('edge_catch','Edge Catch','Trap the blade and it is no longer a blade.'),
 ('second_wind','Second Wind','The fight is longer than the first minute.'),
 ('field_repair','Field Repair','A cracked board holds if you know how to bind it.'),
 ('watchful_rest','Watchful Rest','You are still on guard while the party sits.'),
 ('unmoved','Unmoved','They stop coming at you and go around.'),
 ('the_last_rank','The Last Rank','Whoever is behind you gets out.'),
 ('ability_brace','Brace','Dormant: a held stance that soaks the next blow.'),
 ('ability_taunt','Draw','Dormant: pull an enemy off a party member.'),
 ('ability_bulwark','Bulwark','Dormant: cover an ally for a short window.'),
 ('ability_rally','Rally','Dormant: steady the party after a bad round.'),
 ('ability_shield_bash','Shield Bash','Dormant: an interrupt that trades damage for time.'),
 ('ability_fortify','Fortify','Dormant: raise the floor of what the party can take.'),
 ('ability_last_stand','Last Stand','Dormant: refuse the killing blow, once per raid.'),
 ('ability_aegis','Aegis','Dormant: the capstone guard.'),
]

SWORD_NAMES = [
 ('true_grip','True Grip','Hold it like a tool, not like a threat.'),
 ('half_step','Half Step','Distance is the whole argument.'),
 ('wrist_cut','Wrist Cut','The smallest cut that ends the exchange.'),
 ('guard_change','Guard Change','Move between guards without thinking about it.'),
 ('bind_and_slip','Bind and Slip','Take the blade, then leave it.'),
 ('measured_lunge','Measured Lunge','Reach without committing.'),
 ('off_hand_check','Off-Hand Check','The empty hand still has work.'),
 ('rising_cut','Rising Cut','Up through the opening, not down onto the guard.'),
 ('footwork_drill','Footwork Drill','Everything above the waist is decided below it.'),
 ('point_control','Point Control','The tip never wanders.'),
 ('tempo_break','Tempo Break','Arrive early or arrive late. Never on time.'),
 ('double_time','Double Time','Two cuts inside one answer.'),
 ('edge_alignment','Edge Alignment','A flat blade is a club.'),
 ('close_quarters','Close Quarters','Inside the swing is the safest place to stand.'),
 ('parry_riposte','Parry and Riposte','The block is the first half of the attack.'),
 ('shoulder_roll','Shoulder Roll','Take the graze and keep the arm.'),
 ('long_guard','Long Guard','Hold the distance you chose.'),
 ('sword_care','Sword Care','A cared-for edge is a longer fight.'),
 ('reading_stance','Reading Stance','You know what they will do from how they stand.'),
 ('economy_of_motion','Economy of Motion','Nothing wasted, nothing telegraphed.'),
 ('duelist_calm','Duelist Calm','The pulse stays where you left it.'),
 ('the_even_hand','The Even Hand','Neither reckless nor slow.'),
 ('ability_riposte','Riposte','Dormant: punish a blocked attack.'),
 ('ability_feint','Feint','Dormant: open a guard by threatening elsewhere.'),
 ('ability_flurry','Flurry','Dormant: a burst that trades defence for output.'),
 ('ability_disarm','Disarm','Dormant: strip a weapon for a round.'),
 ('ability_sidestep','Sidestep','Dormant: avoid one attack outright.'),
 ('ability_execute','Execute','Dormant: finish a wounded enemy.'),
 ('ability_second_blade','Second Blade','Dormant: a brief off-hand attack chain.'),
 ('ability_perfect_form','Perfect Form','Dormant: the capstone stance.'),
]

RUNE_NAMES = [
 ('cut_sigil','Cut Sigil','A rune cut clean carries further than one scratched.'),
 ('ore_ink','Ore Ink','Ground mythril holds a charge that ink will not.'),
 ('breath_line','Breath Line','Say it on the out-breath or not at all.'),
 ('slate_focus','Slate Focus','Flat stone takes a mark better than anything living.'),
 ('ember_draw','Ember Draw','Heat is the easiest thing to ask for.'),
 ('grounding_rod','Grounding Rod','What you call up has to go somewhere after.'),
 ('layered_mark','Layered Mark','Two runes on one stone, in order.'),
 ('slow_charge','Slow Charge','Fill it overnight and spend it in a second.'),
 ('resonance','Resonance','Find the note the stone already wants to make.'),
 ('sight_beyond','Sight Beyond','You see the shape of it before it arrives.'),
 ('cinder_hand','Cinder Hand','The heat leaves your palm without burning it.'),
 ('null_stroke','Null Stroke','A mark that unmakes the mark beside it.'),
 ('deep_vein','Deep Vein','Draw from the seam, not from yourself.'),
 ('shard_lens','Shard Lens','Obsidian narrows it to a point.'),
 ('echo_carve','Echo Carve','The second casting is cheaper than the first.'),
 ('warding_ring','Warding Ring','A circle is the oldest instruction there is.'),
 ('ash_reading','Ash Reading','What burned tells you what will.'),
 ('steady_channel','Steady Channel','Held open, not opened repeatedly.'),
 ('silence_before','Silence Before','Nothing works if you are still talking.'),
 ('binding_knot','Binding Knot','Tie it off or it unravels into you.'),
 ('long_burn','Long Burn','Less at once, for much longer.'),
 ('the_quiet_word','The Quiet Word','Loud is amateur.'),
 ('ability_ember','Ember','Dormant: a small, reliable strike.'),
 ('ability_cinderfall','Cinderfall','Dormant: an area burn over several rounds.'),
 ('ability_ward','Ward','Dormant: a short shield against one element.'),
 ('ability_shatter','Shatter','Dormant: break an enemy guard.'),
 ('ability_siphon','Siphon','Dormant: convert damage dealt into staying power.'),
 ('ability_chain','Chain','Dormant: a strike that carries to a second target.'),
 ('ability_sanctum','Sanctum','Dormant: ground the party against one dungeon element.'),
 ('ability_unmaking','Unmaking','Dormant: the capstone strike.'),
]


# ------------------------------------------------------------ processing trees
#
# §6 turns raw into refined at a settlement, and until now nobody got better at
# it. Five jobs, one per line, because §6 is already a five-line structure: a
# village runs one of the five, a city two, a capital all five.
#
# What separates them from the craft benches is the input. A Smith spends
# refined stock on an object; a Smelter makes the stock. So a processing tree
# deals in the three things a run has -- how long it takes, how much ore it
# eats, and how many ingots come off it -- and in what the line can make at all.
#
# Their `stat` nodes are line-locked exactly as the gathering ones are (§8 rule
# 1): a Sawyer's speed is speed at a saw pit and nowhere else. Without it, three
# processing trees would stack processingSpeed on every line at once, which is
# the same stack the tool ladder is careful never to allow.
#
# Only one stat applies to a processing run, which is why these trees are the
# most capability-heavy in the game: eleven unlocks against twelve stat nodes.
# That is the right shape for the work -- a processing line grows by learning
# what it can make, not by shaving another percent off the clock.
#
# 12 processingSpeed (.12, inside the .1275 a single tree may spend), 5
# costReduction (.15, exactly the cap), 2 batch (the cap), 11 unlock.
PROCESS_PATTERN = [
    'p', 'c', 'u', 'p', 'u', 'p',
    'p', 'c', 'u', 'p', 'b', 'u', 'p', 'c',
    'p', 'u', 'c', 'p', 'u', 'b', 'p', 'u',
    'p', 'u', 'c', 'p', 'u', 'u',
    'p', 'u',
]


def process(prefix, names):
    """30 (key, name, desc) into a tree, effects assigned by PROCESS_PATTERN."""
    out = []
    for i, (key, name, desc) in enumerate(names):
        kind = PROCESS_PATTERN[i]
        if kind == 'p':
            e = stat('processingSpeed', .01)
        elif kind == 'c':
            e = cost(.03)
        elif kind == 'b':
            e = batch(1)
        else:
            e = unlock(f'{prefix}.{key}')
        out.append((key, name, e, desc))
    return out


SAWYER_NAMES = [
 ('saw_set','Saw Set','Teeth set right cut on the pull and clear on the push.'),
 ('bark_first','Bark First','Strip it standing and the blade never meets grit.'),
 ('sawpit','The Sawpit','Unlocks a pit long enough to take a whole trunk.'),
 ('steady_stroke','Steady Stroke','Full length, every stroke. Short strokes are how a day gets long.'),
 ('drying_stack','Drying Stack','Unlocks a stack that seasons while you work the next log.'),
 ('log_dogs','Log Dogs','Pinned down, a log stops arguing with the blade.'),

 ('kerf_line','Kerf Line','Snap a chalk line and the cut follows it.'),
 ('slab_first','Slab First','Take the round off one face and the rest squares itself.'),
 ('frame_saw','Frame Saw','Unlocks a blade held in a frame, and the boards it makes.'),
 ('pit_rhythm','Pit Rhythm','Top man and bottom man, and neither waiting on the other.'),
 ('gang_blades','Gang Blades','Several blades in one frame. One pass, several boards.'),
 ('green_and_dry','Green and Dry','Unlocks the knack for what to cut wet and what to leave standing.'),
 ('roller_bed','Roller Bed','The log arrives at the blade without being lifted.'),
 ('edging_pass','Edging Pass','Trim the wane and the board is stock, not firewood.'),

 ('sharpening_round','Sharpening Round','Ten minutes on the teeth buys an hour at the pit.'),
 ('seasoning_shed','Seasoning Shed','Unlocks a shed where boards dry out of the weather.'),
 ('heart_and_sap','Heart and Sap','Cut around the sapwood and nothing good is wasted.'),
 ('two_man_tempo','Two-Man Tempo','Two sawyers who never fight the blade or each other.'),
 ('quarter_sawn','Quarter Sawn','Unlocks boards cut across the rings, which never cup.'),
 ('stacked_cuts','Stacked Cuts','Log on log, one setting, twice the boards.'),
 ('blade_tension','Blade Tension','A slack blade wanders; a tight one goes where it is sent.'),
 ('offcut_shingles','Offcut Shingles','Unlocks a use for what falls off the edge.'),

 ('true_planing','True Planing','Flat off the pit, so nothing needs doing twice.'),
 ('kiln_drying','Kiln Drying','Unlocks a heated shed, and the weeks it takes off a stack.'),
 ('full_trunk','Full Trunk','Nothing leaves the pit but sawdust.'),
 ('pit_crew','Pit Crew','A yard with hands enough that the blade never stops.'),
 ('laminated_stock','Laminated Stock','Unlocks board glued into something stronger than the tree was.'),
 ('ironwood_setting','Ironwood Setting','Unlocks the teeth and the patience that wood asks for.'),

 ('the_long_pit','The Long Pit','A pit that takes anything the forest can put down.'),
 ('timber_reeve','Timber Reeve','Unlocks the right to work any pit on the ring at your own pace.'),
]

SMELTER_NAMES = [
 ('ore_washing','Ore Washing','Wash the dirt off and the furnace works on metal, not mud.'),
 ('hand_sorting','Hand Sorting','The waste rock never gets a share of the charcoal.'),
 ('bloomery','The Bloomery','Unlocks a low stack that makes a bloom out of ore and time.'),
 ('charge_order','Charge Order','Ore, fuel, ore, fuel. Never two of one.'),
 ('roasting_bed','Roasting Bed','Unlocks a bed that drives the sulphur off before the smelt.'),
 ('tuyere_angle','Tuyere Angle','The air goes where the heat is wanted.'),

 ('bellows_pair','Bellows Pair','Two bags, alternating, and the blast never drops.'),
 ('limestone_flux','Limestone Flux','The slag takes the rubbish and leaves the iron behind.'),
 ('slag_tap','Slag Tap','Unlocks a tap hole, and a furnace that outlives one heat.'),
 ('stack_height','Stack Height','A taller stack holds the heat where the ore falls through it.'),
 ('double_crucible','Double Crucible','Two pots on one fire, and both come off together.'),
 ('charcoal_burn','Charcoal Burn','Unlocks your own burn, and fuel nobody has to be paid for.'),
 ('preheated_blast','Preheated Blast','Warm air costs nothing and saves a third of the fuel.'),
 ('bloom_squeezing','Bloom Squeezing','Beat the slag out while it is soft and the iron stays.'),

 ('hearth_lining','Hearth Lining','A lining that lasts is a furnace that never cools.'),
 ('finery_forge','Finery Forge','Unlocks the second fire, which takes the carbon back out.'),
 ('slag_reclaim','Slag Reclaim','There is iron in what was thrown away.'),
 ('continuous_run','Continuous Run','Charged from the top while it pours from the bottom.'),
 ('banded_frame','Banded Frame','Unlocks banding timber and iron into one thing.'),
 ('ingot_moulds','Ingot Moulds','A row of moulds, and one pour fills them all.'),
 ('blast_timing','Blast Timing','Hard while it is charged, gentle while it is working.'),
 ('wrought_and_cast','Wrought and Cast','Unlocks knowing which of the two a job actually wants.'),

 ('heat_economy','Heat Economy','One fire, all day, and nothing waiting on it.'),
 ('crucible_melt','Crucible Melt','Unlocks melting it properly, so the grain comes out even.'),
 ('full_burden','Full Burden','Every basket of ore weighed against every basket of fuel.'),
 ('water_bellows','Water Bellows','The river works the blast and never gets tired.'),
 ('alloying','Alloying','Unlocks putting something else in on purpose.'),
 ('mythril_heat','Mythril Heat','Unlocks the temperature the humming ore asks for.'),

 ('the_long_blast','The Long Blast','Lit in spring, out in autumn.'),
 ('master_of_the_stack','Master of the Stack','Unlocks first charge at any furnace on the ring.'),
]

TANNER_NAMES = [
 ('fleshing_beam','Fleshing Beam','Everything that rots comes off before anything else happens.'),
 ('salt_cure','Salt Cure','A salted hide waits for you. A green one does not.'),
 ('lime_pit','The Lime Pit','Unlocks a pit that takes the hair off without taking the hide.'),
 ('bark_liquor','Bark Liquor','Weak at the start, strong at the end. Never the other way.'),
 ('drying_loft','Drying Loft','Unlocks a loft with air enough to dry without cracking.'),
 ('scudding','Scudding','Work the grain clean and the tan takes evenly.'),

 ('pit_rotation','Pit Rotation','Move it through weak, middling and strong in turn.'),
 ('spent_bark','Spent Bark','The second liquor is weaker, not useless.'),
 ('bating','Bating','Unlocks the step that turns a stiff hide soft.'),
 ('even_immersion','Even Immersion','Nothing folded, nothing touching, nothing missed.'),
 ('layered_pit','Layered Pit','Hide, bark, hide, bark, and the pit holds a stack.'),
 ('oak_and_hemlock','Oak and Hemlock','Unlocks knowing which bark suits which hide.'),
 ('warm_liquor','Warm Liquor','A warm pit works in weeks where a cold one takes a season.'),
 ('trim_first','Trim First','Cut the shanks off before they drink the liquor.'),

 ('currying_table','Currying Table','Shaved to thickness, and the piece is finished in one pass.'),
 ('oil_tannage','Oil Tannage','Unlocks a hide worked soft with fat rather than bark.'),
 ('offcut_glue','Offcut Glue','Trimmings boil down into something a bench will buy.'),
 ('staking','Staking','Worked over the blade until it gives. Then it is leather.'),
 ('alum_tawing','Alum Tawing','Unlocks white leather, which no bark can make.'),
 ('paired_pits','Paired Pits','One filling while the other empties.'),
 ('steady_warmth','Steady Warmth','A tannery that never gets cold never starts over.'),
 ('split_hides','Split Hides','Unlocks taking two skins out of one thickness.'),

 ('finish_coat','Finish Coat','Dressed, and off the table without a second thought.'),
 ('chamois_work','Chamois Work','Unlocks the soft grades nothing heavy is ever made of.'),
 ('whole_beast','Whole Beast','Horn, hoof, sinew and hide. Nothing carried in is carried out.'),
 ('drum_tanning','Drum Tanning','A turning drum does in a day what a pit does in a season.'),
 ('hardened_leather','Hardened Leather','Unlocks boiled leather, which stops things.'),
 ('beastfang_curing','Beastfang Curing','Unlocks curing a hide that is still trying to bite.'),

 ('the_deep_pit','The Deep Pit','A pit that has not been empty in living memory.'),
 ('master_tanner','Master Tanner','Unlocks first pit at any tannery on the ring.'),
]

MASON_NAMES = [
 ('banker_bench','Banker Bench','Waist height, and the work stops fighting your back.'),
 ('mark_and_measure','Mark and Measure','Twice with the square, once with the chisel.'),
 ('dressing_shed','The Dressing Shed','Unlocks a shed where stone is cut out of the weather.'),
 ('punch_work','Punch Work','Take the waste off fast before you take it off carefully.'),
 ('template_board','Template Board','Unlocks a template, and stones that match without measuring.'),
 ('chisel_angle','Chisel Angle','Too steep and it bruises. Too flat and it slides.'),

 ('claw_and_boaster','Claw and Boaster','Three tools in order, none of them doing another\'s work.'),
 ('bed_and_face','Bed and Face','Lay it the way it lay in the ground and it never spalls.'),
 ('sand_saw','Sand Saw','Unlocks a saw and sand, and cuts a chisel would take a week over.'),
 ('mallet_rhythm','Mallet Rhythm','Light, quick and endless beats heavy and tired.'),
 ('ganged_blocks','Ganged Blocks','Set a row and dress them all to the same line.'),
 ('limestone_and_grit','Limestone and Grit','Unlocks knowing what a stone will do before you strike it.'),
 ('sharpening_forge','Sharpening Forge','A mason who cannot sharpen is a mason who is waiting.'),
 ('offcut_rubble','Offcut Rubble','What falls off the banker is still walling stone.'),

 ('true_arris','True Arris','A clean edge is the whole difference between block and ashlar.'),
 ('ashlar_course','Ashlar Course','Unlocks stone cut close enough to lay without mortar.'),
 ('dust_reclaim','Dust Reclaim','Even the grit sells, to the next mason\'s saw.'),
 ('drafted_margin','Drafted Margin','Cut the border first and the middle takes care of itself.'),
 ('moulded_work','Moulded Work','Unlocks profiles, and stone that is more than a box.'),
 ('double_banker','Double Banker','Two benches, one setting-out, both finished together.'),
 ('wet_cutting','Wet Cutting','Water carries the dust away and the blade lasts twice as long.'),
 ('frost_stone','Frost Stone','Unlocks working the stone that only opens in winter.'),

 ('final_rub','Final Rub','Off the banker finished, not off the banker nearly.'),
 ('voussoir_cutting','Voussoir Cutting','Unlocks the wedge stones an arch stands on.'),
 ('block_economy','Block Economy','Every face of the block is somebody\'s stone.'),
 ('setting_out_floor','Setting-Out Floor','Draw it full size once and cut it fifty times.'),
 ('carved_work','Carved Work','Unlocks stone somebody stops to look at.'),
 ('obsidian_dressing','Obsidian Dressing','Unlocks working glass that takes a hand off if it is rushed.'),

 ('the_great_banker','The Great Banker','A bench that takes a stone two men cannot lift.'),
 ('master_mason','Master Mason','Unlocks first banker at any yard on the ring.'),
]

WEAVER_NAMES = [
 ('retting_judgement','Retting Judgement','A day too long and it rots. A day too short and it fights you.'),
 ('sorted_stricks','Sorted Stricks','Long with long, short with short, and nothing on the wrong cloth.'),
 ('the_brake','The Brake','Unlocks a brake that cracks the woody core out of the stalk.'),
 ('scutching_blade','Scutching Blade','Beat it downward and the boon falls away on its own.'),
 ('hackling_combs','Hackling Combs','Unlocks combs coarse to fine, and thread that runs true.'),
 ('distaff_dressing','Distaff Dressing','A well-dressed distaff spins itself.'),

 ('treadle_wheel','Treadle Wheel','Both hands on the fibre, because the foot does the turning.'),
 ('tow_and_line','Tow and Line','The short fibre is coarse cloth, not sweepings.'),
 ('warping_board','Warping Board','Unlocks a warp measured out before a single pick is thrown.'),
 ('even_tension','Even Tension','A loom argues with you exactly as much as the warp is uneven.'),
 ('double_shuttle','Double Shuttle','Two shuttles, two cloths, one setting-up.'),
 ('sizing_paste','Sizing Paste','Unlocks a dressed warp that does not fray as it is woven.'),
 ('broad_loom','Broad Loom','Wider cloth for the same number of picks.'),
 ('thrums','Thrums','The ends off the loom are cord to somebody.'),

 ('shed_and_pick','Shed and Pick','The rhythm is the whole craft. The rest is setting up.'),
 ('fulling_trough','Fulling Trough','Unlocks cloth beaten dense enough to keep the weather out.'),
 ('noil_carding','Noil Carding','What the combs threw out is carded back into something.'),
 ('flying_shuttle','Flying Shuttle','It crosses on its own, and your hands are free for the beat.'),
 ('twill_setting','Twill Setting','Unlocks a weave that gives where a plain one tears.'),
 ('two_beam_warp','Two-Beam Warp','Two warps on one loom, and both come off finished.'),
 ('damp_weaving','Damp Weaving','A damp shed, and the thread stops snapping.'),
 ('nettle_and_flax','Nettle and Flax','Unlocks the coarse fibres nobody else bothers to ret.'),

 ('off_the_loom','Off the Loom','Cut it down and it is cloth, not a job half done.'),
 ('napping_teasels','Napping Teasels','Unlocks raising a nap, which is cloth against blanket.'),
 ('whole_stalk','Whole Stalk','Fibre, tow, boon and seed. The field gives up all four.'),
 ('loom_discipline','Loom Discipline','Nothing on the loom is ever waiting on the weaver.'),
 ('figured_weave','Figured Weave','Unlocks patterns worked into the cloth rather than onto it.'),
 ('silkweave_handling','Silkweave Handling','Unlocks thread fine enough to lose sight of.'),

 ('the_long_warp','The Long Warp','A warp set up once and woven for a season.'),
 ('master_weaver','Master Weaver','Unlocks first loom at any shed on the ring.'),
]

# ------------------------------------------------------------- gathering trees
#
# §7.2 lines become jobs too, and their job level is the skill level they have
# always had -- one number that drives §7.3 trip reduction and gates the tree.
#
# Their stat nodes are line-locked, exactly as tools are (§8 rule 1): a
# Woodcutting node counts in a forest and nowhere else. Without that, taking
# three gathering trees would stack yield on every line at once, which is the
# thing the tool ladder is careful never to allow.
#
# Effects run in a fixed pattern so every tier carries a mix rather than one
# tier being all yield: 9 yield, 9 trip, 5 travel, 7 unlock.
GATHER_PATTERN = [
    'y', 't', 'v', 'y', 't', 'u',
    'y', 't', 'v', 'y', 't', 'u', 'y', 't',
    'v', 'y', 't', 'u', 'y', 't', 'v', 'u',
    'y', 't', 'v', 'u', 'y', 't',
    'u', 'u',
]


def gather(prefix, names):
    """30 (key, name, desc) into a tree, effects assigned by GATHER_PATTERN."""
    out = []
    for i, (key, name, desc) in enumerate(names):
        kind = GATHER_PATTERN[i]
        if kind == 'y':
            e = stat('yield', .01)
        elif kind == 't':
            e = stat('tripReduction', .01)
        elif kind == 'v':
            e = stat('travelSpeed', .01)
        else:
            e = unlock(f'{prefix}.{key}')
        out.append((key, name, e, desc))
    return out


WOODCUTTING_NAMES = [
 ('felling_notch','Felling Notch','Cut the notch first and the tree goes where you want.'),
 ('swing_economy','Swing Economy','Fewer swings, each of them meant.'),
 ('deer_paths','Deer Paths','The animals already found the easy way through.'),
 ('limb_reading','Limb Reading','You can see where the weight is before you cut.'),
 ('two_hand_grip','Two-Hand Grip','Slide the top hand down and let the head do it.'),
 ('coppice_stand','Coppice Stand','Unlocks a regrown stand worked on a shorter cycle.'),
 ('grain_split','Grain Split','Follow the grain and the log opens itself.'),
 ('wedge_and_maul','Wedge and Maul','What the axe will not part, the wedge will.'),
 ('windfall_sense','Windfall Sense','Storms leave good timber lying down.'),
 ('sap_timing','Sap Timing','Cut it dry and it weighs half as much home.'),
 ('bucking_rhythm','Bucking Rhythm','Length after length without straightening up.'),
 ('old_growth','Old Growth','Unlocks a deep stand the young wood hides.'),
 ('crosscut_pair','Crosscut Pair','A saw with two handles halves an afternoon.'),
 ('haul_sled','Haul Sled','Drag it, do not carry it.'),
 ('ridge_route','Ridge Route','Downhill all the way back, if you plan it.'),
 ('heartwood_cut','Heartwood Cut','Take the middle and leave the sap.'),
 ('kerf_control','Kerf Control','A narrow cut is a fast cut.'),
 ('burnt_stand','Burnt Stand','Unlocks a fire-cleared stand where the char hides good wood.'),
 ('stump_yield','Stump Yield','What is left in the ground is still timber.'),
 ('measured_felling','Measured Felling','Down in one, not worried down in six.'),
 ('skid_trail','Skid Trail','Cut the road once, use it all season.'),
 ('river_stand','River Stand','Unlocks a bank stand you float the haul out of.'),
 ('quarter_sawing','Quarter Sawing','More usable board from the same trunk.'),
 ('dawn_start','Dawn Start','Cold wood cuts cleaner.'),
 ('pack_frame','Pack Frame','The load rides high and does not swing.'),
 ('ironwood_sense','Ironwood Sense','Unlocks the knack for finding the wood that turns an axe.'),
 ('clean_stump','Clean Stump','Nothing left standing to trip over next trip.'),
 ('felling_line','Felling Line','A rope decides the direction, not luck.'),
 ('the_marked_grove','The Marked Grove','Unlocks a stand only you have bothered to map.'),
 ('woodward','Woodward','Unlocks the warden\'s right to cut where others may not.'),
]

MINING_NAMES = [
 ('seam_reading','Seam Reading','The rock tells you where it wants to break.'),
 ('short_haft_work','Short-Haft Work','Close in, where the swing has nowhere to go.'),
 ('spoil_ramp','Spoil Ramp','Build the way out of what you take out.'),
 ('face_squaring','Face Squaring','A square face gives up more than a ragged one.'),
 ('drive_rhythm','Drive Rhythm','Strike, reset, strike. Never rush the reset.'),
 ('shallow_adit','Shallow Adit','Unlocks a side cut into an outcrop others walk past.'),
 ('ore_sorting','Ore Sorting','Leave the waste at the face, not in your bag.'),
 ('wedge_lines','Wedge Lines','Split it along the marks and it comes away whole.'),
 ('prop_setting','Prop Setting','A propped roof is a roof you come back under.'),
 ('dry_working','Dry Working','Water costs more time than rock does.'),
 ('tally_stick','Tally Stick','Know what came out before you climb up.'),
 ('deep_drift','Deep Drift','Unlocks a drift far enough in to be quiet.'),
 ('double_jack','Double Jack','One holds the drill, one swings. Twice the depth.'),
 ('bucket_line','Bucket Line','The ore leaves without you.'),
 ('shaft_ladder','Shaft Ladder','Down in a minute instead of ten.'),
 ('vein_following','Vein Following','Chase the metal, not the plan.'),
 ('cold_chisel','Cold Chisel','For where the pick is too blunt an argument.'),
 ('flooded_level','Flooded Level','Unlocks a level worth pumping out.'),
 ('fines_recovery','Fines Recovery','The dust is ore too.'),
 ('face_lighting','Face Lighting','You cannot mine what you cannot see.'),
 ('windlass','Windlass','A crank beats a rope and a back.'),
 ('mythril_trace','Mythril Trace','Unlocks the ear for a seam that hums.'),
 ('gad_and_feather','Gad and Feather','Iron persuades stone politely.'),
 ('shift_pacing','Shift Pacing','The last hour is worth as much as the first.'),
 ('cage_hoist','Cage Hoist','Ride up instead of climbing.'),
 ('deep_shaft_right','Deep Shaft Right','Unlocks working a shaft below the water table.'),
 ('assay_eye','Assay Eye','Worth carrying, or worth leaving.'),
 ('roof_bolting','Roof Bolting','Nothing falls on a bolted roof.'),
 ('the_named_seam','The Named Seam','Unlocks a seam that carries your name on the map.'),
 ('shift_captain','Shift Captain','Unlocks first claim on a face at any settlement.'),
]

HUNTING_NAMES = [
 ('quiet_step','Quiet Step','Heel down last, and slowly.'),
 ('wind_reading','Wind Reading','Downwind or do not bother.'),
 ('game_trail','Game Trail','They walk the same ground every day.'),
 ('clean_release','Clean Release','The string leaves without a twitch.'),
 ('field_dressing','Field Dressing','Half the weight stays in the field.'),
 ('herd_ground','Herd Ground','Unlocks a wintering ground the herds keep returning to.'),
 ('sign_reading','Sign Reading','Tracks, droppings, bent grass. All of it talks.'),
 ('short_stalk','Short Stalk','The last twenty yards decide it.'),
 ('drag_harness','Drag Harness','A carcass moves better dragged than carried.'),
 ('rut_timing','Rut Timing','They stop being careful once a year.'),
 ('blind_building','Blind Building','Sit still enough and they come to you.'),
 ('high_pasture','High Pasture','Unlocks summer ground above the tree line.'),
 ('two_shot_draw','Two-Shot Draw','The second arrow is already nocked.'),
 ('hide_curing','Hide Curing','Salt it in the field and it does not spoil walking home.'),
 ('river_crossing','River Crossing','Know the fords and the map gets smaller.'),
 ('vital_shot','Vital Shot','One that drops it is kinder than three that do not.'),
 ('call_work','Call Work','Bring the animal to the arrow.'),
 ('marsh_edge','Marsh Edge','Unlocks wet ground the herds water at.'),
 ('bone_and_sinew','Bone and Sinew','Nothing on the animal is waste.'),
 ('patience','Patience','The hunt is mostly waiting, done well.'),
 ('pack_line','Pack Line','Load balanced, hands free.'),
 ('beast_run','Beast Run','Unlocks a run where the big ones move.'),
 ('skinning_speed','Skinning Speed','Off clean, in minutes, no cuts in the hide.'),
 ('dawn_watch','Dawn Watch','They move at first light and nowhere else.'),
 ('light_kit','Light Kit','Carry only what the day needs.'),
 ('beastfang_ground','Beastfang Ground','Unlocks ground where something hunts back.'),
 ('tallow_rendering','Tallow Rendering','The fat is worth the pot it takes.'),
 ('long_shot','Long Shot','Range you can hold, not range you can reach.'),
 ('the_quiet_kill','The Quiet Kill','Unlocks taking one without scattering the herd.'),
 ('master_of_hounds','Master of Hounds','Unlocks working a ground with dogs ahead of you.'),
]

QUARRYING_NAMES = [
 ('bedding_plane','Bedding Plane','Stone has a grain too. Find it.'),
 ('hammer_angle','Hammer Angle','Square on, or the force goes nowhere.'),
 ('scree_path','Scree Path','The loose slope is faster down than around.'),
 ('block_marking','Block Marking','Chalk the line before the first blow.'),
 ('shim_work','Shim Work','Thin iron opens what a sledge cannot.'),
 ('shelf_quarry','Shelf Quarry','Unlocks a terraced face worked in steps.'),
 ('dressing_cuts','Dressing Cuts','Square it at the face, carry less home.'),
 ('sledge_relay','Sledge Relay','Two arms, alternating, all afternoon.'),
 ('roller_track','Roller Track','Logs under stone move mountains.'),
 ('frost_splitting','Frost Splitting','Let the winter do the first cut.'),
 ('spoil_sorting','Spoil Sorting','The rubble is worth something to somebody.'),
 ('deep_bench','Deep Bench','Unlocks a bench below the weathered rock.'),
 ('plug_and_feather','Plug and Feather','Drill, wedge, wait. It opens itself.'),
 ('sled_haul','Sled Haul','Downhill, loaded, once.'),
 ('cliff_stair','Cliff Stair','Cut the steps once and use them forever.'),
 ('true_face','True Face','Flat enough that the next cut is easy.'),
 ('crack_reading','Crack Reading','Every flaw is an invitation.'),
 ('obsidian_flow','Obsidian Flow','Unlocks a flow where the glass runs.'),
 ('offcut_dressing','Offcut Dressing','Small blocks are still blocks.'),
 ('bench_pacing','Bench Pacing','Stone does not reward hurry.'),
 ('crane_gin','Crane Gin','A tripod and a rope beat six men.'),
 ('canyon_face','Canyon Face','Unlocks a wall the weather has already opened.'),
 ('facing_stone','Facing Stone','The good side out, every time.'),
 ('dry_season_work','Dry Season Work','Wet stone is heavy stone.'),
 ('quarry_road','Quarry Road','Built once, used every haul.'),
 ('deep_badland','Deep Badland','Unlocks ground nobody has bothered to survey.'),
 ('rubble_reclaim','Rubble Reclaim','What was left behind is still cut stone.'),
 ('sound_testing','Sound Testing','Tap it. Hollow stone is wasted effort.'),
 ('the_great_bench','The Great Bench','Unlocks a face that takes a season to work out.'),
 ('quarrymaster','Quarrymaster','Unlocks first cut on any face you find.'),
]

HARVESTING_NAMES = [
 ('sharp_hook','Sharp Hook','A dull sickle tears where it should cut.'),
 ('sweep_and_gather','Sweep and Gather','Cut and collect in one motion.'),
 ('field_edge','Field Edge','Work the margins where the stalks stand thickest.'),
 ('stalk_selection','Stalk Selection','Take the long ones, leave the rest to seed.'),
 ('bundle_tying','Bundle Tying','A tied sheaf carries; a loose armful does not.'),
 ('fallow_strip','Fallow Strip','Unlocks a rested strip that comes back stronger.'),
 ('retting_pit','Retting Pit','Soak it right and the fiber lets go.'),
 ('scythe_stance','Scythe Stance','Turn from the hips, not the arms.'),
 ('cart_track','Cart Track','A wheel beats a back over open ground.'),
 ('dew_cutting','Dew Cutting','Damp stalks bend instead of shattering.'),
 ('sheaf_stacking','Sheaf Stacking','Stooked upright, it dries as you work.'),
 ('river_meadow','River Meadow','Unlocks bottom land that never runs short.'),
 ('two_row_pass','Two-Row Pass','Two rows a sweep, if the blade is long enough.'),
 ('seed_saving','Seed Saving','Next season starts this one.'),
 ('open_ground','Open Ground','Nothing between you and the next field.'),
 ('long_fiber','Long Fiber','Cut low and the whole stalk is usable.'),
 ('windrow_timing','Windrow Timing','Turned once at the right hour, dry by dusk.'),
 ('silk_ground','Silk Ground','Unlocks tall grass where something else has been spinning.'),
 ('chaff_reclaim','Chaff Reclaim','Even the broken stuff sells by the sack.'),
 ('steady_pace','Steady Pace','A field is won by not stopping.'),
 ('handcart','Handcart','One trip instead of four.'),
 ('storm_meadow','Storm Meadow','Unlocks a meadow the weather keeps others out of.'),
 ('combing_board','Combing Board','Straight fiber is worth more than tangled.'),
 ('cool_hours','Cool Hours','Cut early, rest at noon, cut again.'),
 ('light_load','Light Load','Carry the fiber, leave the water.'),
 ('silkweave_run','Silkweave Run','Unlocks the ground the good thread comes from.'),
 ('second_cut','Second Cut','The regrowth is shorter and just as good.'),
 ('blade_setting','Blade Setting','Angle the hook and it does the work.'),
 ('the_long_field','The Long Field','Unlocks a field big enough to lose a day in.'),
 ('field_reeve','Field Reeve','Unlocks the right to cut any meadow you reach.'),
]

# ------------------------------------------------------------- wayfaring tree
#
# One job, and it is shaped nothing like the others on purpose.
#
# Five nodes in a single line -- one hex per tier, no branches -- and not one of
# them is bought. They arrive on their own as the job levels, which is the whole
# argument: exploring is not a specialisation you spend your hundred points on,
# it is what the map does to you while you walk it. A tree that competed for
# points would make walking a trade-off against every bench in the game, and the
# point of it is the opposite.
#
# It levels by hexes crossed, and travelling grants no character XP at all -- so
# this is the only thing a long walk pays out. What it pays in is the two things
# a walker owns: how far the eye reaches (§5.6) and how much the back carries
# (§7.6).
#
# **It writes no stat, and it must never write one.** Every other tree is paid
# for with a skill point, and a point is the scarce thing that keeps §7.4.1's
# hundred-point cap meaningful. This one is free, so the only safe currency for
# it is capability: counts, each with its own cap, none of them touching the
# §8.1 ceiling. A free tree that could move a percentage would be a power ladder
# you climb by leaving the app open on a long walk.
#
# It is also the only tree that touches the bag at all. Carrying capacity used
# to come from levelling, which made it a problem that solved itself; putting it
# on the road makes it a problem you solve by walking.
#
# Fifteen skills in five rows of three: eight of ten units (120 -> 200), five of
# four rows (30 -> 50), and two hexes of sight (1 -> 3). The eye comes late both
# times -- it is the rarest thing here and the one with a query budget behind it
# -- while the back grows the whole way up, because that is what a long career of
# walking should feel like.
#
# Read it in columns, which is how the parent wiring runs: the left is room in
# the pack, the middle is straps all the way down, and the right is the mixed
# one that carries both hexes of sight.
EXPLORER = [
 # row 1 -- lv 2, 4, 6. What a walker learns in the first fortnight.
 ('deep_pockets','Deep Pockets',bag_units(10),'You stop leaving things behind because there was nowhere to put them.'),
 ('second_strap','Second Strap',bag_rows(4),'A second strap, and four more things you never have to choose between.'),
 ('rolled_blanket','Rolled Blanket',bag_units(10),'Rolled, not folded. It takes half the room and sheds the rain.'),
 # row 2 -- lv 8, 10, 12. The load starts sitting right, and the country opens up.
 ('even_load','Even Load',bag_units(10),'Weight over the hips, not the shoulders. The miles get shorter.'),
 ('side_pouch','Side Pouch',bag_rows(4),'Small things stop living at the bottom of the pack.'),
 ('high_ground','High Ground',sight(1),'Take the ridge and the country opens a hex further out.'),
 # row 3 -- lv 14, 16, 18.
 ('bindle','Bindle',bag_units(10),'An old trick: the pack that hangs outside the pack.'),
 ('sorted_kit','Sorted Kit',bag_rows(4),'Everything has a place, so everything fits in it.'),
 ('tump_line','Tump Line',bag_units(10),'A strap across the brow. Your neck argues; the load moves.'),
 # row 4 -- lv 20, 22, 24.
 ('packers_knot','Packer\'s Knot',bag_units(10),'Cinch it once and it stays cinched for thirty miles.'),
 ('outer_pockets','Outer Pockets',bag_rows(4),'What you need on the road no longer lives under what you do not.'),
 ('long_haul','Long Haul',bag_units(10),'The day you stop counting the hours is the day you carry more of them.'),
 # row 5 -- lv 26, 28, 30. Six thousand hexes in.
 ('drovers_back','Drover\'s Back',bag_units(10),'Built by the road, and it shows in what you can pick up.'),
 ('tinkers_roll','Tinker\'s Roll',bag_rows(4),'A roll of pockets, and a pocket for everything worth keeping.'),
 ('horizon_line','Horizon Line',sight(1),'You read the far edge of the ground the way others read the near.'),
]

# Order matters: this is the order the panel lists them in, and it follows the
# order a character meets them. You gather from the first minute, craft once you
# have something to craft with, and raid when raiding exists.
TREES = {
    'explorer': EXPLORER,

    'woodcutting': gather('woodcutting', WOODCUTTING_NAMES),
    'mining': gather('mining', MINING_NAMES),
    'hunting': gather('hunting', HUNTING_NAMES),
    'quarrying': gather('quarrying', QUARRYING_NAMES),
    'harvesting': gather('harvesting', HARVESTING_NAMES),

    'sawyer': process('sawyer', SAWYER_NAMES),
    'smelter': process('smelter', SMELTER_NAMES),
    'tanner': process('tanner', TANNER_NAMES),
    'mason': process('mason', MASON_NAMES),
    'weaver': process('weaver', WEAVER_NAMES),

    'smith': SMITH,
    'armorer': ARMORER,
    'alchemist': ALCHEMIST,

    # 12/5 defensive, 8.8/8.8 even, 5/12 offensive -- shield, sword, magic.
    'shieldbearer': battle('shieldbearer', SHIELD_NAMES, 'defence', 'power', 12, .01, .005),
    'swordhand': battle('swordhand', SWORD_NAMES, 'power', 'defence', 11, .008, .008),
    'runecaster': battle('runecaster', RUNE_NAMES, 'power', 'defence', 12, .01, .005),
}

JOBS = [
    ('explorer', 'Explorer', 'wayfaring', 'travel', 'fiber', 'Levels by walking, and by nothing else. Its skills are not bought -- they arrive as you go.'),

    ('woodcutting', 'Woodcutting', 'gathering', 'woodcutting', 'wood', 'Forest work. Its level is the skill you already carry, and it still takes time off the trip.'),
    ('mining', 'Mining', 'gathering', 'mining', 'iron', 'Mountain seams, and the patience a shaft asks for.'),
    ('hunting', 'Hunting', 'gathering', 'hunting', 'pelt', 'Any ground a herd wanders onto. Pelt, horn, sinew, and the animal itself.'),
    ('quarrying', 'Quarrying', 'gathering', 'quarrying', 'stone', 'Badlands stone, cut square at the face.'),
    ('harvesting', 'Harvesting', 'gathering', 'harvesting', 'fiber', 'Grassland fiber, and the field that comes back twice a year.'),

    ('sawyer', 'Sawyer', 'processing', 'woodcutting', 'wood', 'Saws wood into planks. The first bench a prospector ever stands at, and the one the opening arc ends on.'),
    ('smelter', 'Smelter', 'processing', 'mining', 'iron', 'Smelts ore into ingots, and bands ingots to planks for a frame -- the one run that spends two lines.'),
    ('tanner', 'Tanner', 'processing', 'hunting', 'pelt', 'Turns pelt into leather. Slow, and it cannot be hurried by wanting it.'),
    ('mason', 'Mason', 'processing', 'quarrying', 'stone', 'Dresses rough stone square. What the walls and the boots are made of.'),
    ('weaver', 'Weaver', 'processing', 'harvesting', 'fiber', 'Rets, spins and weaves fiber into cloth. The longest chain from raw to refined.'),

    ('smith', 'Smith', 'craft', 'weapon', 'iron', 'Forges the tools every line depends on, and the raid weapon nobody has needed yet.'),
    ('armorer', 'Armorer', 'craft', 'armor', 'pelt', 'Cuts and fits what is worn, which is the only gear that counts on every line at once.'),
    ('alchemist', 'Alchemist', 'craft', 'consumable', 'fiber', 'Brews what is drunk. Everything made here is spent, and the expiry is the sink.'),

    ('shieldbearer', 'Shieldbearer', 'battle', 'defence', 'stone', 'Stands in front. Dormant until raid combat exists.'),
    ('swordhand', 'Swordhand', 'battle', 'balance', 'wood', 'Trades evenly between the blow and the block. Dormant until raid combat exists.'),
    ('runecaster', 'Runecaster', 'battle', 'offence', 'raid', 'Cuts the marks that burn. Dormant until raid combat exists.'),
]

# ------------------------------------------------------------------ structure
TIERS = [(1, 6, 1), (2, 8, 5), (3, 8, 12), (4, 6, 20), (5, 2, 28)]

# The tier gates are shared by every shape, so a chain sits at the same depths
# as a full tree and "level 12" means one thing across the whole panel.
JOB_LEVELS = {tier: job_level for tier, _, job_level in TIERS}

# ...and the wayfaring tree keeps their *shape* while gating node by node.
#
# Five rows of three, exactly like every bought tree, because a twelfth job that
# is also a twelfth layout is one thing too many to learn. What is exceptional is
# underneath the layout: a bought tree's depth opens whole, three or eight nodes
# at once, because the gate only says you may start spending points there. A
# granted tree has no points to spend, so a row arriving whole would be three
# rewards for one level. Here each skill has a level of its own -- one every
# second level, 2 through 30 -- and a row fills in across three of them.
#
# Skill one waits for level 2 rather than 1 because a granted node has no skill
# point paying for it: seventeen XP, four hexes. Short, but a walk, and a walk is
# the only thing this job is ever allowed to charge. Even spacing in *levels* is
# steep spacing in effort -- the job XP curve puts level 12 about 800 hexes out
# and level 30 about 6,400.
WAYFARING_TIER_WIDTH = 3
WAYFARING_STEP = 2

# The level each *row* opens at, which is its first skill's. The panel labels a
# band with it; the two skills after it in the row arrive a level-step apart.
WAYFARING_JOB_LEVELS = {
    row: (row - 1) * WAYFARING_TIER_WIDTH * WAYFARING_STEP + WAYFARING_STEP
    for row in range(1, 6)
}


def wayfare(nodes):
    """Five rows of three, one level per skill, wired down the columns.

    The wayfaring shape, §7.5. It borrows the *layout* of a bought tree -- five
    depths -- because a twelfth job that is also a twelfth kind of diagram is one
    thing too many to learn. Two things it does not borrow:

    One, the gating. A bought depth opens whole: the level says you may start
    spending points there, and the points are the real cost. Nothing is bought
    here, so a row opening whole would hand over three rewards for one level.
    Each skill has its own level instead -- every second one, 2 through 30 -- and
    a row fills in across three of them.

    Two, the branching. A bought tree forks so that thirty points have to be
    spent through choices, and there is nothing to choose here. Each node hangs
    off the one directly above it in its own column, which makes the columns
    readable as three strands -- room, straps, and the mixed one -- rather than a
    lattice nobody has to navigate.
    """
    assert len(nodes) == 15, len(nodes)
    width = WAYFARING_TIER_WIDTH

    out = []
    for idx, (key, name, effect, desc) in enumerate(nodes):
        tier = idx // width + 1
        job_level = (idx + 1) * WAYFARING_STEP
        req = [] if tier == 1 else [nodes[idx - width][0]]
        out.append((key, name, tier, job_level, effect, req, desc))
    return out


def wire(nodes):
    """Split a flat 30 into tiers and derive each node's parents."""
    bands, i = [], 0
    for tier, count, job_level in TIERS:
        bands.append((tier, job_level, nodes[i:i + count]))
        i += count
    assert i == 30, i

    keys = {t: [n[0] for n in band] for t, _, band in bands}
    out = []
    for tier, job_level, band in bands:
        for idx, (key, name, effect, desc) in enumerate(band):
            if tier == 1:
                req = []
            elif tier == 2:
                req = [keys[1][idx % 6]]
            elif tier == 3:
                req = [keys[2][idx]]
            elif tier == 4:
                req = [keys[3][idx]]
            else:
                req = [keys[4][0], keys[4][1]] if idx == 0 else [keys[4][4], keys[4][5]]
            out.append((key, name, tier, job_level, effect, req, desc))
    return out


def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def effect_php(e):
    kind, target, value = e
    if kind == 'unlock':
        return "['kind' => 'unlock', 'target' => %s]" % php_str(target)
    if kind == 'stat':
        return "['kind' => 'stat', 'stat' => %s, 'value' => %s]" % (php_str(target), value)
    if kind in ('batch', 'sight', 'bagUnits', 'bagRows'):
        return "['kind' => %s, 'value' => %d]" % (php_str(kind), value)
    return "['kind' => %s, 'value' => %s]" % (php_str(kind), value)


lines = []
lines.append('''<?php

declare(strict_types=1);

namespace App\\Game;

/**
 * Jobs and their skill trees, §7.4.
 *
 * Generated by `python scripts/gen_jobs.py` -- edit the spec there, not this
 * file, or the next regeneration will quietly eat the change. The generator
 * derives every tier and every parent link, so the only hand-written part is
 * what a node is called and what it does.
 *
 * Sixteen jobs of thirty nodes, bought one at a time with the skill points a
 * character level grants. Two numbers and only one of them is power: a job level
 * gates nodes and does nothing else, while a point is the scarce thing you spend.
 *
 * And one job that plays by neither rule. Explorer (§7.5) is fifteen nodes in
 * the same five-row shape as the rest, granted rather than bought, levelling on
 * hexes walked. It is the answer to a map with no reach limit: the only thing a
 * long walk pays out, and it pays in capability -- sight and straps -- never in
 * a stat.
 *
 * This file is the single source of truth and is **served** to the client over
 * GET /api/jobs rather than mirrored into catalog.ts. 180 hand-copied rows would
 * be 180 chances for the two halves to drift, and the tree is static data the
 * client only needs when the panel opens.
 *
 * The rule that keeps 90 points from breaking the game: a `stat` node feeds the
 * very same aggregate and the same STAT_CEILING clamp as gear, rolled options
 * and potions (§8.1 rule 1). A point buys a different road to +15%, never a
 * higher one. The four effects that are not stats each thin a §11 sink instead,
 * so each is bounded by its own cap in Balance.
 *
 * Generated shape: tiers of 6/8/8/6/2 at job levels 1/5/12/20/28, every node
 * above tier 1 naming a parent, and both capstones naming two. Explorer is the
 * one exception: 3/3/3/3/3, wired down its columns rather than across, and gated
 * one skill at a time -- every second job level from 2 to 30 -- rather than a
 * row at a time. A granted node has no point to pay for it, so the walk is the
 * price and each skill is charged for separately (§7.5).
 */
final class Jobs
{
    /**
     * §7.2 -- the five material lines, now jobs in their own right.
     *
     * Their job level is not a new number: it is the skill level they have
     * always had, which both drives §7.3 trip reduction and gates the tree. One
     * number, so there is nothing for two systems to disagree about.
     *
     * Their `stat` nodes are line-locked, exactly as tools are (§8 rule 1): a
     * Woodcutting node counts in a forest and nowhere else. Without that, three
     * gathering trees would stack yield on every line at once.
     */
    public const GATHERING = 'gathering';

    /**
     * §6 -- the five settlement lines, now jobs of their own.
     *
     * Processing is not crafting and the split is the input. A craft bench
     * spends refined stock on an object; a processing line makes the stock. So
     * these trees deal in the three things a run actually has -- how long it
     * takes, how much raw it eats, how much refined comes off it -- and in what
     * the line can make at all.
     *
     * Their level comes from finished runs, and their `stat` nodes are
     * line-locked exactly as the gathering ones are (§8 rule 1): a Sawyer is
     * faster at a saw pit and nowhere else. Three processing trees must not
     * stack processingSpeed on every line at once.
     */
    public const PROCESSING = 'processing';

    public const CRAFT = 'craft';

    public const BATTLE = 'battle';

    /**
     * §7.5 -- the kind with one member, and every rule of §7.4 bent.
     *
     * A wayfaring job is granted, not bought: its nodes arrive at their job
     * level and cost no skill point. That is only safe because there is exactly
     * one of them and it is five nodes long -- a second free tree would make the
     * hundred-point cap (§7.4.1) a suggestion.
     */
    public const WAYFARING = 'wayfaring';

    /**
     * §7.4 -- the kinds, in the order a character meets them. Anything listing
     * jobs walks this rather than inventing its own order.
     *
     * Wayfaring comes first because it does: you walk before you have swung at
     * anything, and you refine what you gathered before you craft with it.
     */
    public const KINDS = [self::WAYFARING, self::GATHERING, self::PROCESSING, self::CRAFT, self::BATTLE];

    /**
     * §7.4 -- the seventeen. `source` is the craft category a job draws XP
     * from, the §6 processing line it draws XP from, the battle role it will
     * draw XP from once raiding exists (§9), or -- for Explorer alone --
     * `travel`, meaning hexes crossed.
     *
     * A processing job's `source` is a gathering line key on purpose: it is the
     * line the recipe belongs to, which is what makes Sawyer the job that
     * learns from sawing planks and the one whose nodes count there.
     *
     * @var array<string,array<string,string>>
     */
    public const JOBS = [''')

for key, name, kind, source, palette, desc in JOBS:
    lines.append("        %s => ['name' => %s, 'kind' => self::%s, 'source' => %s, 'palette' => %s, 'description' => %s]," % (
        php_str(key), php_str(name), kind.upper(), php_str(source), php_str(palette), php_str(desc)))

lines.append('''    ];

    /**
     * §7.4.2 -- job level required to reach into each tier of a *bought* tree.
     *
     * The wayfaring tree keeps the same five depths and sets its own levels for
     * them (WAYFARING_TIER_JOB_LEVEL below), because it is paced by walking
     * rather than by skill points.
     */
    public const TIER_JOB_LEVEL = [1 => 1, 2 => 5, 3 => 12, 4 => 20, 5 => 28];

    /** §7.4.2 -- how many nodes each tier holds in a full tree. Thirty in all. */
    public const TIER_SIZE = [1 => 6, 2 => 8, 3 => 8, 4 => 6, 5 => 2];

    public const NODES_PER_JOB = 30;

    /** §7.5 -- the wayfaring shape: five rows of three, fifteen in all. */
    public const WAYFARING_TIER_SIZE = [1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3];

    /**
     * §7.5 -- the level each wayfaring *row* opens at: 2, 8, 14, 20, 26.
     *
     * This is where the wayfaring tree stops being like the others, and the
     * difference is the point of it. A bought depth opens whole -- its gate only
     * says you may start spending points there, and the point is the real price.
     * Nothing is bought here, so a row arriving whole would be three rewards for
     * one level. **Each skill carries its own `jobLevel` instead**, one every
     * second level from 2 to 30, and a row fills in across three of them.
     *
     * So this table is the row's *first* skill, not a gate every node in the row
     * shares: read `NODES[$key]['jobLevel']` for what a given skill actually
     * needs. The panel uses this to label the band and to say when the depth
     * begins.
     *
     * Row one waits for level 2 rather than 1 because a character who has walked
     * nowhere must not be handed anything. Seventeen XP, four hexes: a short
     * walk, but a walk, and a walk is the only price this job may charge. The
     * last skill lands exactly on JOB_MAX_LEVEL.
     */
    public const WAYFARING_TIER_JOB_LEVEL = [1 => 2, 2 => 8, 3 => 14, 4 => 20, 5 => 26];

    public const NODES_PER_WAYFARING = 15;

    /**
     * Every node, keyed by its own key. `requires` names parent nodes in the
     * same job and a strictly lower tier; a capstone names two.
     *
     * @var array<string,array<string,mixed>>
     */
    public const NODES = [''')

total = 0
for job, nodes in TREES.items():
    assert len(nodes) in (15, 30), (job, len(nodes))
    lines.append('        // ---- %s' % job)
    for key, name, tier, job_level, effect, req, desc in (wayfare if len(nodes) == 15 else wire)(nodes):
        total += 1
        # Parents are namespaced here, where the job is known. Doing it after
        # the fact by string replacement used to reach across trees: two jobs
        # with a node of the same name (second_wind, say) had the first job's
        # prefix stamped onto both, which wired a shieldbearer capstone to an
        # explorer node and silently broke the tree.
        req_php = '[' + ', '.join(php_str(job + '.' + r) for r in req) + ']'
        lines.append(
            "        %s => ['job' => %s, 'tier' => %d, 'jobLevel' => %d, 'name' => %s, 'effect' => %s, 'requires' => %s, 'description' => %s]," % (
                php_str(f'{job}.{key}'), php_str(job), tier, job_level, php_str(name),
                effect_php(effect), req_php, php_str(desc)))

lines.append('''    ];

    /** @return array<string,mixed>|null */
    public static function node(string $key): ?array
    {
        return self::NODES[$key] ?? null;
    }

    /**
     * §7.5 -- true for a job whose nodes are granted by job level rather than
     * bought with a point.
     *
     * Asked as a question about the *kind* rather than a hardcoded 'explorer',
     * so nothing has to be rewritten if a second wayfaring job ever earns its
     * place -- and so the one rule lives in one sentence.
     */
    public static function isAutomatic(string $job): bool
    {
        return (self::JOBS[$job]['kind'] ?? null) === self::WAYFARING;
    }

    /** How many nodes this job's tree holds, whichever shape it is. */
    public static function nodeCount(string $job): int
    {
        return self::isAutomatic($job) ? self::NODES_PER_WAYFARING : self::NODES_PER_JOB;
    }

    /** @return array<int,int> tier => node count, for this job's shape. */
    public static function tierSizes(string $job): array
    {
        return self::isAutomatic($job) ? self::WAYFARING_TIER_SIZE : self::TIER_SIZE;
    }

    /** @return array<int,int> tier => job level required, for this job's shape. */
    public static function tierJobLevels(string $job): array
    {
        return self::isAutomatic($job) ? self::WAYFARING_TIER_JOB_LEVEL : self::TIER_JOB_LEVEL;
    }

    /**
     * §7.5 -- every node this character has by right of a job level, rather
     * than by having paid for it.
     *
     * @param  array<string,int>  $jobLevels
     * @return array<int,string>
     */
    public static function granted(array $jobLevels): array
    {
        $out = [];

        foreach (self::NODES as $key => $node) {
            if (self::isAutomatic($node['job']) && ($jobLevels[$node['job']] ?? 0) >= $node['jobLevel']) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    public static function nodesFor(string $job): array
    {
        return array_filter(self::NODES, fn (array $n) => $n['job'] === $job);
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::NODES);
    }
}''')

out = '\n'.join(lines)

io.open('app/Game/Jobs.php', 'w', encoding='utf-8', newline='').write(out + '\n')
print('wrote app/Game/Jobs.php with %d nodes' % total)
