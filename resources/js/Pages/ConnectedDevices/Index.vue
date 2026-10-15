<template>
  <AppLayout full-width>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold tracking-tight text-white">Connected Devices</h2>
        <p class="text-sm text-slate-400">
          Every device seen by the routers — ARP, DHCP, Hotspot sessions/hosts, PPPoE and CDP/LLDP neighbours — merged by MAC. Manage them inline: onboard, block, kick, rate-limit and control access.
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
    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
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
        <div class="text-xs uppercase text-slate-400 mb-1">Managed</div>
        <div class="text-2xl font-extrabold text-sky-400">{{ filtered.filter(d => d.managed_device_id).length }}</div>
      </div>
      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-4">
        <div class="text-xs uppercase text-slate-400 mb-1">Blocked</div>
        <div class="text-2xl font-extrabold text-rose-400">{{ filtered.filter(d => d.is_blocked).length }}</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="mb-6 flex flex-col lg:flex-row gap-3">
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
      <select v-model="sourceFilter" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <option value="">All sources</option>
        <option value="arp">ARP</option>
        <option value="dhcp">DHCP</option>
        <option value="hotspot">Hotspot session</option>
        <option value="hotspot_host">Hotspot host</option>
        <option value="pppoe">PPPoE</option>
        <option value="neighbor">Neighbor</option>
      </select>
      <select v-model="typologyFilter" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <option value="">All typologies</option>
        <option v-for="(label, value) in typeOptions" :key="value" :value="value">{{ label }}</option>
      </select>
      <select v-model="managedFilter" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <option value="">All devices</option>
        <option value="managed">Managed only</option>
        <option value="guest">Guest / unmanaged</option>
        <option value="blocked">Blocked only</option>
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
              <th class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/40">
            <tr v-for="device in filtered" :key="device.key + device.router_id" class="hover:bg-slate-700/20 transition">
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase" :class="statusClass(device.status)">
                  {{ device.status }}
                </span>
                <span v-if="device.is_blocked" class="block mt-1 px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-rose-950 text-rose-400 border border-rose-800 w-fit">Blocked</span>
              </td>
              <td class="px-4 py-3">
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase border" :class="typologyClass(device.typology)">
                  {{ device.typology_label || 'Unknown' }}
                </span>
                <span v-if="device.client_name" class="block text-[11px] text-emerald-400 mt-0.5">{{ device.client_name }}</span>
                <span v-else-if="!device.managed_device_id" class="block text-[11px] text-slate-500 mt-0.5">Guest</span>
                <span v-if="device.managed_device_id && device.access_expires_at" class="block text-[10px] font-mono mt-0.5"
                  :class="device.access_expired ? 'text-rose-400' : 'text-slate-400'">
                  {{ device.access_expired ? 'expired' : 'exp' }} {{ new Date(device.access_expires_at).toLocaleDateString() }}
                </span>
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
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <div class="inline-flex items-center gap-1">
                  <button @click="selected = device" class="text-[11px] px-2 py-1 bg-slate-700 hover:bg-slate-600 text-indigo-300 rounded font-medium transition" title="Details & all actions">Details</button>
                  <button v-if="!device.managed_device_id && device.mac_address" @click="openOnboard(device)" class="text-[11px] px-2 py-1 bg-emerald-900/60 hover:bg-emerald-800 text-emerald-300 rounded font-medium transition" title="Add to managed inventory">Onboard</button>
                  <button v-if="device.is_blocked" @click="doUnblock(device)" class="text-[11px] px-2 py-1 bg-slate-700 hover:bg-slate-600 text-emerald-300 rounded font-medium transition" title="Unblock IP">Unblock</button>
                  <button v-else-if="device.ip_address" @click="doBlock(device)" class="text-[11px] px-2 py-1 bg-rose-900/50 hover:bg-rose-800 text-rose-300 rounded font-medium transition" title="Block IP + kick session">Block</button>
                  <button v-if="device.user && hasSession(device)" @click="doKick(device)" class="text-[11px] px-2 py-1 bg-amber-900/50 hover:bg-amber-800 text-amber-300 rounded font-medium transition" title="Disconnect session">Kick</button>
                </div>
              </td>
            </tr>
            <tr v-if="!filtered.length">
              <td colspan="12" class="px-6 py-10 text-center text-slate-400 text-sm">
                No devices found. Routers may be offline, or no clients are currently connected.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Device Details + Actions Modal -->
    <div v-if="selected" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-4xl p-6 shadow-2xl max-h-[85vh] overflow-y-auto">
        <div class="flex items-start justify-between mb-4">
          <div>
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
              {{ selected.hostname || selected.ip_address || selected.mac_address || 'Device' }}
              <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase" :class="statusClass(selected.status)">{{ selected.status }}</span>
              <span v-if="selected.is_blocked" class="px-2 py-0.5 rounded text-[11px] font-bold uppercase bg-rose-950 text-rose-400 border border-rose-800">Blocked</span>
            </h3>
            <p class="text-xs text-slate-400 font-mono">
              {{ selected.router_name }} · {{ selected.ip_address || 'no IP' }} · {{ selected.mac_address || 'no MAC' }}
            </p>
          </div>
          <button @click="selected = null" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
        </div>

        <!-- Management actions -->
        <div class="mb-5 bg-slate-900/60 border border-slate-700/50 rounded-lg p-4">
          <h4 class="text-xs uppercase font-bold tracking-wider text-indigo-400 mb-3">Management</h4>
          <div class="flex flex-wrap gap-2">
            <button v-if="!selected.managed_device_id && selected.mac_address" @click="openOnboard(selected)" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs font-semibold transition">
              Onboard to Inventory
            </button>
            <template v-if="selected.managed_device_id">
              <button @click="openRenew(selected)" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs font-semibold transition">
                Renew Access
              </button>
              <button @click="doDisable(selected)" class="px-3 py-1.5 bg-rose-600 hover:bg-rose-500 text-white rounded-lg text-xs font-semibold transition">
                Disable Access
              </button>
              <a :href="`/clients/${selected.managed_client_id || ''}`" v-if="selected.managed_client_id" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-indigo-300 rounded-lg text-xs font-semibold transition">
                View Client
              </a>
            </template>
            <button v-if="selected.is_blocked" @click="doUnblock(selected)" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-emerald-300 rounded-lg text-xs font-semibold transition">
              Unblock IP
            </button>
            <button v-else-if="selected.ip_address" @click="doBlock(selected)" class="px-3 py-1.5 bg-rose-600 hover:bg-rose-500 text-white rounded-lg text-xs font-semibold transition">
              Block Device
            </button>
            <button v-if="selected.user && hasSession(selected)" @click="doKick(selected)" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-500 text-white rounded-lg text-xs font-semibold transition">
              Kick Session
            </button>
            <button v-if="selected.ip_address" @click="openSpeed(selected)" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-semibold transition">
              Set Speed Limit
            </button>
          </div>
          <!-- Access status for managed customer-edge devices -->
          <div v-if="selected.managed_device_id" class="mt-3 pt-3 border-t border-slate-800/60 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
            <span class="text-slate-400">Access:</span>
            <span v-if="selected.access_expired" class="px-2 py-0.5 rounded font-bold uppercase bg-rose-950 text-rose-400 border border-rose-800">Expired</span>
            <span v-else-if="selected.access_is_active" class="px-2 py-0.5 rounded font-bold uppercase bg-emerald-950 text-emerald-400 border border-emerald-800">Active</span>
            <span v-else class="px-2 py-0.5 rounded font-bold uppercase bg-slate-700 text-slate-300 border border-slate-600">Inactive</span>
            <span v-if="selected.access_expires_at" class="text-slate-300 font-mono">expires {{ new Date(selected.access_expires_at).toLocaleDateString() }}</span>
            <span v-if="selected.access_duration_days" class="text-slate-400 font-mono">· {{ selected.access_duration_days }}-day period</span>
          </div>
        </div>

        <!-- Merged summary -->
        <div class="mb-5 bg-slate-900/60 border border-slate-700/50 rounded-lg p-4">
          <h4 class="text-xs uppercase font-bold tracking-wider text-indigo-400 mb-3">Merged Summary</h4>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-2 text-xs">
            <Detail label="Router" :value="selected.router_name" />
            <Detail label="Status" :value="selected.status" />
            <Detail label="Typology" :value="selected.typology_label" />
            <Detail label="Client" :value="selected.client_name" />
            <Detail label="Blocked" :value="boolText(selected.is_blocked)" />
            <Detail label="Access Expires" :value="selected.access_expires_at ? new Date(selected.access_expires_at).toLocaleDateString() : null" />
            <Detail label="Access Period" :value="selected.access_duration_days ? selected.access_duration_days + ' days' : null" />
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

    <!-- Onboard Modal -->
    <div v-if="onboardDevice" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-1">Onboard Device</h3>
        <p class="text-xs text-slate-400 font-mono mb-4">
          {{ onboardDevice.mac_address }} · {{ onboardDevice.ip_address || 'no IP' }} · {{ onboardDevice.router_name }}
        </p>
        <form @submit.prevent="submitOnboard" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Typology</label>
            <select v-model="onboardForm.type" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
              <option v-for="(label, value) in typeOptions" :key="value" :value="value">{{ label }}</option>
            </select>
            <p class="text-[11px] text-slate-500 mt-1">Customer-edge types (CPE, Hotspot, Bridge, ONU) are MAC-authorized on the router.</p>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Assign to Client <span class="normal-case font-normal text-slate-500">(optional)</span></label>
            <select v-model="onboardForm.client_id" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
              <option value="">— Guest / unassigned —</option>
              <option v-for="c in clients" :key="c.id" :value="String(c.id)">{{ c.name }} ({{ c.client_code }})</option>
            </select>
          </div>
          <div v-if="isCustomerEdge(onboardForm.type)">
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Access Duration</label>
            <div class="flex flex-wrap gap-2 mb-2">
              <button v-for="d in durationPresets" :key="d" type="button" @click="onboardForm.access_days = d"
                class="px-3 py-1.5 rounded-lg text-xs font-semibold transition"
                :class="onboardForm.access_days === d ? 'bg-emerald-600 text-white' : 'bg-slate-900 text-slate-300 border border-slate-700 hover:bg-slate-700'">
                {{ d }} days
              </button>
              <button type="button" @click="onboardForm.access_days = 'custom'"
                class="px-3 py-1.5 rounded-lg text-xs font-semibold transition"
                :class="onboardForm.access_days === 'custom' ? 'bg-emerald-600 text-white' : 'bg-slate-900 text-slate-300 border border-slate-700 hover:bg-slate-700'">
                Custom
              </button>
            </div>
            <input v-if="onboardForm.access_days === 'custom'" v-model.number="onboardForm.custom_days" type="number" min="1" max="3650" placeholder="Number of days" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            <p class="text-[11px] text-slate-500 mt-1">Access expires this many days from now (calendar-based).</p>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Comment <span class="normal-case font-normal text-slate-500">(optional)</span></label>
            <input v-model="onboardForm.comment" type="text" maxlength="150" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>
          <div class="flex justify-end gap-3 pt-3 border-t border-slate-700/60">
            <button type="button" @click="onboardDevice = null" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-sm font-semibold transition">Onboard</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Speed Limit Modal -->
    <div v-if="speedDevice" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-sm p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-1">Set Speed Limit</h3>
        <p class="text-xs text-slate-400 font-mono mb-4">{{ speedDevice.ip_address }} · {{ speedDevice.hostname || speedDevice.mac_address }}</p>
        <form @submit.prevent="submitSpeed" class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Upload</label>
              <input v-model="speedForm.upload" type="text" required placeholder="e.g. 5M" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Download</label>
              <input v-model="speedForm.download" type="text" required placeholder="e.g. 10M" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>
          <p class="text-[11px] text-slate-500">RouterOS format: number + K/M/G (e.g. 5M). Applied as a simple queue on this IP.</p>
          <div class="flex justify-end gap-3 pt-3 border-t border-slate-700/60">
            <button type="button" @click="speedDevice = null" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">Apply</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Renew Access Modal -->
    <div v-if="renewDevice" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-sm p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-1">Renew Access</h3>
        <p class="text-xs text-slate-400 font-mono mb-4">{{ renewDevice.mac_address }} · {{ renewDevice.hostname || renewDevice.ip_address || '' }}</p>
        <form @submit.prevent="submitRenew" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Access Duration</label>
            <div class="flex flex-wrap gap-2 mb-2">
              <button v-for="d in durationPresets" :key="d" type="button" @click="renewForm.access_days = d"
                class="px-3 py-1.5 rounded-lg text-xs font-semibold transition"
                :class="renewForm.access_days === d ? 'bg-emerald-600 text-white' : 'bg-slate-900 text-slate-300 border border-slate-700 hover:bg-slate-700'">
                {{ d }} days
              </button>
              <button type="button" @click="renewForm.access_days = 'custom'"
                class="px-3 py-1.5 rounded-lg text-xs font-semibold transition"
                :class="renewForm.access_days === 'custom' ? 'bg-emerald-600 text-white' : 'bg-slate-900 text-slate-300 border border-slate-700 hover:bg-slate-700'">
                Custom
              </button>
            </div>
            <input v-if="renewForm.access_days === 'custom'" v-model.number="renewForm.custom_days" type="number" min="1" max="3650" placeholder="Number of days" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            <p v-if="renewDevice.access_expires_at" class="text-[11px] text-slate-500 mt-1">
              Currently expires {{ new Date(renewDevice.access_expires_at).toLocaleDateString() }}. Renewing restarts the clock from today.
            </p>
          </div>
          <div class="flex justify-end gap-3 pt-3 border-t border-slate-700/60">
            <button type="button" @click="renewDevice = null" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-sm font-semibold transition">Renew</button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Detail from '../../Components/Detail.vue';

const props = defineProps({
  devices: Array,
  routers: Array,
  routerErrors: Array,
  fetchedAt: String,
  clients: Array,
  typeOptions: Object,
  filters: Object,
});

const search = ref(props.filters?.q || '');
const routerFilter = ref(props.filters?.router_id || '');
const statusFilter = ref(props.filters?.status || '');
const sourceFilter = ref(props.filters?.source || '');
const typologyFilter = ref(props.filters?.typology || '');
const managedFilter = ref(props.filters?.managed || '');
const autoRefresh = ref(false);
const loading = ref(false);
const selected = ref(null);

const onboardDevice = ref(null);
const onboardForm = reactive({ type: 'cpe', client_id: '', comment: '', access_days: 30, custom_days: null });

const speedDevice = ref(null);
const speedForm = reactive({ upload: '', download: '' });

const renewDevice = ref(null);
const renewForm = reactive({ access_days: 30, custom_days: null });

const durationPresets = [15, 30, 60, 90];

const CUSTOMER_EDGE_TYPES = ['cpe', 'hotspot_client', 'bridge_client', 'onu'];
const isCustomerEdge = (type) => CUSTOMER_EDGE_TYPES.includes(type);

let timer = null;

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase();

  return props.devices.filter((device) => {
    if (routerFilter.value && String(device.router_id) !== routerFilter.value) return false;
    if (statusFilter.value && device.status !== statusFilter.value) return false;
    if (sourceFilter.value && !device.sources.includes(sourceFilter.value)) return false;
    if (typologyFilter.value && device.typology !== typologyFilter.value) return false;
    if (managedFilter.value === 'managed' && !device.managed_device_id) return false;
    if (managedFilter.value === 'guest' && device.managed_device_id) return false;
    if (managedFilter.value === 'blocked' && !device.is_blocked) return false;
    if (!term) return true;

    return [device.ip_address, device.mac_address, device.hostname, device.user, device.comment, device.interface, device.typology_label, device.client_name]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(term));
  });
});

const hasSession = (device) =>
  device.sources.includes('hotspot') || device.sources.includes('pppoe');

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

// ---- Management actions ----

const openOnboard = (device) => {
  onboardDevice.value = device;
  onboardForm.type = device.typology && device.typology !== 'unknown' ? device.typology : 'cpe';
  onboardForm.client_id = '';
  onboardForm.comment = device.comment || '';
  onboardForm.access_days = 30;
  onboardForm.custom_days = null;
};

const resolvedDays = (form) => (form.access_days === 'custom' ? Number(form.custom_days) || 30 : form.access_days);

const submitOnboard = () => {
  const device = onboardDevice.value;
  const payload = {
    router_id: device.router_id,
    mac_address: device.mac_address,
    type: onboardForm.type,
    client_id: onboardForm.client_id || null,
    ip_address: device.ip_address || null,
    hostname: device.hostname || null,
    comment: onboardForm.comment || null,
  };
  if (isCustomerEdge(onboardForm.type)) {
    payload.access_duration_days = resolvedDays(onboardForm);
  }
  inertiaRouter.post('/connected-devices', payload, {
    preserveScroll: true,
    onFinish: () => {
      onboardDevice.value = null;
      refresh();
    },
  });
};

const openRenew = (device) => {
  renewDevice.value = device;
  renewForm.access_days = device.access_duration_days || 30;
  renewForm.custom_days = null;
};

const submitRenew = () => {
  const device = renewDevice.value;
  inertiaRouter.post(`/connected-devices/${device.managed_device_id}/renew`, {
    access_duration_days: resolvedDays(renewForm),
  }, {
    preserveScroll: true,
    onFinish: () => {
      renewDevice.value = null;
      refresh();
    },
  });
};

const doBlock = (device) => {
  if (!confirm(`Block ${device.ip_address || device.mac_address}? This kicks any live session and isolates the IP.`)) return;
  inertiaRouter.post('/connected-devices/block', {
    router_id: device.router_id,
    ip_address: device.ip_address || null,
    user: device.user || null,
    protocol: device.sources.includes('pppoe') ? 'pppoe' : 'hotspot',
  }, { preserveScroll: true, onFinish: () => refresh() });
};

const doUnblock = (device) => {
  inertiaRouter.post('/connected-devices/unblock', {
    router_id: device.router_id,
    ip_address: device.ip_address,
  }, { preserveScroll: true, onFinish: () => refresh() });
};

const doKick = (device) => {
  if (!confirm(`Disconnect ${device.user}'s ${device.sources.includes('pppoe') ? 'PPPoE' : 'hotspot'} session?`)) return;
  inertiaRouter.post('/connected-devices/kick', {
    router_id: device.router_id,
    user: device.user,
    protocol: device.sources.includes('pppoe') ? 'pppoe' : 'hotspot',
  }, { preserveScroll: true, onFinish: () => refresh() });
};

const openSpeed = (device) => {
  speedDevice.value = device;
  speedForm.upload = '';
  speedForm.download = '';
};

const submitSpeed = () => {
  const device = speedDevice.value;
  inertiaRouter.post('/connected-devices/queue', {
    router_id: device.router_id,
    ip_address: device.ip_address,
    upload: speedForm.upload,
    download: speedForm.download,
  }, {
    preserveScroll: true,
    onFinish: () => {
      speedDevice.value = null;
    },
  });
};

const doDisable = (device) => {
  if (!confirm(`Disable access for ${device.mac_address}? The L2 gate will block it immediately.`)) return;
  inertiaRouter.post(`/connected-devices/${device.managed_device_id}/disable`, {}, {
    preserveScroll: true,
    onFinish: () => refresh(),
  });
};

// ---- Styling helpers ----

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
