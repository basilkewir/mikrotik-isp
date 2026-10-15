<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 class="text-2xl font-extrabold text-white tracking-tight">Device Inventory</h2>
          <p class="text-sm text-slate-400">
            Full network typology — CPEs, ONUs, APs, switches, sectors, backhauls, cameras and more.
          </p>
        </div>
        <button @click="openAdd" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
          + Add Device
        </button>
      </div>

      <!-- Type chips -->
      <div class="flex flex-wrap gap-2">
        <button
          @click="filters.type = ''"
          class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
          :class="!filters.type ? 'bg-indigo-600 border-indigo-500 text-white' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'"
        >
          All · {{ totalCount }}
        </button>
        <button
          v-for="(label, value) in typeOptions"
          :key="value"
          @click="filters.type = filters.type === value ? '' : value"
          class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
          :class="filters.type === value ? 'bg-indigo-600 border-indigo-500 text-white' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'"
        >
          {{ label }} · {{ typeCounts[value] || 0 }}
        </button>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap items-center gap-3">
        <input v-model="filters.q" @keyup.enter="applyFilters" type="text" placeholder="Search MAC, IP, hostname, serial, model…" class="flex-1 min-w-[240px] bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500" />
        <select v-model="filters.router_id" @change="applyFilters" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
          <option value="">All routers</option>
          <option v-for="r in routers" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
        <button @click="applyFilters" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-lg text-sm font-medium transition">Search</button>

        <div class="ml-auto flex rounded-lg overflow-hidden border border-slate-700">
          <button @click="view = 'table'" class="px-3 py-1.5 text-xs font-semibold transition" :class="view === 'table' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300'">Table</button>
          <button @click="view = 'topology'" class="px-3 py-1.5 text-xs font-semibold transition" :class="view === 'topology' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300'">Topology</button>
        </div>
      </div>

      <!-- Topology view -->
      <div v-if="view === 'topology'" class="bg-slate-800 border border-slate-700 rounded-xl p-6">
        <div v-if="topology.length" class="space-y-3">
          <TopologyNode v-for="node in topology" :key="node.id" :node="node" :depth="0" />
        </div>
        <p v-else class="text-sm text-slate-500">No devices in inventory yet — add APs, switches and CPEs to build the topology.</p>
      </div>

      <!-- Table view -->
      <div v-else class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-slate-900/60 text-[11px] uppercase tracking-wider text-slate-400">
              <tr>
                <th class="px-5 py-3">Type</th>
                <th class="px-5 py-3">Name</th>
                <th class="px-5 py-3">MAC Address</th>
                <th class="px-5 py-3">IP Address</th>
                <th class="px-5 py-3">Router</th>
                <th class="px-5 py-3">Client</th>
                <th class="px-5 py-3">Parent</th>
                <th class="px-5 py-3">Seen</th>
                <th class="px-5 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-700/50">
              <tr v-for="device in devices.data" :key="device.id" class="hover:bg-slate-700/30 transition">
                <td class="px-5 py-3">
                  <span class="px-2 py-0.5 rounded text-[11px] font-bold border" :class="typeBadge(device.type)">
                    {{ device.type_label }}
                  </span>
                </td>
                <td class="px-5 py-3 text-white font-medium">{{ device.hostname || '—' }}
                  <div v-if="device.model" class="text-[11px] text-slate-500 font-mono">{{ device.model }}</div>
                </td>
                <td class="px-5 py-3 font-mono text-slate-300">{{ device.mac_address }}</td>
                <td class="px-5 py-3 font-mono text-slate-300">{{ device.ip_address || '—' }}</td>
                <td class="px-5 py-3 text-slate-300">{{ device.router?.name || '—' }}</td>
                <td class="px-5 py-3 text-slate-300">
                  <template v-if="device.client">
                    <span class="text-emerald-400">{{ device.client.name }}</span>
                    <div class="text-[11px] font-mono text-slate-500">{{ device.client.client_code }}</div>
                  </template>
                  <span v-else class="text-slate-600">—</span>
                </td>
                <td class="px-5 py-3 text-slate-400 text-xs">
                  {{ device.parent ? (device.parent.hostname || device.parent.mac_address) : '—' }}
                </td>
                <td class="px-5 py-3">
                  <span class="inline-flex items-center gap-1.5 text-xs" :class="device.is_online ? 'text-emerald-400' : 'text-slate-500'">
                    <span class="w-1.5 h-1.5 rounded-full" :class="device.is_online ? 'bg-emerald-400' : 'bg-slate-600'"></span>
                    {{ device.is_online ? 'Online' : 'Offline' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-right whitespace-nowrap">
                  <button @click="openEdit(device)" class="text-xs px-2.5 py-1 mr-1 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded font-medium transition">Edit</button>
                  <button @click="destroy(device)" class="text-xs px-2.5 py-1 bg-rose-900/60 hover:bg-rose-800 text-rose-300 rounded font-medium transition">Delete</button>
                </td>
              </tr>
              <tr v-if="!devices.data.length">
                <td colspan="9" class="px-5 py-10 text-center text-slate-500 text-sm">No devices match the current filters.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="devices.links?.length > 3" class="flex items-center justify-between px-5 py-3 border-t border-slate-700/60 bg-slate-900/40">
          <span class="text-xs text-slate-500">Showing {{ devices.from }}–{{ devices.to }} of {{ devices.total }}</span>
          <div class="flex gap-1">
            <button
              v-for="(link, i) in devices.links"
              :key="i"
              v-html="link.label"
              @click="link.url && visit(link.url)"
              class="px-2.5 py-1 rounded text-xs font-medium transition"
              :class="link.active ? 'bg-indigo-600 text-white' : (link.url ? 'bg-slate-800 text-slate-300 hover:bg-slate-700' : 'bg-slate-800/50 text-slate-600')"
            ></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Add / Edit modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-2xl p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-start justify-between mb-4">
          <h3 class="text-lg font-bold text-white">{{ editing ? 'Edit Device' : 'Add Device' }}</h3>
          <button @click="showModal = false" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Type</label>
              <select v-model="form.type" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <optgroup label="Customer edge">
                  <option v-for="(label, value) in customerTypes" :key="value" :value="value">{{ label }}</option>
                </optgroup>
                <optgroup label="Infrastructure">
                  <option v-for="(label, value) in infraTypes" :key="value" :value="value">{{ label }}</option>
                </optgroup>
                <optgroup label="Other">
                  <option value="iot">Camera / IoT</option>
                  <option value="server">Server / Infrastructure</option>
                  <option value="unknown">Unknown / Guest</option>
                </optgroup>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">MAC Address</label>
              <input v-model="form.mac_address" type="text" placeholder="AA:BB:CC:DD:EE:FF" pattern="([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Hostname</label>
              <input v-model="form.hostname" type="text" maxlength="100" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">IP Address</label>
              <input v-model="form.ip_address" type="text" placeholder="192.168.20.10" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Router</label>
              <select v-model="form.router_id" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option :value="null">— none —</option>
                <option v-for="r in routers" :key="r.id" :value="r.id">{{ r.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Client (if customer-owned)</label>
              <select v-model="form.client_id" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option :value="null">— infrastructure / unassigned —</option>
                <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} ({{ c.client_code }})</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Parent Device</label>
              <select v-model="form.parent_device_id" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option :value="null">— none —</option>
                <option v-for="p in parentOptions.filter(o => o.id !== editing?.id)" :key="p.id" :value="p.id">{{ p.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Serial Number</label>
              <input v-model="form.serial_number" type="text" maxlength="64" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Model</label>
              <input v-model="form.model" type="text" maxlength="100" placeholder="RB951Ui-2HnD" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Manufacturer</label>
              <input v-model="form.manufacturer" type="text" maxlength="80" placeholder="MikroTik / Ubiquiti / Huawei…" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Firmware</label>
              <input v-model="form.firmware_version" type="text" maxlength="50" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Interface</label>
              <input v-model="form.interface" type="text" maxlength="50" placeholder="ether2 / wlan1" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Management IP</label>
              <input v-model="form.management_ip" type="text" placeholder="10.0.0.5" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Notes</label>
            <textarea v-model="form.notes" rows="2" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white"></textarea>
          </div>

          <div class="flex justify-end gap-3 pt-3 border-t border-slate-700/60">
            <button type="button" @click="showModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              {{ editing ? 'Save Changes' : 'Add Device' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import TopologyNode from '../../Components/TopologyNode.vue';

const props = defineProps({
  devices: Object,
  typeCounts: Object,
  routers: Array,
  clients: Array,
  typeOptions: Object,
  parentOptions: Array,
  filters: Object,
  topology: Array,
});

const view = ref('table');
const showModal = ref(false);
const editing = ref(null);

const filters = reactive({
  q: props.filters?.q || '',
  type: props.filters?.type || '',
  router_id: props.filters?.router_id || '',
});

const totalCount = computed(() => Object.values(props.typeCounts || {}).reduce((a, b) => a + b, 0));

const CUSTOMER_TYPES = ['cpe', 'hotspot_client', 'bridge_client', 'onu'];
const INFRA_TYPES = ['access_point', 'router', 'switch', 'sector', 'backhaul'];

const customerTypes = computed(() => Object.fromEntries(Object.entries(props.typeOptions).filter(([v]) => CUSTOMER_TYPES.includes(v))));
const infraTypes = computed(() => Object.fromEntries(Object.entries(props.typeOptions).filter(([v]) => INFRA_TYPES.includes(v))));

const emptyForm = () => ({
  type: 'cpe',
  mac_address: '',
  hostname: '',
  ip_address: '',
  router_id: null,
  client_id: null,
  parent_device_id: null,
  serial_number: '',
  model: '',
  manufacturer: '',
  firmware_version: '',
  interface: '',
  management_ip: '',
  notes: '',
});

const form = ref(emptyForm());

const typeBadge = (type) => {
  const colors = {
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
  };
  return colors[type] || colors.unknown;
};

const applyFilters = () => {
  inertiaRouter.get('/inventory', filters, { preserveState: true, replace: true });
};

const visit = (url) => inertiaRouter.get(url, filters, { preserveState: true, replace: true });

const openAdd = () => {
  editing.value = null;
  form.value = emptyForm();
  showModal.value = true;
};

const openEdit = (device) => {
  editing.value = device;
  form.value = {
    type: device.type,
    mac_address: device.mac_address,
    hostname: device.hostname || '',
    ip_address: device.ip_address || '',
    router_id: device.router_id,
    client_id: device.client_id,
    parent_device_id: device.parent_device_id,
    serial_number: device.serial_number || '',
    model: device.model || '',
    manufacturer: device.manufacturer || '',
    firmware_version: device.firmware_version || '',
    interface: device.interface || '',
    management_ip: device.management_ip || '',
    notes: device.notes || '',
  };
  showModal.value = true;
};

const submit = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => {
      showModal.value = false;
      editing.value = null;
    },
  };

  if (editing.value) {
    inertiaRouter.put(`/inventory/${editing.value.id}`, form.value, options);
  } else {
    inertiaRouter.post('/inventory', form.value, options);
  }
};

const destroy = (device) => {
  if (confirm(`Delete ${device.hostname || device.mac_address} from inventory?`)) {
    inertiaRouter.delete(`/inventory/${device.id}`, { preserveScroll: true });
  }
};
</script>
