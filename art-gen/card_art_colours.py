"""Colourways for card art: a shared library, and 4 per item.

Each item's 4 cards (CardColor 0-3) get 4 different colourways from the
library, so no colour stands for one CardColor across the deck. The rules:

- within an item, the 4 main colours are far apart, so its cards are easy to
  tell apart;
- across items, colours may be similar but never identical: each colourway is
  nudged slightly (hue, lightness, saturation) for every item that uses it;
- no two items get a near-identical group of 4: groups may share at most 2
  colour families, and their mains must differ overall (best one-to-one match).

The resolved colours are written to card_art_schemes.json once and then only
read, so they can be edited by hand. Run this file to create it (or --force to redo it)
and to draw a swatch sheet of every item's 4 colourways.

Usage:
    python3 art-gen/card_art_colours.py [--force] [--sheet FILE]
"""
from __future__ import annotations

import argparse
import colorsys
import json
import random
from itertools import combinations, permutations
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

Rgb = tuple[int, int, int]
Colourway = dict[str, Rgb]

SCRIPTS_DIR = Path(__file__).resolve().parent
ZONES_FILE = SCRIPTS_DIR / "card_art_zones.json"
SCHEMES_FILE = SCRIPTS_DIR / "card_art_schemes.json"

# main = most of the item, secondary = a contrasting hue for its sizeable
# detail (a pocket, a panel), accent = a contrast colour for small details. Very dark and very pale mains
# are left out: they'd vanish against the navy linework or the cream card face.
COLOURWAYS: dict[str, Colourway] = {
    "tomato": {"main": (224, 59, 59), "secondary": (35, 60, 110), "accent": (246, 231, 200)},
    "coral": {"main": (255, 127, 102), "secondary": (30, 130, 135), "accent": (255, 236, 179)},
    "rust": {"main": (190, 85, 40), "secondary": (45, 95, 110), "accent": (242, 201, 76)},
    "tangerine": {"main": (247, 140, 30), "secondary": (245, 232, 205), "accent": (40, 70, 130)},
    "mustard": {"main": (226, 176, 36), "secondary": (105, 65, 35), "accent": (91, 63, 160)},
    "lemon": {"main": (247, 215, 60), "secondary": (70, 150, 210), "accent": (220, 70, 90)},
    "olive": {"main": (135, 135, 50), "secondary": (175, 85, 40), "accent": (240, 220, 170)},
    "leaf": {"main": (120, 190, 60), "secondary": (240, 140, 40), "accent": (250, 240, 200)},
    "forest": {"main": (47, 140, 80), "secondary": (200, 60, 50), "accent": (255, 209, 102)},
    "mint": {"main": (110, 210, 170), "secondary": (40, 60, 110), "accent": (240, 90, 110)},
    "teal": {"main": (20, 150, 150), "secondary": (245, 230, 200), "accent": (255, 180, 80)},
    "sky": {"main": (90, 180, 235), "secondary": (245, 200, 50), "accent": (255, 240, 200)},
    "cobalt": {"main": (45, 110, 230), "secondary": (240, 232, 215), "accent": (255, 159, 67)},
    "denim": {"main": (75, 115, 170), "secondary": (190, 120, 60), "accent": (240, 230, 210)},
    "lilac": {"main": (175, 145, 225), "secondary": (110, 200, 170), "accent": (255, 230, 150)},
    "violet": {"main": (125, 75, 190), "secondary": (240, 130, 170), "accent": (250, 200, 90)},
    "berry": {"main": (165, 45, 110), "secondary": (225, 175, 40), "accent": (250, 205, 215)},
    "pink": {"main": (240, 120, 170), "secondary": (30, 140, 140), "accent": (255, 240, 220)},
    "slate": {"main": (115, 130, 145), "secondary": (200, 60, 55), "accent": (240, 180, 70)},
    "cocoa": {"main": (135, 90, 58), "secondary": (110, 175, 220), "accent": (240, 200, 140)},
}

# Minimum CIE76 distance between the 4 main colours of one item.
MIN_MAIN_DISTANCE = 38.0
# Two items' groups: at most this many shared families, and at least this mean
# distance between their mains when matched one-to-one as closely as possible.
MAX_SHARED_FAMILIES = 2
MIN_GROUP_DISTANCE = 22.0
# Per-item nudge applied to a family's colours: hue in degrees, lightness and
# saturation as fractions.
HUE_JITTER, LIGHTNESS_JITTER, SATURATION_JITTER = 7.0, 0.05, 0.10
ASSIGNMENT_SEED = 20261119


def to_lab(rgb: Rgb) -> tuple[float, float, float]:
    def linear(channel: int) -> float:
        value = channel / 255
        return value / 12.92 if value <= 0.04045 else ((value + 0.055) / 1.055) ** 2.4

    red, green, blue = (linear(channel) for channel in rgb)
    x = (0.4124 * red + 0.3576 * green + 0.1805 * blue) / 0.95047
    y = 0.2126 * red + 0.7152 * green + 0.0722 * blue
    z = (0.0193 * red + 0.1192 * green + 0.9505 * blue) / 1.08883

    def f(value: float) -> float:
        return value ** (1 / 3) if value > 0.008856 else 7.787 * value + 16 / 116

    return 116 * f(y) - 16, 500 * (f(x) - f(y)), 200 * (f(y) - f(z))


def lab_distance(first: Rgb, second: Rgb) -> float:
    a, b = to_lab(first), to_lab(second)
    return sum((p - q) ** 2 for p, q in zip(a, b)) ** 0.5


def main_distance(first: str, second: str) -> float:
    return lab_distance(COLOURWAYS[first]["main"], COLOURWAYS[second]["main"])


def group_distance(first: list[Rgb], second: list[Rgb]) -> float:
    """Mean distance between two groups' mains, matched one-to-one as closely as possible."""
    return min(
        sum(lab_distance(a, b) for a, b in zip(first, order)) / len(first)
        for order in permutations(second)
    )


def nudge(rgb: Rgb, hue_shift: float, lightness_scale: float, saturation_scale: float) -> Rgb:
    hue, lightness, saturation = colorsys.rgb_to_hls(*(channel / 255 for channel in rgb))
    hue = (hue + hue_shift / 360) % 1.0
    lightness = min(max(lightness * lightness_scale, 0.0), 1.0)
    saturation = min(max(saturation * saturation_scale, 0.0), 1.0)
    return tuple(round(channel * 255) for channel in colorsys.hls_to_rgb(hue, lightness, saturation))


def item_variant(name: str, rng: random.Random) -> Colourway:
    """The family's colours nudged for one item. Main and secondary move together so they stay balanced."""
    hue_shift = rng.uniform(-HUE_JITTER, HUE_JITTER)
    lightness_scale = 1 + rng.uniform(-LIGHTNESS_JITTER, LIGHTNESS_JITTER)
    saturation_scale = 1 + rng.uniform(-SATURATION_JITTER, SATURATION_JITTER)
    family = COLOURWAYS[name]
    return {
        "main": nudge(family["main"], hue_shift, lightness_scale, saturation_scale),
        "secondary": nudge(family["secondary"], hue_shift, lightness_scale, saturation_scale),
        "accent": nudge(family["accent"], rng.uniform(-HUE_JITTER, HUE_JITTER) / 2, 1.0, 1.0),
    }


def assign(item_types: list[int]) -> dict[int, list[dict]]:
    """4 well-separated colourways per item, each a per-item variant of a library family."""
    rng = random.Random(ASSIGNMENT_SEED)
    valid_sets = [
        combo
        for combo in combinations(COLOURWAYS, 4)
        if all(main_distance(a, b) >= MIN_MAIN_DISTANCE for a, b in combinations(combo, 2))
    ]
    usage = {name: 0 for name in COLOURWAYS}
    assigned: list[tuple[set[str], list[Rgb]]] = []
    assignment = {}
    for item_type in item_types:
        candidates = valid_sets[:]
        rng.shuffle(candidates)
        candidates.sort(key=lambda combo: sum(usage[name] for name in combo))
        for combo in candidates:
            if any(len(set(combo) & families) > MAX_SHARED_FAMILIES for families, _ in assigned):
                continue
            variants = [item_variant(name, rng) for name in combo]
            mains = [variant["main"] for variant in variants]
            if any(lab_distance(a, b) < MIN_MAIN_DISTANCE for a, b in combinations(mains, 2)):
                continue
            if any(group_distance(mains, other) < MIN_GROUP_DISTANCE for _, other in assigned):
                continue
            break
        else:
            raise SystemExit(f"No colourway group fits item {item_type}; loosen the limits")
        assigned.append((set(combo), mains))
        for name in combo:
            usage[name] += 1
        order = list(zip(combo, variants))
        rng.shuffle(order)
        assignment[item_type] = [{"family": name, **{zone: list(rgb) for zone, rgb in variant.items()}} for name, variant in order]
    return assignment


def load_assignment() -> dict[int, list[dict]]:
    return {int(item_type): colourways for item_type, colourways in json.loads(SCHEMES_FILE.read_text()).items()}


def colourways_for(item_type: int) -> list[tuple[str, Colourway]]:
    """The item's 4 colourways, in CardColor order 0-3, as (family name, colours)."""
    return [
        (entry["family"], {zone: tuple(entry[zone]) for zone in ("main", "secondary", "accent")})
        for entry in load_assignment()[item_type]
    ]


def swatch_sheet(assignment: dict[int, list[dict]], names: dict[int, str], path: Path) -> None:
    columns, cell_width, cell_height, swatch = 4, 330, 64, 44
    rows = (len(assignment) + columns - 1) // columns
    sheet = Image.new("RGB", (columns * cell_width + 20, rows * cell_height + 20), (251, 247, 239))
    draw = ImageDraw.Draw(sheet)
    font = ImageFont.load_default(size=12)
    for index, item_type in enumerate(sorted(assignment)):
        x = 10 + (index % columns) * cell_width
        y = 10 + (index // columns) * cell_height
        draw.text((x, y), f"#{item_type} {names[item_type]}", fill=(40, 40, 40), font=font)
        for slot, (_, colourway) in enumerate(colourways_for(item_type)):
            left = x + slot * (swatch + 30)
            draw.rectangle((left, y + 16, left + swatch, y + 16 + 30), fill=colourway["main"])
            draw.rectangle((left + swatch - 14, y + 16, left + swatch, y + 31), fill=colourway["secondary"])
            draw.rectangle((left + swatch - 14, y + 31, left + swatch, y + 46), fill=colourway["accent"])
    path.parent.mkdir(parents=True, exist_ok=True)
    sheet.save(path)
    print(f"Swatches: {path}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--force", action="store_true", help="Redo the assignment even if the file exists")
    parser.add_argument("--sheet", type=Path, help="Write a swatch sheet of every item's colourways")
    args = parser.parse_args()

    items = json.loads(ZONES_FILE.read_text())
    names = {item["type"]: item["name"] for item in items}
    if args.force or not SCHEMES_FILE.exists():
        assignment = assign(sorted(names))
        SCHEMES_FILE.write_text(json.dumps({str(k): v for k, v in assignment.items()}, indent=2) + "\n")
        print(f"Wrote {SCHEMES_FILE.name}")
    assignment = load_assignment()

    usage: dict[str, int] = {name: 0 for name in COLOURWAYS}
    all_mains = []
    for colourways in assignment.values():
        for entry in colourways:
            usage[entry["family"]] += 1
            all_mains.append(tuple(entry["main"]))
    groups = [[tuple(entry["main"]) for entry in colourways] for colourways in assignment.values()]
    closest = min(group_distance(a, b) for a, b in combinations(groups, 2))
    most_shared = max(
        len({e["family"] for e in a} & {e["family"] for e in b})
        for a, b in combinations(assignment.values(), 2)
    )
    print("Uses per family:", ", ".join(f"{name} {count}" for name, count in usage.items()))
    print(f"Identical main colours across the deck: {len(all_mains) - len(set(all_mains))}")
    print(f"Closest pair of groups: {closest:.1f} (minimum {MIN_GROUP_DISTANCE}); most families shared: {most_shared}")
    if args.sheet:
        swatch_sheet(assignment, names, args.sheet)


if __name__ == "__main__":
    main()
