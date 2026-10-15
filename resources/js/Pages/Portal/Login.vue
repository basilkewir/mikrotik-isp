<template>
  <div class="min-h-screen bg-slate-950 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
      <div class="text-center mb-8">
        <h1 class="text-2xl font-extrabold text-white tracking-tight">Subscriber Portal</h1>
        <p class="text-sm text-slate-400 mt-1">Check your subscription, wallet and invoices.</p>
      </div>

      <form @submit.prevent="submit" class="bg-slate-800 border border-slate-700 rounded-xl p-6 shadow-2xl space-y-4">
        <div v-if="form.errors.client_code" class="bg-rose-950/50 border border-rose-800 text-rose-300 text-xs rounded-lg px-3 py-2">
          {{ form.errors.client_code }}
        </div>

        <div>
          <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Client Code</label>
          <input v-model="form.client_code" type="text" required autocomplete="username" placeholder="e.g. C-0001" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono uppercase" />
        </div>
        <div>
          <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Password</label>
          <input v-model="form.portal_password" type="password" required autocomplete="current-password" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
        </div>

        <button type="submit" :disabled="form.processing" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white rounded-lg text-sm font-semibold transition">
          Sign In
        </button>

        <p class="text-[11px] text-slate-500 text-center">
          No password yet? Contact your ISP office to set one up.
        </p>
      </form>
    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
  client_code: '',
  portal_password: '',
});

const submit = () => {
  form.post('/portal/login', {
    onFinish: () => form.reset('portal_password'),
  });
};
</script>
