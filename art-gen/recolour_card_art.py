"""Recolour marker-coloured card art masters into the four card colours.

A master is generated with each recolourable zone painted in a marker colour:
main = magenta, secondary = cyan, accent = lime. Everything else (outlines,
metal, highlights, halftone ink) is a fixed part and keeps its colour. The
background is plain white and is cut out from the image edges inwards.

Usage:
    python3 art-gen/recolour_card_art.py MASTER_OR_DIR [...] --out DIR [--sheet FILE]

See art-gen/README.md for the full approach.
"""
from __future__ import annotations

import argparse
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFont
from scipy import ndimage

# Hue ranges in degrees for each marker zone. Generators drift off the exact
# hex values (magenta comes out deep pink, lime comes out yellow-green), so
# the ranges are wide. Navy ink (about 240 degrees) must stay outside "main".
ZONE_HUES: dict[str, tuple[float, float]] = {
    "main": (255.0, 350.0),
    "secondary": (165.0, 205.0),
    "accent": (55.0, 145.0),
}
MIN_ZONE_SATURATION = 0.20
MIN_ZONE_VALUE = 0.15
BACKGROUND_MIN_CHANNEL = 225
# Enclosed near-white areas count as background when they look like it: big
# enough, as bright as the background and as flat. Silver highlights and cream
# thread average about 245 with a spread near 8; holes average 254 with about 3.
HOLE_MIN_PIXELS = 300
HOLE_MIN_MEAN = 250
HOLE_MAX_SPREAD = 4.5
# Bright, saturated pixels outside every zone are colours the generator invented
# (gradients, extra tints). Dark navy ink is excluded by the brightness floor.
STRAY_MIN_VALUE = 0.55
STRAY_FLAG_SHARE = 0.02
# The secondary colour marks a sizeable detail (a pocket, a panel), so a master
# where it covers less than this share of the item is worth a look.
MIN_SECONDARY_SHARE = 0.04

Rgb = tuple[int, int, int]
Colourway = dict[str, Rgb]

# Used when no item is known (running this file directly on loose masters).
# The batch uses each item's own colourways from card_art_colours.py.
TRIAL_COLOURWAYS: list[tuple[str, Colourway]] = [
    ("red", {"main": (224, 59, 59), "secondary": (139, 30, 45), "accent": (246, 231, 200)}),
    ("blue", {"main": (45, 127, 249), "secondary": (23, 59, 122), "accent": (255, 159, 67)}),
    ("green", {"main": (47, 168, 90), "secondary": (20, 92, 51), "accent": (255, 209, 102)}),
    ("yellow", {"main": (247, 197, 43), "secondary": (185, 119, 14), "accent": (91, 63, 160)}),
]


def background_mask(rgb: np.ndarray) -> np.ndarray:
    """Near-white pixels connected to the image border, plus enclosed holes (a bag's top loop).

    A hole is told apart from white parts of the item (silver highlights, cream
    thread) by being the same flat pure white as the background.
    """
    near_white = (rgb >= BACKGROUND_MIN_CHANNEL).all(axis=-1)
    labels, count = ndimage.label(near_white)
    border_labels = np.unique(np.concatenate([labels[0], labels[-1], labels[:, 0], labels[:, -1]]))
    border_labels = border_labels[border_labels != 0]
    if count:
        index = np.arange(1, count + 1)
        darkest = rgb.min(axis=-1).astype(float)
        sizes = ndimage.sum(near_white, labels, index)
        means = ndimage.mean(darkest, labels, index)
        spreads = ndimage.standard_deviation(darkest, labels, index)
        holes = index[(sizes >= HOLE_MIN_PIXELS) & (means >= HOLE_MIN_MEAN) & (spreads <= HOLE_MAX_SPREAD)]
        border_labels = np.union1d(border_labels, holes)
    return np.isin(labels, border_labels)


def zone_masks(rgb: np.ndarray) -> dict[str, np.ndarray]:
    hsv = np.array(Image.fromarray(rgb).convert("HSV")).astype(np.float32) / 255.0
    hue_degrees = hsv[..., 0] * 360.0
    is_coloured = (hsv[..., 1] >= MIN_ZONE_SATURATION) & (hsv[..., 2] >= MIN_ZONE_VALUE)
    return {
        zone: is_coloured & (hue_degrees >= low) & (hue_degrees <= high)
        for zone, (low, high) in ZONE_HUES.items()
    }


def stray_colour_share(rgb: np.ndarray, masks: dict[str, np.ndarray], item: np.ndarray) -> float:
    """Share of the item's pixels in a colour the recolour can't map."""
    hsv = np.array(Image.fromarray(rgb).convert("HSV")).astype(np.float32) / 255.0
    in_any_zone = np.logical_or.reduce(list(masks.values()))
    stray = item & ~in_any_zone & (hsv[..., 1] >= MIN_ZONE_SATURATION) & (hsv[..., 2] >= STRAY_MIN_VALUE)
    return stray.sum() / max(item.sum(), 1)


MARKER_COLOURS = {"main": (255, 0, 255), "secondary": (0, 255, 255), "accent": (0, 255, 0)}
# How dark a filled hole is drawn: the inside of a garment, in shadow.
HOLE_FILL_SHADE = 0.7


def fill_holes(rgb: np.ndarray, zone: str) -> np.ndarray:
    """Paint enclosed light gaps (a dress's neckline) as a shaded part of a zone.

    Takes each flat pure-white hole background_mask would cut out, grown into the
    grey shadow along its edge, and fills it with a darkened copy of the zone's
    own fabric from just below, so it recolours as a darker tone with the same
    thread texture. Falls back to the flat marker colour if no patch fits.
    """
    near_white = (rgb >= BACKGROUND_MIN_CHANNEL).all(axis=-1)
    labels, _ = ndimage.label(near_white)
    border = np.unique(np.concatenate([labels[0], labels[-1], labels[:, 0], labels[:, -1]]))
    # The gaps themselves: background_mask's flat pure-white holes, not cream thread.
    holes = background_mask(rgb) & ~np.isin(labels, border[border != 0])
    hsv = np.array(Image.fromarray(rgb).convert("HSV")).astype(float) / 255
    light_neutral = (hsv[..., 1] < 0.25) & (hsv[..., 2] > 0.5)
    regions, _ = ndimage.label(light_neutral)
    gaps = np.isin(regions, np.unique(regions[holes])) & light_neutral
    filled = rgb.copy()
    # Copy the zone's own stitched fabric into the gap, from the nearest spot
    # below it that is all that zone, so the fill keeps the thread texture.
    zone_mask = zone_masks(rgb)[zone]
    ys, xs = np.nonzero(gaps)
    for shift in range(ys.max() - ys.min() + 1, rgb.shape[0] - ys.max()):
        if zone_mask[ys + shift, xs].mean() >= 0.98:
            filled[ys, xs] = (rgb[ys + shift, xs] * HOLE_FILL_SHADE).astype(np.uint8)
            return filled
    filled[gaps] = [round(channel * HOLE_FILL_SHADE) for channel in MARKER_COLOURS[zone]]
    return filled


def recolour(rgb: np.ndarray, masks: dict[str, np.ndarray], scheme: dict[str, Rgb]) -> np.ndarray:
    """Swap each zone to its scheme colour, keeping the master's shading.

    Saturation below the zone's typical value blends toward white (highlights,
    anti-aliasing against white); value below it darkens (shading, ink edges).
    """
    hsv = np.array(Image.fromarray(rgb).convert("HSV")).astype(np.float32) / 255.0
    out = rgb.astype(np.float32).copy()
    for zone, mask in masks.items():
        if not mask.any():
            continue
        reference_saturation = np.median(hsv[..., 1][mask])
        reference_value = np.median(hsv[..., 2][mask])
        saturation_ratio = np.clip(hsv[..., 1][mask] / reference_saturation, 0.0, 1.0)[:, None]
        value_ratio = np.clip(hsv[..., 2][mask] / reference_value, 0.0, 1.1)[:, None]
        target = np.array(scheme[zone], dtype=np.float32)[None, :]
        blended = 255.0 * (1.0 - saturation_ratio) + target * saturation_ratio
        out[mask] = np.clip(blended * value_ratio, 0, 255)
    return out.astype(np.uint8)


def master_label(path: Path) -> str:
    """Folder plus seed: Draw Things names exports after the prompt, ending in the seed."""
    seed = path.stem.rsplit("_", 1)[-1]
    return f"{path.parent.name}-{seed}"


def process_master(
    path: Path, out_dir: Path, colourways: list[tuple[str, Colourway]] | None = None, fill_holes_with: str | None = None
) -> tuple[list[Image.Image], list[str]]:
    """The 4 recoloured cards (CardColor order), and the issues that make the master worth a look."""
    rgb = np.array(Image.open(path).convert("RGB"))
    if fill_holes_with:
        rgb = fill_holes(rgb, fill_holes_with)
    alpha = np.where(background_mask(rgb), 0, 255).astype(np.uint8)
    masks = zone_masks(rgb)
    coverage = {zone: f"{mask.sum() / max((alpha > 0).sum(), 1):.0%}" for zone, mask in masks.items()}
    stray = stray_colour_share(rgb, masks, alpha > 0)
    secondary = masks["secondary"].sum() / max((alpha > 0).sum(), 1)
    issues = []
    if stray >= STRAY_FLAG_SHARE:
        issues.append("stray colours")
    if secondary < MIN_SECONDARY_SHARE:
        issues.append("small secondary")
    print(f"{master_label(path)}: zone coverage {coverage}, stray colour {stray:.1%}{'  <-- CHECK: ' + ', '.join(issues) if issues else ''}")

    cards = []
    for card_color, (name, colourway) in enumerate(colourways or TRIAL_COLOURWAYS):
        rgba = np.dstack([recolour(rgb, masks, colourway), alpha])
        card = Image.fromarray(rgba, mode="RGBA")
        card = card.crop(card.getbbox())
        out_dir.mkdir(parents=True, exist_ok=True)
        card.save(out_dir / f"{master_label(path)}-c{card_color}-{name}.png", optimize=True)
        cards.append(card)
    return cards, issues


def card_face(item: Image.Image, width: int, height: int) -> Image.Image:
    """The item on a neutral card, roughly as the game would draw it."""
    face = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    radius = max(4, width // 8)
    ImageDraw.Draw(face).rounded_rectangle(
        (0, 0, width - 1, height - 1), radius=radius, fill=(251, 247, 239, 255), outline=(214, 205, 190, 255), width=max(1, width // 40)
    )
    padding = max(4, width // 10)
    fitted = item.copy()
    fitted.thumbnail((width - 2 * padding, height - 2 * padding), Image.LANCZOS)
    face.alpha_composite(fitted, ((width - fitted.width) // 2, (height - fitted.height) // 2))
    return face


def contact_sheet(rows: list[tuple[str, Path, list[Image.Image]]], path: Path) -> None:
    """One row per master: the master, 4 large cards, then 4 at in-game size (80x112)."""
    large, small, gap = (180, 240), (80, 112), 16
    row_height = large[1] + 40
    width = gap + large[0] + 4 * (large[0] + gap) + gap * 2 + 4 * (small[0] + gap)
    sheet = Image.new("RGBA", (width, gap + row_height * len(rows)), (255, 255, 255, 255))
    draw = ImageDraw.Draw(sheet)
    font = ImageFont.load_default()
    for index, (label, master_path, cards) in enumerate(rows):
        top = gap + index * row_height
        draw.text((gap, top), label, fill=(60, 60, 60, 255), font=font)
        y = top + 20
        master = Image.open(master_path).convert("RGBA")
        master.thumbnail(large, Image.LANCZOS)
        sheet.alpha_composite(master, (gap, y))
        x = gap * 2 + large[0]
        for card in cards:
            sheet.alpha_composite(card_face(card, *large), (x, y))
            x += large[0] + gap
        x += gap
        for card in cards:
            sheet.alpha_composite(card_face(card, *small), (x, y + large[1] - small[1]))
            x += small[0] + gap
    path.parent.mkdir(parents=True, exist_ok=True)
    sheet.convert("RGB").save(path)
    print(f"Sheet: {path}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("inputs", nargs="+", type=Path, help="Master PNGs, or folders of them")
    parser.add_argument("--out", type=Path, required=True, help="Folder for the recoloured cards")
    parser.add_argument("--sheet", type=Path, help="Optional contact sheet PNG for review")
    args = parser.parse_args()

    masters = sorted(
        path
        for source in args.inputs
        for path in ([source] if source.is_file() else source.glob("*.png"))
    )
    rows = []
    for master in masters:
        cards, issues = process_master(master, args.out)
        rows.append((f"{master_label(master)}{'  CHECK: ' + ', '.join(issues) if issues else ''}", master, cards))
    if args.sheet:
        contact_sheet(rows, args.sheet)


if __name__ == "__main__":
    main()
