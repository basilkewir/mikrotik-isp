<template>
  <div class="min-h-screen bg-slate-950 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
      <div class="text-center mb-8">
        <h1 class="text-2xl font-extrabold text-white tracking-tight">MikroTik ISP Platform</h1>
        <p class="text-sm text-slate-400 mt-1">Operator sign in.</p>
      </div>

      <form @submit.prevent="submit" class="bg-slate-800 border border-slate-700 rounded-xl p-6 shadow-2xl space-y-4">
        <div v-if="form.errors.email" class="bg-rose-950/50 border border-rose-800 text-rose-300 text-xs rounded-lg px-3 py-2">
          {{ form.errors.email }}
        </div>

        <div>
          <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Email</label>
          <input v-model="form.email" type="email" required autocomplete="username" autofocus class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
        </div>
        <div>
          <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Password</label>
          <input v-model="form.password" type="password" required autocomplete="current-password" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
        </div>

        <label class="flex items-center gap-2 text-xs text-slate-400">
          <input v-model="form.remember" type="checkbox" class="rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500" />
          Remember me
        </label>

        <button type="submit" :disabled="form.processing" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white rounded-lg text-sm font-semibold transition">
          Sign In
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
  email: '',
  password: '',
  remember: false,
});

const submit = () => {
  form.post('/login', {
    onFinish: () => form.reset('password'),
  });
};
</script>
