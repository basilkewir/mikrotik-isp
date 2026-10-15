<template>
  <div class="min-h-screen bg-slate-950 text-slate-200">
    <header class="border-b border-slate-800 bg-slate-900/60 backdrop-blur sticky top-0 z-10">
      <div class="max-w-3xl mx-auto px-4 py-4 flex items-center justify-between">
        <div>
          <h1 class="text-lg font-extrabold text-white">Support Tickets</h1>
          <p class="text-xs text-slate-400 font-mono">{{ client.name }} · {{ client.client_code }}</p>
        </div>
        <div class="flex items-center gap-2">
          <Link href="/portal" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-semibold transition">Dashboard</Link>
          <button @click="showCreate = true" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-semibold transition">+ New Ticket</button>
        </div>
      </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 py-6 space-y-4">
      <div v-for="ticket in tickets" :key="ticket.id" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase" :class="statusBadge(ticket.status)">{{ ticket.status }}</span>
            <h2 class="font-bold text-white text-sm">#{{ ticket.id }} — {{ ticket.subject }}</h2>
          </div>
          <span class="text-[11px] font-mono text-slate-500">{{ new Date(ticket.created_at).toLocaleDateString() }}</span>
        </div>

        <div class="px-5 py-4 space-y-3 max-h-72 overflow-y-auto">
          <div v-for="message in ticket.messages" :key="message.id" class="rounded-lg px-4 py-3 text-sm" :class="message.author_type === 'client' ? 'bg-slate-950/60 border border-slate-800' : 'bg-indigo-950/40 border border-indigo-900/50'">
            <div class="flex items-center justify-between mb-1">
              <span class="text-[11px] font-bold uppercase" :class="message.author_type === 'client' ? 'text-amber-400' : 'text-indigo-400'">
                {{ message.author_type === 'client' ? 'You' : 'Support' }}
              </span>
              <span class="text-[11px] font-mono text-slate-500">{{ new Date(message.created_at).toLocaleString() }}</span>
            </div>
            <p class="text-slate-200 whitespace-pre-wrap">{{ message.body }}</p>
          </div>
        </div>

        <form v-if="ticket.status !== 'closed'" @submit.prevent="reply(ticket)" class="px-5 py-3 border-t border-slate-800 flex gap-2">
          <input v-model="replyBodies[ticket.id]" type="text" placeholder="Reply…" class="flex-1 bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white" />
          <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-semibold transition">Send</button>
        </form>
      </div>

      <div v-if="!tickets.length" class="bg-slate-900 border border-slate-800 rounded-xl px-5 py-10 text-center text-slate-500 text-sm">
        No support tickets yet.
      </div>
    </main>

    <!-- New ticket modal -->
    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">Open a Ticket</h3>
        <form @submit.prevent="submitCreate" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Subject</label>
            <input v-model="form.subject" type="text" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Message</label>
            <textarea v-model="form.body" rows="4" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white"></textarea>
          </div>
          <div class="flex justify-end gap-3 pt-3 border-t border-slate-700">
            <button type="button" @click="showCreate = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">Submit</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
  client: Object,
  tickets: Array,
});

const showCreate = ref(false);
const replyBodies = reactive({});
const form = ref({ subject: '', body: '' });

const statusBadge = (status) => ({
  open: 'bg-emerald-950 text-emerald-400',
  pending: 'bg-amber-950 text-amber-400',
  closed: 'bg-slate-700 text-slate-300',
}[status] || 'bg-slate-700 text-slate-300');

const submitCreate = () => {
  router.post('/portal/tickets', form.value, {
    preserveScroll: true,
    onSuccess: () => {
      showCreate.value = false;
      form.value = { subject: '', body: '' };
    },
  });
};

const reply = (ticket) => {
  const body = replyBodies[ticket.id];
  if (!body?.trim()) return;
  router.post(`/portal/tickets/${ticket.id}/reply`, { body }, {
    preserveScroll: true,
    onSuccess: () => {
      replyBodies[ticket.id] = '';
    },
  });
};
</script>
