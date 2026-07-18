"""Process Piles card art: keep only pure #ffffff pixels, make rest transparent, compress."""
from __future__ import annotations

from pathlib import Path

import numpy as np
from PIL import Image

REPO_ROOT = Path(__file__).resolve().parent.parent
SOURCE_DIR = REPO_ROOT / "Piles Images"
OUTPUT_DIR = REPO_ROOT / "resources" / "images" / "cards"

FILENAME_TO_ID: dict[str, int] = {
    "socks": 0,
    "jeans": 1,
    "dress": 2,
    "trenchcoat": 3,
    "pufferjacket": 4,
    "blouse": 5,
    "tshirt": 6,
    "shorts": 7,
    "skirt": 8,
    "cardigan": 9,
    "hoodie": 10,
    "sweater": 11,
    "leggings": 12,
    "overalls": 13,
    "swimsuit": 14,
    "tanktop": 15,
    "poloshirt": 16,
    "buttondownshirt": 17,
    "blazer": 18,
    "suitjacket": 19,
    "vest": 20,
    "raincoat": 21,
    "parka": 22,
    "windbreaker": 23,
    "fleecejacket": 24,
    "denimjacket": 25,
    "leatherjacket": 26,
    "bomberjacket": 27,
    "peacoat": 28,
    "tracksuittop": 29,
    "tracksuitbottoms": 30,
    "yogapants": 31,
    "pajamatop": 32,
    "pajamabottoms": 33,
    "bathrobe": 34,
    "scarf": 35,
    "gloves": 36,
    "cap": 37,
    "beanie": 38,
    "belt": 39,
    "tie": 40,
    "bowtie": 41,
    "suspenders": 42,
    "mittens": 43,
    "sneakers": 44,
    "joggingshoes": 45,
    "formalshoes": 46,
}


def process(src: Path, dst: Path) -> tuple[int, int]:
    img = Image.open(src).convert("RGBA")
    arr = np.array(img)

    # Near-white threshold: AI-generated art has anti-aliasing (#fffefd, #fefefe, etc.).
    threshold = 240
    is_white = (arr[..., 0] >= threshold) & (arr[..., 1] >= threshold) & (arr[..., 2] >= threshold)

    # LA (grayscale + alpha): compact and lossless for pure white / transparent.
    la = np.zeros((arr.shape[0], arr.shape[1], 2), dtype=np.uint8)
    la[..., 0] = 255
    la[..., 1] = np.where(is_white, 255, 0)

    out = Image.fromarray(la, mode="LA")

    # Crop to non-transparent bounding box to trim empty margins.
    bbox = out.getbbox()
    if bbox is not None:
        out = out.crop(bbox)

    dst.parent.mkdir(parents=True, exist_ok=True)
    out.save(dst, format="PNG", optimize=True)

    return src.stat().st_size, dst.stat().st_size


def main() -> None:
    total_in = 0
    total_out = 0
    missing: list[str] = []

    for stem, card_id in FILENAME_TO_ID.items():
        src = SOURCE_DIR / f"{stem}.png"
        if not src.exists():
            missing.append(stem)
            continue
        dst = OUTPUT_DIR / f"{card_id}.png"
        in_size, out_size = process(src, dst)
        total_in += in_size
        total_out += out_size
        print(f"{stem:<20} -> {card_id:>2}.png  {in_size / 1024:>8.1f} KB -> {out_size / 1024:>7.1f} KB")

    if missing:
        print("\nMissing source files:", ", ".join(missing))

    print(f"\nTotal: {total_in / 1024 / 1024:.2f} MB -> {total_out / 1024:.1f} KB")


if __name__ == "__main__":
    main()