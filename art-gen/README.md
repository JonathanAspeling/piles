# Card art brief

## Goal

Replace the white-silhouette icons with real illustrated card art. Each set uses **one illustration**, and the four cards in a set differ only in the **item's colouring**: a red, blue, green or yellow colour scheme applied to the same drawing. Today the colour is the card's background and the item is white. That matches the rules but not how the physical game reads.

The illustrations use **several colours each**, not one. Accent colours (straps, belt lines, zips, soles, trims, buttons) are part of what makes an item read as that item.

The art ships as **themes** the host picks per game:

| Theme | What it is |
|---|---|
| Classic | the current white silhouettes on coloured card backgrounds, kept as they are |
| Standard | the new illustrated art (name to be decided) |
| Halloween | Standard art with Halloween colour schemes and prints |
| Christmas | Standard art with Christmas colour schemes and prints |

## What does not change

- **Sets are meant to look alike.** Some sets look similar on purpose (the jackets, the shoes) to make the game harder. The new art keeps that similarity. It does not try to make every set obviously different.
- **63 items × 4 colours = 252 cards.** Both enums (`ClothingType`, `CardColor`) and the deck stay the same. Themes are a visual change only.
- **Colouring is the only difference inside a set.** All four cards of a set share the same drawing, framing and detail. Nothing about the shape changes between colours.

## Art requirements

1. **Built on the existing illustrations.** The current silhouettes in `resources/images/cards/{type}.png` are the shape reference. Each new master is generated from its silhouette plus a style prompt, so pose, angle, proportions and framing carry over from the art already in the game. The silhouettes were chosen with the deliberate similarities in mind, so building on them keeps those similarities too.
2. **One master per item, then recoloured.** Image generators can't redraw the same picture four times. So each item is generated once, with each colour area painted in its own flat marker colour. A script then maps the marker colours to each card colour's scheme. That keeps all four cards in a set pixel-identical except for the colouring.
3. **Each master has colour zones.** Every master is split into zones, each painted in its own marker colour:
   - **Main:** the bulk of the item (body fabric, upper of a shoe). Always carries the card colour and covers most of the item, so the card colour reads at a glance.
   - **Secondary:** a sizeable, clearly separate detail (a bib pocket, a front pocket, a hood lining, a sole, contrasting sleeves). Every item has one, so every card is visibly multi-colour. Thin bands such as cuffs and hems are accents, not secondaries. The script flags a master whose secondary covers less than 4% of the item.
   - **Accent:** small details (straps, belt lines, stitching, zip pulls, buttons). A contrast colour that changes with the card colour.
   - **Fixed:** parts that look the same on all four cards (silver buckles, black laces, a watch face). These are never recoloured.

   Every item uses Main and Secondary. Accent and Fixed are optional. Where an item has no natural secondary, the zone sheet adds a plausible design detail (a stripe, a panel, a pocket) that doesn't change the silhouette.
4. **Shading survives the recolour.** Outlines and shading are a separate greyscale layer over the flat zones. The script multiplies each new zone colour under it, so folds and highlights look the same on every card.
5. **Colour schemes, not single colours.** Each card colour (`CardColor` 0–3) is a scheme with one colour for each of main, secondary and accent. All three change with the card colour. The secondary is a contrasting hue, not a shade of the main, so the secondary detail stands out. Example: rust is rust main, deep teal secondary, mustard accent; cobalt is cobalt main, cream secondary, orange accent. The four schemes must be easy to tell apart from the main colour alone.
6. **Neutral card background.** In every theme except Classic, the card face is a light neutral (cream or off-white) so the item's colours stand out. Classic keeps the coloured backgrounds.
7. **Consistent framing.** Every item is centred at the same scale on a transparent background (generated on plain white, then cut out by the recolour script with a flood fill from the edges; the item's outline keeps the fill out), with a portrait ratio matching the current art (about 4:5). It must still read clearly at in-game size (64×96 px on mobile).
8. **Original, not copied.** Use our own style brief and our own silhouettes as references, and our own approved masters for the style LoRA. Don't use scans or photos of the physical cards as inputs or style references, and don't copy the physical card's layout or typography.

## Marker colours

Masters are generated with each recolourable zone in a fixed marker colour. These colours are picked because they almost never appear in real clothing art:

| Zone | Marker |
|---|---|
| Main | magenta `#FF00FF` |
| Secondary | cyan `#00FFFF` |
| Accent | lime `#00FF00` |
| Fixed | natural colours (silver, black, brown, white) |

Image generators won't hit exact hex values. The recolour script snaps each pixel to the nearest marker by hue and leaves everything else as a Fixed part. Some masters will still need a manual touch-up where a zone bleeds.

**Fallback if marker prompting fails in the chosen tool or style:** generate in natural colours, then mark out the zones by hand in an image editor (one layer per zone). That's slower for each item, but it doesn't depend on the generator following colour instructions.

## Image tool: Draw Things

**Chosen tool:** [Draw Things](https://drawthings.ai), a free Mac app that runs image models locally. No subscription, no per-image cost, and no limit on retries.

**Requirements it meets:** it follows a reference image (the silhouette) for shape and a text prompt for style and zone colours. SVG output stays a bonus. Draw Things doesn't produce it, so the masters are raster.

**Hardware:** Apple M2, 16 GB memory, about 41 GB free disk. That rules out the big models (FLUX.2 [dev], full-size Qwen) and favours the small "klein" models. Use the quantised downloads Draw Things offers (8-bit or lower), and generate below 1024 px (for example 768×960). The cards display far smaller than that, and it keeps memory use down.

### Models

All three edit models take the silhouette plus an instruction, for example "turn this into a risograph illustration, fabric in flat magenta…", and keep the input's shape.

| Role | Model | In Draw Things since | Notes |
|---|---|---|---|
| First choice | FLUX.2 [klein] 4B | Jan 2026 (LoRA training since Apr 2026) | Small enough for 16 GB, and the fastest option: one Mac test measured about 30 seconds per edit, likely slower on a base M2. Handles both generation and reference-image editing. Apache 2.0 licence. Draw Things can train LoRAs for it, which pipeline step 6 needs. |
| Worth a look | FLUX.3 [klein] 5.6B | Oct 2026 | The newest small FLUX model. Its editing ability, licence and memory use on 16 GB are unconfirmed. Add it to the trial only if Draw Things lists it as an edit-capable model. |
| Alternative | Qwen Image Edit 2511 | Dec 2025 | Stronger at instruction edits and supports several reference images. Around 20B parameters, so on 16 GB it only runs heavily quantised, and slowly. Use it if klein can't hold the marker colours. |
| Fallback | SDXL with a ControlNet (Canny or Scribble) | long-standing | Uses the silhouette's outline as a hard limit on the shape. Light, at roughly 30–60 seconds per image. Weaker at following long instructions such as zone colours. |

**Which FLUX.2 [klein] 4B download:** the plain "4B" model is the distilled version, built for fast generation in a few steps. Use it for the trial and the full run. "4B Base" is the undistilled version: it takes many more steps and is meant for fine-tuning. Download it only when training the style LoRA in pipeline step 6. Start with the 6-bit version. Try "8-bit S" if memory allows, for slightly better quality.

**Dropped:** FLUX.1 Kontext [dev], added June 2025. It's older, and at about 12B parameters it's both heavier and slower here than FLUX.2 [klein] 4B. FLUX.2 [klein] 9B is also out: it's tight on 16 GB and its licence is non-commercial.

### Style consistency: train a style LoRA

63 items generated one at a time will drift in style. After the pilot, train a small style LoRA (an add-on that teaches the model one style) on 10–15 approved masters. Draw Things can train LoRAs locally. Generate the remaining items with that LoRA turned on.

### Scripting

Draw Things has an optional HTTP API server (off by default, on `localhost:7860`), which community tools describe as compatible with the Automatic1111 API. If it accepts a reference image and the model settings through that API, a script in the repo can run all 63 items from the zone sheet unattended. Confirm in the trial: does the API accept an input image for the edit model or ControlNet, and does it respect the model selected in the app?

Draw Things also has a built-in **Scripts** tab that runs JavaScript inside the app. If the API server can't take the input image, a script there could loop over the zone sheet instead.

## Style brief options

Each option is a starting prompt plus the trade-offs that matter for zone recolouring. Pick one as the Standard theme's style. Halloween and Christmas then build on top of it. Every prompt is sent with the item's silhouette as the shape reference. `{zones}` in a prompt seed means a per-item zone description, for example: "denim fabric in flat magenta, collar and cuffs in flat cyan, buttons and stitching in flat lime, silver rivets".

### A. Ink-outline sticker

- **Look:** a thick dark outline, flat zone fills, one white highlight stroke and a slight paper texture. It looks like a die-cut sticker.
- **Prompt seed:** "flat vector sticker illustration of a {item}, matching the reference silhouette's shape and pose, thick dark ink outline, {zones}, one white highlight, light paper grain, centred, transparent background, no text"
- **Recolour:** easiest. Flat zones swap exactly, and the outline keeps zones from bleeding into each other.
- **Risk:** can look generic. Its character has to come from the drawing itself.

### B. Risograph print

- **Look:** a grainy, limited-ink print with slight misregistration between layers, halftone shading and a hand-printed feel.
- **Prompt seed:** "risograph print illustration of a {item}, matching the reference silhouette's shape and pose, dark navy linework, {zones}, visible grain and halftone shading, slight misregistration, centred, transparent background, no text"
- **Recolour:** good. Grain and halftone sit in the shading layer and survive a multiply recolour. Misregistration fits naturally with separate colour zones.
- **Risk:** the grain gets muddy at small sizes, and small accents (stitching, zip pulls) may vanish. Test at in-game size early.

### C. Mid-century flat

- **Look:** geometric shapes, simplified folds drawn as hard-edged shadow shapes, and a 1950s–60s textile and advertising feel.
- **Prompt seed:** "mid-century modern flat illustration of a {item}, matching the reference silhouette's shape and pose, geometric simplified shapes, hard-edged shadow shapes, {zones}, charcoal details, centred, transparent background, no text"
- **Recolour:** good, as long as the shadow shapes come out as a greyscale overlay rather than as extra colours.
- **Risk:** generators tend to add their own accent colours, which then won't recolour.

### D. Soft 3D toy

- **Look:** a clay or plastic toy render with soft studio lighting and rounded chunky forms.
- **Prompt seed:** "soft 3D clay render of a {item}, matching the reference silhouette's shape and pose, rounded chunky toy proportions, matte {zones}, soft studio lighting, centred, transparent background, no text"
- **Recolour:** hardest. Lighting spreads tones across every zone, so each zone needs hue-shift recolouring rather than an exact swap, and zone edges blur. Yellow tends to go muddy.
- **Risk:** the biggest jump from the current look, and the heaviest image files.

### E. Embroidered patch (not trialled)

- **Look:** a sewn fabric badge: dense satin-stitch thread fills with visible stitch direction, a raised charcoal merrowed edge, and back-stitch lines for seams and hems. Clothing made of thread fits the game's theme.
- **Prompt:** `--style embroidered` in the batch script. Zone colours are phrased as "stitched magenta" and so on.
- **Recolour:** should be good. The thread texture is lightness variation within each zone, which the multiply recolour keeps, and the border and detail lines are charcoal, so they stay fixed.
- **Risk:** satin-stitch sheen can create pale streaks that read as white rather than a light tint. Fine thread texture may also turn to noise at in-game size.

### F. Cut-paper collage (not trialled)

- **Look:** each part cut from its own sheet of matte coloured paper, with slightly uneven scissor edges, layered with small soft shadows between the sheets, and pencil lines for seams and stitching.
- **Prompt:** `--style papercut` in the batch script. Zone colours are phrased as "paper magenta" and so on.
- **Recolour:** should be good. Each paper layer is one flat zone, and the layer shadows are darker tones that recolour as shading.
- **Risk:** the grey shadow under the outermost layer falls onto the background. The cut-out keeps it as a faint grey halo, which may look fine on the cream card face or may need trimming.

## Themes

- **Picked per game.** The host chooses the theme in the lobby when creating the game. Every player in that game sees the same theme.
- **Classic** is the current art, unchanged: white silhouettes on coloured backgrounds. It needs no new assets.
- **Standard** is the new illustrated art in the chosen style.
- **Halloween and Christmas only, for seasonal themes.** Many items already belong to a season (parka, mittens, sun hat, swimsuit, sandals). Weather-season themes like spring or summer would clash with half the deck. Holiday themes work on every item.
  - **Halloween:** spooky prints (bats, cobwebs, stitches) on fabric. Main colours: pumpkin, purple, slime green, bone yellow, with black accents.
  - **Christmas:** festive prints (snowflakes, holly, knit patterns) and a frost edge on the card. Main colours: berry red, ice blue, pine green, warm gold, with cream and white accents.

Rules for the seasonal themes:

- **They reuse the Standard masters.** A seasonal theme adds a detail layer on top of the master (a print or texture over the Main zone) and swaps the colour schemes. It doesn't redraw the item. This keeps the 63 items recognisable across themes.
- **Each theme owns its colour schemes.** Each theme defines four schemes (main, secondary, accent), mapped to `CardColor` 0–3.
- **Similarity is kept.** A theme must not add detail that tells deliberately similar sets apart.

## Trial: one set first

Before the pilot, run everything end to end on a single set. This finds out cheaply whether the approach works (silhouette in, marker-coloured illustration out, recoloured into 4 cards) before committing to the 6-item pilot or the full batch.

**Set:** Overalls (#13). It's the only item that exercises all four zones in one drawing:

- Main: bib and legs
- Secondary: bib pocket and leg cuffs
- Accent: shoulder straps
- Fixed: strap buckles (silver metal)

**Prep (done):** the silhouettes are white on a transparent background, which a model sees as blank. `13.png` has been flattened onto mid-grey at 768×960 as `storage/app/card-art/archive/2026-11-19-trial/13-overalls-input.png`. Everything under `storage/app/card-art/` is ignored by git.

**Runs:**

| Run | Model | Style |
|---|---|---|
| 1 | FLUX.2 [klein] 4B | A. Ink-outline sticker |
| 2 | FLUX.2 [klein] 4B | B. Risograph print |
| 3 | SDXL with ControlNet | A. Ink-outline sticker |
| 4 | SDXL with ControlNet | B. Risograph print |

Styles A and B go first: A is the easiest to recolour and B the most characterful. C and D wait until the pilot. **Runs 3–4 only happen if runs 1–2 fail**, since they need extra downloads (an SDXL model and a ControlNet). Qwen Image Edit 2511 is tried only if both klein runs fail on marker colours. FLUX.3 [klein] gets runs 1–2 repeated if Draw Things lists it as edit-capable. Generate 4 images per run and keep the best one.

### Running it in Draw Things

**Settings (left panel, once):**

1. **Model:** FLUX.2 [klein] 4B (6-bit). Click **Try recommended settings** under the model description. That sets the steps, sampler and guidance the distilled model expects.
2. **LoRA:** Disabled.
3. **Strength:** 100%. With edit models, the canvas image is used as a reference rather than as a starting image to repaint, so full strength is expected.
4. **Image size** (further down in Basic settings): 768×960.
5. **Seed:** leave random.

**Each run:**

1. Clear the canvas (the new-document icon at the top), then drag `13-overalls-input.png` into the Generation Area so it fills it.
2. Replace the whole prompt box with the run's prompt below. Leave the negative prompt empty: the distilled model ignores it.
3. Set the image count next to the generate button to **4**, then press generate. Note roughly how long each image takes.
4. Export the best result (the export icon in the top bar) as PNG to the trial folder, named `run1.png`, `run2.png` and so on.

**If the output ignores the silhouette's shape:** clear the canvas, add the input image to the **Moodboard** (the control under the canvas) instead, and generate again.

**Prompts** (the zone colours are written out in words as well as hex codes, since models follow colour names more reliably):

Run 1, ink-outline sticker:

> Redraw these overalls as a flat vector sticker illustration. Keep the exact shape, pose and proportions of the input. Thick dark ink outline around every part. Bib and legs in flat magenta #FF00FF. Bib pocket and leg cuffs in flat cyan #00FFFF. Shoulder straps in flat lime green #00FF00. Strap buckles in silver metal. One small white highlight stroke on the bib. Plain white background. No text, no shadow on the background.

Run 2, risograph print:

> Redraw these overalls as a risograph print illustration. Keep the exact shape, pose and proportions of the input. Dark navy linework. Bib and legs in flat magenta #FF00FF. Bib pocket and leg cuffs in flat cyan #00FFFF. Shoulder straps in flat lime green #00FF00. Strap buckles in silver metal. Grain and halftone shading in dark navy only, no other colours. Slight misregistration between ink layers. Plain white background. No text.

**Then:**

1. Recolour each kept master into 4 cards, using a first, minimal version of the recolour script.
2. View the 4 cards of each run side by side at in-game size in the `/cards` gallery (needs a small trial view added to the gallery).
3. Try one run through the Draw Things API server to see whether it accepts the input image.

**The trial passes when, for at least one run:**

- the shape matches the silhouette closely enough that it reads as the same overalls;
- the zones come out in clearly separate marker colours that the script can snap without hand-fixing;
- the 4 recoloured cards look like the same drawing in different colours, with the accents changing too;
- it still reads at 64×96 px;
- each image takes a workable amount of time on this Mac.

**What it decides:** the model, the styles to carry into the pilot, and whether marker prompting works or the manual-zones fallback is needed. If no run passes, revisit the approach before generating anything else.

### Trial results (2026-11-19)

Runs 1 and 2 were done with FLUX.2 [klein] 4B (6-bit), 4 images each. Everything from the trial is archived in `storage/app/card-art/archive/2026-11-19-trial/`: masters in `run1/` and `run2/`, recoloured cards in `recoloured/`, and the review sheet `preview.png`.

| Check | Result |
|---|---|
| Matches the silhouette | **Pass**, all 8 images, both styles |
| Marker zones snap without hand-fixing | **Pass.** Magenta drifts to deep pink and lime to yellow-green, and the hue ranges in the script absorb both. Cyan comes out exact. |
| 4 cards look like the same drawing, accents changing | **Pass**, see `preview.png` |
| Reads at in-game size | **Pass** at 80×112 |
| Time per image | **2–3 minutes** on the M2, so 3 images per item for 62 items is about 8 hours: one overnight run |
| API server accepts the input image | not yet tested |

**Findings:**

- **The pocket ignored its zone.** All 8 images kept the bib pocket in the main colour (outlined), not cyan. The model seems to follow the silhouette's own areas more than the prompt. The zone sheet should only give separate zones to parts the silhouette clearly separates, or the pocket should be accepted as main.
- **Risograph survives recolouring.** The navy halftone dots and grain stay as shading on every colour. A faint scatter of light dots appears where navy dots meet the zone edge, visible only zoomed in.
- **One risograph image (seed 1621328026) added a lighter pink pocket.** The script turned it into a lighter tint of each card colour, which looks intentional. It's a lucky outcome rather than a reliable one.
- **Background cut-out works.** Plain white, removed from the edges inwards, leaves no halo on the cream card face.
- **The trial colour schemes are a first draft.** They're defined in `SCHEMES` in `art-gen/recolour_card_art.py`: red/maroon/cream, blue/navy/orange, green/forest/gold, yellow/mustard/purple.

**Recolour script:** `art-gen/recolour_card_art.py`, a first version next to the existing `process_card_art.py`. On its own it recolours loose masters with the trial's 4 colourways: `python3 art-gen/recolour_card_art.py MASTERS... --out DIR --sheet FILE`. The batch calls it with each item's own colourways.

**Runs 3–4 (SDXL) are not needed:** klein passed.

**Decisions after the trial:**

- **Style: B. Risograph print** looked best once recoloured. Mid-century and soft toy were then sampled on Overalls too, to decide which styles get a full run (see Styles below).
- **Model: FLUX.2 [klein] 4B (6-bit).**
- **No separate 6-item pilot.** The overnight batch generates all 62 remaining items, and the morning review in the gallery does the pilot's job. Items that come out wrong get re-run.
- **Style LoRA only if needed.** The trial's style held across all 4 seeds with a fixed prompt. Train a LoRA only if the overnight batch drifts, then re-run the drifted items with it.

### Overnight batch

**Setup (done):**

- **API server:** Draw Things' HTTP API server on port 7860, with the app open and the trial's model and settings loaded.
- **Zone sheet:** `art-gen/card_art_zones.json`, one entry per item with its subject and zones. It gives separate zones only to parts the silhouette clearly separates. **The Gloves (#36) and Mittens (#43) silhouettes are swapped in the current art,** so each uses the other's silhouette (the `silhouette` field). The Classic theme still has them the wrong way round.
- **Colourways:** `art-gen/card_art_colours.py` and `art-gen/card_art_schemes.json`, see Colourways below.
- **Batch script:** `art-gen/generate_card_art.py`, which runs every step for each item:
  1. flatten the silhouette onto grey and request each candidate from the API;
  2. recolour every candidate into the item's 4 colourways;
  3. rebuild the item's review sheet.

  A run ends by drawing the style's overview image. Items that already have enough candidates are skipped, so a stopped run resumes. `--dry-run` prints every prompt, `--only` limits the run to some items, `--count` sets candidates per item (default 2), `--style` picks the style, and `--recolour-only` rebuilds every card, sheet and overview from the existing masters (for example after changing colourways).

**API test results (2026-11-19):**

- **Draw Things' HTTP API works with an input image.** `/sdapi/v1/img2img` with `init_images` keeps the silhouette's shape. Steps, sampler, guidance and model come from the app's current settings: `GET /` on the server returns them, and the script prints them at the start of each run.
- **The seed must be set by the script.** A seed of `-1` is ignored, and the app's last seed is reused. The script picks a random seed for each image and puts it in the filename.
- **2–4.5 minutes per image through the API,** depending on style (soft toy is slowest). The first request of a session is slower while the model warms up.
- **The generator sometimes invents colours.** One Backpack test image painted a purple-to-blue gradient in the pocket. The recolour step reports a "stray colour" share for each master and marks anything over 2% with CHECK on the review sheet and overview. Clean images score under 1%; the gradient one scored 11.5%. Dull, washed-out marker colours (a grey-green handle on one Backpack) are not caught yet.

**Command** (from the repo root, with Draw Things open, the HTTP API server on, and the Mac kept awake):

```
python3 art-gen/generate_card_art.py --style risograph 2>&1 | tee -a storage/app/card-art/styles/risograph/logs/run.log
```

**Candidates, not colours:** `--count` sets how many candidate drawings each item gets. Every candidate is recoloured into all 4 of the item's colourways, so every item always has its 4 cards, and the review picks one candidate per item.

### Output layout

Everything lives in `storage/app/card-art/`, which git ignores:

```
styles/{style}/                    one folder per style: risograph, sticker, midcentury, softtoy
  masters/{type}-{name}/           marker-coloured candidates from Draw Things: {n}-seed{seed}.png
  cards/{type}-{name}/             recoloured cards: {type}-{name}-{n}-seed{seed}-c{0-3}-{family}.png
  review/{type}-{name}.png         per item: each candidate, its 4 cards large and at in-game size, CHECK flags
  overview.png                     every item's candidates in one image, rebuilt at the end of each run
  logs/                            run logs
reference/                         silhouette sheets, colourways.png, style comparison sheets
archive/                           the original trial and the first API test, kept as they were
```

`c0`–`c3` in a card's filename is its `CardColor`, and the family name is the colourway it got.

### Colourways

Each item's 4 cards get 4 different colourways, so no colour stands for one `CardColor` across the deck.

- **Library:** 20 colour families in `art-gen/card_art_colours.py` (tomato, coral, rust, tangerine, mustard, lemon, olive, leaf, forest, mint, teal, sky, cobalt, denim, lilac, violet, berry, pink, slate, cocoa). Each has a main, a contrasting secondary and an accent colour. Very dark and very pale mains are left out, so the item doesn't vanish against the navy linework or the cream card face.
- **Within an item:** the 4 main colours are at least 38 apart (CIE76), so the cards are easy to tell apart.
- **Across items, similar is fine but identical is not.** Each family is nudged slightly for every item that uses it (hue ±7°, lightness ±5%, saturation ±10%), so no two items share an exact colour.
- **No near-duplicate groups.** Two items share at most 2 families, and their groups of 4 mains must be at least 22 apart on average when matched one-to-one. With 20 families, 22 is the strictest limit that still fits all 63 items. A bigger library would allow more.
- **Assignment:** `art-gen/card_art_schemes.json` holds each item's 4 resolved colourways in `CardColor` order. It's generated once, then only read, so it can be edited by hand. `python3 art-gen/card_art_colours.py --force` regenerates it, and `--sheet FILE` draws every item's swatches (`reference/colourways.png`).

### Styles

**Decision (2026-11-19): mid-century and soft toy go forward; risograph and sticker are dropped.** At the size cards are actually shown, sticker, mid-century and risograph look much alike, and mid-century's shading is the best of the three. Both remaining prompts add risograph's non-shading detail work: thin charcoal lines for seams, stitching, hems, pocket edges and panel lines. Soft toy shows them as subtle grooves with dark stitching. The first samples of every style, before the detail lines, are archived in `archive/2026-11-19-style-samples/`.

**Samples v2 (2026-11-19), Overalls in its own colourways, 2 candidates per style:** `reference/style-comparison-v2.png`. Every candidate recoloured cleanly, at 0.0–0.1% stray colour and about 3–4.5 minutes per image.

| Style | Result |
|---|---|
| Mid-century + detail | seams, pocket stitching and fly lines come through; still flat and clean |
| Soft toy + detail | dark stitching on rounded clay; the most dimensional |
| Embroidered patch | the most distinct: the charcoal stitched border and thread texture read even at in-game size; the border takes some of the item's area |
| Cut-paper collage | subtle; reads close to mid-century at card size, with faint pencil lines and no visible halo from the layer shadows |

**Decision (2026-11-19): embroidered patch is the chosen style.** The v2 samples showed a second problem: only one candidate had a clearly separate secondary detail. On Overalls the secondary was the leg cuffs, too small to read. Fixes:

- `card_art_zones.json` now gives every item a sizeable secondary. Thin bands moved to accent. Items without a secondary got one. The generator refuses to run if any item lacks one.
- The secondary prompt sentence now reads "… in cyan #00FFFF, a large and clearly separate part that stands out against the magenta, never blended into it."
- Each colour family's secondary is now a contrasting hue (rust gets deep teal, cobalt gets cream, olive gets burnt orange) instead of a darker shade of the main. Before, the redrawn pockets came out cyan as asked but recoloured as a dark shadow of the main. Families and mains per item are unchanged.
- Masters whose secondary covers under 4% of the item are flagged "small secondary" in the review sheets and the overview.

Re-sample of Overalls and Backpack: both pockets come out as a clear, separate cyan part in all 4 masters and recolour cleanly (0.0–0.2% stray colour, about 2.5 minutes per image). **Details and one candidate per item (2026-11-19).** The best candidates stood out through small neutral flourishes: side buttons and cream piping on the overalls, a tag and a better zip pull on the backpack. These make items in similar sets a little easier to tell apart. Every item in `card_art_zones.json` now has a `details` line. The prompt asks for these in neutral cream, silver or charcoal, so they look the same on all 4 cards. A test with 2 seeds each got the first-named detail every time and the second about half the time, so each line leads with the detail that matters most. A score that counted small neutral shapes didn't agree with picks made by eye, so candidates are still chosen by eye. The overnight run makes 1 candidate per item. Items that come out weak are re-run the next day with `--only`. The picked samples are Overalls seed 2044365790 and Backpack seed 1042062932; the others are in `archive/2026-11-19-style-samples/embroidered-v2-unpicked/` and `embroidered-details-test/`.

**Holes are transparent.** Enclosed gaps, such as a backpack's top loop, are cut out as background too. An enclosed near-white area counts as a hole only when it is at least 300 px and as flat and pure white as the background. That leaves silver highlights and cream thread alone. On the 29 masters so far, only the backpack loops were cut out.

**Embroidered batch (2026-11-19).** 61 items, 1 candidate each, in 138 minutes. Review rejected 5:
- Jeans: stray colour on the hems.
- Dress: the neckline was cut out as a hole. The prompt now asks for the inside back to be filled in.
- Windbreaker, Fleece Jacket, Suspenders: lopsided colour areas.

The embroidered prompt now asks for symmetrical colour areas wherever the input is symmetrical, and those three items' zones say "the same on both sides". The rejected masters are in `archive/2026-11-19-embroidered-rejected/`. The other cut-out holes were all fine: vest, scarf, ice skates, bike helmet, apron and handbag.

The first embroidered samples are archived in `archive/2026-11-19-style-samples/embroidered-v1/`.

All six style prompts stay in the batch script. Overalls samples of each, recoloured into Overalls' colourways, are in `reference/style-comparison-overalls.png`.

| Style | Per image | Recolour result |
|---|---|---|
| Risograph | ~4 min | clean; grain and halftone survive |
| Sticker | ~2–3 min (by hand) | cleanest; flat fills |
| Mid-century | ~3.5 min | clean; subtle hard-edged shadows, close to sticker |
| Soft toy | ~4.5 min | clean; lighting and shading survive the recolour |

Time for a full run of all 63 items:

| Plan | Images | Time |
|---|---|---|
| 1 style, 2 candidates per item | 126 | ~8.5 h |
| 3 styles, 1 candidate per item | 189 | ~12.5 h |
| 3 styles, 2 candidates per item | 378 | ~25 h |

## Pipeline (proposed)

1. **Trial:** one set, as above.
2. **Write the zone sheet:** one line per item listing its zones, for example "Overalls: main = bib and legs; secondary = pockets; accent = straps; fixed = metal clasps". This feeds `{zones}` in the prompts. It replaces `card-art-prompts.txt`, which is written for white silhouettes.
3. **Pilot:** generate 6 items with the trial's winning model and styles. Use 3 deliberately similar items (denim jacket, bomber jacket, leather jacket) and 3 complex multi-zone ones (backpack, ice skates, suspenders). Score each style on: matches the silhouette, matches the style, holds marker colours, stays consistent across all 6. Pick the Standard style.
4. **Recolour script:** grow the trial version into the full one: snap marker colours, then turn each master into 4 coloured PNG/WebP files per theme.
5. **Review in the gallery:** `/cards` gets a theme switcher and the neutral card face, and shows the coloured item in place of the coloured background.
6. **Style LoRA:** train it on 10–15 approved masters from the pilot and early full-run items.
7. **Full run:** generate the remaining masters with the chosen model, style and LoRA, scripted through the API server if the trial confirmed it works.
8. **Seasonal themes:** Halloween and Christmas detail layers and colour schemes, on top of the Standard masters.
9. **Game integration:** a theme picker in the lobby, and the card face, completed-pile fan and opponent views all switch to the game's theme.

## Code impact (for later)

- **Theme on the game:** `game_sessions` gets a `theme` column. The lobby's create-game form gets a theme picker, and the theme is sent to every player with the game state.
- **Two ways to draw a card:** `CardArt.vue` takes `type` only. It will need `color` and `theme`. Classic keeps the coloured background with a white silhouette. Every other theme draws a neutral face with the pre-coloured image.
- **Bundle size:** `resources/js/utils/cardArt.ts` packs every PNG into the JavaScript bundle as text. At 252 coloured images per theme, that would make the bundle far too big. Load images as separate files instead (URL imports, or files in `public/`), and only load the theme the game uses.
- **Card colours:** `CARD_COLOR_CLASSES` keeps styling Classic. In the other themes a `CardColor` has no fixed colour: each item has its own 4 colourways. Anything that draws a colour without the art (the completed-pile fan in `PlayerPile.vue`) needs the item's main colours from `card_art_schemes.json`, exported alongside the images.
- **Asset layout:** `resources/images/cards/{theme}/{type}-{color}.webp` for the generated cards, with the masters stored next to them so themes can be regenerated. Classic keeps its current files.

## Deferred

- **Colour-blind support.** Not addressed for now. Note for later: Christmas puts red and green next to each other as main colours, which is the hardest pair for colour-blind players. A small corner symbol for each colour would solve it across all themes.

## Still open

- **Name for the Standard theme.**
- **Base style and model:** the model is decided by the trial, the style by the pilot.
