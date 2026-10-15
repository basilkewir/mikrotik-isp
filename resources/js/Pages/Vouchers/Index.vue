<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 class="text-2xl font-extrabold text-white tracking-tight">Vouchers</h2>
          <p class="text-sm text-slate-400">Prepaid top-up codes — each redemption credits the client wallet.</p>
        </div>
        <button @click="showGenerate = true" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
          + Generate Batch
        </button>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap items-center gap-3">
        <input v-model="filters.search" @keyup.enter="applyFilters" type="text" placeholder="Search code or batch…" class="flex-1 min-w-[220px] bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500" />
        <select v-model="filters.status" @change="applyFilters" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="redeemed">Redeemed</option>
          <option value="void">Void</option>
        </select>
        <button @click="applyFilters" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-lg text-sm font-medium transition">Search</button>
      </div>

      <!-- Table -->
      <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-slate-900/60 text-[11px] uppercase tracking-wider text-slate-400">
              <tr>
                <th class="px-5 py-3">Code</th>
                <th class="px-5 py-3">Value</th>
                <th class="px-5 py-3">Plan</th>
                <th class="px-5 py-3">Batch</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3">Redeemed By</th>
                <th class="px-5 py-3">Redeemed At</th>
                <th class="px-5 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-700/50">
              <tr v-for="voucher in vouchers.data" :key="voucher.id" class="hover:bg-slate-700/30 transition">
                <td class="px-5 py-3 font-mono font-bold text-white tracking-wider">{{ voucher.code }}</td>
                <td class="px-5 py-3 font-mono text-emerald-400 font-semibold">{{ Number(voucher.value).toLocaleString('fr-FR') }} XAF</td>
                <td class="px-5 py-3 text-slate-300 text-xs">{{ voucher.plan?.name || '—' }}</td>
                <td class="px-5 py-3 text-xs text-slate-400">{{ voucher.batch_name || '—' }}</td>
                <td class="px-5 py-3">
                  <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase border" :class="statusBadge(voucher.status)">{{ voucher.status }}</span>
                </td>
                <td class="px-5 py-3 text-xs text-slate-300">
                  <template v-if="voucher.redeemer">
                    {{ voucher.redeemer.name }}
                    <span class="block text-[11px] font-mono text-slate-500">{{ voucher.redeemer.client_code }}</span>
                  </template>
                  <span v-else class="text-slate-600">—</span>
                </td>
                <td class="px-5 py-3 text-xs font-mono text-slate-400">{{ voucher.redeemed_at ? new Date(voucher.redeemed_at).toLocaleString() : '—' }}</td>
                <td class="px-5 py-3 text-right">
                  <button v-if="voucher.status === 'active'" @click="voidVoucher(voucher)" class="text-xs px-2.5 py-1 bg-rose-900/60 hover:bg-rose-800 text-rose-300 rounded font-medium transition">Void</button>
                </td>
              </tr>
              <tr v-if="!vouchers.data.length">
                <td colspan="8" class="px-5 py-10 text-center text-slate-500 text-sm">No vouchers match the current filters.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="vouchers.links?.length > 3" class="flex items-center justify-between px-5 py-3 border-t border-slate-700/60 bg-slate-900/40">
          <span class="text-xs text-slate-500">Showing {{ vouchers.from }}–{{ vouchers.to }} of {{ vouchers.total }}</span>
          <div class="flex gap-1">
            <button
              v-for="(link, i) in vouchers.links"
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

    <!-- Generate Modal -->
    <div v-if="showGenerate" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">Generate Voucher Batch</h3>

        <form @submit.prevent="submitGenerate" class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Count</label>
              <input v-model.number="form.count" type="number" min="1" max="500" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Value (XAF)</label>
              <input v-model.number="form.value" type="number" min="1" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Batch Name (optional)</label>
            <input v-model="form.batch_name" type="text" maxlength="100" placeholder="e.g. X-MAS-2026-PROMO" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Attach Plan (optional, informational)</label>
            <select v-model="form.plan_id" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
              <option :value="null">— none —</option>
              <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }} ({{ Number(p.price).toLocaleString('fr-FR') }} XAF)</option>
            </select>
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showGenerate = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">Generate</button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
  vouchers: Object,
  plans: Array,
  filters: Object,
});

const showGenerate = ref(false);

const filters = reactive({
  search: props.filters?.search || '',
  status: props.filters?.status || '',
});

const form = ref({
  count: 10,
  value: 5000,
  batch_name: '',
  plan_id: null,
});

const statusBadge = (status) => ({
  active: 'bg-emerald-950 text-emerald-400 border-emerald-800',
  redeemed: 'bg-indigo-950 text-indigo-400 border-indigo-800',
  void: 'bg-rose-950 text-rose-400 border-rose-800',
}[status] || 'bg-slate-700 text-slate-300 border-slate-600');

const applyFilters = () => {
  inertiaRouter.get('/vouchers', filters, { preserveState: true, replace: true });
};

const submitGenerate = () => {
  inertiaRouter.post('/vouchers', form.value, {
    preserveScroll: true,
    onSuccess: () => {
      showGenerate.value = false;
      form.value = { count: 10, value: 5000, batch_name: '', plan_id: null };
    },
  });
};

const voidVoucher = (voucher) => {
  if (confirm(`Void voucher ${voucher.code}?`)) {
    inertiaRouter.post(`/vouchers/${voucher.id}/void`, {}, { preserveScroll: true });
  }
};
</script>
