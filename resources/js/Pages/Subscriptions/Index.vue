<template>
  <AppLayout>
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold tracking-tight text-white">Live Router Users & Sessions</h2>
        <p class="text-sm text-slate-400">Accounts and current session details fetched directly from configured MikroTik routers.</p>
      </div>
    </div>

    <div v-if="routerErrors.length" class="mb-6 rounded-lg border border-rose-600/50 bg-rose-900/30 p-4 text-sm text-rose-200">
      <p class="mb-2 font-semibold">Could not fetch live data from:</p>
      <p v-for="error in routerErrors" :key="error.router_name" class="text-xs">
        {{ error.router_name }}: {{ error.message }}
      </p>
    </div>

    <!-- Filters Bar -->
    <div class="mb-6 bg-slate-800 border border-slate-700/60 rounded-xl p-4 flex flex-wrap items-center gap-4 text-xs">
      <div>
        <label class="block text-slate-400 font-semibold mb-1">Status</label>
        <select v-model="filterStatus" @change="applyFilters" class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-white">
          <option value="">All Account States</option>
          <option value="enabled">Enabled</option>
          <option value="active">Connected</option>
          <option value="disabled">Disabled</option>
        </select>
      </div>

      <div>
        <label class="block text-slate-400 font-semibold mb-1">Service Type</label>
        <select v-model="filterType" @change="applyFilters" class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-white">
          <option value="">All Protocols</option>
          <option value="hotspot">Hotspot</option>
          <option value="pppoe">PPPoE</option>
        </select>
      </div>

      <div>
        <label class="block text-slate-400 font-semibold mb-1">Router</label>
        <select v-model="filterRouter" @change="applyFilters" class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-white">
          <option value="">All Routers</option>
          <option v-for="r in routers" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
      </div>
    </div>

    <!-- Live Router Users -->
    <div class="bg-slate-800 border border-slate-700/60 rounded-xl overflow-hidden shadow-sm">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
          <thead class="bg-slate-900/50 text-xs uppercase text-slate-400 border-b border-slate-700/60">
            <tr>
              <th class="px-6 py-3">Username</th>
              <th class="px-6 py-3">Router</th>
              <th class="px-6 py-3">Protocol / Profile</th>
              <th class="px-6 py-3">Status</th>
              <th class="px-6 py-3">Address / MAC</th>
              <th class="px-6 py-3">Uptime</th>
              <th class="px-6 py-3">Traffic In / Out</th>
              <th class="px-6 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/40">
            <tr v-for="user in users" :key="user.key" class="hover:bg-slate-750/30 transition">
              <td class="px-6 py-4 font-mono font-bold text-white">{{ user.username }}</td>
              <td class="px-6 py-4 text-xs font-mono text-slate-300">{{ user.router_name }}</td>
              <td class="px-6 py-4 font-mono text-xs">
                <span class="px-1.5 py-0.5 rounded text-[10px] uppercase mr-1.5" :class="user.auth_type === 'hotspot' ? 'bg-amber-950 text-amber-400 border border-amber-800' : 'bg-blue-950 text-blue-400 border border-blue-800'">
                  {{ user.auth_type }}
                </span>
                <span class="text-slate-300">{{ user.profile || '—' }}</span>
              </td>
              <td class="px-6 py-4">
                <span class="px-2 py-0.5 rounded text-xs font-semibold" :class="user.status === 'active' ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : user.status === 'disabled' ? 'bg-rose-950 text-rose-400 border border-rose-800' : 'bg-slate-700 text-slate-300'">
                  {{ user.status }}
                </span>
              </td>
              <td class="px-6 py-4 text-xs font-mono text-slate-400">
                {{ user.ip_address || '—' }}
                <span class="block text-[11px] text-slate-600">{{ user.mac_address || '—' }}</span>
              </td>
              <td class="px-6 py-4 text-xs font-mono text-slate-400">{{ user.uptime || '—' }}</td>
              <td class="px-6 py-4 text-xs font-mono text-slate-400">
                {{ formatBytes(user.bytes_in) }} / {{ formatBytes(user.bytes_out) }}
              </td>
              <td class="px-6 py-4 text-right">
                <button v-if="user.has_active_session" @click="kickSession(user)" class="text-xs px-2.5 py-1 bg-slate-700 hover:bg-slate-600 text-amber-300 rounded font-medium transition">
                  Kick
                </button>
              </td>
            </tr>
            <tr v-if="!users.length">
              <td colspan="8" class="px-6 py-8 text-center text-slate-400 text-sm">
                No RouterOS users match these filters.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
  users: Array,
  routers: Array,
  filters: Object,
  routerErrors: Array,
});

const filterStatus = ref(props.filters?.status || '');
const filterType = ref(props.filters?.auth_type || '');
const filterRouter = ref(props.filters?.router_id || '');

const applyFilters = () => {
  inertiaRouter.get('/subscriptions', {
    status: filterStatus.value || undefined,
    auth_type: filterType.value || undefined,
    router_id: filterRouter.value || undefined,
  }, { preserveState: true });
};

const kickSession = (user) => {
  inertiaRouter.post(`/routers/${user.router_id}/sessions/kick`, {
    auth_type: user.auth_type,
    username: user.username,
  }, { preserveScroll: true });
};

const formatBytes = (bytes) => {
  if (!bytes || bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};
</script>
