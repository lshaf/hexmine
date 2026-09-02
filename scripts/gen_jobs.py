"""Emit app/Game/Jobs.php from a compact spec.

Tier shape and the prerequisite graph are derived, not typed 480 times: every
bought tree is 6/8/8/6/2 and the parent wiring is the same everywhere, so the
hand-written part is what a node is called, what it does, and how deep it sits.

What a node does is written as one letter per node in CODES -- thirty of them,
grouped by tier -- and the value comes from VALUES, which is read by depth. A
tier-1 node is worth less than a tier-5 one of the same kind, which is what
stops a tree being thirty copies of "+1%".

Every kind here has a call site in GameService. Anything that does not is not a
skill, it is a promise, and the panel has no honest way to say so.
"""
import io

# effect helpers -------------------------------------------------------------
def stat(key, v): return ('stat', key, v)
def cost(v): return ('costReduction', None, v)
def dur(v): return ('craftDurability', None, v)
def opt(v): return ('craftOption', None, v)
def opt_tier(v): return ('optionTier', None, v)
def brew(v): return ('brewExtra', None, v)
def stack(n): return ('stackCap', None, n)
def batch(n): return ('batch', None, n)
def run_slot(n): return ('runSlot', None, n)
def presence(v): return ('presence', None, v)
def tool_wear(v): return ('toolWear', None, v)
def bite(n): return ('bite', None, n)
def seam_grade(v): return ('seamGrade', None, v)
def pair(key, n): return ('pair', key, n)
def battle_wear(v): return ('battleWear', None, v)
def skill_power(v): return ('skillPower', None, v)
def skill_cooldown(n): return ('skillCooldown', None, n)
def skill_stun(n): return ('skillStun', None, n)
def weapon_wear(v): return ('weaponWear', None, v)
def gold_find(v): return ('goldFind', None, v)
def loot_option(v): return ('lootOption', None, v)
def sight(n): return ('sight', None, n)
def bag_slots(n): return ('bagSlots', None, n)

# What one node of each kind is worth at each of the five depths.
VALUES = {
    'y': (stat, 'yield',           [.01, .01, .015, .015, .02]),
    'p': (stat, 'processingSpeed', [.01, .01, .015, .015, .02]),
    'w': (tool_wear, None,  [.01, .015, .02, .03, .04]),
    'k': (bite, None,       [1, 1, 1, 2, 2]),
    'd': (seam_grade, None, [.01, .015, .015, .02, .025]),
    'c': (cost, None,       [.01, .015, .02, .025, .03]),
    's': (presence, None,   [.01, .015, .015, .02, .025]),
    'b': (batch, None,      [1, 1, 1, 1, 1]),
    'r': (run_slot, None,   [1, 1, 1, 1, 1]),
    'D': (dur, None,        [.02, .03, .03, .04, .05]),
    'O': (opt, None,        [.03, .04, .05, .06, .07]),
    'T': (opt_tier, None,   [.02, .03, .03, .04, .05]),
    'X': (brew, None,       [.03, .04, .05, .06, .07]),
    'K': (stack, None,      [1, 1, 1, 2, 2]),
    'A': (pair, 'attack',   [1, 1, 2, 2, 3]),
    'F': (pair, 'defense',  [1, 1, 2, 2, 3]),
    'W': (battle_wear, None, [.01, .015, .015, .02, .025]),
    'P': (skill_power, None,    [.03, .04, .05, .06, .08]),
    'C': (skill_cooldown, None, [1, 1, 1, 1, 1]),
    'S': (skill_stun, None,     [1, 1, 1, 1, 1]),
    'V': (weapon_wear, None, [.01, .015, .015, .02, .025]),
    'G': (gold_find, None,   [.02, .03, .03, .04, .05]),
    'L': (loot_option, None, [.02, .03, .04, .05, .06]),
}

# Mirrors Balance. A tree that passes one of these is a node bought and never
# felt, so the generator refuses to emit it.
CAPS = {
    'stat': .1275,
    'toolWear': .25,
    'bite': 5,
    'seamGrade': .12,
    'costReduction': .15,
    'presence': .20,
    'batch': 2,
    'runSlot': 2,
    'craftDurability': .25,
    'craftOption': .35,
    'optionTier': .25,
    'brewExtra': .35,
    'stackCap': 10,
    'pair': 12,
    'battleWear': .15,
    'skillPower': .25,
    'skillCooldown': 2,
    'skillStun': 1,
    'weaponWear': .15,
    'goldFind': .25,
    'lootOption': .25,
}

# Which kinds each job kind may spend, and nothing else. A Sawyer node that
# rolled an option would be a bench effect on a run that makes a material (§6.3);
# an Alchemist node granting durability would be durability on a potion.
ALLOWED = {
    'gathering': set('ykwd'),
    'processing': set('pcsbr'),
    'weapon': set('pcDOT'),
    'armor': set('pcDOT'),
    'consumable': set('pcbXK'),
    'battle': set('AFWVGLPCS'),
}

# ---------------------------------------------------------------- the names
WOODCUTTING_NAMES = [
 ('felling_notch','Felling Notch','Cut the notch first and the tree goes where you want.'),
 ('swing_economy','Swing Economy','Fewer swings, each of them meant.'),
 ('deer_paths','Deer Paths','The animals already found the easy way through.'),
 ('limb_reading','Limb Reading','You can see where the weight is before you cut.'),
 ('two_hand_grip','Two-Hand Grip','Slide the top hand down and let the head do the work, not the edge.'),
 ('coppice_stand','Coppice Stand','Cut the same stand for years and you learn which stems were worth waiting for.'),

 ('grain_split','Grain Split','Follow the grain and the log opens itself.'),
 ('wedge_and_maul','Wedge and Maul','What the axe will not part, the wedge will.'),
 ('windfall_sense','Windfall Sense','Storms leave good timber lying down.'),
 ('sap_timing','Sap Timing','Cut it dry and it weighs half as much home.'),
 ('bucking_rhythm','Bucking Rhythm','Length after length without straightening up.'),
 ('old_growth','Old Growth','Old trees are worth the walk, and you can tell the heartwood from the outside.'),
 ('crosscut_pair','Crosscut Pair','A saw with two handles halves an afternoon.'),
 ('helve_care','Helve Care','A cracked haft is found in the shed, not at the tree.'),

 ('sheltered_draw','Sheltered Draw','A fold in the hill the weather never reached, and it shows in the timber.'),
 ('heartwood_cut','Heartwood Cut','Take the middle and leave the sap.'),
 ('kerf_control','Kerf Control','A narrow cut is a fast cut.'),
 ('burnt_stand','Burnt Stand','Char hides good wood. You have learned to look under it.'),
 ('stump_yield','Stump Yield','What is left in the ground is still timber.'),
 ('measured_felling','Measured Felling','Down in one, not worried down in six.'),
 ('stone_and_strop','Stone and Strop','Five minutes on the stone buys a week of the edge.'),
 ('river_stand','River Stand','Float the haul out and you can afford to fell the awkward one further in.'),

 ('quarter_sawing','Quarter Sawing','More usable board from the same trunk.'),
 ('dawn_start','Dawn Start','Cold wood cuts cleaner.'),
 ('bit_guard','Bit Guard','The edge travels sheathed and arrives sharp.'),
 ('ironwood_sense','Ironwood Sense','Wood that turns an axe, met on your terms rather than the axe\'s.'),
 ('clean_stump','Clean Stump','Nothing left standing to catch a boot on next time.'),
 ('felling_line','Felling Line','A rope decides the direction, not luck.'),

 ('the_marked_grove','The Marked Grove','A stand only you have bothered to map, and you know which tree is which.'),
 ('woodward','Woodward','The warden\'s habit: the edge checked before the walk, not after the day.'),
]

MINING_NAMES = [
 ('seam_reading','Seam Reading','The rock tells you where it wants to break.'),
 ('short_haft_work','Short-Haft Work','Close in, where the swing has nowhere to go.'),
 ('outcrop_break','Outcrop Break','Crack the weathered skin off and work what is under it.'),
 ('face_squaring','Face Squaring','A square face gives up more than a ragged one.'),
 ('drive_rhythm','Drive Rhythm','Strike, reset, strike. Never rush the reset.'),
 ('shallow_adit','Shallow Adit','A side cut into an outcrop others walk past, into the part they never saw.'),

 ('ore_sorting','Ore Sorting','Leave the waste at the face, not in your bag.'),
 ('wedge_lines','Wedge Lines','Split it along the marks and it comes away whole.'),
 ('prop_setting','Prop Setting','A propped roof is a roof you can work under long enough to find the good rock.'),
 ('dry_working','Dry Working','Water costs an iron edge more than it costs the rock.'),
 ('tally_stick','Tally Stick','Know what came out before you climb up.'),
 ('deep_drift','Deep Drift','Far enough in that what you are cutting has never seen weather.'),
 ('double_jack','Double Jack','One holds the drill, one swings. Twice the depth.'),
 ('bucket_line','Bucket Line','The ore leaves without you, and none of it is left at the bottom.'),

 ('helve_soaking','Helve Soaking','A swelled haft does not work loose at the head.'),
 ('vein_following','Vein Following','Chase the metal, not the plan.'),
 ('cold_chisel','Cold Chisel','For where the pick is too blunt an argument.'),
 ('flooded_level','Flooded Level','Pumped out because of what was down there, and what was down there is still down there.'),
 ('fines_recovery','Fines Recovery','The dust is ore too.'),
 ('face_lighting','Face Lighting','You cannot mine what you cannot see.'),
 ('windlass','Windlass','A crank beats a rope and a back, so the heavy blocks come up too.'),
 ('mythril_trace','Mythril Trace','The ear for a seam that hums, and the sense to take the good of it.'),

 ('gad_and_feather','Gad and Feather','Iron persuades stone politely.'),
 ('shift_pacing','Shift Pacing','The last hour is worth as much as the first.'),
 ('spare_heads','Spare Heads','Two heads to a haft, and neither worked down to nothing.'),
 ('deep_shaft_right','Deep Shaft Right','Below the water table, where the rock has had no chance to go soft.'),
 ('assay_eye','Assay Eye','Worth carrying, or worth leaving.'),
 ('roof_bolting','Roof Bolting','Nothing falls on a bolted roof.'),

 ('the_named_seam','The Named Seam','A seam that carries your name on the map, and you know where it runs richest.'),
 ('shift_captain','Shift Captain','The tools go up the shaft with you, cleaned, and come back down whole.'),
]

HUNTING_NAMES = [
 ('quiet_step','Quiet Step','Heel down last, and slowly.'),
 ('wind_reading','Wind Reading','Downwind or do not bother.'),
 ('game_trail','Game Trail','They walk the same ground every day.'),
 ('clean_release','Clean Release','The string leaves without a twitch.'),
 ('bowstring_wax','Bowstring Wax','Waxed and cased, and it does not go slack in the wet.'),
 ('herd_ground','Herd Ground','A wintering ground the herds keep returning to. Short stalks, and an easy draw.'),

 ('sign_reading','Sign Reading','Tracks, droppings, bent grass. All of it talks.'),
 ('short_stalk','Short Stalk','The last twenty yards decide it.'),
 ('nock_check','Nock Check','A split nock found at the quiver, not at full draw.'),
 ('rut_timing','Rut Timing','They stop being careful once a year.'),
 ('blind_building','Blind Building','Sit still enough and they come to you.'),
 ('high_pasture','High Pasture','Summer ground above the tree line, where the animals are heavy.'),
 ('two_shot_draw','Two-Shot Draw','The second arrow is already nocked.'),
 ('limb_rest','Limb Rest','Unstrung between stalks, and the bow keeps its cast.'),

 ('spare_string','Spare String','A second string, dry, against your chest.'),
 ('vital_shot','Vital Shot','One that drops it is kinder than three that do not.'),
 ('call_work','Call Work','Bring the animal to the arrow.'),
 ('marsh_edge','Marsh Edge','Wet ground the herds water at, and the best of them come down to it.'),
 ('bone_and_sinew','Bone and Sinew','Nothing on the animal is waste.'),
 ('patience','Patience','The hunt is mostly waiting, done well.'),
 ('salt_lick','Salt Lick','Ground they come to on their own, and the heavy ones come first.'),
 ('beast_run','Beast Run','A run where the big ones move, and they move along it in season.'),

 ('skinning_speed','Skinning Speed','Off clean, in minutes, no cuts in the hide.'),
 ('dawn_watch','Dawn Watch','They move at first light and nowhere else.'),
 ('winter_yard','Winter Yard','Where they hole up when it turns, packed close and in prime coat.'),
 ('beastfang_ground','Beastfang Ground','Ground where something hunts back, and what lives on it is worth the risk.'),
 ('tallow_rendering','Tallow Rendering','The fat is worth the pot it takes.'),
 ('long_shot','Long Shot','Range you can hold, not range you can reach.'),

 ('the_quiet_kill','The Quiet Kill','One taken without scattering the herd, and without a bow drawn twice.'),
 ('master_of_hounds','Master of Hounds','Dogs ahead of you, and less asked of the string all day.'),
]

QUARRYING_NAMES = [
 ('bedding_plane','Bedding Plane','Stone has a grain too. Find it.'),
 ('hammer_angle','Hammer Angle','Square on, or the force goes nowhere.'),
 ('face_sweeping','Face Sweeping','Grit under the sledge is what rounds an edge.'),
 ('block_marking','Block Marking','Chalk the line before the first blow.'),
 ('shim_work','Shim Work','Thin iron opens what a sledge cannot.'),
 ('shelf_quarry','Shelf Quarry','A terraced face worked in steps, so the good course is reached rather than guessed at.'),

 ('dressing_cuts','Dressing Cuts','Square it at the face, carry less home.'),
 ('sledge_relay','Sledge Relay','Two arms, alternating, all afternoon.'),
 ('handle_seating','Handle Seating','Wedge the head tight before it works loose on you.'),
 ('frost_splitting','Frost Splitting','Let the winter make the first cut and it shows you where the sound stone is.'),
 ('spoil_sorting','Spoil Sorting','The rubble is worth something to somebody.'),
 ('deep_bench','Deep Bench','Below the weathered rock, where the face has something better than rubble in it.'),
 ('plug_and_feather','Plug and Feather','Drill, wedge, wait. It opens itself.'),
 ('point_dressing','Point Dressing','The point redrawn while it is still a point.'),

 ('tempered_iron','Tempered Iron','Hard enough to bite, soft enough not to shatter.'),
 ('true_face','True Face','Flat enough that the next cut is easy.'),
 ('crack_reading','Crack Reading','Every flaw is an invitation, and some of them open onto the good stuff.'),
 ('obsidian_flow','Obsidian Flow','A flow where the glass runs, and it takes the edge off anything hurried.'),
 ('offcut_dressing','Offcut Dressing','Small blocks are still blocks.'),
 ('bench_pacing','Bench Pacing','Stone does not reward hurry.'),
 ('padded_haft','Padded Haft','Leather at the grip takes the shock the wood was taking.'),
 ('canyon_face','Canyon Face','A wall the weather has already opened, and it opened it at the best course.'),

 ('facing_stone','Facing Stone','The good side out, every time.'),
 ('dry_season_work','Dry Season Work','Wet stone is heavy stone.'),
 ('forge_day','Forge Day','One day a month at the fire and nothing goes to the scrap pile.'),
 ('deep_badland','Deep Badland','Ground nobody has bothered to survey. You have.'),
 ('rubble_reclaim','Rubble Reclaim','What was left behind is still cut stone.'),
 ('sound_testing','Sound Testing','Tap it. Hollow stone is wasted effort.'),

 ('the_great_bench','The Great Bench','A face that takes a season to work, and you know every course in it by name.'),
 ('quarrymaster','Quarrymaster','Sledges dressed, wedges seated, and nothing broken at the face.'),
]

HARVESTING_NAMES = [
 ('sharp_hook','Sharp Hook','A dull sickle tears where it should cut.'),
 ('sweep_and_gather','Sweep and Gather','Cut and collect in one motion.'),
 ('field_edge','Field Edge','Work the margins where the stalks stand thickest.'),
 ('stalk_selection','Stalk Selection','Take the long ones, leave the rest to seed.'),
 ('hook_angle','Hook Angle','Set the hook right and the stalk comes away in one.'),
 ('fallow_strip','Fallow Strip','A rested strip comes back stronger, and you are the one who knows it rested.'),

 ('retting_pit','Retting Pit','Soak it right and the fiber lets go.'),
 ('scythe_stance','Scythe Stance','Turn from the hips, not the arms.'),
 ('hook_stoning','Hook Stoning','A stone in the pocket, used at the end of every row.'),
 ('dew_cutting','Dew Cutting','Damp stalks bend instead of shattering.'),
 ('sheaf_stacking','Sheaf Stacking','Stooked upright, it dries as you work.'),
 ('river_meadow','River Meadow','Bottom land that never runs short, and the long stems are always in the same place.'),
 ('peened_edge','Peened Edge','Hammered thin again, and it holds a week of cutting.'),
 ('seed_saving','Seed Saving','Keep the best of this year and you know the best of it when you see it again.'),

 ('snath_fitting','Snath Fitting','A handle set to your own reach stops the blade fighting you.'),
 ('long_fiber','Long Fiber','Cut low and the whole stalk is usable.'),
 ('dry_blade','Dry Blade','Sap left on the steel is rust by morning.'),
 ('silk_ground','Silk Ground','Tall grass something else has been spinning in. You can tell which stems it used.'),
 ('chaff_reclaim','Chaff Reclaim','Even the broken stuff sells by the sack.'),
 ('steady_pace','Steady Pace','A field is won by not stopping.'),
 ('handcart','Handcart','One mine instead of four.'),
 ('storm_meadow','Storm Meadow','A meadow the weather keeps others out of, so nobody has taken the best of it.'),

 ('combing_board','Combing Board','Straight fiber is worth more than tangled.'),
 ('cool_hours','Cool Hours','Cut early, rest at noon, cut again.'),
 ('blade_case','Blade Case','The hook travels wrapped and comes out sharp.'),
 ('silkweave_run','Silkweave Run','The ground the good thread comes from, and you know the strip it comes from.'),
 ('second_cut','Second Cut','The regrowth is shorter and just as good.'),
 ('blade_setting','Blade Setting','Angle the hook and it does the work.'),

 ('the_long_field','The Long Field','A field big enough to lose a day in, and you know the corner worth the day.'),
 ('field_reeve','Field Reeve','The reeve\'s habit: the hook stoned every evening, whatever the day was.'),
]

SAWYER_NAMES = [
 ('saw_set','Saw Set','Teeth set right cut on the pull and clear on the push.'),
 ('bark_first','Bark First','Strip it standing and the blade never meets grit.'),
 ('sawpit','The Sawpit','A pit long enough to take a whole trunk, so nothing is cut twice.'),
 ('steady_stroke','Steady Stroke','Full length, every stroke. Short strokes are how a day gets long.'),
 ('drying_stack','Drying Stack','A stack that seasons while you work the next log, if somebody turns it.'),
 ('log_dogs','Log Dogs','Pinned down, a log stops arguing with the blade.'),

 ('kerf_line','Kerf Line','Snap a chalk line and the cut follows it.'),
 ('slab_first','Slab First','Take the round off one face and the rest squares itself.'),
 ('frame_saw','Frame Saw','A blade held in a frame goes where it is sent, and goes faster.'),
 ('pit_rhythm','Pit Rhythm','Top man and bottom man, and neither waiting on the other.'),
 ('gang_blades','Gang Blades','Several blades in one frame. One pass, several boards.'),
 ('green_and_dry','Green and Dry','What to cut wet and what to leave standing. Less of the log is wasted.'),
 ('roller_bed','Roller Bed','The log arrives at the blade without being lifted.'),
 ('edging_pass','Edging Pass','Trim the wane and the board is stock, not firewood.'),

 ('sharpening_round','Sharpening Round','Ten minutes on the teeth buys an hour at the pit.'),
 ('seasoning_shed','Seasoning Shed','Boards dry out of the weather, and quicker for being watched.'),
 ('heart_and_sap','Heart and Sap','Cut around the sapwood and nothing good is wasted.'),
 ('two_man_tempo','Two-Man Tempo','Two sawyers who never fight the blade or each other.'),
 ('quarter_sawn','Quarter Sawn','Cut across the rings. They never cup, so nothing comes back to the pit.'),
 ('stacked_cuts','Stacked Cuts','Log on log, one setting, twice the boards.'),
 ('blade_tension','Blade Tension','A slack blade wanders; a tight one goes where it is sent.'),
 ('offcut_shingles','Offcut Shingles','What falls off the edge is stock for something, so less trunk is needed.'),

 ('true_planing','True Planing','Flat off the pit, so nothing needs doing twice.'),
 ('kiln_drying','Kiln Drying','A heated shed takes weeks off a stack, and wants a hand on the door.'),
 ('full_trunk','Full Trunk','Nothing leaves the pit but sawdust.'),
 ('pit_crew','Pit Crew','A yard with hands enough that the blade never stops.'),
 ('laminated_stock','Laminated Stock','Glued into something stronger than the tree was, and off the pit sooner.'),
 ('ironwood_setting','Ironwood Setting','The teeth and the patience that wood asks for, standing over it.'),

 ('the_long_pit','The Long Pit','A pit that takes anything the forest can put down.'),
 ('timber_reeve','Timber Reeve','Any pit on the ring at your own pace, and another one running while you do.'),
]

SMELTER_NAMES = [
 ('ore_washing','Ore Washing','Wash the dirt off and the furnace works on metal, not mud.'),
 ('hand_sorting','Hand Sorting','The waste rock never gets a share of the charcoal.'),
 ('bloomery','The Bloomery','A low stack makes a bloom out of ore and time, and asks for less of the time.'),
 ('charge_order','Charge Order','Ore, fuel, ore, fuel. Never two of one.'),
 ('roasting_bed','Roasting Bed','The sulfur goes off before the smelt, if somebody is turning the bed.'),
 ('tuyere_angle','Tuyere Angle','The air goes where the heat is wanted.'),

 ('bellows_pair','Bellows Pair','Two bags, alternating, and the blast never drops.'),
 ('limestone_flux','Limestone Flux','The slag takes the rubbish and leaves the iron behind.'),
 ('slag_tap','Slag Tap','A tap hole, and a furnace that outlives one heat while it is watched.'),
 ('stack_height','Stack Height','A taller stack holds the heat where the ore falls through it.'),
 ('double_crucible','Double Crucible','Two pots on one fire, and both come off together.'),
 ('charcoal_burn','Charcoal Burn','Your own burn, and fuel nobody has to be paid for.'),
 ('preheated_blast','Preheated Blast','Warm air costs nothing and saves a third of the fuel.'),
 ('bloom_squeezing','Bloom Squeezing','Beat the slag out while it is soft and the iron stays.'),

 ('hearth_lining','Hearth Lining','A lining that lasts is a furnace that never cools.'),
 ('finery_forge','Finery Forge','The second fire takes the carbon back out, and takes less of the day.'),
 ('slag_reclaim','Slag Reclaim','There is iron in what was thrown away.'),
 ('continuous_run','Continuous Run','Charged from the top while it pours from the bottom.'),
 ('banded_frame','Banded Frame','Timber and iron banded into one thing, in one heat.'),
 ('ingot_molds','Ingot Molds','A row of molds, and one pour fills them all.'),
 ('blast_timing','Blast Timing','Hard while it is charged, gentle while it is working.'),
 ('wrought_and_cast','Wrought and Cast','Which of the two a job wants. The other one is not made twice.'),

 ('heat_economy','Heat Economy','One fire, all day, and nothing waiting on it.'),
 ('crucible_melt','Crucible Melt','Melted properly, so the grain comes out even the first time.'),
 ('full_burden','Full Burden','Every basket of ore weighed against every basket of fuel.'),
 ('water_bellows','Water Bellows','The river works the blast and never gets tired.'),
 ('alloying','Alloying','Something else in, on purpose, and the pour goes quicker for it.'),
 ('mythril_heat','Mythril Heat','The temperature the humming ore asks for, held by hand.'),

 ('the_long_blast','The Long Blast','Lit in spring, out in autumn.'),
 ('master_of_the_stack','Master of the Stack','First charge at any furnace on the ring, and a stack of your own beside it.'),
]

TANNER_NAMES = [
 ('fleshing_beam','Fleshing Beam','Everything that rots comes off before anything else happens.'),
 ('salt_cure','Salt Cure','A salted hide waits for you. A green one does not.'),
 ('lime_pit','The Lime Pit','A pit that takes the hair off without taking the hide, if the liquor is watched.'),
 ('bark_liquor','Bark Liquor','Weak at the start, strong at the end. Never the other way.'),
 ('drying_loft','Drying Loft','Air enough to dry without cracking, and a hand to turn what hangs.'),
 ('scudding','Scudding','Work the grain clean and the tan takes evenly.'),

 ('pit_rotation','Pit Rotation','Move it through weak, middling and strong in turn.'),
 ('spent_bark','Spent Bark','The second liquor is weaker, not useless.'),
 ('bating','Bating','The step that turns a stiff hide soft. It is done by feel, not by clock.'),
 ('even_immersion','Even Immersion','Nothing folded, nothing touching, nothing missed.'),
 ('layered_pit','Layered Pit','Hide, bark, hide, bark, and the pit holds a stack.'),
 ('oak_and_hemlock','Oak and Hemlock','Which bark suits which hide, decided at the pit rather than after it.'),
 ('warm_liquor','Warm Liquor','A warm pit works in weeks where a cold one takes a season.'),
 ('trim_first','Trim First','Cut the shanks off before they drink the liquor.'),

 ('currying_table','Currying Table','Shaved to thickness, and the piece is finished in one pass.'),
 ('oil_tannage','Oil Tannage','Worked soft with fat rather than bark. It wants working, all day.'),
 ('offcut_glue','Offcut Glue','Trimmings boil down into something a bench will buy.'),
 ('staking','Staking','Worked over the blade until it gives. Then it is leather.'),
 ('alum_tawing','Alum Tawing','White leather no bark can make, and none of it left in the pit.'),
 ('paired_pits','Paired Pits','One filling while the other empties.'),
 ('steady_warmth','Steady Warmth','A tannery that never gets cold never starts over.'),
 ('split_hides','Split Hides','Two skins out of one thickness, which is a knife held by somebody awake.'),

 ('finish_coat','Finish Coat','Dressed, and off the table without a second thought.'),
 ('chamois_work','Chamois Work','The soft grades nothing heavy is made of, and nothing but attention makes.'),
 ('whole_beast','Whole Beast','Horn, hoof, sinew and hide. Nothing carried in is carried out.'),
 ('drum_tanning','Drum Tanning','A turning drum does in a day what a pit does in a season.'),
 ('hardened_leather','Hardened Leather','Boiled leather, which stops things. It stops being leather if it is left.'),
 ('beastfang_curing','Beastfang Curing','Curing a hide that is still trying to bite. Not a job to walk away from.'),

 ('the_deep_pit','The Deep Pit','A pit that has not been empty in living memory.'),
 ('master_tanner','Master Tanner','First pit at any tannery on the ring, and one of your own going beside it.'),
]

MASON_NAMES = [
 ('banker_bench','Banker Bench','Waist height, and the work stops fighting your back.'),
 ('mark_and_measure','Mark and Measure','Twice with the square, once with the chisel.'),
 ('dressing_shed','The Dressing Shed','Stone cut out of the weather, and faster for somebody being in the shed.'),
 ('punch_work','Punch Work','Take the waste off fast before you take it off carefully.'),
 ('template_board','Template Board','Stones that match without measuring, so none are cut to waste.'),
 ('chisel_angle','Chisel Angle','Too steep and it bruises. Too flat and it slides.'),

 ('claw_and_boaster','Claw and Boaster','Three tools in order, none of them doing another\'s work.'),
 ('bed_and_face','Bed and Face','Lay it the way it lay in the ground and it never spalls.'),
 ('sand_saw','Sand Saw','Saw and sand, and cuts a chisel would take a week over.'),
 ('mallet_rhythm','Mallet Rhythm','Light, quick and endless beats heavy and tired.'),
 ('ganged_blocks','Ganged Blocks','Set a row and dress them all to the same line.'),
 ('limestone_and_grit','Limestone and Grit','What a stone will do before you strike it, read at the banker.'),
 ('sharpening_forge','Sharpening Forge','A mason who cannot sharpen is a mason who is waiting.'),
 ('offcut_rubble','Offcut Rubble','What falls off the banker is still walling stone.'),

 ('true_arris','True Arris','A clean edge is the whole difference between block and ashlar.'),
 ('ashlar_course','Ashlar Course','Cut close enough to lay without mortar. That closeness is a person, not a tool.'),
 ('dust_reclaim','Dust Reclaim','Even the grit sells, to the next mason\'s saw.'),
 ('drafted_margin','Drafted Margin','Cut the border first and the middle takes care of itself.'),
 ('molded_work','Molded Work','Profiles, and stone that is more than a box, worked while you stand over it.'),
 ('double_banker','Double Banker','Two benches, one setting-out, both finished together.'),
 ('wet_cutting','Wet Cutting','Water carries the dust away and the blade lasts twice as long.'),
 ('frost_stone','Frost Stone','Stone that only opens in winter, and only for whoever is there when it does.'),

 ('final_rub','Final Rub','Off the banker finished, not off the banker nearly.'),
 ('voussoir_cutting','Voussoir Cutting','The wedge stones an arch stands on. Each one is checked against the last.'),
 ('block_economy','Block Economy','Every face of the block is somebody\'s stone.'),
 ('setting_out_floor','Setting-Out Floor','Draw it full size once and cut it fifty times.'),
 ('carved_work','Carved Work','Stone somebody stops to look at, which means somebody stood over it.'),
 ('obsidian_dressing','Obsidian Dressing','Glass that takes a hand off if it is rushed, and half the block with it.'),

 ('the_great_banker','The Great Banker','A bench that takes a stone two men cannot lift.'),
 ('master_mason','Master Mason','First banker at any yard on the ring, and a second one working while you cut.'),
]

WEAVER_NAMES = [
 ('retting_judgment','Retting Judgment','A day too long and it rots. A day too short and it fights you.'),
 ('sorted_stricks','Sorted Stricks','Long with long, short with short, and nothing on the wrong cloth.'),
 ('the_brake','The Brake','The woody core cracked out of the stalk, and the fibre free that much sooner.'),
 ('scutching_blade','Scutching Blade','Beat it downward and the boon falls away on its own.'),
 ('hackling_combs','Hackling Combs','Combs coarse to fine. Thread that runs true is thread that is not thrown out.'),
 ('distaff_dressing','Distaff Dressing','A well-dressed distaff spins itself.'),

 ('treadle_wheel','Treadle Wheel','Both hands on the fibre, because the foot does the turning.'),
 ('tow_and_line','Tow and Line','The short fibre is coarse cloth, not sweepings.'),
 ('warping_board','Warping Board','A warp measured out before a pick is thrown, which is an hour somebody spends.'),
 ('even_tension','Even Tension','A loom argues with you exactly as much as the warp is uneven.'),
 ('double_shuttle','Double Shuttle','Two shuttles, two cloths, one setting-up.'),
 ('sizing_paste','Sizing Paste','A dressed warp does not fray as it is woven, so less of it is lost.'),
 ('broad_loom','Broad Loom','Wider cloth for the same number of picks.'),
 ('thrums','Thrums','The ends off the loom are cord to somebody.'),

 ('shed_and_pick','Shed and Pick','The rhythm is the whole craft. The rest is setting up.'),
 ('fulling_trough','Fulling Trough','Cloth beaten dense enough to keep the weather out, by whoever does the beating.'),
 ('noil_carding','Noil Carding','What the combs threw out is carded back into something.'),
 ('flying_shuttle','Flying Shuttle','It crosses on its own, and your hands are free for the beat.'),
 ('twill_setting','Twill Setting','A weave that gives where a plain one tears, watched onto the beam.'),
 ('two_beam_warp','Two-Beam Warp','Two warps on one loom, and both come off finished.'),
 ('damp_weaving','Damp Weaving','A damp shed, and the thread stops snapping.'),
 ('nettle_and_flax','Nettle and Flax','The coarse fibres nobody else bothers to ret. Nothing is thrown out.'),

 ('off_the_loom','Off the Loom','Cut it down and it is cloth, not a job half done.'),
 ('napping_teasels','Napping Teasels','Raising a nap is cloth against blanket, and it is raised by hand.'),
 ('whole_stalk','Whole Stalk','Fibre, tow, boon and seed. The field gives up all four.'),
 ('loom_discipline','Loom Discipline','Nothing on the loom is ever waiting on the weaver.'),
 ('figured_weave','Figured Weave','Patterns worked into the cloth rather than onto it. Nobody leaves that loom.'),
 ('silkweave_handling','Silkweave Handling','Thread fine enough to lose sight of, which is why you do not look away.'),

 ('the_long_warp','The Long Warp','A warp set up once and woven for a season.'),
 ('master_weaver','Master Weaver','First loom at any shed on the ring, and another of yours running beside it.'),
]

SMITH_NAMES = [
 ('whetstone_round','Whetstone Round','Ten minutes on the stone before every shift.'),
 ('cold_shut_eye','Cold-Shut Eye','Spot the bad weld before the billet is wasted.'),
 ('dry_seated_haft','Dry-Seated Haft','A haft seated dry outlives one seated green.'),
 ('bellows_rhythm','Bellows Rhythm','Air on the coals in time, not in a panic.'),
 ('offcut_ledger','Offcut Ledger','Nothing leaves the shop as scrap that could be stock.'),
 ('hammer_sense','Hammer Sense','You can hear when a piece has come out right.'),

 ('drawn_temper','Drawn Temper','Color the steel back down and it stops chipping.'),
 ('quench_oil','Quench Oil','Oil where water would crack it.'),
 ('edge_geometry','Edge Geometry','The angle matters more than the arm behind it.'),
 ('charcoal_husbandry','Charcoal Husbandry','A hotter fire from less fuel.'),
 ('billet_folding','Billet Folding','Fold it again and the flaws fold out.'),
 ('anvil_song','Anvil Song','The pitch tells you when to stop.'),
 ('twin_stroke','Twin Stroke','Two blanks under the hammer in one heat.'),
 ('shoulder_fit','Shoulder Fit','Seat the shoulder and the head never works loose.'),

 ('differential_temper','Differential Temper','Hard at the edge, soft at the spine.'),
 ('pattern_weld','Pattern Weld','Layers that show, and hold.'),
 ('hollow_grind','Hollow Grind','Less steel behind the edge, more bite in front of it.'),
 ('forge_economy','Forge Economy','One heat where an apprentice takes three.'),
 ('striker_pair','Striker Pair','A second hammer, timed to yours.'),
 ('crucible_steel','Crucible Steel','Melt it properly and the grain comes out even.'),
 ('blank_stamping','Blank Stamping','Cut the blanks first, finish them together.'),
 ('flux_reading','Flux Reading','Read the flux and you waste no metal to scale.'),

 ('mastersmith_eye','Mastersmith Eye','You know what it will be before it is.'),
 ('socket_and_wedge','Socket and Wedge','A head that cannot fly off is a head you can swing hard.'),
 ('hearth_discipline','Hearth Discipline','The fire is never waiting on you.'),
 ('tang_extension','Tang Extension','Steel all the way through the grip.'),
 ('scale_hammering','Scale Hammering','Knock the scale off while it is still cheap to.'),
 ('heat_ledger','Heat Ledger','Every heat written down, every heat learned from.'),

 ('the_named_blade','The Named Blade','The shop is known for one thing, and this is it.'),
 ('shop_of_record','Shop of Record','Every settlement on the ring sends work to you.'),
]

ARMORER_NAMES = [
 ('measured_cut','Measured Cut','Cut once, to the wearer, not to the pattern.'),
 ('hide_grading','Hide Grading','The bad half of a hide never reaches the bench.'),
 ('doubled_seam','Doubled Seam','The seam gives out long before the plate does.'),
 ('boot_last','Boot Last','Built on a last, not guessed at.'),
 ('scrap_leather','Scrap Leather','Offcuts become straps, gussets and lining.'),
 ('fit_by_eye','Fit by Eye','A glance and you know where it will chafe.'),

 ('waxed_thread','Waxed Thread','Wax it and the wet stops rotting the stitch.'),
 ('riveted_lap','Riveted Lap','Rivets where thread would fail.'),
 ('weight_balance','Weight Balance','Carried on the hips, not hung off the shoulders.'),
 ('tannin_economy','Tannin Economy','The same bark liquor, three times over.'),
 ('padded_arming','Padded Arming','What goes under the plate decides whether it is bearable.'),
 ('flex_panel','Flex Panel','Rigid where it must be, and nowhere else.'),
 ('paired_cutting','Paired Cutting','Two sets nested from one hide.'),
 ('edge_binding','Edge Binding','Bound edges do not fray, and fraying is how armor dies.'),

 ('hardened_boss','Hardened Boss','One thick place where the blows land.'),
 ('articulation','Articulation','Plates that move with the joint instead of against it.'),
 ('sole_stitching','Sole Stitching','Stitched, not glued. Glue is for indoors.'),
 ('pattern_nesting','Pattern Nesting','Every scrap of the hide accounted for before the knife.'),
 ('breathable_lining','Breathable Lining','You can wear it all day, which is the whole point.'),
 ('lamellar_lacing','Lamellar Lacing','Laced so a broken plate is replaced, not the suit.'),
 ('bulk_tanning','Bulk Tanning','The pit holds more than one hide at a time.'),
 ('hide_yield','Hide Yield','Nothing left on the beam but hair and lime.'),

 ('bespoke_fitting','Bespoke Fitting','Made for one prospector, and it shows.'),
 ('load_transfer','Load Transfer','The weight goes into the ground, not into you.'),
 ('road_sole','Road Sole','Built for the hexes between rings.'),
 ('gusset_work','Gusset Work','Room to move exactly where you need it.'),
 ('ankle_support','Ankle Support','The difference over ten hexes is an hour.'),
 ('cold_weather_lining','Cold-Weather Lining','Tundra stops being a reason to turn back.'),

 ('the_second_skin','The Second Skin','You stop noticing you are wearing it.'),
 ('outfitter_of_the_ring','Outfitter of the Ring','Every capital keeps a set of yours in stock.'),
]

ALCHEMIST_NAMES = [
 ('clean_glass','Clean Glass','A dirty flask ruins a good draft.'),
 ('measured_pour','Measured Pour','Weighed, not eyeballed.'),
 ('sealed_stopper','Sealed Stopper','What does not evaporate does not need remaking.'),
 ('cold_steep','Cold Steep','Slower, and it keeps what heat would burn off.'),
 ('spent_mash','Spent Mash','The second pressing is weaker, not worthless.'),
 ('nose_for_it','Nose for It','You can smell a batch going wrong.'),

 ('double_boiler','Double Boiler','Nothing scorches at the bottom of the pot.'),
 ('resin_binding','Resin Binding','Resin holds the mixture together in the flask.'),
 ('potency_titration','Potency Titration','Strong enough to work, weak enough to drink.'),
 ('reflux_still','Reflux Still','The vapour comes back down instead of out the window.'),
 ('sediment_reading','Sediment Reading','What settles tells you what you made.'),
 ('warm_room','Warm Room','A steady room is half the recipe.'),
 ('batch_kettle','Batch Kettle','One fire under a bigger pot.'),
 ('wax_seal','Wax Seal','A waxed flask survives a fall down a shaft.'),

 ('fractional_draw','Fractional Draw','Take only the middle of the run.'),
 ('mineral_mordant','Mineral Mordant','Ore dust fixes what plant matter will not.'),
 ('slow_infusion','Slow Infusion','Two days steeping beats two hours boiling.'),
 ('solvent_recovery','Solvent Recovery','The spirit is worth more than what it dissolved.'),
 ('bench_order','Bench Order','Everything within reach, nothing in the way.'),
 ('crystal_seeding','Crystal Seeding','Drop one crystal in and the rest follow.'),
 ('rack_brewing','Rack Brewing','Six flasks at a time, all the same.'),
 ('lees_reclaim','Lees Reclaim','What sinks to the bottom goes back in the next pot.'),

 ('perfect_draft','Perfect Draft','Every flask off the rack is the good one.'),
 ('deep_reserve','Deep Reserve','Kept properly, it is as good in a month.'),
 ('steady_hand','Steady Hand','No spills, no waste, no second attempt.'),
 ('bitter_tincture','Bitter Tincture','Nobody enjoys it. Everybody finishes it.'),
 ('kiln_dried_stock','Kiln-Dried Stock','Dry ingredients keep, and keep their strength.'),
 ('shelf_life','Shelf Life','Nothing on the shelf is ever wasted.'),

 ('the_house_blend','The House Blend','Copied everywhere, matched nowhere.'),
 ('capital_supplier','Capital Supplier','The bazaar buys everything you can make.'),
]

SHIELD_NAMES = [
 ('braced_stance','Braced Stance','Feet set before the blow lands.'),
 ('rim_work','Rim Work','The edge of a shield is a weapon.'),
 ('shoulder_behind','Shoulder Behind It','The arm holds it; the body stops it.'),
 ('short_steps','Short Steps','Never cross your feet under pressure.'),
 ('boss_punch','Boss Punch','Close range, and it buys you a yard.'),
 ('shoulder_drive','Shoulder Drive','All of you behind the boss, not just the arm.'),

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
 ('rim_first','Rim First','The edge arrives before the arm does.'),

 ('rim_binding','Rim Binding','A bound rim takes the chips so the boards do not.'),
 ('board_swap','Board Swap','Replace the split board and keep the shield.'),
 ('recovered_stance','Recovered Stance','Back behind the boss before it has finished swinging.'),
 ('boss_reseat','Boss Reseat','Reseat it before the rivets work loose.'),
 ('glancing_habit','Glancing Habit','Let it slide off rather than stopping it dead.'),
 ('ringing_blow','Ringing Blow','It stays down a beat longer than it means to.'),

 ('kit_discipline','Kit Discipline','Checked before the walk, not after the fight.'),
 ('the_long_watch','The Long Watch','Gear you look after outlives the ones who do not.'),
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
 ('committed_edge','Committed Edge','Nothing held back for a second attempt.'),
 ('long_guard','Long Guard','Hold the distance you chose.'),
 ('sword_care','Sword Care','A cared-for edge is a longer fight.'),
 ('reading_stance','Reading Stance','You know what they will do from how they stand.'),
 ('economy_of_motion','Economy of Motion','Nothing wasted, nothing telegraphed.'),
 ('duelist_calm','Duelist Calm','The pulse stays where you left it.'),
 ('the_even_hand','The Even Hand','Neither reckless nor slow.'),

 ('flat_of_the_blade','Flat of the Blade','Turn it aside on the flat; the edge is for one thing.'),
 ('opening_read','Opening Read','You saw the gap two rounds ago.'),
 ('scabbard_fit','Scabbard Fit','A loose scabbard files the edge off for free.'),
 ('no_wasted_swing','No Wasted Swing','Every swing that lands on nothing still costs you.'),
 ('pommel_strike','Pommel Strike','Not a cut. It still sits down.'),
 ('rust_watch','Rust Watch','Dry it before you sit down.'),

 ('pommel_seat','Pommel Seat','A tight pommel keeps the tang from working.'),
 ('the_kept_edge','The Kept Edge','The blade you brought home is the blade you keep.'),
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
 ('ash_reading','Ash Reading','What burned tells you what will, and what it is worth.'),
 ('steady_channel','Steady Channel','Held open, not opened repeatedly.'),
 ('silence_before','Silence Before','Nothing works if you are still talking.'),
 ('open_channel','Open Channel','Never quite closed, so never quite cold.'),
 ('long_burn','Long Burn','Less at once, for much longer.'),
 ('the_quiet_word','The Quiet Word','Loud is amateur.'),

 ('overdraw','Overdraw','More than the focus was meant to hold.'),
 ('unspent_charge','Unspent Charge','Nothing left in it afterwards, which is the point.'),
 ('stilling_word','Stilling Word','One syllable, and it forgets what it was doing.'),
 ('measured_draw','Measured Draw','Ask for what the work needs and not a spark more.'),
 ('rest_the_rod','Rest the Rod','Between marks, put it down.'),
 ('clean_the_groove','Clean the Groove','Ash in the cut is what widens the cut.'),

 ('true_bedding','True Bedding','Bedded straight, so nothing levers against the grain.'),
 ('the_kept_word','The Kept Word','A focus lasts as long as the care taken over it.'),
]

# ------------------------------------------------------------- wayfaring tree
# Thirteen straps and two hexes of sight, and the straps are 50 -> 80.
#
# Every pack node used to be one of two kinds -- room, or straps -- and §7.6
# collapsed those into one thing: a strap IS the room, because what sits on one
# is a whole stack. So the two columns that used to mean different things now
# mean the same one, and the tree simply hands out more of it.
#
# Two at a time, and four on the last row: a node's worth is read off its depth
# everywhere else (§7.4.3), and a granted tree has no reason to be the exception.
# Eleven twos and two fours is thirty.
EXPLORER = [
 # row 1 -- lv 2, 4, 6. What a walker learns in the first fortnight.
 ('deep_pockets','Deep Pockets',bag_slots(2),'You stop leaving things behind because there was nowhere to put them.'),
 ('second_strap','Second Strap',bag_slots(2),'A second strap, and two more things you never have to choose between.'),
 ('rolled_blanket','Rolled Blanket',bag_slots(2),'Rolled, not folded. It takes half the room and sheds the rain.'),
 # row 2 -- lv 8, 10, 12. The load starts sitting right, and the country opens up.
 ('even_load','Even Load',bag_slots(2),'Weight over the hips, not the shoulders. The miles get shorter.'),
 ('side_pouch','Side Pouch',bag_slots(2),'Small things stop living at the bottom of the pack.'),
 ('high_ground','High Ground',sight(1),'Take the ridge and the country opens a hex further out.'),
 # row 3 -- lv 14, 16, 18.
 ('bindle','Bindle',bag_slots(2),'An old trick: the pack that hangs outside the pack.'),
 ('sorted_kit','Sorted Kit',bag_slots(2),'Everything has a place, so everything fits in it.'),
 ('tump_line','Tump Line',bag_slots(2),'A strap across the brow. Your neck argues; the load moves.'),
 # row 4 -- lv 20, 22, 24.
 ('packers_knot','Packer\'s Knot',bag_slots(2),'Cinch it once and it stays cinched for thirty miles.'),
 ('outer_pockets','Outer Pockets',bag_slots(2),'What you need on the road no longer lives under what you do not.'),
 ('long_haul','Long Haul',bag_slots(2),'The day you stop counting the hours is the day you carry more of them.'),
 # row 5 -- lv 26, 28, 30. Six thousand hexes in, and the last two are worth double.
 ('drovers_back','Drover\'s Back',bag_slots(4),'Built by the road, and it shows in what you can pick up.'),
 ('tinkers_roll','Tinker\'s Roll',bag_slots(4),'A roll of pockets, and a pocket for everything worth keeping.'),
 ('horizon_line','Horizon Line',sight(1),'You read the far edge of the ground the way others read the near.'),
]

# ---------------------------------------------------------------- what they do
CODES = {
 'woodcutting':  'ykyywd ywdykdyw dykdywwd ywwwyk dw',
 'mining':       'ywdykd ywdwydky wywdykyy wkwdyw dw',
 'hunting':      'ykyywd ykwywyww wykdywdd kyddyw ww',
 'quarrying':    'ykwywd ykwdydww wydwykwd ykwdyw dw',
 'harvesting':   'wyyykd ykwyydwd wywdykwd ykwdyw dw',

 'sawyer':       'pcppsc pcpsbcpc pscspbsc pscsps rr',
 'smelter':      'ccppsp pcssbcpc spcspbsc cpssps rr',
 'tanner':       'psscsp scspbspc pscpsbss pscpss rr',
 'mason':        'ppspcp pcpsbscc pscpsbss pscpsc rr',
 'weaver':       'scppcp pcspbcsc pscpsbsc pscpss rr',

 'smith':        'pcDpcO DDTcOTpD DTpcpOTc ODpppT DT',
 'armorer':      'pcDpcO DDTcODcc DTpcpOpc TDppcT Dp',
 'alchemist':    'pcKpcX pXXcXpbK XKpcpXbc XKcpKK pp',

 'shieldbearer': 'FAFWAP FFWAPVAG FAFCWVFP WWCFAS GL',
 'swordhand':    'AFAFWP FAFAGCVW FPFVAACL FPWASG WG',
 'runecaster':   'AAFFPW AVAFVFCW VFGVACAL PPSAAF VL',
}

TIER_OF = [1] * 6 + [2] * 8 + [3] * 8 + [4] * 6 + [5] * 2


def build(job, names, codes, allowed):
    """Thirty (key, name, desc) plus thirty letters into a tree.

    The letter says what the node does and the depth says how much, so the
    same kind is worth more the deeper it is bought.
    """
    codes = codes.replace(' ', '')
    assert len(names) == 30, (job, len(names))
    assert len(codes) == 30, (job, len(codes))

    out = []
    totals = {}
    for i, ((key, name, desc), code) in enumerate(zip(names, codes)):
        assert code in ALLOWED[allowed], (job, key, code)
        maker, arg, values = VALUES[code]
        value = values[TIER_OF[i] - 1]
        effect = maker(arg, value) if arg is not None else maker(value)
        out.append((key, name, effect, desc))

        kind = effect[0]
        bucket = (kind, effect[1]) if kind in ('stat', 'pair') else kind
        totals[bucket] = round(totals.get(bucket, 0) + value, 6)

    for bucket, total in totals.items():
        kind = bucket[0] if isinstance(bucket, tuple) else bucket
        assert total <= CAPS[kind] + 1e-9, (job, bucket, total, CAPS[kind])

    if allowed == 'battle':
        points = sum(v for b, v in totals.items() if isinstance(b, tuple) and b[0] == 'pair')
        assert points == 20, (job, points)

    return out

# Order matters: this is the order the panel lists them in, and it follows the
# order a character meets them. You walk before you have swung at anything, you
# gather from the first minute, and you refine what you gathered before you
# craft with it.
TREES = {
    'explorer': EXPLORER,

    'woodcutting': build('woodcutting', WOODCUTTING_NAMES, CODES['woodcutting'], 'gathering'),
    'mining': build('mining', MINING_NAMES, CODES['mining'], 'gathering'),
    'hunting': build('hunting', HUNTING_NAMES, CODES['hunting'], 'gathering'),
    'quarrying': build('quarrying', QUARRYING_NAMES, CODES['quarrying'], 'gathering'),
    'harvesting': build('harvesting', HARVESTING_NAMES, CODES['harvesting'], 'gathering'),

    'sawyer': build('sawyer', SAWYER_NAMES, CODES['sawyer'], 'processing'),
    'smelter': build('smelter', SMELTER_NAMES, CODES['smelter'], 'processing'),
    'tanner': build('tanner', TANNER_NAMES, CODES['tanner'], 'processing'),
    'mason': build('mason', MASON_NAMES, CODES['mason'], 'processing'),
    'weaver': build('weaver', WEAVER_NAMES, CODES['weaver'], 'processing'),

    'smith': build('smith', SMITH_NAMES, CODES['smith'], 'weapon'),
    'armorer': build('armorer', ARMORER_NAMES, CODES['armorer'], 'armor'),
    'alchemist': build('alchemist', ALCHEMIST_NAMES, CODES['alchemist'], 'consumable'),

    'shieldbearer': build('shieldbearer', SHIELD_NAMES, CODES['shieldbearer'], 'battle'),
    'swordhand': build('swordhand', SWORD_NAMES, CODES['swordhand'], 'battle'),
    'runecaster': build('runecaster', RUNE_NAMES, CODES['runecaster'], 'battle'),
}

JOBS = [
    ('explorer', 'Explorer', 'wayfaring', 'travel', 'fiber', 'Levels by walking, and by nothing else. Its skills are not bought -- they arrive as you go.'),

    ('woodcutting', 'Woodcutting', 'gathering', 'woodcutting', 'wood', 'Forest work. Its level is the skill you already carry, and it still takes time off the mine.'),
    ('mining', 'Mining', 'gathering', 'mining', 'iron', 'Mountain seams, and the patience a shaft asks for.'),
    ('hunting', 'Hunting', 'gathering', 'hunting', 'pelt', 'Any ground a herd wanders onto. Pelt, horn, sinew, and the animal itself.'),
    ('quarrying', 'Quarrying', 'gathering', 'quarrying', 'stone', 'Badlands stone, cut square at the face.'),
    ('harvesting', 'Harvesting', 'gathering', 'harvesting', 'fiber', 'Grassland fiber, and the field that comes back twice a year.'),

    ('sawyer', 'Sawyer', 'processing', 'woodcutting', 'wood', 'Saws wood into planks. The first bench a prospector ever stands at, and the one the opening arc ends on.'),
    ('smelter', 'Smelter', 'processing', 'mining', 'iron', 'Smelts ore into ingots, and bands ingots to planks for a frame -- the one run that spends two lines.'),
    ('tanner', 'Tanner', 'processing', 'hunting', 'pelt', 'Turns pelt into leather. Slow, and it cannot be hurried by wanting it.'),
    ('mason', 'Mason', 'processing', 'quarrying', 'stone', 'Dresses rough stone square. What the walls and the boots are made of.'),
    ('weaver', 'Weaver', 'processing', 'harvesting', 'fiber', 'Rets, spins and weaves fiber into cloth. The longest chain from raw to refined.'),

    ('smith', 'Smith', 'craft', 'weapon', 'iron', 'Forges the tools every line depends on, and the weapon that walks the road with you.'),
    ('armorer', 'Armorer', 'craft', 'armor', 'pelt', 'Cuts and fits what is worn, which is the only gear that counts on every line at once.'),
    ('alchemist', 'Alchemist', 'craft', 'consumable', 'fiber', 'Brews what is drunk. Everything made here is spent, and the expiry is the sink.'),

    ('shieldbearer', 'Shieldbearer', 'battle', 'defense', 'stone', 'Stands in front, and levels by standing there with a shield on the arm.'),
    ('swordhand', 'Swordhand', 'battle', 'balance', 'wood', 'Trades evenly between the blow and the block. Levels with a sword in hand.'),
    ('runecaster', 'Runecaster', 'battle', 'offense', 'raid', 'Cuts the marks that burn. Levels with a wand in hand.'),
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
    if kind == 'stat':
        return "['kind' => 'stat', 'stat' => %s, 'value' => %s]" % (php_str(target), value)
    if kind == 'pair':
        return "['kind' => 'pair', 'stat' => %s, 'value' => %d]" % (php_str(target), value)
    if kind in ('batch', 'runSlot', 'stackCap', 'sight', 'bagSlots', 'bite',
                'skillCooldown', 'skillStun'):
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
 * character level grants. No two of them are the same tree with different words
 * on it: what a node does is chosen per job, and what it is worth is read off
 * its depth, so a capstone is worth more than an opening node of the same kind. Two numbers and only one of them is power: a job level
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
 * higher one. Every effect that is not a stat thins a §11 sink or buys a
 * capability instead, so each is bounded by its own cap in Balance.
 *
 * And every one of them has a call site. There is no `unlock` kind any more: it
 * was collected into an array nothing read, which made a third of some trees a
 * promise rather than a skill. §7.4 forbids exactly that -- a node is bought
 * with one of a character's scarce points, and the panel has no honest way to
 * say "not yet".
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
     * always had, which both drives §7.3 mine reduction and gates the tree. One
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
     * draw XP from when a pack stops them (§9.5), or -- for Explorer alone --
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
