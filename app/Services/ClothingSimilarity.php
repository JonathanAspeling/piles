<?php

namespace App\Services;

use App\Enums\ClothingType;

/**
 * Which clothing items look alike, for building "similar items" decks.
 *
 * Edit GROUPS to tune it. Each group is a list of items that are easy to
 * confuse at a glance. An item may sit in several groups or in none (it's then
 * unique). Two items are similar when they share at least one group.
 */
class ClothingSimilarity
{
    /**
     * Look-alike groups, keyed by a human-readable name. Socks, Sunglasses
     * and Umbrella are unique and belong to no group.
     *
     * @var array<string, list<ClothingType>>
     */
    public const GROUPS = [
        'Long coats and robes' => [ClothingType::TrenchCoat, ClothingType::Bathrobe, ClothingType::Peacoat],
        'Rainwear' => [ClothingType::Raincoat, ClothingType::Parka],
        'Zip-up jackets' => [ClothingType::PufferJacket, ClothingType::Windbreaker, ClothingType::FleeceJacket, ClothingType::BomberJacket, ClothingType::TrackSuitTop],
        'Collared jackets' => [ClothingType::DenimJacket, ClothingType::LeatherJacket],
        'Tailoring' => [ClothingType::Blazer, ClothingType::SuitJacket, ClothingType::ButtonDownShirt, ClothingType::Vest],
        'Tops' => [ClothingType::TShirt, ClothingType::PoloShirt, ClothingType::TankTop, ClothingType::Blouse, ClothingType::PajamaTop, ClothingType::ButtonDownShirt],
        'Knitwear' => [ClothingType::Sweater, ClothingType::Cardigan, ClothingType::Hoodie],
        'Long trousers' => [ClothingType::Jeans, ClothingType::TrackSuitBottoms, ClothingType::PajamaBottoms],
        'Tight legwear' => [ClothingType::Leggings, ClothingType::YogaPants],
        'Short bottoms' => [ClothingType::Shorts, ClothingType::Skirt],
        'One-pieces' => [ClothingType::Dress, ClothingType::Swimsuit],
        'Bib and straps' => [ClothingType::Overalls, ClothingType::Apron],
        'Everyday shoes' => [ClothingType::Sneakers, ClothingType::JoggingShoes, ClothingType::FormalShoes],
        'Slip-on shoes' => [ClothingType::BalletFlat, ClothingType::HighHeels, ClothingType::Sandals],
        'Boots and skates' => [ClothingType::RainBoots, ClothingType::IceSkates],
        'Brimmed hats' => [ClothingType::CowboyHat, ClothingType::TopHat, ClothingType::SunHat],
        'Close-fitting headwear' => [ClothingType::Cap, ClothingType::Beanie, ClothingType::BikeHelmet, ClothingType::ChefHat],
        'Handwear' => [ClothingType::Gloves, ClothingType::Mittens],
        'Neckwear' => [ClothingType::Tie, ClothingType::BowTie, ClothingType::Scarf],
        'Straps' => [ClothingType::Belt, ClothingType::Suspenders, ClothingType::Wristwatch],
        'Bags' => [ClothingType::Backpack, ClothingType::Handbag],
    ];

    /** @var array<int, list<ClothingType>>|null */
    private static ?array $similarByType = null;

    /**
     * Every item that shares a group with the given one (never the item itself).
     *
     * @return list<ClothingType>
     */
    public static function similarTo(ClothingType $type): array
    {
        return self::similarByType()[$type->value] ?? [];
    }

    public static function areSimilar(ClothingType $first, ClothingType $second): bool
    {
        return $first !== $second && in_array($second, self::similarTo($first), true);
    }

    /**
     * @return array<int, list<ClothingType>>
     */
    private static function similarByType(): array
    {
        if (self::$similarByType !== null) {
            return self::$similarByType;
        }

        $similar = [];

        foreach (self::GROUPS as $group) {
            foreach ($group as $type) {
                foreach ($group as $other) {
                    if ($other !== $type) {
                        $similar[$type->value][$other->value] = $other;
                    }
                }
            }
        }

        return self::$similarByType = array_map(array_values(...), $similar);
    }
}
