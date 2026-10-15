<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 class="text-2xl font-extrabold text-white tracking-tight">Payments</h2>
          <p class="text-sm text-slate-400">Mobile Money transactions via Monetbil (MTN / Orange / Express Union).</p>
        </div>
        <Link href="/settings" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-lg text-sm font-semibold transition">
          Gateway Settings
        </Link>
      </div>

      <div v-if="!configured" class="bg-amber-950/40 border border-amber-800 rounded-xl px-5 py-4 text-sm text-amber-300">
        Monetbil is not configured yet —
        <Link href="/settings" class="underline font-semibold">set your service key and secret</Link>
        to start accepting Mobile Money top-ups.
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap items-center gap-3">
        <input v-model="filters.search" @keyup.enter="applyFilters" type="text" placeholder="Search payment ref, phone, operator txn…" class="flex-1 min-w-[240px] bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500" />
        <select v-model="filters.status" @change="applyFilters" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
          <option value="success">Success</option>
          <option value="failed">Failed</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <button @click="applyFilters" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-lg text-sm font-medium transition">Search</button>
      </div>

      <!-- Table -->
      <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-slate-900/60 text-[11px] uppercase tracking-wider text-slate-400">
              <tr>
                <th class="px-5 py-3">Date</th>
                <th class="px-5 py-3">Payment Ref</th>
                <th class="px-5 py-3">Client</th>
                <th class="px-5 py-3">Phone</th>
                <th class="px-5 py-3">Operator</th>
                <th class="px-5 py-3 text-right">Amount</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3">Operator Txn</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-700/50">
              <tr v-for="payment in payments.data" :key="payment.id" class="hover:bg-slate-700/30 transition">
                <td class="px-5 py-3 text-xs font-mono text-slate-400">{{ new Date(payment.created_at).toLocaleString() }}</td>
                <td class="px-5 py-3 font-mono text-xs text-white">{{ payment.payment_ref }}</td>
                <td class="px-5 py-3 text-xs">
                  <Link :href="`/clients/${payment.client_id}`" class="text-indigo-300 hover:text-indigo-200 font-medium">
                    {{ payment.client?.name }}
                  </Link>
                  <span class="block text-[11px] font-mono text-slate-500">{{ payment.client?.client_code }}</span>
                </td>
                <td class="px-5 py-3 font-mono text-xs text-slate-300">{{ payment.phone || '—' }}</td>
                <td class="px-5 py-3 text-xs text-slate-300">{{ payment.operator || '—' }}</td>
                <td class="px-5 py-3 font-mono text-xs text-right font-semibold text-emerald-400">{{ Number(payment.amount).toLocaleString('fr-FR') }} XAF</td>
                <td class="px-5 py-3">
                  <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase border" :class="statusBadge(payment.status)">{{ payment.status }}</span>
                </td>
                <td class="px-5 py-3 font-mono text-[11px] text-slate-500">{{ payment.operator_transaction_id || '—' }}</td>
              </tr>
              <tr v-if="!payments.data.length">
                <td colspan="8" class="px-5 py-10 text-center text-slate-500 text-sm">No payments found.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="payments.links?.length > 3" class="flex items-center justify-between px-5 py-3 border-t border-slate-700/60 bg-slate-900/40">
          <span class="text-xs text-slate-500">Showing {{ payments.from }}–{{ payments.to }} of {{ payments.total }}</span>
          <div class="flex gap-1">
            <button
              v-for="(link, i) in payments.links"
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
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { Link, router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
  payments: Object,
  filters: Object,
  configured: Boolean,
});

const filters = reactive({
  search: props.filters?.search || '',
  status: props.filters?.status || '',
});

const statusBadge = (status) => ({
  pending: 'bg-amber-950 text-amber-400 border-amber-800',
  success: 'bg-emerald-950 text-emerald-400 border-emerald-800',
  failed: 'bg-rose-950 text-rose-400 border-rose-800',
  cancelled: 'bg-slate-700 text-slate-300 border-slate-600',
}[status] || 'bg-slate-700 text-slate-300 border-slate-600');

const applyFilters = () => {
  inertiaRouter.get('/payments', filters, { preserveState: true, replace: true });
};
</script>
