"""Turn a style's recoloured cards into same-size, web-ready files.

Every card is centred on one shared transparent canvas, the size of the largest
card in the style, so all cards line up and keep their relative scale. Then:

  final/full/{type}-{color}.png       full resolution, lossless
  final/web-png/{type}-{color}.png    scaled to --height, 256-colour palette
  final/web-webp/{type}-{color}.webp  scaled to --height, lossy WebP with alpha

{type} is the ClothingType id and {color} the CardColor (0-3), matching the
order of each item's colourways. Each item must have exactly one master left in
masters/ (delete or move the candidates you didn't pick).

Usage:
    python3 art-gen/finalise_card_art.py --style embroidered
"""
from __future__ import annotations

import argparse
import json
import shutil
from pathlib import Path

from PIL import Image

import recolour_card_art

REPO_ROOT = Path(__file__).resolve().parent.parent
ZONES_FILE = Path(__file__).resolve().parent / "card_art_zones.json"
STYLES_DIR = REPO_ROOT / "storage" / "app" / "card-art" / "styles"

# The largest card art in play is 64x96 CSS px (sm:w-20 sm:h-28 minus padding),
# and the gallery's large view about 104x168. 360 px tall covers both on 2-3x screens.
WEB_HEIGHT = 360
WEBP_QUALITY = 85


def item_cards(style_dir: Path, item: dict) -> list[Path]:
    folder = f"{item['type']:02d}-{item['name']}"
    masters = sorted((style_dir / "masters" / folder).glob("*.png"))
    if len(masters) != 1:
        raise SystemExit(f"{folder}: expected 1 master, found {len(masters)}. Keep only the picked one.")
    label = recolour_card_art.master_label(masters[0])
    cards = sorted((style_dir / "cards" / folder).glob(f"{label}-c*.png"))
    if len(cards) != 4:
        raise SystemExit(f"{folder}: expected 4 cards for {label}, found {len(cards)}. Run --recolour-only first.")
    return cards


def folder_size(folder: Path) -> int:
    return sum(path.stat().st_size for path in folder.iterdir())


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--style", default="embroidered", help="Style folder under storage/app/card-art/styles")
    parser.add_argument("--height", type=int, default=WEB_HEIGHT, help="Height of the web copies in px")
    args = parser.parse_args()
    style_dir = STYLES_DIR / args.style
    items = json.loads(ZONES_FILE.read_text())

    cards = {(item["type"], color): Image.open(path).convert("RGBA")
             for item in items
             for color, path in enumerate(item_cards(style_dir, item))}
    width = max(card.width for card in cards.values())
    height = max(card.height for card in cards.values())
    web_size = (round(width * args.height / height), args.height)
    print(f"{len(cards)} cards; shared canvas {width}x{height}, web copies {web_size[0]}x{web_size[1]}")

    out = style_dir / "final"
    folders = {name: out / name for name in ("full", "web-png", "web-webp")}
    for folder in folders.values():
        shutil.rmtree(folder, ignore_errors=True)
        folder.mkdir(parents=True)

    for (item_type, color), card in cards.items():
        canvas = Image.new("RGBA", (width, height), (0, 0, 0, 0))
        canvas.alpha_composite(card, ((width - card.width) // 2, (height - card.height) // 2))
        name = f"{item_type}-{color}"
        canvas.save(folders["full"] / f"{name}.png", optimize=True)
        web = canvas.resize(web_size, Image.LANCZOS)
        web.quantize(colors=256, method=Image.Quantize.FASTOCTREE).save(folders["web-png"] / f"{name}.png", optimize=True)
        web.save(folders["web-webp"] / f"{name}.webp", quality=WEBP_QUALITY, method=6)

    for name, folder in folders.items():
        total = folder_size(folder)
        print(f"{name:>8}: {total / 1e6:6.1f} MB total, {total / len(cards) / 1e3:5.0f} KB per card")


if __name__ == "__main__":
    main()
