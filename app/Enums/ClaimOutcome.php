<?php

namespace App\Enums;

/** What came of a "PILES!" call. */
enum ClaimOutcome
{
    /** Verified: the game is over and the caller won. */
    case Won;

    /** A pile didn't check out: play resumed. */
    case Rejected;

    /** Someone else's call was already being verified. */
    case AlreadyVerifying;
}
