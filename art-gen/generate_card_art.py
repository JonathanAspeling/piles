"""Generate card art end to end through the Draw Things API server.

For each item in card_art_zones.json:
  1. flatten its silhouette onto grey and send it with the style prompt to
     Draw Things (img2img), saving each master to masters/{item}/;
  2. recolour every master of the item into its 4 cards in cards/{item}/,
     using the item's own colourways from card_art_colours.py;
  3. rebuild the item's review sheet in review/{item}.png, flagging masters
     with stray colours.
A run ends by drawing overview.png: every item's candidates in one image.

Items that already have enough masters are skipped, so a stopped run resumes
where it left off. Draw Things must be open with the HTTP API server enabled,
and with the model and settings from the trial loaded (see art-gen/README.md).
Output goes to storage/app/card-art/styles/{style}/ unless --out says otherwise.

Usage:
    python3 art-gen/generate_card_art.py --dry-run                # print prompts only
    python3 art-gen/generate_card_art.py --only 50                # one item
    python3 art-gen/generate_card_art.py                          # the full batch, risograph
    python3 art-gen/generate_card_art.py --style midcentury       # the full batch, another style
    python3 art-gen/generate_card_art.py --recolour-only          # redo cards, sheets and overview
"""
from __future__ import annotations

import argparse
import base64
import io
import json
import random
import time
import urllib.error
import urllib.request
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

import card_art_colours
import recolour_card_art

REPO_ROOT = Path(__file__).resolve().parent.parent
ZONES_FILE = Path(__file__).resolve().parent / "card_art_zones.json"
SILHOUETTE_DIR = REPO_ROOT / "resources" / "images" / "cards"
STYLES_DIR = REPO_ROOT / "storage" / "app" / "card-art" / "styles"

WIDTH, HEIGHT = 768, 960
INPUT_BACKGROUND = (128, 128, 128, 255)

# Style options from art-gen/README.md. Each has a prompt template and the word used
# for the zone colours ("flat" suits print styles, "matte" suits 3D).
STYLES: dict[str, tuple[str, str]] = {
    "risograph": (
        "Redraw this illustration of {subject} as a risograph print illustration. "
        "Keep the exact shape, pose and proportions of the input. Dark navy linework. "
        "{zones} "
        "Grain and halftone shading in dark navy only, no other colours. "
        "Slight misregistration between ink layers. Plain white background. No text.",
        "flat",
    ),
    "sticker": (
        "Redraw this illustration of {subject} as a flat vector sticker illustration. "
        "Keep the exact shape, pose and proportions of the input. Thick dark ink outline around every part. "
        "{zones} "
        "One small white highlight stroke. Plain white background. No text, no shadow on the background.",
        "flat",
    ),
    "midcentury": (
        "Redraw this illustration of {subject} as a mid-century modern flat illustration, 1950s textile and advertising style. "
        "Keep the exact shape, pose and proportions of the input. Geometric simplified shapes, charcoal linework. "
        "{zones} "
        "Add fine construction detail as thin charcoal lines: seams, stitching, hems, pocket edges and panel lines. "
        "Folds and shadows drawn as hard-edged darker shapes of the same colour. No gradients, no other colours. "
        "Plain white background. No text.",
        "flat",
    ),
    "softtoy": (
        "Redraw this illustration of {subject} as a soft 3D clay render with rounded, chunky toy proportions. "
        "Keep the exact shape, pose and proportions of the input. "
        "{zones} "
        "Add fine construction detail: seams, stitching, hems, pocket edges and panel lines, "
        "shown as subtle grooves with thin dark charcoal stitching. "
        "Matte clay texture, soft studio lighting, gentle shading in darker tones of the same colours only. "
        "No other colours. Plain white background. No text.",
        "matte",
    ),
    "embroidered": (
        "Redraw this illustration of {subject} as an embroidered fabric patch. "
        "Keep the exact shape, pose and proportions of the input. "
        "Where the input is symmetrical, keep the colour areas and details symmetrical left to right too. "
        "Dense satin-stitch thread fills with visible stitch direction, and a raised charcoal merrowed border around the outline. "
        "{zones} "
        "Seams, stitching, hems, pocket edges and panel lines as thin charcoal back-stitch lines. "
        "Shading only from the thread texture, in darker tones of the same colours. No other colours. "
        "Plain white background. No text.",
        "stitched",
    ),
    "papercut": (
        "Redraw this illustration of {subject} as a layered cut-paper collage. "
        "Keep the exact shape, pose and proportions of the input. "
        "Each part cut from a separate sheet of matte coloured paper with slightly uneven scissor-cut edges, "
        "stacked in layers with small soft grey shadows between them. "
        "{zones} "
        "Seams, stitching, hems and pocket edges as thin charcoal pencil lines drawn on the paper. "
        "Subtle paper fibre texture. No other colours. Plain white background. No text.",
        "paper",
    ),
}
ZONE_SENTENCES = {
    "main": "{part} in {finish} magenta #FF00FF.",
    "secondary": "{part} in {finish} cyan #00FFFF, a large and clearly separate part that stands out against the magenta, never blended into it.",
    "accent": "{part} in {finish} lime green #00FF00.",
    "fixed": "{part}.",
}


# Small neutral flourishes make items in similar sets easier to tell apart. They
# stay off the marker colours, so they look the same on all 4 cards.
DETAILS_SENTENCE = "Small characterful details in neutral cream, silver or charcoal: {details}."
GENERIC_DETAILS = "a few small buttons, rivets, a sewn-on tag or thin piping around pockets and panels, where they suit the item"


def build_prompt(item: dict, style: str) -> str:
    template, finish = STYLES[style]
    zones = " ".join(
        sentence.format(part=item[zone][0].upper() + item[zone][1:], finish=finish)
        for zone, sentence in ZONE_SENTENCES.items()
        if item.get(zone)
    )
    zones += " " + DETAILS_SENTENCE.format(details=item.get("details", GENERIC_DETAILS))
    return template.format(subject=item["subject"], zones=zones)


def input_image(silhouette_type: int) -> str:
    """The silhouette, centred on grey at the generation size, as base64 PNG."""
    item = Image.open(SILHOUETTE_DIR / f"{silhouette_type}.png").convert("RGBA")
    item = item.crop(item.getbbox())
    scale = min(WIDTH * 0.8 / item.width, HEIGHT * 0.85 / item.height)
    item = item.resize((round(item.width * scale), round(item.height * scale)), Image.LANCZOS)
    canvas = Image.new("RGBA", (WIDTH, HEIGHT), INPUT_BACKGROUND)
    canvas.alpha_composite(item, ((WIDTH - item.width) // 2, (HEIGHT - item.height) // 2))
    buffer = io.BytesIO()
    canvas.convert("RGB").save(buffer, format="PNG")
    return base64.b64encode(buffer.getvalue()).decode()


def current_settings(api: str) -> dict:
    """Draw Things reports its current settings at the server root."""
    with urllib.request.urlopen(f"{api}/", timeout=10) as response:
        return json.loads(response.read())


def request_image(api: str, prompt: str, init_image: str, seed: int, timeout: int) -> bytes:
    """One image. Model, steps, sampler and guidance come from the app's current settings."""
    payload = {
        "prompt": prompt,
        "negative_prompt": "",
        "init_images": [init_image],
        "denoising_strength": 1.0,
        "width": WIDTH,
        "height": HEIGHT,
        # Draw Things ignores -1 and reuses the app's seed, so pick one here.
        "seed": seed,
        "batch_size": 1,
    }
    request = urllib.request.Request(
        f"{api}/sdapi/v1/img2img",
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        body = json.loads(response.read())
    return base64.b64decode(body["images"][0])


CandidateResult = tuple[str, list[Image.Image], bool]


def item_folder(item: dict) -> str:
    return f"{item['type']:02d}-{item['name']}"


def recolour_item(out: Path, item: dict) -> list[CandidateResult]:
    """Recolour all of an item's masters into cards/ and rebuild its review sheet.

    Returns (label, 4 cards, flagged) for each master, for the overview.
    """
    masters = sorted((out / "masters" / item_folder(item)).glob("*.png"))
    if not masters:
        return []
    cards_dir = out / "cards" / item_folder(item)
    for old_card in cards_dir.glob("*.png"):
        old_card.unlink()
    colourways = card_art_colours.colourways_for(item["type"])
    rows, results = [], []
    for master in masters:
        cards, issues = recolour_card_art.process_master(master, cards_dir, colourways, fill_holes_with=item.get("fill_holes"))
        label = recolour_card_art.master_label(master)
        rows.append((f"{label}{'  CHECK: ' + ', '.join(issues) if issues else ''}", master, cards))
        results.append((master.stem, cards, bool(issues)))
    recolour_card_art.contact_sheet(rows, out / "review" / f"{item_folder(item)}.png")
    return results


def existing_results(out: Path, item: dict) -> list[CandidateResult]:
    """An item's cards as already recoloured, for the overview."""
    results = []
    for master in sorted((out / "masters" / item_folder(item)).glob("*.png")):
        label = recolour_card_art.master_label(master)
        cards = [Image.open(card) for card in sorted((out / "cards" / item_folder(item)).glob(f"{label}-c*.png"))]
        if len(cards) == 4:
            results.append((master.stem, cards, False))
    return results


def overview(out: Path, style: str, items: list[dict], results: dict[int, list[CandidateResult]]) -> None:
    """Every item's candidates, each as its 4 cards, in one image."""
    card_width, card_height, gap, label_height = 96, 134, 6, 14
    columns = 4
    candidate_rows = max([len(r) for r in results.values()] + [1])
    block_width = 4 * (card_width + gap) + 24
    block_height = 24 + candidate_rows * (card_height + gap + label_height) + 12
    rows = (len(items) + columns - 1) // columns
    sheet = Image.new("RGBA", (columns * block_width + 24, 64 + rows * block_height), (255, 255, 255, 255))
    draw = ImageDraw.Draw(sheet)
    title_font = ImageFont.load_default(size=28)
    font = ImageFont.load_default(size=14)
    small = ImageFont.load_default(size=11)
    total = sum(len(r) for r in results.values())
    draw.text((24, 16), f"{style}: {total} candidates, {len(results)} of {len(items)} items", fill=(30, 30, 30, 255), font=title_font)
    for index, item in enumerate(items):
        left = 24 + (index % columns) * block_width
        top = 64 + (index // columns) * block_height
        draw.text((left, top), f"#{item['type']} {item['name']}", fill=(30, 30, 30, 255), font=font)
        candidates = results.get(item["type"], [])
        if not candidates:
            draw.text((left, top + 30), "not generated yet", fill=(170, 170, 170, 255), font=small)
        for row, (label, cards, flagged) in enumerate(candidates):
            y = top + 24 + row * (card_height + gap + label_height)
            colour = (200, 40, 40, 255) if flagged else (120, 120, 120, 255)
            draw.text((left, y), label + ("  CHECK" if flagged else ""), fill=colour, font=small)
            for slot, card in enumerate(cards):
                face = recolour_card_art.card_face(card, card_width, card_height)
                sheet.alpha_composite(face, (left + slot * (card_width + gap), y + label_height))
    path = out / "overview.png"
    sheet.convert("RGB").save(path)
    print(f"Overview: {path}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--api", default="http://127.0.0.1:7860", help="Draw Things API server address")
    parser.add_argument("--style", choices=sorted(STYLES), default="risograph", help="Style prompt to use")
    parser.add_argument("--out", type=Path, help="Style folder (default storage/app/card-art/styles/{style})")
    parser.add_argument("--count", type=int, default=2, help="Candidate masters per item")
    parser.add_argument("--only", type=int, nargs="+", help="Only these ClothingType ids")
    parser.add_argument("--timeout", type=int, default=900, help="Seconds to wait for one image")
    parser.add_argument("--dry-run", action="store_true", help="Print prompts without generating")
    parser.add_argument("--recolour-only", action="store_true", help="Skip generation; recolour existing masters")
    args = parser.parse_args()
    out = args.out or STYLES_DIR / args.style

    all_items = json.loads(ZONES_FILE.read_text())
    # Every item needs a sizeable secondary detail (a pocket, a panel) so its 4 cards
    # are clearly multi-colour; see "Art requirements" in art-gen/README.md.
    missing = [item["name"] for item in all_items if not item.get("secondary")]
    if missing:
        raise SystemExit(f"No secondary zone in card_art_zones.json for: {', '.join(missing)}")
    items = [item for item in all_items if item["type"] in args.only] if args.only else all_items
    results: dict[int, list[CandidateResult]] = {}

    def finish() -> None:
        for item in all_items:
            if item["type"] not in results:
                found = existing_results(out, item)
                if found:
                    results[item["type"]] = found
        overview(out, args.style, all_items, results)

    if args.recolour_only:
        for item in items:
            found = recolour_item(out, item)
            if found:
                results[item["type"]] = found
        finish()
        return

    if not args.dry_run:
        try:
            settings = current_settings(args.api)
        except (urllib.error.URLError, TimeoutError, ValueError) as error:
            raise SystemExit(f"Draw Things API server not reachable at {args.api}: {error}")
        print(
            f"Draw Things: {settings.get('model')}, {settings.get('steps')} steps, "
            f"guidance {settings.get('guidance_scale')}, sampler {settings.get('sampler')}\n"
        )

    started = time.monotonic()
    generated = 0
    for item in items:
        prompt = build_prompt(item, args.style)
        folder = out / "masters" / item_folder(item)
        existing = len(list(folder.glob("*.png"))) if folder.exists() else 0
        if args.dry_run:
            print(f"#{item['type']} {item['name']} (silhouette {item.get('silhouette', item['type'])}):\n  {prompt}\n")
            continue
        if existing >= args.count:
            print(f"#{item['type']:>2} {item['name']}: already has {existing}, skipping")
            continue

        folder.mkdir(parents=True, exist_ok=True)
        init_image = input_image(item.get("silhouette", item["type"]))
        new_masters = 0
        for number in range(existing + 1, args.count + 1):
            image_started = time.monotonic()
            seed = random.randint(0, 2**32 - 1)
            try:
                image = request_image(args.api, prompt, init_image, seed, args.timeout)
            except (urllib.error.URLError, TimeoutError, KeyError, ValueError) as error:
                print(f"#{item['type']:>2} {item['name']} image {number}: failed ({error}), moving on")
                break
            path = folder / f"{number}-seed{seed}.png"
            path.write_bytes(image)
            new_masters += 1
            generated += 1
            print(f"#{item['type']:>2} {item['name']} image {number}/{args.count}: {path.name} in {time.monotonic() - image_started:.0f}s", flush=True)
        if new_masters:
            results[item["type"]] = recolour_item(out, item)

    if not args.dry_run:
        print(f"\nDone: {generated} images in {(time.monotonic() - started) / 60:.0f} minutes")
        finish()


if __name__ == "__main__":
    main()
