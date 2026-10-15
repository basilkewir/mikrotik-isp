<template>
  <AppLayout>
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold tracking-tight text-white">Internet Service Plans</h2>
        <p class="text-sm text-slate-400">Configure prepaid time vouchers, data quota buckets, and unlimited broadband tiers.</p>
      </div>
      <button @click="showAddModal = true" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold shadow transition">
        + Create New Plan
      </button>
    </div>

    <!-- Plans Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
      <div v-for="plan in plans" :key="plan.id" class="bg-slate-800 border border-slate-700/60 rounded-xl p-6 shadow-sm flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs uppercase font-bold tracking-wider px-2 py-0.5 rounded bg-indigo-950 text-indigo-400 border border-indigo-800">
              {{ plan.service_type }}
            </span>
            <span class="text-lg font-extrabold text-white">{{ Number(plan.price).toLocaleString('fr-FR') }} XAF</span>
          </div>

          <h3 class="text-lg font-bold text-white">{{ plan.name }}</h3>
          <p class="text-xs text-slate-400 font-mono mb-4">Code: {{ plan.code }}</p>

          <div class="space-y-2 text-xs text-slate-300 font-mono bg-slate-900/60 p-3 rounded-lg border border-slate-700/40 mb-4">
            <div class="flex justify-between">
              <span class="text-slate-500">Plan Model:</span>
              <span class="capitalize text-slate-200">{{ plan.type.replace('_', ' ') }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">Speed (Up / Down):</span>
              <span class="text-emerald-400 font-bold">{{ plan.rate_limit_upload }} / {{ plan.rate_limit_download }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">Validity:</span>
              <span>{{ plan.validity_days ? plan.validity_days + ' Calendar Days' : 'Unlimited' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">Data Quota:</span>
              <span>{{ plan.data_limit_mb ? (plan.data_limit_mb >= 1024 ? (plan.data_limit_mb / 1024) + ' GB' : plan.data_limit_mb + ' MB') : 'Unlimited' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">Active Subs:</span>
              <span>{{ plan.subscriptions_count }}</span>
            </div>
          </div>
        </div>

        <div class="flex items-center justify-between pt-2 border-t border-slate-700/40">
          <button @click="openEdit(plan)" class="text-xs text-indigo-400 hover:text-indigo-300 transition">
            Edit Plan
          </button>
          <button @click="deletePlan(plan.id)" class="text-xs text-rose-400 hover:text-rose-300 transition">
            Delete Plan
          </button>
        </div>
      </div>
    </div>

    <p v-if="!plans.length" class="text-center text-slate-400 text-sm py-8">No plans created yet.</p>

    <!-- Create / Edit Plan Modal -->
    <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
      <div class="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-4">{{ editingPlan ? 'Edit Bandwidth Plan' : 'Create New Bandwidth Plan' }}</h3>

        <form @submit.prevent="submitPlan" class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Plan Name</label>
              <input v-model="form.name" type="text" placeholder="e.g. 10Mbps Home Unlimited" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Plan Code</label>
              <input v-model="form.code" type="text" placeholder="HOME-10M" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Service Type</label>
              <select v-model="form.service_type" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="both">Both (Hotspot & PPPoE)</option>
                <option value="hotspot">Hotspot Only</option>
                <option value="pppoe">PPPoE Only</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Billing Model</label>
              <select v-model="form.type" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="time">Time-based</option>
                <option value="data">Data Quota</option>
                <option value="time_and_data">Time & Data Bound</option>
                <option value="unlimited">Unlimited Monthly</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Price (XAF)</label>
              <input v-model.number="form.price" type="number" step="0.01" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Upload Rate</label>
              <input v-model="form.rate_limit_upload" type="text" placeholder="5M" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Download Rate</label>
              <input v-model="form.rate_limit_download" type="text" placeholder="10M" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Access Duration (Calendar Days)</label>
              <input v-model.number="form.validity_days" type="number" min="1" max="3650" placeholder="30" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Data Cap (MB)</label>
              <input v-model.number="form.data_limit_mb" type="number" placeholder="e.g. 51200 for 50GB" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>

          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Hotspot Profile (on router)</label>
              <input v-model="form.hotspot_profile" type="text" placeholder="default" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">PPPoE Profile (on router)</label>
              <input v-model="form.pppoe_profile" type="text" placeholder="default" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Shared Users</label>
              <input v-model.number="form.shared_users" type="number" min="1" max="10" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
            </div>
          </div>

          <div class="flex items-center gap-2 pt-1">
            <input v-model="form.is_active" type="checkbox" id="plan_active" class="rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500" />
            <label for="plan_active" class="text-xs text-slate-300">Plan active (available for new subscriptions)</label>
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
            <button type="button" @click="showAddModal = false" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-lg text-sm font-medium transition">
              Cancel
            </button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              {{ editingPlan ? 'Save Changes' : 'Save Plan' }}
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

defineProps({
  plans: Array,
});

const showAddModal = ref(false);
const editingPlan = ref(null);

const emptyForm = () => ({
  name: '',
  code: '',
  type: 'time',
  service_type: 'both',
  price: 5000,
  validity_days: 30,
  data_limit_mb: null,
  rate_limit_upload: '5M',
  rate_limit_download: '10M',
  hotspot_profile: 'default',
  pppoe_profile: 'default',
  shared_users: 1,
  is_active: true,
});

const form = ref(emptyForm());

const openEdit = (plan) => {
  editingPlan.value = plan;
  form.value = {
    name: plan.name,
    code: plan.code,
    type: plan.type,
    service_type: plan.service_type,
    price: Number(plan.price),
    validity_days: plan.validity_days,
    data_limit_mb: plan.data_limit_mb,
    rate_limit_upload: plan.rate_limit_upload,
    rate_limit_download: plan.rate_limit_download,
    hotspot_profile: plan.hotspot_profile || 'default',
    pppoe_profile: plan.pppoe_profile || 'default',
    shared_users: plan.shared_users || 1,
    is_active: !!plan.is_active,
  };
  showAddModal.value = true;
};

const submitPlan = () => {
  const options = {
    onSuccess: () => {
      showAddModal.value = false;
      editingPlan.value = null;
    },
  };

  if (editingPlan.value) {
    inertiaRouter.put(`/plans/${editingPlan.value.id}`, form.value, options);
  } else {
    inertiaRouter.post('/plans', form.value, options);
  }
};

const deletePlan = (id) => {
  if (confirm('Are you sure you want to delete this plan?')) {
    inertiaRouter.delete(`/plans/${id}`);
  }
};
</script>
