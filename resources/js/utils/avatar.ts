// Seat-keyed so a player keeps the same colour on every client and in every view.
const AVATAR_COLOR_CLASSES = [
    'bg-[#E58A1F]',
    'bg-[#7A7468]',
    'bg-[#2F6FE0]',
    'bg-[#1E9E5A]',
    'bg-[#A9306D]',
    'bg-[#138A96]',
    'bg-[#7C4ABD]',
    'bg-[#C2410C]',
    'bg-[#4D7C0F]',
    'bg-[#BE185D]',
];

export function avatarColorClass(seatIndex: number): string {
    return AVATAR_COLOR_CLASSES[seatIndex % AVATAR_COLOR_CLASSES.length];
}
