export enum GameStatus {
    Lobby = 'lobby',
    Countdown = 'countdown',
    Playing = 'playing',
    Verifying = 'verifying',
    Ended = 'ended',
}

export enum CardColor {
    Red = 0,
    Blue = 1,
    Green = 2,
    Yellow = 3,
}

export enum ClothingType {
    Socks = 0,
    Jeans = 1,
    Dress = 2,
    TrenchCoat = 3,
    PufferJacket = 4,
    Blouse = 5,
    TShirt = 6,
    Shorts = 7,
    Skirt = 8,
    Cardigan = 9,
    Hoodie = 10,
    Sweater = 11,
    Leggings = 12,
    Overalls = 13,
    Swimsuit = 14,
    TankTop = 15,
    PoloShirt = 16,
    ButtonDownShirt = 17,
    Blazer = 18,
    SuitJacket = 19,
    Vest = 20,
    Raincoat = 21,
    Parka = 22,
    Windbreaker = 23,
    FleeceJacket = 24,
    DenimJacket = 25,
    LeatherJacket = 26,
    BomberJacket = 27,
    Peacoat = 28,
    TrackSuitTop = 29,
    TrackSuitBottoms = 30,
    YogaPants = 31,
    PajamaTop = 32,
    PajamaBottoms = 33,
    Bathrobe = 34,
    Scarf = 35,
    Gloves = 36,
    Cap = 37,
    Beanie = 38,
    Belt = 39,
    Tie = 40,
    BowTie = 41,
    Suspenders = 42,
    Mittens = 43,
}

export interface Card {
    id: number;
    clothing_type: ClothingType;
    color: CardColor;
}

export interface GameSession {
    id: number;
    code: string;
    status: GameStatus;
    host_user_id: number;
    variant: boolean;
    sets_count: number | null;
    winner_user_id: number | null;
    started_at: string | null;
    ended_at: string | null;
}

export interface GamePlayer {
    id: number;
    game_session_id: number;
    user_id: number;
    seat_index: number;
    is_ready: boolean;
}

export interface LobbyPlayer {
    id: number;
    user_id: number;
    name: string;
    seat_index: number;
    is_ready: boolean;
}

export interface PlayerPile {
    id: number;
    pile_index: number;
    is_completed: boolean;
    cards: Card[];
}

export interface CenterPile {
    id: number;
    pile_index: number;
    version: number;
    top_card: Card | null;
}

export interface OpponentPile {
    id: number;
    pile_index: number;
    is_completed: boolean;
}

export interface OpponentState {
    id: number;
    user_id: number;
    name: string;
    seat_index: number;
    piles: OpponentPile[];
}

export interface LobbyGame {
    id: number;
    code: string;
    host_name: string;
    player_count: number;
    variant: boolean;
}

export interface GameWinner {
    game_player_id: number;
    user_id: number;
    name: string;
}

export const CARD_COLOR_CLASSES: Record<CardColor, string> = {
    [CardColor.Red]: 'bg-red-400 border-red-600',
    [CardColor.Blue]: 'bg-blue-400 border-blue-600',
    [CardColor.Green]: 'bg-green-400 border-green-600',
    [CardColor.Yellow]: 'bg-yellow-300 border-yellow-500',
};

export const CLOTHING_TYPE_LABELS: Record<ClothingType, string> = {
    [ClothingType.Socks]: 'Socks',
    [ClothingType.Jeans]: 'Jeans',
    [ClothingType.Dress]: 'Dress',
    [ClothingType.TrenchCoat]: 'Trench Coat',
    [ClothingType.PufferJacket]: 'Puffer Jacket',
    [ClothingType.Blouse]: 'Blouse',
    [ClothingType.TShirt]: 'T-Shirt',
    [ClothingType.Shorts]: 'Shorts',
    [ClothingType.Skirt]: 'Skirt',
    [ClothingType.Cardigan]: 'Cardigan',
    [ClothingType.Hoodie]: 'Hoodie',
    [ClothingType.Sweater]: 'Sweater',
    [ClothingType.Leggings]: 'Leggings',
    [ClothingType.Overalls]: 'Overalls',
    [ClothingType.Swimsuit]: 'Swimsuit',
    [ClothingType.TankTop]: 'Tank Top',
    [ClothingType.PoloShirt]: 'Polo Shirt',
    [ClothingType.ButtonDownShirt]: 'Button-Down Shirt',
    [ClothingType.Blazer]: 'Blazer',
    [ClothingType.SuitJacket]: 'Suit Jacket',
    [ClothingType.Vest]: 'Vest',
    [ClothingType.Raincoat]: 'Raincoat',
    [ClothingType.Parka]: 'Parka',
    [ClothingType.Windbreaker]: 'Windbreaker',
    [ClothingType.FleeceJacket]: 'Fleece Jacket',
    [ClothingType.DenimJacket]: 'Denim Jacket',
    [ClothingType.LeatherJacket]: 'Leather Jacket',
    [ClothingType.BomberJacket]: 'Bomber Jacket',
    [ClothingType.Peacoat]: 'Peacoat',
    [ClothingType.TrackSuitTop]: 'Tracksuit Top',
    [ClothingType.TrackSuitBottoms]: 'Tracksuit Bottoms',
    [ClothingType.YogaPants]: 'Yoga Pants',
    [ClothingType.PajamaTop]: 'Pajama Top',
    [ClothingType.PajamaBottoms]: 'Pajama Bottoms',
    [ClothingType.Bathrobe]: 'Bathrobe',
    [ClothingType.Scarf]: 'Scarf',
    [ClothingType.Gloves]: 'Gloves',
    [ClothingType.Cap]: 'Cap',
    [ClothingType.Beanie]: 'Beanie',
    [ClothingType.Belt]: 'Belt',
    [ClothingType.Tie]: 'Tie',
    [ClothingType.BowTie]: 'Bow Tie',
    [ClothingType.Suspenders]: 'Suspenders',
    [ClothingType.Mittens]: 'Mittens',
};