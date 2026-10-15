<template>
  <div :style="{ marginLeft: depth * 22 + 'px' }" class="py-1">
    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-900/50 border border-slate-700/50 hover:border-slate-600 transition">
      <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="node.is_online ? 'bg-emerald-400' : 'bg-slate-600'"></span>
      <span class="px-1.5 py-0.5 rounded text-[10px] font-bold border shrink-0" :class="badge(node.type)">{{ node.type_label }}</span>
      <span class="text-sm text-white font-medium truncate">{{ node.name }}</span>
      <span v-if="node.ip_address" class="text-[11px] font-mono text-slate-500">{{ node.ip_address }}</span>
    </div>

    <div v-if="node.children?.length">
      <TopologyNode v-for="child in node.children" :key="child.id" :node="child" :depth="depth + 1" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  node: { type: Object, required: true },
  depth: { type: Number, default: 0 },
});

const badge = (type) => ({
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
</script>
