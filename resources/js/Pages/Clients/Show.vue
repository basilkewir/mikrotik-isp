<template>
  <AppLayout>
    <div class="mb-6 flex items-center justify-between">
      <div class="flex items-center gap-4">
        <a href="/clients" class="text-sm text-slate-400 hover:text-white transition">&larr; Back to Subscribers</a>
        <h2 class="text-2xl font-bold text-white">{{ client.name }}</h2>
        <span class="px-2.5 py-0.5 rounded text-xs font-mono font-bold bg-indigo-950 text-indigo-400 border border-indigo-800">
          {{ client.client_code }}
        </span>
      </div>

      <div class="flex gap-2">
        <button @click="openClientEdit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm font-semibold shadow transition">
          Edit Subscriber
        </button>
        <button @click="showLanDeviceModal = true" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm font-semibold shadow transition">
          + Add LAN Device
        </button>
        <button @click="showAssignModal = true" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold shadow transition">
          + Provision Hotspot / PPPoE
        </button>
      </div>
    </div>

    <!-- Subscriber Info Header Card -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-5 shadow-sm md:col-span-2">
        <h3 class="text-xs uppercase font-semibold text-slate-400 mb-3">Contact & Location Details</h3>
        <div class="grid grid-cols-2 gap-4 text-xs font-mono">
          <div>
            <span class="text-slate-500 block mb-0.5">Phone:</span>
            <span class="text-slate-200">{{ client.phone || 'None' }}</span>
          </div>
          <div>
            <span class="text-slate-500 block mb-0.5">Email:</span>
            <span class="text-slate-200">{{ client.email || 'None' }}</span>
          </div>
          <div class="col-span-2">
            <span class="text-slate-500 block mb-0.5">Physical Address:</span>
            <span class="text-slate-200">{{ client.address || 'Unspecified' }}</span>
          </div>
        </div>
      </div>

      <div class="bg-slate-800 border border-slate-700/60 rounded-xl p-5 shadow-sm flex flex-col justify-between">
        <div>
          <h3 class="text-xs uppercase font-semibold text-slate-400 mb-1">Wallet Balance</h3>
          <span class="text-3xl font-extrabold text-white">{{ formatXaf(client.balance) }}</span>
          <button @click="showWalletModal = true" class="mt-3 block w-full text-center px-3 py-1.5 bg-emerald-900/60 hover:bg-emerald-800 text-emerald-300 rounded-lg text-xs font-semibold transition">
            + Top Up / Adjust
          </button>
          <button @click="showMomoModal = true" class="mt-2 block w-full text-center px-3 py-1.5 bg-amber-900/60 hover:bg-amber-800 text-amber-300 rounded-lg text-xs font-semibold transition">
            Pay via Mobile Money
          </button>
        </div>
        <div class="pt-3 border-t border-slate-700/40 text-xs mt-3">
          <span class="text-slate-500">Account Status: </span>
          <span class="font-bold uppercase" :class="client.status === 'active' ? 'text-emerald-400' : 'text-rose-400'">{{ client.status }}</span>
        </div>
      </div>
    </div>

    <!-- Wallet Ledger -->
    <div class="bg-slate-800 border border-slate-700/60 rounded-xl overflow-hidden mb-8 shadow-sm">
      <div class="px-6 py-4 border-b border-slate-700/60 flex items-center justify-between">
        <h3 class="font-bold text-white text-base">Wallet Ledger</h3>
        <div class="flex items-center gap-2">
          <button @click="showRedeemModal = true" class="px-3 py-1.5 bg-cyan-900/60 hover:bg-cyan-800 text-cyan-300 rounded-lg text-xs font-semibold transition">
            Redeem Voucher
          </button>
          <span class="text-xs text-slate-400 font-mono">{{ client.walletTransactions?.length || 0 }} Recent Transaction(s)</span>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
          <thead class="bg-slate-900/50 text-xs uppercase text-slate-400 border-b border-slate-700/60">
            <tr>
              <th class="px-6 py-3">Date</th>
              <th class="px-6 py-3">Type</th>
              <th class="px-6 py-3">Description</th>
              <th class="px-6 py-3 text-right">Amount</th>
              <th class="px-6 py-3 text-right">Balance After</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/40">
            <tr v-for="tx in client.walletTransactions" :key="tx.id" class="hover:bg-slate-750/30 transition">
              <td class="px-6 py-3 text-xs font-mono text-slate-400">{{ new Date(tx.created_at).toLocaleString() }}</td>
              <td class="px-6 py-3">
                <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase" :class="txTypeBadge(tx.type)">{{ tx.type.replace('_', ' ') }}</span>
              </td>
              <td class="px-6 py-3 text-xs">{{ tx.description }}</td>
              <td class="px-6 py-3 text-xs font-mono text-right font-bold" :class="Number(tx.amount) >= 0 ? 'text-emerald-400' : 'text-rose-400'">
                {{ Number(tx.amount) >= 0 ? '+' : '' }}{{ formatXaf(tx.amount) }}
              </td>
              <td class="px-6 py-3 text-xs font-mono text-right text-slate-300">{{ formatXaf(tx.balance_after) }}</td>
            </tr>
            <tr v-if="!client.walletTransactions?.length">
              <td colspan="5" class="px-6 py-8 text-center text-slate-400 text-sm">No wallet transactions yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Subscriptions Section -->
    <div class="bg-slate-800 border border-slate-700/60 rounded-xl overflow-hidden mb-8 shadow-sm">
      <div class="px-6 py-4 border-b border-slate-700/60 flex items-center justify-between">
        <h3 class="font-bold text-white text-base">Provisioned MikroTik Services</h3>
        <span class="text-xs text-slate-400 font-mono">{{ client.subscriptions.length }} Service(s)</span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
          <thead class="bg-slate-900/50 text-xs uppercase text-slate-400 border-b border-slate-700/60">
            <tr>
              <th class="px-6 py-3">Router</th>
              <th class="px-6 py-3">Protocol</th>
              <th class="px-6 py-3">Login Username</th>
              <th class="px-6 py-3">Plan</th>
              <th class="px-6 py-3">Status</th>
              <th class="px-6 py-3">Expires At</th>
              <th class="px-6 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/40">
            <tr v-for="sub in client.subscriptions" :key="sub.id" class="hover:bg-slate-750/30 transition">
              <td class="px-6 py-4 text-xs font-medium text-white">{{ sub.router?.name }}</td>
              <td class="px-6 py-4">
                <span class="px-2 py-0.5 rounded text-[11px] font-mono uppercase" :class="sub.auth_type === 'hotspot' ? 'bg-amber-950 text-amber-400 border border-amber-800' : 'bg-blue-950 text-blue-400 border border-blue-800'">
                  {{ sub.auth_type }}
                </span>
              </td>
              <td class="px-6 py-4 text-xs font-mono font-bold text-white">{{ sub.username }}</td>
              <td class="px-6 py-4 text-xs text-slate-300">
                {{ sub.plan?.name }}
                <span class="block text-[11px] text-slate-500 font-mono">
                  {{ sub.plan?.rate_limit_upload }}/{{ sub.plan?.rate_limit_download }}
                  <template v-if="sub.plan?.validity_days"> · {{ sub.plan.validity_days }} calendar days</template>
                </span>
              </td>
              <td class="px-6 py-4">
                <span class="px-2 py-0.5 rounded text-xs font-semibold" :class="sub.status === 'active' ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-rose-950 text-rose-400 border border-rose-800'">
                  {{ sub.status }}
                </span>
              </td>
              <td class="px-6 py-4 text-xs font-mono text-slate-400">
                {{ sub.expires_at ? new Date(sub.expires_at).toLocaleString() : 'Permanent' }}
              </td>
              <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                <button @click="openSubEdit(sub)" class="text-xs px-2.5 py-1 bg-slate-700 hover:bg-slate-600 text-indigo-300 rounded font-medium transition">
                  Edit
                </button>
                <Link :href="`/subscriptions/${sub.id}/kick`" method="post" as="button" preserve-scroll class="text-xs px-2.5 py-1 bg-slate-700 hover:bg-slate-600 text-amber-300 rounded font-medium transition">
                  Kick Active
                </Link>
                <Link v-if="sub.status === 'active'" :href="`/subscriptions/${sub.id}/disable`" method="post" as="button" preserve-scroll class="text-xs px-2.5 py-1 bg-rose-900/60 hover:bg-rose-800 text-rose-300 rounded font-medium transition">
                  Disable
                </Link>
                <Link v-else :href="`/subscriptions/${sub.id}/enable`" method="post" as="button" preserve-scroll class="text-xs px-2.5 py-1 bg-emerald-900/60 hover:bg-emerald-800 text-emerald-300 rounded font-medium transition">
                  Re-enable
                </Link>
                <button @click="deleteSub(sub)" class="text-xs px-2.5 py-1 bg-rose-900/60 hover:bg-rose-800 text-rose-300 rounded font-medium transition">
                  Delete
                </button>
              </td>
            </tr>
            <tr v-if="!client.subscriptions.length">
              <td colspan="7" class="px-6 py-8 text-center text-slate-400 text-sm">
                No active internet services provisioned for this subscriber yet.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="bg-slate-800 border border-slate-700/60 rounded-xl overflow-hidden mb-8 shadow-sm">
      <div class="px-6 py-4 border-b border-slate-700/60 flex items-center justify-between">
        <h3 class="font-bold text-white text-base">LAN Devices</h3>
        <span class="text-xs text-slate-400 font-mono">{{ client.devices.length }} Device(s)</span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
          <thead class="bg-slate-900/50 text-xs uppercase text-slate-400 border-b border-slate-700/60">
            <tr>
              <th class="px-6 py-3">Type</th>
              <th class="px-6 py-3">Device</th>
              <th class="px-6 py-3">Router</th>
              <th class="px-6 py-3">MAC / IP Address</th>
              <th class="px-6 py-3">Access</th>
              <th class="px-6 py-3">Expires At</th>
              <th class="px-6 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/40">
            <tr v-for="device in client.devices" :key="device.id">
              <td class="px-6 py-4">
                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide" :class="deviceTypeBadge(device.type)">
                  {{ device.type_label || device.type }}
                </span>
              </td>
              <td class="px-6 py-4 font-medium text-white">{{ device.hostname || 'LAN device' }}</td>
              <td class="px-6 py-4 text-xs">{{ device.router?.name || '—' }}</td>
              <td class="px-6 py-4 text-xs font-mono">
                {{ device.mac_address }}
                <span class="block text-slate-500">{{ device.ip_address || 'IP not set' }}</span>
              </td>
              <td class="px-6 py-4 text-xs">
                <span v-if="device.is_active && !isExpired(device.access_expires_at)" class="text-emerald-400">Enabled</span>
                <span v-else-if="isExpired(device.access_expires_at)" class="text-rose-400">Expired</span>
                <span v-else class="text-slate-400">Disabled</span>
              </td>
              <td class="px-6 py-4 text-xs font-mono text-slate-400">
                {{ device.access_expires_at ? new Date(device.access_expires_at).toLocaleString() : '—' }}
              </td>
              <td class="px-6 py-4 text-right whitespace-nowrap">
                <button @click="openDeviceEdit(device)" class="text-xs px-2.5 py-1 mr-1 bg-slate-700 hover:bg-slate-600 text-indigo-300 rounded font-medium transition">
                  Edit
                </button>
                <Link v-if="device.is_active" :href="`/devices/${device.id}/disable`" method="post" as="button" preserve-scroll class="text-xs px-2.5 py-1 mr-1 bg-rose-900/60 hover:bg-rose-800 text-rose-300 rounded font-medium transition">
                  Disable
                </Link>
                <Link v-else :href="`/devices/${device.id}/enable`" method="post" as="button" preserve-scroll class="text-xs px-2.5 py-1 mr-1 bg-emerald-900/60 hover:bg-emerald-800 text-emerald-300 rounded font-medium transition">
                  Renew & Enable
                </Link>
                <button @click="deleteDevice(device)" class="text-xs px-2.5 py-1 bg-rose-900/60 hover:bg-rose-800 text-rose-300 rounded font-medium transition">
                  Delete
                </button>
              </td>
            </tr>
            <tr v-if="!client.devices.length">
              <td colspan="7" class="px-6 py-8 text-center text-slate-400 text-sm">
                No LAN devices registered for this client.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Provision Service Modal -->
    <div v-if="showAssignModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">Provision MikroTik Service</h3>

        <form @submit.prevent="submitSubscription" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Target Router</label>
            <select v-model="subForm.router_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
              <option v-for="r in routers" :key="r.id" :value="r.id">{{ r.name }} ({{ r.ip_address }})</option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Authentication Mode</label>
              <select v-model="subForm.auth_type" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="hotspot">Hotspot (Captive Portal)</option>
                <option value="pppoe">PPPoE (Dial-up / Tunnel)</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Bandwidth Plan</label>
              <select v-model="subForm.plan_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option v-for="p in plans" :key="p.id" :value="p.id">
                  {{ p.name }} · {{ p.validity_days ? `${p.validity_days} calendar days` : 'no time expiry' }} · {{ Number(p.price).toLocaleString('fr-FR') }} XAF
                </option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Subscriber Username</label>
              <input v-model="subForm.username" type="text" placeholder="e.g. john_wifi" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Subscriber Password</label>
              <input v-model="subForm.password" type="text" placeholder="Secret / PIN" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showAssignModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">
              Cancel
            </button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              Provision to MikroTik
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="showLanDeviceModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-2">Provision LAN Device Access</h3>
        <p class="text-xs text-slate-400 mb-4">Access is matched by MAC address and expires after the selected calendar days.</p>

        <form @submit.prevent="submitLanDevice" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Target Router</label>
            <select v-model="lanForm.router_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
              <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }} ({{ router.ip_address }})</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Device Name</label>
              <input v-model="lanForm.hostname" type="text" placeholder="Customer laptop" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Device Type</label>
              <select v-model="lanForm.type" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option v-for="(label, value) in deviceTypeOptions" :key="value" :value="value">{{ label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">MAC Address</label>
              <input v-model="lanForm.mac_address" type="text" placeholder="AA:BB:CC:DD:EE:FF" pattern="([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">IP Address (Optional)</label>
              <input v-model="lanForm.ip_address" type="text" placeholder="192.168.20.50" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Serial Number</label>
              <input v-model="lanForm.serial_number" type="text" placeholder="Optional" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Model</label>
              <input v-model="lanForm.model" type="text" placeholder="e.g. hAP ac lite" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Access Duration (Calendar Days)</label>
              <input v-model.number="lanForm.access_duration_days" type="number" min="1" max="3650" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>
          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showLanDeviceModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">
              Cancel
            </button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              Save & Enable LAN Access
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Mobile Money Top-Up Modal -->
    <div v-if="showMomoModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-1">Mobile Money Top-Up</h3>
        <p class="text-xs text-slate-400 mb-4">Redirects to the Monetbil payment widget. The wallet is credited once the operator confirms.</p>

        <form @submit.prevent="submitMomo" class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Amount (XAF)</label>
              <input v-model.number="momoForm.amount" type="number" min="1" max="1000000" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Operator (optional)</label>
              <select v-model="momoForm.operator" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="">Auto / any</option>
                <option value="CM_MTNMOBILEMONEY">MTN Mobile Money</option>
                <option value="CM_ORANGEMONEY">Orange Money</option>
                <option value="CM_EUMM">Express Union</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Phone (optional — pre-fills the widget)</label>
            <input v-model="momoForm.phone" type="text" placeholder="6XXXXXXXX" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showMomoModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-lg text-sm font-semibold transition">Pay with Mobile Money</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Redeem Voucher Modal -->
    <div v-if="showRedeemModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-sm p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-1">Redeem Voucher</h3>
        <p class="text-xs text-slate-400 mb-4">The voucher value is credited to this client's wallet.</p>

        <form @submit.prevent="submitRedeem" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Voucher Code</label>
            <input v-model="redeemForm.code" type="text" required placeholder="e.g. XKQBMRTPL" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono uppercase tracking-widest text-center" />
          </div>
          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showRedeemModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg text-sm font-semibold transition">Redeem</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Wallet Adjustment Modal -->
    <div v-if="showWalletModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-1">Wallet Adjustment</h3>
        <p class="text-xs text-slate-400 mb-4">
          Current balance: <span class="font-mono text-white">{{ formatXaf(client.balance) }}</span>.
          Every adjustment is recorded in the ledger.
        </p>

        <form @submit.prevent="submitWallet" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Action</label>
            <div class="grid grid-cols-2 gap-2">
              <button type="button" @click="walletForm.action = 'topup'" class="px-3 py-2 rounded-lg text-sm font-semibold border transition" :class="walletForm.action === 'topup' ? 'bg-emerald-600 border-emerald-500 text-white' : 'bg-slate-900 border-slate-700 text-slate-300'">
                Credit (+)
              </button>
              <button type="button" @click="walletForm.action = 'debit'" class="px-3 py-2 rounded-lg text-sm font-semibold border transition" :class="walletForm.action === 'debit' ? 'bg-rose-600 border-rose-500 text-white' : 'bg-slate-900 border-slate-700 text-slate-300'">
                Debit (−)
              </button>
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Amount (XAF)</label>
            <input v-model.number="walletForm.amount" type="number" min="1" step="1" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Reason</label>
            <input v-model="walletForm.description" type="text" placeholder="e.g. Cash payment at office" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showWalletModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">
              Cancel
            </button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              Confirm Adjustment
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Subscriber Modal -->
    <div v-if="showClientEditModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">Edit Subscriber</h3>

        <form @submit.prevent="submitClientEdit" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Subscriber Full Name</label>
            <input v-model="clientForm.name" type="text" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Phone Number</label>
              <input v-model="clientForm.phone" type="text" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Email Address</label>
              <input v-model="clientForm.email" type="email" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Physical Installation Address</label>
            <input v-model="clientForm.address" type="text" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Wallet Balance (XAF)</label>
              <input v-model.number="clientForm.balance" type="number" step="0.01" min="0" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Account Status</label>
              <select v-model="clientForm.status" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Internal Notes</label>
            <textarea v-model="clientForm.notes" rows="2" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white"></textarea>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">
              Portal Password
              <span class="normal-case font-normal text-slate-500">({{ client.portal_password_set_at ? 'set — enter new value to change' : 'not set yet' }})</span>
            </label>
            <input v-model="clientForm.portal_password" type="text" minlength="6" placeholder="Leave blank to keep current" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showClientEditModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">
              Cancel
            </button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Subscription Modal -->
    <div v-if="showSubEditModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-1">Edit Subscription</h3>
        <p class="text-xs text-slate-400 mb-4">Changes are re-provisioned to the router immediately. Access expiry is calendar-based.</p>

        <form @submit.prevent="submitSubEdit" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Bandwidth Plan</label>
            <select v-model="subEditForm.plan_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
              <option v-for="p in plans" :key="p.id" :value="p.id">
                {{ p.name }} · {{ p.validity_days ? `${p.validity_days} calendar days` : 'no time expiry' }} · {{ Number(p.price).toLocaleString('fr-FR') }} XAF
              </option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Username</label>
              <input v-model="subEditForm.username" type="text" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">New Password (optional)</label>
              <input v-model="subEditForm.password" type="text" placeholder="Leave blank to keep" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Assigned IP (optional)</label>
              <input v-model="subEditForm.assigned_ip" type="text" placeholder="192.168.20.10" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Expires At (calendar date)</label>
              <input v-model="subEditForm.expires_at" type="datetime-local" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>

          <p class="text-[11px] text-slate-500">Leave expiry empty for a permanent (no time limit) subscription.</p>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showSubEditModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">
              Cancel
            </button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              Save & Re-provision
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit LAN Device Modal -->
    <div v-if="showDeviceEditModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-1">Edit LAN Device</h3>
        <p class="text-xs text-slate-400 mb-4 font-mono">{{ deviceEditForm.mac_address }}</p>

        <form @submit.prevent="submitDeviceEdit" class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Device Name</label>
              <input v-model="deviceEditForm.hostname" type="text" placeholder="Customer laptop" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">IP Address (Optional)</label>
              <input v-model="deviceEditForm.ip_address" type="text" placeholder="192.168.20.50" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Access Duration (Calendar Days)</label>
            <input v-model.number="deviceEditForm.access_duration_days" type="number" min="1" max="3650" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
          </div>

          <div class="flex items-center gap-2">
            <input v-model="deviceEditForm.renew" type="checkbox" id="device_renew" class="rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500" />
            <label for="device_renew" class="text-xs text-slate-300">Reset access period from today (extend by {{ deviceEditForm.access_duration_days }} days)</label>
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showDeviceEditModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">
              Cancel
            </button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
  client: Object,
  routers: Array,
  plans: Array,
  deviceTypeOptions: Object,
});

const showAssignModal = ref(false);
const showLanDeviceModal = ref(false);
const showClientEditModal = ref(false);
const showSubEditModal = ref(false);
const showDeviceEditModal = ref(false);
const showWalletModal = ref(false);
const showRedeemModal = ref(false);
const showMomoModal = ref(false);

const momoForm = ref({ amount: 5000, phone: props.client.phone || '', operator: '' });

const submitMomo = () => {
  inertiaRouter.post('/payments', {
    client_id: props.client.id,
    amount: momoForm.value.amount,
    phone: momoForm.value.phone,
    operator: momoForm.value.operator,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      showMomoModal.value = false;
    },
  });
};

const walletForm = ref({ action: 'topup', amount: 1000, description: '' });
const redeemForm = ref({ code: '' });

const submitRedeem = () => {
  inertiaRouter.post(`/clients/${props.client.id}/wallet/redeem`, { code: redeemForm.value.code }, {
    preserveScroll: true,
    onSuccess: () => {
      showRedeemModal.value = false;
      redeemForm.value.code = '';
    },
  });
};

const formatXaf = (value) => {
  const n = Number(value) || 0;
  return `${n.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} XAF`;
};

const txTypeBadge = (type) => ({
  topup: 'bg-emerald-950 text-emerald-400 border border-emerald-800',
  voucher: 'bg-cyan-950 text-cyan-400 border border-cyan-800',
  manual_credit: 'bg-emerald-950 text-emerald-400 border border-emerald-800',
  manual_debit: 'bg-rose-950 text-rose-400 border border-rose-800',
  subscription_charge: 'bg-amber-950 text-amber-400 border border-amber-800',
  invoice_payment: 'bg-indigo-950 text-indigo-400 border border-indigo-800',
  refund: 'bg-violet-950 text-violet-400 border border-violet-800',
}[type] || 'bg-slate-700 text-slate-300 border border-slate-600');

const submitWallet = () => {
  const url = `/clients/${props.client.id}/wallet/${walletForm.value.action}`;
  inertiaRouter.post(url, {
    amount: walletForm.value.amount,
    description: walletForm.value.description,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      showWalletModal.value = false;
      walletForm.value = { action: 'topup', amount: 1000, description: '' };
    },
  });
};

const editingSub = ref(null);
const editingDevice = ref(null);

const clientForm = ref({
  name: '',
  phone: '',
  email: '',
  address: '',
  notes: '',
  balance: 0,
  status: 'active',
});

const subEditForm = ref({
  plan_id: '',
  username: '',
  password: '',
  assigned_ip: '',
  expires_at: '',
});

const deviceEditForm = ref({
  hostname: '',
  ip_address: '',
  access_duration_days: 30,
  mac_address: '',
  renew: false,
});

const toLocalInput = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const openClientEdit = () => {
  clientForm.value = {
    name: props.client.name,
    phone: props.client.phone || '',
    email: props.client.email || '',
    address: props.client.address || '',
    notes: props.client.notes || '',
    balance: Number(props.client.balance) || 0,
    status: props.client.status,
    portal_password: '',
  };
  showClientEditModal.value = true;
};

const submitClientEdit = () => {
  inertiaRouter.put(`/clients/${props.client.id}`, clientForm.value, {
    onSuccess: () => {
      showClientEditModal.value = false;
    },
  });
};

const openSubEdit = (sub) => {
  editingSub.value = sub;
  subEditForm.value = {
    plan_id: sub.plan_id,
    username: sub.username,
    password: '',
    assigned_ip: sub.assigned_ip || '',
    expires_at: toLocalInput(sub.expires_at),
  };
  showSubEditModal.value = true;
};

const submitSubEdit = () => {
  inertiaRouter.put(`/subscriptions/${editingSub.value.id}`, subEditForm.value, {
    onSuccess: () => {
      showSubEditModal.value = false;
      editingSub.value = null;
    },
  });
};

const deleteSub = (sub) => {
  if (confirm(`Delete subscription "${sub.username}"?\n\nThe account will be removed from the router and its active session terminated.`)) {
    inertiaRouter.delete(`/subscriptions/${sub.id}`);
  }
};

const openDeviceEdit = (device) => {
  editingDevice.value = device;
  deviceEditForm.value = {
    hostname: device.hostname || '',
    ip_address: device.ip_address || '',
    access_duration_days: device.access_duration_days || 30,
    mac_address: device.mac_address,
    renew: false,
  };
  showDeviceEditModal.value = true;
};

const submitDeviceEdit = () => {
  inertiaRouter.put(`/devices/${editingDevice.value.id}`, deviceEditForm.value, {
    onSuccess: () => {
      showDeviceEditModal.value = false;
      editingDevice.value = null;
    },
  });
};

const deleteDevice = (device) => {
  if (confirm(`Delete LAN device ${device.hostname || device.mac_address}?\n\nIts bridge filter rule will be removed from the router.`)) {
    inertiaRouter.delete(`/devices/${device.id}`);
  }
};

const subForm = ref({
  client_id: props.client.id,
  router_id: props.routers[0]?.id || '',
  plan_id: props.plans[0]?.id || '',
  auth_type: 'hotspot',
  username: '',
  password: '',
});

const lanForm = ref({
  router_id: props.routers[0]?.id || '',
  hostname: '',
  type: 'cpe',
  mac_address: '',
  ip_address: '',
  serial_number: '',
  model: '',
  manufacturer: '',
  notes: '',
  access_duration_days: 30,
});

const submitSubscription = () => {
  inertiaRouter.post('/subscriptions', subForm.value, {
    onSuccess: () => {
      showAssignModal.value = false;
      subForm.value.username = '';
      subForm.value.password = '';
    },
  });
};

const submitLanDevice = () => {
  inertiaRouter.post(`/clients/${props.client.id}/devices`, lanForm.value, {
    onSuccess: () => {
      showLanDeviceModal.value = false;
      lanForm.value = {
        router_id: props.routers[0]?.id || '',
        hostname: '',
        type: 'cpe',
        mac_address: '',
        ip_address: '',
        serial_number: '',
        model: '',
        manufacturer: '',
        notes: '',
        access_duration_days: 30,
      };
    },
  });
};

const isExpired = (expiresAt) => expiresAt && new Date(expiresAt) <= new Date();

const deviceTypeBadge = (type) => ({
  cpe: 'bg-emerald-950 text-emerald-400',
  hotspot_client: 'bg-sky-950 text-sky-400',
  bridge_client: 'bg-teal-950 text-teal-400',
  onu: 'bg-cyan-950 text-cyan-400',
}[type] || 'bg-slate-700 text-slate-300');
</script>
