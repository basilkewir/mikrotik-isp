<template>
  <div class="min-h-screen bg-slate-950 text-slate-200">
    <!-- Top bar -->
    <header class="border-b border-slate-800 bg-slate-900/60 backdrop-blur sticky top-0 z-10">
      <div class="max-w-4xl mx-auto px-4 py-4 flex items-center justify-between">
        <div>
          <h1 class="text-lg font-extrabold text-white">My Account</h1>
          <p class="text-xs text-slate-400 font-mono">{{ client.name }} · {{ client.client_code }}</p>
        </div>
        <div class="flex items-center gap-2">
          <Link href="/portal/tickets" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-semibold transition">Support</Link>
          <form @submit.prevent="logout">
            <button type="submit" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-semibold transition">Sign Out</button>
          </form>
        </div>
      </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 py-6 space-y-6">
      <!-- Flash messages -->
      <div
        v-if="flashMessage"
        class="rounded-xl border p-4 text-sm"
        :class="flashClass"
      >
        {{ flashMessage }}
      </div>

      <!-- Renewal banner: the first thing a finished subscription must see. -->
      <section
        v-if="renewables.length"
        class="rounded-xl border border-amber-800/60 bg-amber-950/40 p-5 space-y-3"
      >
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="font-extrabold text-amber-200">Service {{ renewalsArePast ? 'expired' : 'expiring soon' }} — pay to stay online</h2>
            <p class="text-xs text-amber-200/80 mt-1">
              Paying with Mobile Money reactivates your internet immediately — no need to call anyone.
            </p>
          </div>
        </div>

        <div
          v-for="sub in renewables"
          :key="sub.id"
          class="bg-slate-900/70 border border-slate-800 rounded-lg p-4 flex flex-wrap items-center gap-4 justify-between"
        >
          <div class="min-w-[200px]">
            <p class="text-sm font-bold text-white">{{ sub.plan?.name }}</p>
            <p class="text-xs font-mono text-slate-400">{{ sub.username }}</p>
            <p class="text-xs text-amber-300 mt-0.5">
              {{ sub.expires_at ? (renewalsArePast || isExpired(sub) ? `Expired ${formatDate(sub.expires_at)}` : `Expires ${formatDate(sub.expires_at)}`) : 'No expiry date' }}
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <input
              v-model="renewPhone[sub.id]"
              type="tel"
              placeholder="Phone (e.g. 6XXXXXXXX)"
              class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white w-44"
            />
            <button
              v-if="Number(sub.plan?.price) > 0"
              @click="renew(sub)"
              :disabled="paying === sub.id"
              class="px-4 py-2 bg-amber-500 hover:bg-amber-400 disabled:opacity-50 text-slate-950 rounded-lg text-xs font-extrabold uppercase tracking-wide transition"
            >
              {{ paying === sub.id ? 'Opening…' : `Pay ${formatXaf(sub.plan?.price)}` }}
            </button>
            <span v-else class="text-xs text-amber-300/80 font-semibold">Contact support to renew</span>
          </div>
        </div>
      </section>

      <!-- Balance + status -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
          <h2 class="text-xs uppercase font-semibold text-slate-500 mb-1">Wallet Balance</h2>
          <p class="text-3xl font-extrabold text-white">{{ formatXaf(client.balance) }}</p>
        </div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col justify-center">
          <h2 class="text-xs uppercase font-semibold text-slate-500 mb-1">Account Status</h2>
          <p class="text-2xl font-extrabold uppercase" :class="client.status === 'active' ? 'text-emerald-400' : 'text-rose-400'">{{ client.status }}</p>
        </div>
      </div>

      <!-- Subscriptions -->
      <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between">
          <h2 class="font-bold text-white text-sm">My Internet Services</h2>
          <span class="text-xs text-slate-500">{{ client.subscriptions?.length || 0 }}</span>
        </div>
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-950/50 text-[11px] uppercase text-slate-500">
            <tr>
              <th class="px-5 py-2">Plan</th>
              <th class="px-5 py-2">Username</th>
              <th class="px-5 py-2">Status</th>
              <th class="px-5 py-2">Expires</th>
              <th class="px-5 py-2">Renew</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr v-for="sub in client.subscriptions" :key="sub.id">
              <td class="px-5 py-3 text-xs">{{ sub.plan?.name }}</td>
              <td class="px-5 py-3 text-xs font-mono text-white">{{ sub.username }}</td>
              <td class="px-5 py-3">
                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase" :class="sub.status === 'active' ? 'bg-emerald-950 text-emerald-400' : 'bg-rose-950 text-rose-400'">{{ sub.status }}</span>
              </td>
              <td class="px-5 py-3 text-xs font-mono text-slate-400">{{ sub.expires_at ? new Date(sub.expires_at).toLocaleDateString() : '—' }}</td>
              <td class="px-5 py-3">
                <button
                  v-if="needsRenewal(sub) && Number(sub.plan?.price) > 0"
                  @click="renew(sub)"
                  :disabled="paying === sub.id"
                  class="px-3 py-1.5 bg-amber-500 hover:bg-amber-400 disabled:opacity-50 text-slate-950 rounded-lg text-[11px] font-extrabold uppercase transition"
                >
                  {{ paying === sub.id ? 'Opening…' : `Pay ${formatXaf(sub.plan?.price)}` }}
                </button>
                <span v-else-if="needsRenewal(sub)" class="text-[11px] text-amber-300/80 font-semibold">Contact support</span>
                <span v-else class="text-slate-600 text-xs">—</span>
              </td>
            </tr>
            <tr v-if="!client.subscriptions?.length">
              <td colspan="5" class="px-5 py-6 text-center text-slate-500 text-xs">No active services.</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Invoices -->
      <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-800">
          <h2 class="font-bold text-white text-sm">Recent Invoices</h2>
        </div>
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-950/50 text-[11px] uppercase text-slate-500">
            <tr>
              <th class="px-5 py-2">Number</th>
              <th class="px-5 py-2">Amount</th>
              <th class="px-5 py-2">Status</th>
              <th class="px-5 py-2">Paid At</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr v-for="invoice in client.invoices" :key="invoice.id">
              <td class="px-5 py-3 text-xs font-mono text-white">{{ invoice.number }}</td>
              <td class="px-5 py-3 text-xs font-mono">{{ formatXaf(invoice.amount) }}</td>
              <td class="px-5 py-3">
                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase" :class="invoice.status === 'paid' ? 'bg-emerald-950 text-emerald-400' : 'bg-amber-950 text-amber-400'">{{ invoice.status }}</span>
              </td>
              <td class="px-5 py-3 text-xs font-mono text-slate-400">{{ invoice.paid_at ? new Date(invoice.paid_at).toLocaleDateString() : '—' }}</td>
            </tr>
            <tr v-if="!client.invoices?.length">
              <td colspan="4" class="px-5 py-6 text-center text-slate-500 text-xs">No invoices yet.</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Wallet ledger -->
      <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-800">
          <h2 class="font-bold text-white text-sm">Wallet History</h2>
        </div>
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-950/50 text-[11px] uppercase text-slate-500">
            <tr>
              <th class="px-5 py-2">Date</th>
              <th class="px-5 py-2">Description</th>
              <th class="px-5 py-2 text-right">Amount</th>
              <th class="px-5 py-2 text-right">Balance</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr v-for="tx in client.walletTransactions" :key="tx.id">
              <td class="px-5 py-3 text-xs font-mono text-slate-400">{{ new Date(tx.created_at).toLocaleDateString() }}</td>
              <td class="px-5 py-3 text-xs">{{ tx.description }}</td>
              <td class="px-5 py-3 text-xs font-mono text-right" :class="Number(tx.amount) >= 0 ? 'text-emerald-400' : 'text-rose-400'">
                {{ Number(tx.amount) >= 0 ? '+' : '' }}{{ formatXaf(tx.amount) }}
              </td>
              <td class="px-5 py-3 text-xs font-mono text-right text-slate-300">{{ formatXaf(tx.balance_after) }}</td>
            </tr>
            <tr v-if="!client.walletTransactions?.length">
              <td colspan="4" class="px-5 py-6 text-center text-slate-500 text-xs">No wallet transactions yet.</td>
            </tr>
          </tbody>
        </table>
      </section>
    </main>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
  client: Object,
  flash: { type: Object, default: () => ({}) },
});

const formatXaf = (value) => {
  const n = Number(value) || 0;
  return `${n.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} XAF`;
};

const flashMessage = computed(
  () => props.flash?.error || props.flash?.info || props.flash?.success || props.flash?.warning || '',
);
const flashClass = computed(() => {
  if (props.flash?.error) return 'bg-rose-950/60 border-rose-800 text-rose-200';
  if (props.flash?.info) return 'bg-sky-950/60 border-sky-800 text-sky-200';
  if (props.flash?.success) return 'bg-emerald-950/60 border-emerald-800 text-emerald-200';
  return 'bg-amber-950/60 border-amber-800 text-amber-200';
});

const RENEW_WINDOW_MS = 3 * 24 * 60 * 60 * 1000;

const isExpired = (sub) => Boolean(sub.expires_at && new Date(sub.expires_at).getTime() <= Date.now());

/** Expired, or still active but inside the 3-day renewal window. */
const needsRenewal = (sub) => {
  if (sub.status === 'expired') return true;
  if (sub.status !== 'active' || !sub.expires_at) return false;
  return new Date(sub.expires_at).getTime() - Date.now() <= RENEW_WINDOW_MS;
};

const renewables = computed(() => (props.client.subscriptions || []).filter(needsRenewal));
const renewalsArePast = computed(() => renewables.value.some(isExpired));

const renewPhone = ref({});
const paying = ref(null);

const renew = (sub) => {
  paying.value = sub.id;
  router.post(
    `/portal/subscriptions/${sub.id}/renew`,
    { phone: renewPhone.value[sub.id] || null },
    {
      onFinish: () => { paying.value = null; },
      onError: () => { paying.value = null; },
    },
  );
};

const logout = () => {
  router.post('/portal/logout');
};
</script>
