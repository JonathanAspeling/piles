<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GuestSessionController extends Controller
{
    /**
     * Start playing under just a name, with no email or password.
     *
     * The guest is logged in with a remember cookie so the same browser stays
     * signed in as them on later visits.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $guest = User::create(['name' => $validated['name']]);

        Auth::login($guest, remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('lobby.index', absolute: false));
    }
}
