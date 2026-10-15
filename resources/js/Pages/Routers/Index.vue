<template>
  <AppLayout>
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold tracking-tight text-white">MikroTik Router Devices</h2>
        <p class="text-sm text-slate-400">Manage RouterOS instances, credentials, API ports, and view the plans/profiles configured on each router.</p>
      </div>
      <button @click="openAddModal" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold shadow transition">
        + Add New Router
      </button>
    </div>

    <!-- Routers Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
      <div v-for="router in routers" :key="router.id" class="bg-slate-800 border border-slate-700/60 rounded-xl p-5 shadow-sm flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span class="w-2.5 h-2.5 rounded-full" :class="router.is_online ? 'bg-emerald-400' : 'bg-rose-400'"></span>
              <h3 class="font-bold text-white text-base">{{ router.name }}</h3>
            </div>
            <span class="text-xs px-2 py-0.5 rounded font-mono" :class="router.is_online ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-rose-950 text-rose-400 border border-rose-800'">
              {{ router.is_online ? 'ONLINE' : 'OFFLINE' }}
            </span>
          </div>

          <div class="space-y-1.5 text-xs text-slate-300 font-mono bg-slate-900/50 p-3 rounded-lg border border-slate-700/40 mb-4">
            <div class="flex justify-between">
              <span class="text-slate-500">Host IP:</span>
              <span>{{ router.ip_address }}:{{ router.api_port }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">Identity:</span>
              <span class="text-indigo-300">{{ router.identity || 'Not probed' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">Model / Version:</span>
              <span>{{ router.board_name || '—' }} ({{ router.ros_version || 'ROS' }})</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">CPU Load:</span>
              <span>{{ router.cpu_load }}%</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">Router accounts:</span>
              <span v-if="router.hotspot_users_count !== null">
                {{ router.hotspot_users_count }} Hotspot / {{ router.pppoe_users_count }} PPPoE
              </span>
              <span v-else class="text-rose-300">Unavailable</span>
            </div>
          </div>
          <p v-if="router.connection_error" class="mb-4 text-xs text-rose-300">{{ router.connection_error }}</p>
        </div>

        <div class="pt-3 border-t border-slate-700/40 space-y-3">
          <div class="flex items-center justify-between">
            <Link :href="`/routers/${router.id}/test`" method="post" as="button" preserve-scroll class="text-xs px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded font-medium transition">
              Test Connection
            </Link>
            <span class="text-[11px] text-slate-500 font-mono">
              {{ router.last_seen_at ? 'Synced ' + new Date(router.last_seen_at).toLocaleTimeString() : 'Never synced' }}
            </span>
          </div>

          <div class="flex items-center justify-between gap-2">
            <button @click="openProfiles(router)" class="flex-1 text-xs px-3 py-1.5 bg-indigo-900/60 hover:bg-indigo-800 text-indigo-300 rounded font-medium transition">
              View Router Plans
            </button>
            <button @click="openEdit(router)" class="text-xs px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded font-medium transition">
              Edit
            </button>
            <button @click="deleteRouter(router)" class="text-xs px-3 py-1.5 bg-rose-900/60 hover:bg-rose-800 text-rose-300 rounded font-medium transition">
              Delete
            </button>
          </div>

          <button @click="openFirewall(router)" class="w-full mt-2 text-xs px-3 py-1.5 bg-amber-900/40 hover:bg-amber-900/70 text-amber-200 border border-amber-800/60 rounded font-medium transition">
            Firewall Policy · MAC Allow-List
          </button>
        </div>
      </div>
    </div>

    <p v-if="!routers.length" class="text-center text-slate-400 text-sm py-8">No routers registered yet.</p>

    <!-- Add / Edit Router Modal -->
    <div v-if="showRouterModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">{{ editingRouter ? 'Edit Router' : 'Connect New RouterOS Device' }}</h3>

        <form @submit.prevent="submitRouter" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Friendly Name</label>
            <input v-model="form.name" type="text" placeholder="e.g. Core Gateway RB951Ui" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500" />
          </div>

          <div class="grid grid-cols-3 gap-3">
            <div class="col-span-2">
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">IP Address / Host</label>
              <input v-model="form.ip_address" type="text" placeholder="192.168.88.1" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">API Port</label>
              <input v-model.number="form.api_port" type="number" placeholder="8728" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">API Username</label>
              <input v-model="form.api_username" type="text" placeholder="admin" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">API Password</label>
              <input v-model="form.api_password" type="password" :placeholder="editingRouter ? 'Leave blank to keep current' : ''" :required="!editingRouter" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
          </div>

          <div class="flex items-center gap-2 pt-2">
            <input v-model="form.use_ssl" type="checkbox" id="use_ssl" class="rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500" />
            <label for="use_ssl" class="text-xs text-slate-300">Use SSL / TLS (Default port 8729)</label>
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showRouterModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">
              Cancel
            </button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              {{ editingRouter ? 'Save Changes' : 'Save & Probe Router' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Router Plans / Profiles Modal -->
    <div v-if="showProfilesModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-4xl p-6 shadow-2xl max-h-[85vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h3 class="text-lg font-bold text-white">Plans on "{{ profilesRouter?.name }}"</h3>
            <p class="text-xs text-slate-400">Live Hotspot & PPPoE profiles fetched directly from RouterOS.</p>
          </div>
          <button @click="showProfilesModal = false" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
        </div>

        <div v-if="profilesLoading" class="py-10 text-center text-sm text-slate-400">
          Fetching profiles from router...
        </div>

        <div v-else-if="profilesError" class="py-6 text-center text-sm text-rose-300">
          {{ profilesError }}
        </div>

        <template v-else-if="profiles">
          <!-- Hotspot Profiles -->
          <div class="mb-6">
            <h4 class="text-xs uppercase font-bold tracking-wider text-amber-400 mb-2">Hotspot Profiles ({{ profiles.hotspot_profiles.length }})</h4>
            <div class="overflow-x-auto border border-slate-700/60 rounded-lg">
              <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-900/60 text-[11px] uppercase text-slate-400">
                  <tr>
                    <th class="px-3 py-2">Profile Name</th>
                    <th class="px-3 py-2">Rate Limit (Up/Down)</th>
                    <th class="px-3 py-2">Session Timeout</th>
                    <th class="px-3 py-2">Idle Timeout</th>
                    <th class="px-3 py-2">Shared Users</th>
                    <th class="px-3 py-2">Address Pool</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/40">
                  <tr v-for="p in profiles.hotspot_profiles" :key="'hs-' + p.name">
                    <td class="px-3 py-2 font-mono font-bold text-white">{{ p.name }}</td>
                    <td class="px-3 py-2 font-mono text-emerald-400">{{ p['rate-limit'] || 'unlimited' }}</td>
                    <td class="px-3 py-2 font-mono">{{ p['session-timeout'] || '—' }}</td>
                    <td class="px-3 py-2 font-mono">{{ p['idle-timeout'] || '—' }}</td>
                    <td class="px-3 py-2 font-mono">{{ p['shared-users'] || '—' }}</td>
                    <td class="px-3 py-2 font-mono">{{ p['address-pool'] || '—' }}</td>
                  </tr>
                  <tr v-if="!profiles.hotspot_profiles.length">
                    <td colspan="6" class="px-3 py-4 text-center text-slate-500">No hotspot profiles configured on this router.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- PPPoE Profiles -->
          <div>
            <h4 class="text-xs uppercase font-bold tracking-wider text-blue-400 mb-2">PPPoE / PPP Profiles ({{ profiles.pppoe_profiles.length }})</h4>
            <div class="overflow-x-auto border border-slate-700/60 rounded-lg">
              <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-900/60 text-[11px] uppercase text-slate-400">
                  <tr>
                    <th class="px-3 py-2">Profile Name</th>
                    <th class="px-3 py-2">Rate Limit (Up/Down)</th>
                    <th class="px-3 py-2">Remote Address</th>
                    <th class="px-3 py-2">Local Address</th>
                    <th class="px-3 py-2">Only One</th>
                    <th class="px-3 py-2">DNS Servers</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/40">
                  <tr v-for="p in profiles.pppoe_profiles" :key="'pp-' + p.name">
                    <td class="px-3 py-2 font-mono font-bold text-white">{{ p.name }}</td>
                    <td class="px-3 py-2 font-mono text-emerald-400">{{ p['rate-limit'] || 'unlimited' }}</td>
                    <td class="px-3 py-2 font-mono">{{ p['remote-address'] || '—' }}</td>
                    <td class="px-3 py-2 font-mono">{{ p['local-address'] || '—' }}</td>
                    <td class="px-3 py-2 font-mono">{{ p['only-one'] || '—' }}</td>
                    <td class="px-3 py-2 font-mono">{{ p['dns-server'] || '—' }}</td>
                  </tr>
                  <tr v-if="!profiles.pppoe_profiles.length">
                    <td colspan="6" class="px-3 py-4 text-center text-slate-500">No PPP profiles configured on this router.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Firewall Policy (MAC Allow-List) Modal -->
    <div v-if="showFirewallModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-3xl p-6 shadow-2xl max-h-[85vh] overflow-y-auto">
        <div class="flex items-start justify-between mb-4">
          <div>
            <h3 class="text-lg font-bold text-white">Firewall Policy · "{{ firewallRouter?.name }}"</h3>
            <p class="text-xs text-slate-400">
              Default-deny internet for direct (non-hotspot) devices unless their IP is on the
              <span class="font-mono text-amber-300">active-clients</span> allow-list.
            </p>
          </div>
          <button @click="showFirewallModal = false" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
        </div>

        <div v-if="policyLoading" class="py-10 text-center text-sm text-slate-400">Reading policy state from router…</div>
        <div v-else-if="policyError" class="py-6 text-center text-sm text-rose-300">{{ policyError }}</div>

        <template v-else-if="policy">
          <!-- State summary -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
            <div class="bg-slate-900/60 border border-slate-700/50 rounded-lg p-3">
              <div class="text-[11px] uppercase text-slate-400 mb-1">Default-Deny Rule</div>
              <span class="px-2 py-0.5 rounded text-xs font-bold" :class="!policy.installed ? 'bg-slate-800 text-slate-400 border border-slate-700' : (policy.effective ? 'bg-rose-950 text-rose-400 border border-rose-800' : 'bg-amber-950 text-amber-400 border border-amber-800')">
                {{ !policy.installed ? 'NOT INSTALLED' : (policy.effective ? 'INSTALLED · ENFORCING' : 'INSTALLED · NOT ENFORCING') }}
              </span>
            </div>
            <div class="bg-slate-900/60 border border-slate-700/50 rounded-lg p-3">
              <div class="text-[11px] uppercase text-slate-400 mb-1">WAN Interface</div>
              <div class="text-sm font-mono text-emerald-400">{{ policy.wan_interface || 'not detected' }}</div>
            </div>
            <div class="bg-slate-900/60 border border-slate-700/50 rounded-lg p-3">
              <div class="text-[11px] uppercase text-slate-400 mb-1">Allow-List Entries</div>
              <div class="text-sm font-mono text-amber-300">{{ policy.allow_list_entries }}</div>
            </div>
            <div class="bg-slate-900/60 border border-slate-700/50 rounded-lg p-3">
              <div class="text-[11px] uppercase text-slate-400 mb-1">ISP-BILLING Rules</div>
              <div class="text-sm font-mono text-indigo-300">{{ policy.rules.length }}</div>
            </div>
          </div>

          <!-- Controls -->
          <div class="bg-slate-900/60 border border-slate-700/50 rounded-lg p-4 mb-5">
            <div class="grid md:grid-cols-2 gap-4 mb-4">
              <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">WAN Interface</label>
                <input v-model="wanInput" type="text" placeholder="auto-detected" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
              </div>
              <div class="flex items-end pb-1">
                <label class="flex items-start gap-2 text-xs text-slate-300">
                  <input v-model="seedInput" type="checkbox" class="mt-0.5 rounded bg-slate-800 border-slate-700 text-amber-600 focus:ring-amber-500" />
                  Seed allow-list with all currently connected devices before installing (recommended — nobody gets cut off)
                </label>
              </div>
            </div>

            <div class="flex flex-wrap gap-3 pt-3 border-t border-slate-700/60">
              <button @click="installPolicy" :disabled="policyBusy" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 disabled:opacity-50 text-white rounded-lg text-sm font-semibold transition">
                {{ policyBusy ? 'Applying…' : (policy.installed ? 'Update Policy' : 'Install Policy') }}
              </button>
              <button v-if="policy.installed" @click="removePolicy" :disabled="policyBusy" class="px-4 py-2 bg-rose-900/60 hover:bg-rose-800 disabled:opacity-50 text-rose-200 rounded-lg text-sm font-semibold transition">
                Remove Policy
              </button>
              <button @click="openFirewall(firewallRouter)" :disabled="policyBusy" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-slate-300 rounded-lg text-sm font-medium transition">
                Refresh State
              </button>
            </div>
            <p class="mt-3 text-[11px] text-slate-500">
              The three ISP-BILLING rules (allow-list accept, hotspot-authenticated accept, default-deny drop) are inserted directly
              <span class="font-mono text-slate-300">before the router's main "accept all LAN → WAN" rule</span>
              on the forward chain and scoped to
              <span class="font-mono">{{ wanInput || policy.wan_interface || 'WAN' }}</span>.
              Existing hotspot, blocklist and management rules keep their priority; router management access is never affected.
            </p>
          </div>

          <!-- Rules -->
          <div class="mb-5">
            <h4 class="text-xs uppercase font-bold tracking-wider text-rose-400 mb-2">ISP-BILLING Filter Rules</h4>
            <div v-if="policy.rules.length" class="border border-slate-700/60 rounded-lg overflow-x-auto">
              <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-900/60 text-[11px] uppercase text-slate-400">
                  <tr>
                    <th class="px-3 py-2">Action</th>
                    <th class="px-3 py-2">Out Interface</th>
                    <th class="px-3 py-2">Comment</th>
                    <th class="px-3 py-2">Disabled</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/40">
                  <tr v-for="rule in policy.rules" :key="rule['.id'] || rule.comment">
                    <td class="px-3 py-2 font-mono font-bold" :class="rule.action === 'drop' ? 'text-rose-400' : 'text-emerald-400'">{{ rule.action }}</td>
                    <td class="px-3 py-2 font-mono">{{ rule['out-interface'] || '—' }}</td>
                    <td class="px-3 py-2 font-mono">{{ rule.comment }}</td>
                    <td class="px-3 py-2 font-mono">{{ rule.disabled }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="text-xs text-slate-500">No ISP-BILLING rules on this router yet.</p>
          </div>

          <!-- Allow-list sample -->
          <div>
            <h4 class="text-xs uppercase font-bold tracking-wider text-emerald-400 mb-2">
              active-clients Allow-List ({{ policy.allow_list_entries }} entries, first {{ policy.allow_list_sample.length }} shown)
            </h4>
            <div v-if="policy.allow_list_sample.length" class="border border-slate-700/60 rounded-lg overflow-x-auto">
              <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-900/60 text-[11px] uppercase text-slate-400">
                  <tr>
                    <th class="px-3 py-2">Address</th>
                    <th class="px-3 py-2">Comment</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/40">
                  <tr v-for="entry in policy.allow_list_sample" :key="entry['.id'] || entry.address">
                    <td class="px-3 py-2 font-mono text-white">{{ entry.address }}</td>
                    <td class="px-3 py-2 font-mono text-slate-400">{{ entry.comment || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="text-xs text-slate-500">Allow-list is empty.</p>
          </div>
        </template>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({
  routers: Array,
});

const emptyForm = () => ({
  name: '',
  ip_address: '',
  api_port: 8728,
  api_username: 'admin',
  api_password: '',
  use_ssl: false,
});

const showRouterModal = ref(false);
const editingRouter = ref(null);
const form = ref(emptyForm());

const showProfilesModal = ref(false);
const profilesRouter = ref(null);
const profiles = ref(null);
const profilesLoading = ref(false);
const profilesError = ref(null);

const openAddModal = () => {
  editingRouter.value = null;
  form.value = emptyForm();
  showRouterModal.value = true;
};

const openEdit = (router) => {
  editingRouter.value = router;
  form.value = {
    name: router.name,
    ip_address: router.ip_address,
    api_port: router.api_port,
    api_username: router.api_username || 'admin',
    api_password: '',
    use_ssl: !!router.use_ssl,
  };
  showRouterModal.value = true;
};

const submitRouter = () => {
  const options = {
    onSuccess: () => {
      showRouterModal.value = false;
      form.value = emptyForm();
      editingRouter.value = null;
    },
  };

  if (editingRouter.value) {
    inertiaRouter.put(`/routers/${editingRouter.value.id}`, form.value, options);
  } else {
    inertiaRouter.post('/routers', form.value, options);
  }
};

const deleteRouter = (router) => {
  if (confirm(`Delete router "${router.name}"? Its subscribers and devices will remain in the database without a router.`)) {
    inertiaRouter.delete(`/routers/${router.id}`);
  }
};

const openProfiles = async (router) => {
  profilesRouter.value = router;
  profiles.value = null;
  profilesError.value = null;
  profilesLoading.value = true;
  showProfilesModal.value = true;

  try {
    const response = await fetch(`/routers/${router.id}/profiles`);
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.message || 'Failed to fetch profiles from router.');
    }

    profiles.value = payload;
  } catch (error) {
    profilesError.value = error.message || 'Failed to fetch profiles from router.';
  } finally {
    profilesLoading.value = false;
  }
};

const showFirewallModal = ref(false);
const firewallRouter = ref(null);
const policy = ref(null);
const policyLoading = ref(false);
const policyError = ref(null);
const policyBusy = ref(false);
const wanInput = ref('');
const seedInput = ref(true);

const xsrfToken = () => {
  const cookie = document.cookie
    .split('; ')
    .find((row) => row.startsWith('XSRF-TOKEN='));
  return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
};

const openFirewall = async (router) => {
  firewallRouter.value = router;
  policy.value = null;
  policyError.value = null;
  policyLoading.value = true;
  showFirewallModal.value = true;

  try {
    const response = await fetch(`/routers/${router.id}/firewall-policy`);
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.message || 'Failed to read policy state from router.');
    }

    policy.value = payload;
    wanInput.value = payload.wan_interface || '';
  } catch (error) {
    policyError.value = error.message || 'Failed to read policy state from router.';
  } finally {
    policyLoading.value = false;
  }
};

const installPolicy = async () => {
  if (!firewallRouter.value || policyBusy.value) return;

  if (!confirm(`Apply the default-deny MAC allow-list policy on "${firewallRouter.value.name}"? Existing connected devices are ${seedInput.value ? 'seeded into the allow-list (nobody gets cut off)' : 'NOT seeded — only allow-listed IPs will keep internet access'}.`)) {
    return;
  }

  policyBusy.value = true;
  policyError.value = null;

  try {
    const response = await fetch(`/routers/${firewallRouter.value.id}/firewall-policy`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': xsrfToken(),
      },
      body: JSON.stringify({ wan_interface: wanInput.value || null, seed: seedInput.value }),
    });
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.message || 'Failed to install policy.');
    }

    await openFirewall(firewallRouter.value);
  } catch (error) {
    policyError.value = error.message || 'Failed to install policy.';
    policyLoading.value = false;
    showFirewallModal.value = true;
  } finally {
    policyBusy.value = false;
  }
};

const removePolicy = async () => {
  if (!firewallRouter.value || policyBusy.value) return;

  if (!confirm(`Remove the default-deny firewall rules from "${firewallRouter.value.name}"? Allow-list entries are kept, so re-installing later is safe.`)) {
    return;
  }

  policyBusy.value = true;
  policyError.value = null;

  try {
    const response = await fetch(`/routers/${firewallRouter.value.id}/firewall-policy`, {
      method: 'DELETE',
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
    });
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.message || 'Failed to remove policy.');
    }

    await openFirewall(firewallRouter.value);
  } catch (error) {
    policyError.value = error.message || 'Failed to remove policy.';
    policyLoading.value = false;
    showFirewallModal.value = true;
  } finally {
    policyBusy.value = false;
  }
};
</script>
