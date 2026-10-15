<template>
  <AppLayout>
    <!-- Header -->
    <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold tracking-tight text-white">ISP Network Operations Center</h2>
        <p class="text-sm text-slate-400">Live RouterOS health, configured Hotspot and PPPoE accounts, and active sessions.</p>
      </div>
      <div class="flex items-center gap-3">
        <a href="/subscriptions" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold shadow transition">
          View Router Users
        </a>
      </div>
    </div>

    <!-- Quick Stats Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Online Routers</span>
          <span class="p-2 bg-emerald-500/10 text-emerald-400 rounded-lg">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/></svg>
          </span>
        </div>
        <div class="mt-3 flex items-baseline gap-2">
          <span class="text-3xl font-extrabold text-white">{{ stats.online_routers }}</span>
          <span class="text-xs text-slate-400">/ {{ stats.total_routers }} devices</span>
        </div>
      </div>

      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Active Subscribers</span>
          <span class="p-2 bg-indigo-500/10 text-indigo-400 rounded-lg">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          </span>
        </div>
        <div class="mt-3 flex items-baseline gap-2">
          <span class="text-3xl font-extrabold text-white">{{ stats.active_subscriptions }}</span>
          <span class="text-xs text-slate-400">({{ stats.hotspot_active }} Hotspot, {{ stats.pppoe_active }} PPPoE)</span>
        </div>
      </div>

      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Hotspot Accounts</span>
          <span class="p-2 bg-sky-500/10 text-sky-400 rounded-lg">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
          </span>
        </div>
        <div class="mt-3 flex items-baseline gap-2">
          <span class="text-3xl font-extrabold text-white">{{ stats.hotspot_users }}</span>
          <span class="text-xs text-slate-400">on connected routers</span>
        </div>
      </div>

      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">PPPoE Accounts</span>
          <span class="p-2 bg-amber-500/10 text-amber-400 rounded-lg">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </span>
        </div>
        <div class="mt-3 flex items-baseline gap-2">
          <span class="text-3xl font-extrabold text-white">{{ stats.pppoe_users }}</span>
          <span class="text-xs text-slate-400">on connected routers</span>
        </div>
      </div>
    </div>

    <div v-if="routerErrors.length" class="mb-8 rounded-lg border border-rose-600/50 bg-rose-900/30 p-4 text-sm text-rose-200">
      <p class="mb-2 font-semibold">Live router data could not be fetched:</p>
      <p v-for="error in routerErrors" :key="error.router_name" class="text-xs">
        {{ error.router_name }}: {{ error.message }}
      </p>
    </div>

    <!-- Routers Fleet Status -->
    <div class="bg-slate-800 border border-slate-700/60 rounded-xl overflow-hidden mb-8 shadow-sm">
      <div class="px-6 py-4 border-b border-slate-700/60 flex items-center justify-between">
        <h3 class="font-bold text-white text-base">MikroTik Router Fleet</h3>
        <a href="/routers" class="text-xs text-indigo-400 hover:text-indigo-300 font-semibold">+ Add Router</a>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
          <thead class="bg-slate-900/50 text-xs uppercase text-slate-400 border-b border-slate-700/60">
            <tr>
              <th class="px-6 py-3">Router / Identity</th>
              <th class="px-6 py-3">IP Address</th>
              <th class="px-6 py-3">Status</th>
              <th class="px-6 py-3">CPU Load</th>
              <th class="px-6 py-3">Uptime</th>
              <th class="px-6 py-3">Router Accounts</th>
              <th class="px-6 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/40">
            <tr v-for="router in routers" :key="router.id" class="hover:bg-slate-750/30 transition">
              <td class="px-6 py-4 font-medium text-white flex items-center gap-2">
                <span class="w-2 h-2 rounded-full" :class="router.is_online ? 'bg-emerald-400' : 'bg-rose-400'"></span>
                {{ router.name }}
                <span v-if="router.identity" class="text-xs text-slate-400">({{ router.identity }})</span>
              </td>
              <td class="px-6 py-4 font-mono text-xs">{{ router.ip_address }}</td>
              <td class="px-6 py-4">
                <span v-if="router.is_online" class="px-2 py-0.5 rounded text-xs font-semibold bg-emerald-950/80 text-emerald-400 border border-emerald-800">ONLINE</span>
                <span v-else class="px-2 py-0.5 rounded text-xs font-semibold bg-rose-950/80 text-rose-400 border border-rose-800">OFFLINE</span>
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                  <div class="w-24 bg-slate-700 rounded-full h-2 overflow-hidden">
                    <div class="bg-indigo-500 h-2 rounded-full" :style="{ width: router.cpu_load + '%' }"></div>
                  </div>
                  <span class="text-xs font-mono">{{ router.cpu_load }}%</span>
                </div>
              </td>
              <td class="px-6 py-4 text-xs font-mono text-slate-400">
                <span v-if="router.hotspot_users_count !== null">{{ router.hotspot_users_count }} Hotspot / {{ router.pppoe_users_count }} PPPoE</span>
                <span v-else class="text-rose-300" :title="router.connection_error">Unavailable</span>
              </td>
              <td class="px-6 py-4 text-xs font-mono text-slate-400">{{ router.uptime || '—' }}</td>
              <td class="px-6 py-4 text-right">
                <Link :href="`/routers/${router.id}/test`" method="post" as="button" preserve-scroll class="text-xs px-2.5 py-1 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded font-medium transition">
                  Ping API
                </Link>
              </td>
            </tr>
            <tr v-if="!routers.length">
              <td colspan="7" class="px-6 py-8 text-center text-slate-400 text-sm">
                No MikroTik routers configured yet. <a href="/routers" class="text-indigo-400 underline">Add your first router</a>.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Recent System Logs -->
    <div class="bg-slate-800 border border-slate-700/60 rounded-xl overflow-hidden shadow-sm">
      <div class="px-6 py-4 border-b border-slate-700/60">
        <h3 class="font-bold text-white text-base">Recent System Activity</h3>
      </div>
      <div class="p-6">
        <div class="space-y-3">
          <div v-for="log in recentLogs" :key="log.id" class="flex items-start justify-between text-xs p-3 bg-slate-900/60 rounded-lg border border-slate-700/40">
            <div class="flex items-center gap-3">
              <span class="px-2 py-0.5 rounded uppercase font-bold text-[10px]" :class="log.level === 'warning' ? 'bg-amber-950 text-amber-400 border border-amber-800' : 'bg-slate-800 text-slate-300'">
                {{ log.event }}
              </span>
              <span class="text-slate-300 font-medium">
                {{ log.subscription ? log.subscription.username : 'System' }} on {{ log.router ? log.router.name : 'Unknown' }}
              </span>
              <span class="text-slate-500 font-mono">{{ log.details?.reason || '' }}</span>
            </div>
            <span class="text-slate-500">{{ new Date(log.created_at).toLocaleTimeString() }}</span>
          </div>
          <div v-if="!recentLogs.length" class="text-center text-slate-500 text-xs py-4">
            No activity logs recorded yet. Automated disconnect and sync events will stream here.
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

defineProps({
  stats: Object,
  routers: Array,
  routerErrors: Array,
  recentLogs: Array,
});
</script>
