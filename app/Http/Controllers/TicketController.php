<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Ticket::with(['client:id,name,client_code', 'messages'])->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        return Inertia::render('Tickets/Index', [
            'tickets' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['status', 'client_id']),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'client_code']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'subject' => 'required|string|max:255',
            'priority' => 'required|in:low,normal,high',
            'body' => 'required|string|max:5000',
        ]);

        $ticket = Ticket::create([
            'client_id' => $validated['client_id'],
            'subject' => $validated['subject'],
            'priority' => $validated['priority'],
            'status' => Ticket::STATUS_OPEN,
            'last_reply_at' => now(),
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_type' => 'staff',
            'body' => $validated['body'],
        ]);

        return back()->with('success', "Ticket #{$ticket->id} created.");
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'author_type' => 'staff',
            'body' => $validated['body'],
        ]);

        $ticket->update([
            'last_reply_at' => now(),
            'status' => $ticket->status === Ticket::STATUS_CLOSED ? Ticket::STATUS_OPEN : $ticket->status,
        ]);

        return back()->with('success', 'Reply added.');
    }

    public function updateStatus(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:open,pending,closed',
        ]);

        $ticket->update(['status' => $validated['status']]);

        return back()->with('success', "Ticket #{$ticket->id} marked {$validated['status']}.");
    }
}
