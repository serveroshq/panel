<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Start with Agents": for people who came for agents, not server
 * management. Signing up (or in) lands them on Agents, skipping connect a
 * machine; the welcome flow is still there if they open Servers later.
 */
class StartWithAgentsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless((bool) config('serveros.agents'), 404);
        $to = route('dashboard', ['to' => '/agents']);

        if ($request->user() !== null) {
            return redirect()->to($to);
        }
        $request->session()->put('url.intended', $to);

        return to_route('register');
    }
}
