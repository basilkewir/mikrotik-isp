<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 class="text-2xl font-extrabold text-white tracking-tight">Support Tickets</h2>
          <p class="text-sm text-slate-400">Subscriber issues and requests — reply directly from here.</p>
        </div>
        <button @click="openCreate" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
          + New Ticket
        </button>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap items-center gap-3">
        <select v-model="filters.status" @change="applyFilters" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">All statuses</option>
          <option value="open">Open</option>
          <option value="pending">Pending</option>
          <option value="closed">Closed</option>
        </select>
        <select v-model="filters.client_id" @change="applyFilters" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">All clients</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} ({{ c.client_code }})</option>
        </select>
      </div>

      <!-- Tickets -->
      <div class="space-y-4">
        <div v-for="ticket in tickets.data" :key="ticket.id" class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
          <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-700/60">
            <div class="flex items-center gap-3">
              <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase border" :class="statusBadge(ticket.status)">{{ ticket.status }}</span>
              <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase border" :class="priorityBadge(ticket.priority)">{{ ticket.priority }}</span>
              <h3 class="font-bold text-white text-sm">#{{ ticket.id }} — {{ ticket.subject }}</h3>
            </div>
            <div class="flex items-center gap-2 text-xs text-slate-400">
              <Link :href="`/clients/${ticket.client_id}`" class="text-indigo-300 hover:text-indigo-200 font-medium">{{ ticket.client?.name }}</Link>
              <select :value="ticket.status" @change="updateStatus(ticket, $event.target.value)" class="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-xs text-white">
                <option value="open">open</option>
                <option value="pending">pending</option>
                <option value="closed">closed</option>
              </select>
            </div>
          </div>

          <div class="px-5 py-4 space-y-3 max-h-80 overflow-y-auto">
            <div v-for="message in ticket.messages" :key="message.id" class="rounded-lg px-4 py-3 text-sm" :class="message.author_type === 'client' ? 'bg-slate-900/70 border border-slate-700/50' : 'bg-indigo-950/40 border border-indigo-900/50'">
              <div class="flex items-center justify-between mb-1">
                <span class="text-[11px] font-bold uppercase" :class="message.author_type === 'client' ? 'text-amber-400' : 'text-indigo-400'">
                  {{ message.author_type === 'client' ? (ticket.client?.name || 'Client') : 'Support' }}
                </span>
                <span class="text-[11px] font-mono text-slate-500">{{ new Date(message.created_at).toLocaleString() }}</span>
              </div>
              <p class="text-slate-200 whitespace-pre-wrap">{{ message.body }}</p>
            </div>
          </div>

          <form @submit.prevent="reply(ticket)" class="px-5 py-3 border-t border-slate-700/60 flex gap-2">
            <input v-model="replyBodies[ticket.id]" type="text" placeholder="Write a reply…" class="flex-1 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">Send</button>
          </form>
        </div>

        <div v-if="!tickets.data.length" class="bg-slate-800 border border-slate-700 rounded-xl px-5 py-10 text-center text-slate-500 text-sm">
          No tickets found.
        </div>
      </div>

      <div v-if="tickets.links?.length > 3" class="flex items-center justify-between px-1">
        <span class="text-xs text-slate-500">Showing {{ tickets.from }}–{{ tickets.to }} of {{ tickets.total }}</span>
        <div class="flex gap-1">
          <button
            v-for="(link, i) in tickets.links"
            :key="i"
            v-html="link.label"
            @click="link.url && inertiaRouter.get(link.url, filters, { preserveState: true, replace: true })"
            class="px-2.5 py-1 rounded text-xs font-medium transition"
            :class="link.active ? 'bg-indigo-600 text-white' : (link.url ? 'bg-slate-800 text-slate-300 hover:bg-slate-700' : 'bg-slate-800/50 text-slate-600')"
          ></button>
        </div>
      </div>
    </div>

    <!-- Create Modal -->
    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">New Ticket</h3>
        <form @submit.prevent="submitCreate" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Client</label>
            <select v-model="form.client_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
              <option value="" disabled>Select…</option>
              <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} ({{ c.client_code }})</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Subject</label>
            <input v-model="form.subject" type="text" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Priority</label>
            <select v-model="form.priority" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Message</label>
            <textarea v-model="form.body" rows="3" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white"></textarea>
          </div>
          <div class="flex justify-end gap-3 pt-3 border-t border-slate-700/60">
            <button type="button" @click="showCreate = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">Create</button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Link, router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
  tickets: Object,
  filters: Object,
  clients: Array,
});

const showCreate = ref(false);
const replyBodies = reactive({});

const filters = reactive({
  status: props.filters?.status || '',
  client_id: props.filters?.client_id || '',
});

const form = ref({ client_id: '', subject: '', priority: 'normal', body: '' });

const statusBadge = (status) => ({
  open: 'bg-emerald-950 text-emerald-400 border-emerald-800',
  pending: 'bg-amber-950 text-amber-400 border-amber-800',
  closed: 'bg-slate-700 text-slate-300 border-slate-600',
}[status] || 'bg-slate-700 text-slate-300 border-slate-600');

const priorityBadge = (priority) => ({
  low: 'bg-slate-700 text-slate-300 border-slate-600',
  normal: 'bg-indigo-950 text-indigo-400 border-indigo-800',
  high: 'bg-rose-950 text-rose-400 border-rose-800',
}[priority] || 'bg-slate-700 text-slate-300 border-slate-600');

const applyFilters = () => {
  inertiaRouter.get('/tickets', filters, { preserveState: true, replace: true });
};

const openCreate = () => {
  form.value = { client_id: '', subject: '', priority: 'normal', body: '' };
  showCreate.value = true;
};

const submitCreate = () => {
  inertiaRouter.post('/tickets', form.value, {
    preserveScroll: true,
    onSuccess: () => {
      showCreate.value = false;
    },
  });
};

const reply = (ticket) => {
  const body = replyBodies[ticket.id];
  if (!body?.trim()) return;
  inertiaRouter.post(`/tickets/${ticket.id}/reply`, { body }, {
    preserveScroll: true,
    onSuccess: () => {
      replyBodies[ticket.id] = '';
    },
  });
};

const updateStatus = (ticket, status) => {
  inertiaRouter.put(`/tickets/${ticket.id}/status`, { status }, { preserveScroll: true });
};
</script>
