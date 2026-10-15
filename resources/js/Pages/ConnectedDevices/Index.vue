<template>
  <AppLayout>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold tracking-tight text-white">Connected Devices</h2>
        <p class="text-sm text-slate-400">
          Every device seen by the routers — ARP table, DHCP leases, Hotspot sessions/hosts, PPPoE tunnels and CDP/LLDP neighbours — merged by MAC address.
        </p>
      </div>
      <div class="flex items-center gap-3">
        <label class="flex items-center gap-2 text-xs text-slate-400 select-none">
          <input v-model="autoRefresh" type="checkbox" class="rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500" />
          Auto-refresh 10s
        </label>
        <button @click="refresh" :disabled="loading" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white rounded-lg text-sm font-semibold shadow transition">
          {{ loading ? 'Refreshing…' : 'Refresh' }}
        </button>
      </div>
    </div>

    <!-- Router fetch errors -->
    <div v-for="err in routerErrors" :key="err.router_name" class="mb-4 p-4 bg-rose-900/40 border border-rose-600/50 rounded-lg text-rose-300 text-sm">
      {{ err.router_name }}: {{ err.message }}
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-4">
        <div class="text-xs uppercase text-slate-400 mb-1">Total Devices</div>
        <div class="text-2xl font-extrabold text-white">{{ filtered.length }}</div>
      </div>
      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-4">
        <div class="text-xs uppercase text-slate-400 mb-1">Online</div>
        <div class="text-2xl font-extrabold text-emerald-400">{{ filtered.filter(d => d.status === 'online').length }}</div>
      </div>
      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-4">
        <div class="text-xs uppercase text-slate-400 mb-1">Hotspot Sessions</div>
        <div class="text-2xl font-extrabold text-amber-400">{{ filtered.filter(d => d.sources.includes('hotspot')).length }}</div>
      </div>
      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-4">
        <div class="text-xs uppercase text-slate-400 mb-1">DHCP Leases</div>
        <div class="text-2xl font-extrabold text-indigo-400">{{ filtered.filter(d => d.sources.includes('dhcp')).length }}</div>
      </div>
      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-4">
        <div class="text-xs uppercase text-slate-400 mb-1">Offline / Idle</div>
        <div class="text-2xl font-extrabold text-rose-400">{{ filtered.filter(d => d.status !== 'online').length }}</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="mb-6 flex flex-col md:flex-row gap-3">
      <input v-model="search" type="text" placeholder="Search IP, MAC, hostname, user, comment..." class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500" />
      <select v-model="routerFilter" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <option value="">All routers</option>
        <option v-for="r in routers" :key="r.id" :value="String(r.id)">{{ r.name }}</option>
      </select>
      <select v-model="statusFilter" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <option value="">All statuses</option>
        <option value="online">Online</option>
        <option value="idle">Idle</option>
        <option value="offline">Offline</option>
      </select>
    </div>

    <!-- Devices Table -->
    <div class="bg-slate-800 border border-slate-700/60 rounded-xl overflow-hidden shadow-sm">
      <div class="px-6 py-3 border-b border-slate-700/60 flex items-center justify-between text-xs text-slate-400 font-mono">
        <span>{{ filtered.length }} device(s)</span>
        <span>Fetched {{ new Date(fetchedAt).toLocaleTimeString() }}</span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
          <thead class="bg-slate-900/50 text-xs uppercase text-slate-400 border-b border-slate-700/60">
            <tr>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Type</th>
              <th class="px-4 py-3">Router</th>
              <th class="px-4 py-3">IP Address</th>
              <th class="px-4 py-3">MAC Address</th>
              <th class="px-4 py-3">Hostname / Comment</th>
              <th class="px-4 py-3">Interface</th>
              <th class="px-4 py-3">User</th>
              <th class="px-4 py-3">Sources</th>
              <th class="px-4 py-3">Uptime / Last Seen</th>
              <th class="px-4 py-3 text-right">Traffic In / Out</th>
              <th class="px-4 py-3 text-right">Details</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/40">
            <tr v-for="device in filtered" :key="device.key + device.router_id" class="hover:bg-slate-700/20 transition">
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase" :class="statusClass(device.status)">
                  {{ device.status }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase border" :class="typologyClass(device.typology)">
                  {{ device.typology_label || 'Unknown' }}
                </span>
                <span v-if="device.client_name" class="block text-[11px] text-emerald-400 mt-0.5">{{ device.client_name }}</span>
              </td>
              <td class="px-4 py-3 text-xs font-medium text-white">{{ device.router_name }}</td>
              <td class="px-4 py-3 text-xs font-mono">{{ device.ip_address || '—' }}</td>
              <td class="px-4 py-3 text-xs font-mono">{{ device.mac_address || '—' }}</td>
              <td class="px-4 py-3 text-xs">
                <span class="text-white">{{ device.hostname || '—' }}</span>
                <span v-if="device.comment" class="block text-[11px] text-slate-500">{{ device.comment }}</span>
              </td>
              <td class="px-4 py-3 text-[11px] font-mono text-slate-400">{{ device.interface || '—' }}</td>
              <td class="px-4 py-3 text-xs font-mono">
                <span v-if="device.user" class="text-amber-300">{{ device.user }}</span>
                <span v-else class="text-slate-500">—</span>
                <span v-if="device.session_time_left" class="block text-[11px] text-emerald-400">left: {{ device.session_time_left }}</span>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-1">
                  <span v-for="s in device.sources" :key="s" class="px-1.5 py-0.5 rounded text-[10px] font-mono uppercase border" :class="sourceClass(s)">
                    {{ s }}
                  </span>
                </div>
              </td>
              <td class="px-4 py-3 text-[11px] font-mono text-slate-400">
                {{ device.session_uptime || device.dhcp_last_seen || '—' }}
              </td>
              <td class="px-4 py-3 text-[11px] font-mono text-right">
                <span class="text-emerald-400">{{ formatBytes(device.bytes_in) }}</span>
                <span class="text-slate-600"> / </span>
                <span class="text-amber-400">{{ formatBytes(device.bytes_out) }}</span>
              </td>
              <td class="px-4 py-3 text-right">
                <button @click="selected = device" class="text-xs px-2.5 py-1 bg-slate-700 hover:bg-slate-600 text-indigo-300 rounded font-medium transition">
                  View All
                </button>
              </td>
            </tr>
            <tr v-if="!filtered.length">
              <td colspan="11" class="px-6 py-10 text-center text-slate-400 text-sm">
                No devices found. Routers may be offline, or no clients are currently connected.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Device Details Modal -->
    <div v-if="selected" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-3xl p-6 shadow-2xl max-h-[85vh] overflow-y-auto">
        <div class="flex items-start justify-between mb-4">
          <div>
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
              {{ selected.hostname || selected.ip_address || selected.mac_address || 'Device' }}
              <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase" :class="statusClass(selected.status)">{{ selected.status }}</span>
            </h3>
            <p class="text-xs text-slate-400 font-mono">
              {{ selected.router_name }} · {{ selected.ip_address || 'no IP' }} · {{ selected.mac_address || 'no MAC' }}
            </p>
          </div>
          <button @click="selected = null" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
        </div>

        <!-- Merged summary -->
        <div class="mb-5 bg-slate-900/60 border border-slate-700/50 rounded-lg p-4">
          <h4 class="text-xs uppercase font-bold tracking-wider text-indigo-400 mb-3">Merged Summary</h4>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-2 text-xs">
            <Detail label="Router" :value="selected.router_name" />
            <Detail label="Status" :value="selected.status" />
            <Detail label="Typology" :value="selected.typology_label" />
            <Detail label="Client" :value="selected.client_name" />
            <Detail label="IP Address" :value="selected.ip_address" />
            <Detail label="MAC Address" :value="selected.mac_address" />
            <Detail label="Hostname" :value="selected.hostname" />
            <Detail label="Interface" :value="selected.interface" />
            <Detail label="User" :value="selected.user" />
            <Detail label="Comment" :value="selected.comment" />
            <Detail label="Sources" :value="selected.sources.join(', ')" />
            <Detail label="Session Uptime" :value="selected.session_uptime" />
            <Detail label="Time Remaining" :value="selected.session_time_left" />
            <Detail label="Idle Time" :value="selected.idle_time" />
            <Detail label="Login By" :value="selected.login_by" />
            <Detail label="ARP Status" :value="selected.arp_status" />
            <Detail label="DHCP Status" :value="selected.dhcp_status" />
            <Detail label="DHCP Expires" :value="selected.dhcp_expires" />
            <Detail label="DHCP Last Seen" :value="selected.dhcp_last_seen" />
            <Detail label="Lease Rate Limit" :value="selected.dhcp_rate_limit" />
            <Detail label="DHCP Server" :value="selected.dhcp_server" />
            <Detail label="Class ID" :value="selected.class_id" />
            <Detail label="Blocked" :value="boolText(selected.blocked)" />
            <Detail label="Bytes In" :value="formatBytes(selected.bytes_in)" />
            <Detail label="Bytes Out" :value="formatBytes(selected.bytes_out)" />
            <Detail label="Packets In" :value="selected.packets_in" />
            <Detail label="Packets Out" :value="selected.packets_out" />
            <Detail label="Hotspot Authorized" :value="boolText(selected.host_authorized)" />
            <Detail label="Hotspot Bypassed" :value="boolText(selected.host_bypassed)" />
            <Detail label="Host Found By" :value="selected.host_found_by" />
            <Detail label="PPP Service" :value="selected.ppp_service" />
            <Detail label="PPP Remote Address" :value="selected.ppp_remote_address" />
            <Detail label="Neighbor Identity" :value="selected.neighbor_identity" />
            <Detail label="Neighbor Platform" :value="selected.neighbor_platform" />
            <Detail label="Neighbor Interface" :value="selected.neighbor_interface" />
          </div>
        </div>

        <!-- Raw source details -->
        <div class="space-y-4">
          <div v-for="(fields, source) in selected.raw" :key="source" class="bg-slate-900/60 border border-slate-700/50 rounded-lg p-4">
            <h4 class="text-xs uppercase font-bold tracking-wider text-amber-400 mb-3">{{ sourceLabel(source) }}</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1.5">
              <div v-for="(value, field) in fields" :key="field" class="flex justify-between gap-3 text-xs border-b border-slate-800/60 pb-1">
                <span class="text-slate-500 font-mono shrink-0">{{ field }}</span>
                <span class="text-slate-200 font-mono text-right break-all">{{ displayValue(value) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Detail from '../../Components/Detail.vue';

const props = defineProps({
  devices: Array,
  routers: Array,
  routerErrors: Array,
  fetchedAt: String,
});

const search = ref('');
const routerFilter = ref('');
const statusFilter = ref('');
const autoRefresh = ref(false);
const loading = ref(false);
const selected = ref(null);

let timer = null;

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase();

  return props.devices.filter((device) => {
    if (routerFilter.value && String(device.router_id) !== routerFilter.value) return false;
    if (statusFilter.value && device.status !== statusFilter.value) return false;
    if (!term) return true;

    return [device.ip_address, device.mac_address, device.hostname, device.user, device.comment, device.interface, device.typology_label, device.client_name]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(term));
  });
});

const refresh = () => {
  loading.value = true;
  inertiaRouter.reload({
    only: ['devices', 'routerErrors', 'fetchedAt'],
    preserveState: true,
    preserveScroll: true,
    onFinish: () => {
      loading.value = false;
    },
  });
};

watch(autoRefresh, (enabled) => {
  if (enabled) {
    timer = setInterval(refresh, 10000);
  } else {
    clearInterval(timer);
    timer = null;
  }
});

onBeforeUnmount(() => clearInterval(timer));

const statusClass = (status) => ({
  online: 'bg-emerald-950 text-emerald-400 border border-emerald-800',
  idle: 'bg-amber-950 text-amber-400 border border-amber-800',
  offline: 'bg-rose-950 text-rose-400 border border-rose-800',
}[status] || 'bg-slate-800 text-slate-400 border border-slate-700');

const sourceClass = (source) => ({
  arp: 'bg-slate-800 text-slate-300 border-slate-600',
  dhcp: 'bg-indigo-950 text-indigo-300 border-indigo-800',
  hotspot: 'bg-amber-950 text-amber-300 border-amber-800',
  hotspot_host: 'bg-amber-900/40 text-amber-200 border-amber-700',
  pppoe: 'bg-blue-950 text-blue-300 border-blue-800',
  neighbor: 'bg-emerald-950 text-emerald-300 border-emerald-800',
}[source] || 'bg-slate-800 text-slate-300 border-slate-600');

const typologyClass = (type) => ({
  cpe: 'bg-emerald-950 text-emerald-400 border-emerald-800',
  hotspot_client: 'bg-sky-950 text-sky-400 border-sky-800',
  bridge_client: 'bg-teal-950 text-teal-400 border-teal-800',
  access_point: 'bg-indigo-950 text-indigo-400 border-indigo-800',
  router: 'bg-rose-950 text-rose-400 border-rose-800',
  switch: 'bg-violet-950 text-violet-400 border-violet-800',
  onu: 'bg-cyan-950 text-cyan-400 border-cyan-800',
  sector: 'bg-amber-950 text-amber-400 border-amber-800',
  backhaul: 'bg-orange-950 text-orange-400 border-orange-800',
  iot: 'bg-fuchsia-950 text-fuchsia-400 border-fuchsia-800',
  server: 'bg-slate-700 text-slate-300 border-slate-600',
  unknown: 'bg-zinc-800 text-zinc-400 border-zinc-700',
}[type] || 'bg-zinc-800 text-zinc-400 border-zinc-700');

const sourceLabel = (source) => ({
  arp: 'ARP Table Entry',
  dhcp: 'DHCP Lease',
  hotspot: 'Hotspot Active Session',
  hotspot_host: 'Hotspot Host Entry',
  pppoe: 'PPPoE Active Session',
  neighbor: 'Neighbor Discovery (CDP/LLDP)',
}[source] || source);

const formatBytes = (bytes) => {
  const value = Number(bytes) || 0;
  if (value === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
  return `${(value / Math.pow(1024, index)).toFixed(index > 1 ? 2 : 0)} ${units[index]}`;
};

const boolText = (value) => (value === null || value === undefined ? null : value ? 'Yes' : 'No');

const displayValue = (value) => {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
};
</script>
