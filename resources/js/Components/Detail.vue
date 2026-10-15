<template>
  <div>
    <span class="text-slate-500 block mb-0.5">{{ label }}</span>
    <span class="text-slate-200 font-mono break-all">{{ display }}</span>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  label: String,
  value: {
    type: [String, Number, Boolean],
    default: null,
  },
});

const display = computed(() => {
  if (props.value === null || props.value === undefined || props.value === '') return '—';
  return String(props.value);
});
</script>
