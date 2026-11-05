<template>
  <div>
    <div class="flex flex-wrap items-center gap-2 mb-2">
      <button
        v-for="preset in presets"
        :key="preset.label"
        type="button"
        @click="apply(preset)"
        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition"
        :class="activePreset === preset.label ? 'bg-emerald-600 text-white' : 'bg-slate-900 text-slate-300 border border-slate-700 hover:bg-slate-700'"
      >
        {{ preset.label }}
      </button>
      <button
        type="button"
        @click="clear"
        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition bg-slate-900 text-slate-400 border border-slate-700 hover:bg-slate-700"
      >
        No expiry
      </button>
    </div>

    <div v-if="canExtend" class="flex items-center gap-2 mb-2">
      <input
        :id="`${inputId}_extend`"
        v-model="extend"
        type="checkbox"
        class="rounded bg-slate-900 border-slate-700 text-emerald-600 focus:ring-emerald-500"
      />
      <label :for="`${inputId}_extend`" class="text-[11px] text-slate-400">
        Extend from current expiry ({{ new Date(currentExpiry).toLocaleDateString() }}) instead of today
      </label>
    </div>

    <input
      :id="inputId"
      :value="modelValue"
      @input="$emit('update:modelValue', $event.target.value)"
      type="datetime-local"
      class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono"
    />
    <p v-if="hint" class="text-[11px] text-slate-500 mt-1">{{ hint }}</p>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  currentExpiry: { type: String, default: null },
  hint: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const inputId = `expiry-${Math.random().toString(36).slice(2, 8)}`;
const extend = ref(true);
const activePreset = ref(null);

const presets = [
  { label: '1 week', days: 7 },
  { label: '1 month', months: 1 },
  { label: '3 months', months: 3 },
  { label: '6 months', months: 6 },
  { label: '1 year', months: 12 },
];

const currentIsFuture = computed(() => {
  if (!props.currentExpiry) return false;
  const d = new Date(props.currentExpiry);
  return !Number.isNaN(d.getTime()) && d > new Date();
});

const canExtend = computed(() => currentIsFuture.value);

watch(canExtend, (ok) => {
  if (!ok) extend.value = true;
});

const addMonths = (date, months) => {
  const result = new Date(date.getTime());
  const day = result.getDate();
  result.setDate(1);
  result.setMonth(result.getMonth() + months);
  // Clamp to the last day of the target month (Jan 31 + 1m → Feb 28/29).
  const lastDay = new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate();
  result.setDate(Math.min(day, lastDay));
  return result;
};

const toLocalInput = (date) => {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const apply = (preset) => {
  const base = extend.value && currentIsFuture.value ? new Date(props.currentExpiry) : new Date();
  const target = preset.days ? new Date(base.getTime() + preset.days * 86400000) : addMonths(base, preset.months);
  activePreset.value = preset.label;
  emit('update:modelValue', toLocalInput(target));
};

const clear = () => {
  activePreset.value = null;
  emit('update:modelValue', '');
};
</script>
