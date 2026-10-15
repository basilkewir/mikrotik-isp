<template>
  <AppLayout>
    <div class="max-w-2xl space-y-6">
      <div>
        <h2 class="text-2xl font-extrabold text-white tracking-tight">Settings</h2>
        <p class="text-sm text-slate-400">Payment gateway and platform configuration.</p>
      </div>

      <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-bold text-white text-base">Monetbil (Mobile Money)</h3>
          <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase border" :class="configured ? 'bg-emerald-950 text-emerald-400 border-emerald-800' : 'bg-amber-950 text-amber-400 border-amber-800'">
            {{ configured ? 'Configured' : 'Not configured' }}
          </span>
        </div>

        <p class="text-xs text-slate-400 mb-4">
          Create a payment service at
          <a href="https://www.monetbil.com/services/create" target="_blank" rel="noopener" class="text-indigo-400 underline">monetbil.com/services/create</a>,
          then paste the service key and secret below. The notify URL registered with Monetbil must point to
          <code class="font-mono text-slate-300">/payments/monetbil/notify</code>.
        </p>

        <form @submit.prevent="submit" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Service Key</label>
            <input v-model="form.monetbil_service_key" type="text" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Service Secret</label>
            <input v-model="form.monetbil_service_secret" type="password" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Logo URL (optional, shown on the widget)</label>
            <input v-model="form.monetbil_logo_url" type="url" placeholder="https://…" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
          </div>

          <div class="flex justify-end pt-3 border-t border-slate-700/60">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition">
              Save Settings
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
  settings: Object,
  operators: Object,
});

const configured = Boolean(props.settings.monetbil_service_key && props.settings.monetbil_service_secret);

const form = ref({
  monetbil_service_key: props.settings.monetbil_service_key || '',
  monetbil_service_secret: props.settings.monetbil_service_secret || '',
  monetbil_logo_url: props.settings.monetbil_logo_url || '',
});

const submit = () => {
  inertiaRouter.put('/settings', form.value, { preserveScroll: true });
};
</script>
