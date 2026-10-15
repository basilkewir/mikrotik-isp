<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PortalTicketController extends Controller
{
    public function index(): Response
    {
        /** @var \App\Models\Client $client */
        $client = Auth::guard('client')->user();

        return Inertia::render('Portal/Tickets', [
            'client' => $client,
            'tickets' => $client->tickets()->with('messages')->latest('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var \App\Models\Client $client */
        $client = Auth::guard('client')->user();

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
        ]);

        $ticket = Ticket::create([
            'client_id' => $client->id,
            'subject' => $validated['subject'],
            'priority' => 'normal',
            'status' => Ticket::STATUS_OPEN,
            'last_reply_at' => now(),
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_type' => 'client',
            'client_id' => $client->id,
            'body' => $validated['body'],
        ]);

        return back()->with('success', "Ticket #{$ticket->id} opened.");
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        /** @var \App\Models\Client $client */
        $client = Auth::guard('client')->user();

        if ($ticket->client_id !== $client->id) {
            abort(403);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_type' => 'client',
            'client_id' => $client->id,
            'body' => $validated['body'],
        ]);

        $ticket->update([
            'last_reply_at' => now(),
            'status' => $ticket->status === Ticket::STATUS_CLOSED ? Ticket::STATUS_OPEN : $ticket->status,
        ]);

        return back()->with('success', 'Reply sent.');
    }
}
