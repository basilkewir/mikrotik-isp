<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 class="text-2xl font-extrabold text-white tracking-tight">Invoices</h2>
          <p class="text-sm text-slate-400">Bill subscribers for services — pay directly from wallet or via Mobile Money.</p>
        </div>
        <button @click="openCreate" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
          + New Invoice
        </button>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap items-center gap-3">
        <select v-model="filters.status" @change="applyFilters" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">All statuses</option>
          <option value="unpaid">Unpaid</option>
          <option value="paid">Paid</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <select v-model="filters.client_id" @change="applyFilters" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">All clients</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} ({{ c.client_code }})</option>
        </select>
      </div>

      <!-- Table -->
      <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-slate-900/60 text-[11px] uppercase tracking-wider text-slate-400">
              <tr>
                <th class="px-5 py-3">Number</th>
                <th class="px-5 py-3">Client</th>
                <th class="px-5 py-3">Period</th>
                <th class="px-5 py-3">Items</th>
                <th class="px-5 py-3 text-right">Amount</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3">Paid At</th>
                <th class="px-5 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-700/50">
              <tr v-for="invoice in invoices.data" :key="invoice.id" class="hover:bg-slate-700/30 transition">
                <td class="px-5 py-3 font-mono font-bold text-white text-xs">{{ invoice.number }}</td>
                <td class="px-5 py-3 text-xs">
                  <Link :href="`/clients/${invoice.client_id}`" class="text-indigo-300 hover:text-indigo-200 font-medium">{{ invoice.client?.name }}</Link>
                  <span class="block text-[11px] font-mono text-slate-500">{{ invoice.client?.client_code }}</span>
                </td>
                <td class="px-5 py-3 text-[11px] font-mono text-slate-400">
                  <template v-if="invoice.period_start">
                    {{ invoice.period_start }} → {{ invoice.period_end || '…' }}
                  </template>
                  <span v-else>—</span>
                </td>
                <td class="px-5 py-3 text-xs text-slate-300">
                  <div v-for="item in invoice.items" :key="item.id" class="flex justify-between gap-3">
                    <span>{{ item.description }}</span>
                    <span class="font-mono text-slate-400">{{ Number(item.amount).toLocaleString('fr-FR') }}</span>
                  </div>
                </td>
                <td class="px-5 py-3 font-mono text-xs text-right font-semibold text-white">{{ Number(invoice.amount).toLocaleString('fr-FR') }} XAF</td>
                <td class="px-5 py-3">
                  <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase border" :class="statusBadge(invoice.status)">{{ invoice.status }}</span>
                </td>
                <td class="px-5 py-3 text-[11px] font-mono text-slate-400">{{ invoice.paid_at ? new Date(invoice.paid_at).toLocaleString() : '—' }}</td>
                <td class="px-5 py-3 text-right whitespace-nowrap">
                  <button v-if="invoice.status === 'unpaid'" @click="pay(invoice)" class="text-xs px-2.5 py-1 mr-1 bg-emerald-900/60 hover:bg-emerald-800 text-emerald-300 rounded font-medium transition">
                    Pay from Wallet
                  </button>
                  <button v-if="invoice.status === 'unpaid'" @click="cancel(invoice)" class="text-xs px-2.5 py-1 bg-rose-900/60 hover:bg-rose-800 text-rose-300 rounded font-medium transition">
                    Cancel
                  </button>
                </td>
              </tr>
              <tr v-if="!invoices.data.length">
                <td colspan="8" class="px-5 py-10 text-center text-slate-500 text-sm">No invoices found.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="invoices.links?.length > 3" class="flex items-center justify-between px-5 py-3 border-t border-slate-700/60 bg-slate-900/40">
          <span class="text-xs text-slate-500">Showing {{ invoices.from }}–{{ invoices.to }} of {{ invoices.total }}</span>
          <div class="flex gap-1">
            <button
              v-for="(link, i) in invoices.links"
              :key="i"
              v-html="link.label"
              @click="link.url && inertiaRouter.get(link.url, filters, { preserveState: true, replace: true })"
              class="px-2.5 py-1 rounded text-xs font-medium transition"
              :class="link.active ? 'bg-indigo-600 text-white' : (link.url ? 'bg-slate-800 text-slate-300 hover:bg-slate-700' : 'bg-slate-800/50 text-slate-600')"
            ></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Create Modal -->
    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-bold text-white mb-4">New Invoice</h3>

        <form @submit.prevent="submitCreate" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Client</label>
            <select v-model="form.client_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
              <option value="" disabled>Select…</option>
              <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} ({{ c.client_code }})</option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Period Start</label>
              <input v-model="form.period_start" type="date" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Period End</label>
              <input v-model="form.period_end" type="date" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Line Items</label>
            <div v-for="(item, i) in form.items" :key="i" class="flex gap-2 mb-2">
              <input v-model="item.description" type="text" placeholder="Description" required class="flex-1 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
              <input v-model.number="item.amount" type="number" min="0.01" step="0.01" placeholder="Amount" required class="w-32 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
              <button v-if="form.items.length > 1" type="button" @click="form.items.splice(i, 1)" class="px-2 text-rose-400 hover:text-rose-300">×</button>
            </div>
            <button type="button" @click="form.items.push({ description: '', amount: null })" class="text-xs text-indigo-400 hover:text-indigo-300 font-semibold">+ Add line</button>
          </div>

          <div class="text-right text-sm font-mono text-white border-t border-slate-700/60 pt-3">
            Total: {{ total.toLocaleString('fr-FR') }} XAF
          </div>

          <div class="flex justify-end gap-3 pt-3 border-t border-slate-700/60">
            <button type="button" @click="showCreate = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">Create Invoice</button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
  invoices: Object,
  filters: Object,
  clients: Array,
});

const showCreate = ref(false);

const filters = reactive({
  status: props.filters?.status || '',
  client_id: props.filters?.client_id || '',
});

const emptyForm = () => ({
  client_id: '',
  period_start: '',
  period_end: '',
  items: [{ description: '', amount: null }],
});

const form = ref(emptyForm());

const total = computed(() => form.value.items.reduce((sum, item) => sum + (Number(item.amount) || 0), 0));

const statusBadge = (status) => ({
  unpaid: 'bg-amber-950 text-amber-400 border-amber-800',
  paid: 'bg-emerald-950 text-emerald-400 border-emerald-800',
  cancelled: 'bg-slate-700 text-slate-300 border-slate-600',
  draft: 'bg-slate-700 text-slate-300 border-slate-600',
}[status] || 'bg-slate-700 text-slate-300 border-slate-600');

const applyFilters = () => {
  inertiaRouter.get('/invoices', filters, { preserveState: true, replace: true });
};

const openCreate = () => {
  form.value = emptyForm();
  showCreate.value = true;
};

const submitCreate = () => {
  inertiaRouter.post('/invoices', form.value, {
    preserveScroll: true,
    onSuccess: () => {
      showCreate.value = false;
    },
  });
};

const pay = (invoice) => {
  if (confirm(`Pay invoice ${invoice.number} (${Number(invoice.amount).toLocaleString('fr-FR')} XAF) from the client wallet?`)) {
    inertiaRouter.post(`/invoices/${invoice.id}/pay`, {}, { preserveScroll: true });
  }
};

const cancel = (invoice) => {
  if (confirm(`Cancel invoice ${invoice.number}?`)) {
    inertiaRouter.post(`/invoices/${invoice.id}/cancel`, {}, { preserveScroll: true });
  }
};
</script>
