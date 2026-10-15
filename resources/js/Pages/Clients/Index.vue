<template>
  <AppLayout>
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold tracking-tight text-white">ISP Subscribers & Accounts</h2>
        <p class="text-sm text-slate-400">Subscriber database with auto-generated client IDs and hardware binding.</p>
      </div>
      <button @click="showAddModal = true" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold shadow transition">
        + Register New Client
      </button>
    </div>

    <!-- Search Bar -->
    <div class="mb-6 flex items-center max-w-md">
      <input v-model="searchQuery" @keyup.enter="handleSearch" type="text" placeholder="Search by name, code, phone, or email..." class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500" />
      <button @click="handleSearch" class="ml-2 px-3 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-lg text-sm transition">
        Search
      </button>
    </div>

    <!-- Subscribers Table -->
    <div class="bg-slate-800 border border-slate-700/60 rounded-xl overflow-hidden shadow-sm">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
          <thead class="bg-slate-900/50 text-xs uppercase text-slate-400 border-b border-slate-700/60">
            <tr>
              <th class="px-6 py-3">Client Code</th>
              <th class="px-6 py-3">Full Name</th>
              <th class="px-6 py-3">Contact</th>
              <th class="px-6 py-3">Status</th>
              <th class="px-6 py-3">Balance</th>
              <th class="px-6 py-3">Services</th>
              <th class="px-6 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/40">
            <tr v-for="client in clients.data" :key="client.id" class="hover:bg-slate-750/30 transition">
              <td class="px-6 py-4 font-mono font-bold text-indigo-400 text-xs">{{ client.client_code }}</td>
              <td class="px-6 py-4 font-medium text-white">{{ client.name }}</td>
              <td class="px-6 py-4 text-xs text-slate-400 font-mono">
                <div>{{ client.phone || 'No phone' }}</div>
                <div>{{ client.email || '' }}</div>
              </td>
              <td class="px-6 py-4">
                <span class="px-2 py-0.5 rounded text-xs font-semibold" :class="client.status === 'active' ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-slate-800 text-slate-400'">
                  {{ client.status }}
                </span>
              </td>
              <td class="px-6 py-4 font-mono text-xs">{{ Number(client.balance).toLocaleString('fr-FR') }} XAF</td>
              <td class="px-6 py-4 text-xs text-slate-400">
                {{ client.subscriptions_count }} Sub(s), {{ client.devices_count }} Device(s)
              </td>
              <td class="px-6 py-4 text-right whitespace-nowrap">
                <button @click="openEdit(client)" class="text-xs px-2.5 py-1.5 mr-1 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded font-medium transition">
                  Edit
                </button>
                <button @click="deleteClient(client)" class="text-xs px-2.5 py-1.5 mr-1 bg-rose-900/60 hover:bg-rose-800 text-rose-300 rounded font-medium transition">
                  Delete
                </button>
                <a :href="`/clients/${client.id}`" class="text-xs px-3 py-1.5 bg-indigo-600/80 hover:bg-indigo-600 text-white rounded font-medium transition">
                  Manage Account &rarr;
                </a>
              </td>
            </tr>
            <tr v-if="!clients.data.length">
              <td colspan="7" class="px-6 py-8 text-center text-slate-400 text-sm">
                No subscribers registered yet.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add Client Modal -->
    <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">{{ editingClient ? 'Edit Subscriber' : 'Register New Subscriber' }}</h3>

        <form @submit.prevent="submitClient" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Subscriber Full Name</label>
            <input v-model="form.name" type="text" placeholder="John Doe" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Phone Number</label>
              <input v-model="form.phone" type="text" placeholder="+1234567890" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Email Address</label>
              <input v-model="form.email" type="email" placeholder="john@example.com" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Physical Installation Address</label>
            <input v-model="form.address" type="text" placeholder="Apartment / Building / Street" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Wallet Balance (XAF)</label>
              <input v-model.number="form.balance" type="number" step="0.01" min="0" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Account Status</label>
              <select v-model="form.status" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Internal Notes</label>
            <textarea v-model="form.notes" rows="2" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white"></textarea>
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showAddModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">
              Cancel
            </button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              {{ editingClient ? 'Save Changes' : 'Register & Continue' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
  clients: Object,
  filters: Object,
});

const searchQuery = ref(props.filters?.search || '');
const showAddModal = ref(false);
const editingClient = ref(null);

const emptyForm = () => ({
  name: '',
  phone: '',
  email: '',
  address: '',
  notes: '',
  balance: 0,
  status: 'active',
});

const form = ref(emptyForm());

const handleSearch = () => {
  inertiaRouter.get('/clients', { search: searchQuery.value }, { preserveState: true });
};

const openEdit = (client) => {
  editingClient.value = client;
  form.value = {
    name: client.name,
    phone: client.phone || '',
    email: client.email || '',
    address: client.address || '',
    notes: client.notes || '',
    balance: Number(client.balance) || 0,
    status: client.status,
  };
  showAddModal.value = true;
};

const submitClient = () => {
  const options = {
    onSuccess: () => {
      showAddModal.value = false;
      editingClient.value = null;
    },
  };

  if (editingClient.value) {
    inertiaRouter.put(`/clients/${editingClient.value.id}`, form.value, options);
  } else {
    inertiaRouter.post('/clients', form.value, options);
  }
};

const deleteClient = (client) => {
  if (confirm(`Delete subscriber ${client.name} (${client.client_code})?\n\nThis removes their subscriptions, devices, and cleans up accounts on the routers.`)) {
    inertiaRouter.delete(`/clients/${client.id}`);
  }
};
</script>

