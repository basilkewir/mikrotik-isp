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
            </tr>
            <tr v-if="!client.subscriptions?.length">
              <td colspan="4" class="px-5 py-6 text-center text-slate-500 text-xs">No active services.</td>
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
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
  client: Object,
});

const formatXaf = (value) => {
  const n = Number(value) || 0;
  return `${n.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} XAF`;
};

const logout = () => {
  router.post('/portal/logout');
};
</script>
