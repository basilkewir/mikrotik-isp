<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <div class="flex items-center gap-3">
            <h2 class="text-2xl font-extrabold text-white tracking-tight">Network Topology</h2>
            <span
              class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wide border"
              :class="pollSeconds ? 'bg-emerald-950 text-emerald-400 border-emerald-800' : 'bg-slate-800 text-slate-400 border-slate-700'"
            >
              <span
                class="w-1.5 h-1.5 rounded-full"
                :class="pollSeconds ? 'bg-emerald-400 animate-pulse' : 'bg-slate-600'"
              ></span>
              {{ pollSeconds ? `Live · ${pollSeconds}s` : 'Paused' }}
            </span>
          </div>
          <p class="text-sm text-slate-400 mt-1">
            Routers → L2 segments → managed devices → live clients, drawn from live RouterOS data.
            <span v-if="fetchedAt" class="text-slate-500">Updated {{ ago }} ago.</span>
            <span v-if="lastError" class="text-rose-400">{{ lastError }}</span>
          </p>
        </div>
        <div class="flex items-center gap-2">
          <select
            v-model.number="pollSeconds"
            @change="restartPolling"
            class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white"
            title="Live refresh interval"
          >
            <option :value="1">Every 1s</option>
            <option :value="2">Every 2s</option>
            <option :value="3">Every 3s</option>
            <option :value="5">Every 5s</option>
            <option :value="10">Every 10s</option>
            <option :value="0">Paused</option>
          </select>
          <button
            @click="reload"
            :disabled="loading"
            class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white rounded-lg text-sm font-semibold transition"
          >
            {{ loading ? 'Refreshing…' : 'Refresh now' }}
          </button>
        </div>
      </div>

      <!-- Errors -->
      <div v-if="errors.length" class="bg-amber-950/50 border border-amber-800 rounded-xl p-4 space-y-1">
        <p class="text-sm font-semibold text-amber-300">Some sources could not be read:</p>
        <p v-for="(err, i) in errors" :key="i" class="text-xs text-amber-200/80 font-mono">
          {{ err.source }} — {{ err.message }}
        </p>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-9 gap-3">
        <div v-for="stat in statCards" :key="stat.label" class="bg-slate-800 border border-slate-700 rounded-xl px-4 py-3">
          <p class="text-[11px] uppercase tracking-wider text-slate-400">{{ stat.label }}</p>
          <p class="text-xl font-extrabold" :class="stat.color">{{ stat.value }}</p>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="flex flex-wrap items-center gap-3">
        <input
          v-model="search"
          type="text"
          placeholder="Search hostname, MAC, IP, interface…"
          class="flex-1 min-w-[240px] bg-slate-800 border border-slate-700 rounded-lg px-4 py-2 text-sm text-white focus:ring-indigo-500 focus:border-indigo-500"
        />
        <div class="flex flex-wrap gap-2">
          <button
            @click="kindFilter = ''"
            class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
            :class="!kindFilter ? 'bg-indigo-600 border-indigo-500 text-white' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'"
          >
            All
          </button>
          <button
            v-for="kind in kinds"
            :key="kind.value"
            @click="kindFilter = kindFilter === kind.value ? '' : kind.value"
            class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
            :class="kindFilter === kind.value ? 'bg-indigo-600 border-indigo-500 text-white' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'"
          >
            {{ kind.label }} · {{ kindCounts[kind.value] || 0 }}
          </button>
        </div>
        <button
          @click="expandAll"
          class="px-3 py-1.5 rounded-lg bg-slate-800 border border-slate-700 text-xs font-semibold text-slate-300 hover:bg-slate-700 transition"
        >
          {{ anyCollapsed ? 'Expand all' : 'Collapse leaves' }}
        </button>
      </div>

      <!-- Diagram -->
      <div class="bg-slate-800 border border-slate-700 rounded-xl">
        <div v-if="!nodes.length" class="p-10 text-center text-sm text-slate-500">
          No routers configured yet.
        </div>

        <div v-else class="relative overflow-x-auto">
          <div class="relative" :style="{ width: canvasWidth + 'px', height: canvasHeight + 'px' }">
            <!-- Edges -->
            <svg class="absolute inset-0 pointer-events-none" :width="canvasWidth" :height="canvasHeight">
              <path
                v-for="edge in edges"
                :key="edge.id"
                :d="edge.d"
                fill="none"
                stroke="#334155"
                stroke-width="1.5"
              />
            </svg>

            <!-- Nodes -->
            <button
              v-for="node in laidOut"
              :key="node.id"
              class="absolute text-left rounded-lg border px-3 py-2 transition shadow-sm hover:shadow-md"
              :class="[cardClass(node), selectedId === node.id ? 'ring-2 ring-indigo-400' : '']"
              :style="{ left: node.x + 'px', top: node.y + 'px', width: NODE_W + 'px', height: NODE_H + 'px' }"
              @click="select(node)"
            >
              <div class="flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="dotClass(node)"></span>
                <span class="px-1 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide shrink-0 truncate" :class="badgeClass(node)">
                  {{ kindLabel(node.kind) }}
                </span>
                <span
                  v-if="childCount(node.id)"
                  class="ml-auto text-[9px] font-bold text-slate-500 shrink-0"
                >{{ childCount(node.id) }}</span>
              </div>
              <p class="mt-1 text-[12px] font-semibold text-white leading-tight line-clamp-2 break-all">{{ node.label }}</p>
              <p v-if="node.sublabel" class="text-[10px] text-slate-500 truncate">{{ node.sublabel }}</p>
              <p v-if="rateLine(node)" class="mt-0.5 text-[10px] font-mono truncate text-emerald-400/90">
                ▼ {{ rateLine(node).down }} ▲ {{ rateLine(node).up }}
              </p>
            </button>
          </div>
        </div>
      </div>

      <!-- Detail -->
      <div v-if="selected" class="bg-slate-800 border border-slate-700 rounded-xl p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div class="flex items-center gap-2">
              <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase border" :class="badgeClass(selected)">{{ kindLabel(selected.kind) }}</span>
              <h3 class="text-lg font-bold text-white">{{ selected.label }}</h3>
            </div>
            <p v-if="selected.sublabel" class="text-sm text-slate-400 mt-1">{{ selected.sublabel }}</p>
          </div>
          <div class="flex items-center gap-3">
            <button
              v-if="metaEntries.length"
              @click="copyDetails"
              class="text-xs font-semibold text-sky-400 hover:text-sky-300"
            >
              {{ copied ? 'Copied ✓' : 'Copy details' }}
            </button>
            <button @click="selectedId = null" class="text-xs text-slate-500 hover:text-slate-300">Close</button>
          </div>
        </div>

        <dl v-if="metaEntries.length" class="mt-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div
            v-for="entry in metaEntries"
            :key="entry.key"
            class="bg-slate-900/60 rounded-lg px-3 py-2 border"
            :class="isAddress(entry.key) ? 'border-sky-900/70' : 'border-slate-700/50'"
          >
            <dt class="text-[10px] uppercase tracking-wider text-slate-500">{{ entry.key }}</dt>
            <dd v-if="entry.list" class="mt-1 space-y-1">
              <p
                v-for="(item, i) in entry.list"
                :key="i"
                class="text-xs font-mono break-all"
                :class="isAddress(entry.key) ? 'text-sky-300 font-semibold' : 'text-slate-200'"
              >
                {{ item }}
              </p>
            </dd>
            <dd v-else class="mt-1 text-xs font-mono break-all" :class="isAddress(entry.key) ? 'text-sky-300 font-semibold' : 'text-slate-200'">
              {{ entry.value }}
            </dd>
          </div>
        </dl>
        <p v-else class="mt-4 text-xs text-slate-500">No additional details for this node.</p>

        <p class="mt-3 text-xs text-slate-500">
          Status: <span class="font-semibold" :class="statusTextClass(selected)">{{ selected.status }}</span>
          <span v-if="childCount(selected.id)"> · {{ childCount(selected.id) }} direct child node(s)</span>
        </p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
  nodes: { type: Array, default: () => [] },
  stats: { type: Object, default: () => ({}) },
  errors: { type: Array, default: () => [] },
  fetchedAt: { type: String, default: null },
  filters: { type: Object, default: () => ({}) },
  defaultPollSeconds: { type: Number, default: 2 },
});

const NODE_W = 190;
const NODE_H = 88;
const H_GAP = 22;
const V_GAP = 54;
const PAD = 24;

const loading = ref(false);
const search = ref('');
const kindFilter = ref(props.filters?.kind || '');
const selectedId = ref(null);
const collapsed = ref(new Set());

// Live state: seeded by the initial Inertia render, then kept current by
// polling /topology/live. Nodes stay reactive so the layout recomputes.
const nodes = ref(props.nodes);
const stats = ref(props.stats);
const errors = ref(props.errors);
const fetchedAt = ref(props.fetchedAt);
const pollSeconds = ref(props.defaultPollSeconds);
const lastError = ref('');

let pollTimer = null;
let tickerTimer = null;
const nowTick = ref(Date.now());

const stopPolling = () => {
  if (pollTimer) {
    clearTimeout(pollTimer);
    pollTimer = null;
  }
};

const poll = async () => {
  loading.value = true;
  try {
    const response = await fetch('/topology/live', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const payload = await response.json();
    nodes.value = payload.nodes || [];
    stats.value = payload.stats || {};
    errors.value = payload.errors || [];
    fetchedAt.value = payload.fetchedAt;
    lastError.value = '';
  } catch (error) {
    lastError.value = `Live update failed (${error.message})`;
  } finally {
    loading.value = false;
  }
};

const startPolling = () => {
  stopPolling();
  if (!pollSeconds.value) return;

  const loop = async () => {
    if (!document.hidden) {
      await poll();
    }
    // Scheduled from completion, so a slow scan never overlaps the next one.
    pollTimer = setTimeout(loop, Math.max(300, pollSeconds.value * 1000));
  };

  pollTimer = setTimeout(loop, pollSeconds.value * 1000);
};

const restartPolling = () => {
  stopPolling();
  startPolling();
};

const onVisibility = () => {
  if (document.hidden) {
    stopPolling();
    return;
  }
  poll();
  startPolling();
};

onMounted(() => {
  startPolling();
  tickerTimer = setInterval(() => { nowTick.value = Date.now(); }, 1000);
  document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
  stopPolling();
  if (tickerTimer) clearInterval(tickerTimer);
  document.removeEventListener('visibilitychange', onVisibility);
});

const reload = () => { poll(); };

const kinds = [
  { value: 'router', label: 'Routers' },
  { value: 'bridge', label: 'Bridges' },
  { value: 'interface', label: 'Interfaces' },
  { value: 'device', label: 'Devices' },
  { value: 'endpoint', label: 'Live clients' },
];

const kindLabel = (kind) => ({
  router: 'Router',
  bridge: 'Bridge',
  interface: 'Port',
  device: 'Device',
  endpoint: 'Client',
}[kind] || kind);

const kindCounts = computed(() => {
  const counts = {};
  for (const node of nodes.value) counts[node.kind] = (counts[node.kind] || 0) + 1;
  return counts;
});

const statCards = computed(() => [
  { label: 'Routers', value: stats.value.routers ?? 0, color: 'text-rose-400' },
  { label: 'Bridges', value: stats.value.bridges ?? 0, color: 'text-violet-400' },
  { label: 'Interfaces', value: stats.value.interfaces ?? 0, color: 'text-sky-400' },
  { label: 'Devices', value: stats.value.devices ?? 0, color: 'text-emerald-400' },
  { label: 'Live clients', value: stats.value.endpoints ?? 0, color: 'text-cyan-400' },
  { label: 'Online', value: stats.value.online ?? 0, color: 'text-emerald-400' },
  { label: 'Offline', value: stats.value.offline ?? 0, color: 'text-slate-400' },
  { label: 'Traffic in', value: stats.value.rx_rate ?? '—', color: 'text-emerald-400' },
  { label: 'Traffic out', value: stats.value.tx_rate ?? '—', color: 'text-amber-400' },
]);

const ago = computed(() => {
  if (!fetchedAt.value) return '';
  const secs = Math.max(0, Math.round((nowTick.value - new Date(fetchedAt.value).getTime()) / 1000));
  if (secs < 60) return `${secs}s`;
  if (secs < 3600) return `${Math.round(secs / 60)}m`;
  return `${Math.round(secs / 3600)}h`;
});

// ---- Graph -----------------------------------------------------------------

const childrenMap = computed(() => {
  const map = new Map();
  for (const node of nodes.value) {
    const key = node.parent ?? '__root__';
    if (!map.has(key)) map.set(key, []);
    map.get(key).push(node);
  }
  for (const list of map.values()) list.sort((a, b) => a.label.localeCompare(b.label));
  return map;
});

const matchesFilter = (node) => {
  const query = search.value.trim().toLowerCase();
  if (kindFilter.value && node.kind !== kindFilter.value) return false;
  if (!query) return true;
  return [node.label, node.sublabel, ...Object.values(node.meta || {})]
    .filter(Boolean)
    .some((value) => String(value).toLowerCase().includes(query));
};

/** Keep a node when it matches, or when it is an ancestor of a match. */
const visibleSet = computed(() => {
  if (!kindFilter.value && !search.value.trim()) return null;

  const keep = new Set();
  const visit = (nodes) => {
    for (const node of nodes) {
      const before = keep.size;
      visit(childrenMap.value.get(node.id) || []);
      if (matchesFilter(node) || keep.size > before) keep.add(node.id);
    }
  };
  visit(childrenMap.value.get('__root__') || []);
  return keep;
});

const isHidden = (node) => visibleSet.value !== null && !visibleSet.value.has(node.id);

const anyCollapsed = computed(() => collapsed.value.size > 0);

const laidOut = computed(() => {
  const roots = (childrenMap.value.get('__root__') || []).filter((node) => !isHidden(node));
  const kidsOf = (id) => (collapsed.value.has(id) ? [] : childrenMap.value.get(id) || []);
  const rows = new Map();
  const seen = new Set();
  let nextSlot = 0;

  const place = (node, depth) => {
    // Defensive: a self-referencing or cyclic parent chain must not hang the page.
    if (seen.has(node.id)) {
      rows.set(node.id, rows.get(node.id) || { node, depth, slot: nextSlot++ });
      return rows.get(node.id).slot;
    }
    seen.add(node.id);

    const kids = kidsOf(node.id).filter((child) => !isHidden(child) && !seen.has(child.id));
    let slot;

    if (!kids.length) {
      slot = nextSlot++;
    } else {
      const childSlots = kids.map((child) => place(child, depth + 1));
      slot = (childSlots[0] + childSlots[childSlots.length - 1]) / 2;
    }

    rows.set(node.id, { node, depth, slot });
    return slot;
  };

  for (const root of roots) place(root, 0);

  const step = NODE_W + H_GAP;
  return [...rows.values()].map(({ node, depth, slot }) => ({
    ...node,
    x: PAD + slot * step,
    y: PAD + depth * (NODE_H + V_GAP),
    depth,
  }));
});

const posById = computed(() => {
  const map = new Map();
  for (const node of laidOut.value) map.set(node.id, node);
  return map;
});

const edges = computed(() => {
  const list = [];
  for (const node of laidOut.value) {
    if (!node.parent) continue;
    const parent = posById.value.get(node.parent);
    if (!parent) continue;

    const x1 = parent.x + NODE_W / 2;
    const y1 = parent.y + NODE_H;
    const x2 = node.x + NODE_W / 2;
    const y2 = node.y;
    const mid = (y1 + y2) / 2;

    list.push({
      id: `${parent.id}->${node.id}`,
      d: `M ${x1} ${y1} C ${x1} ${mid}, ${x2} ${mid}, ${x2} ${y2}`,
    });
  }
  return list;
});

const canvasWidth = computed(() => Math.max(
  640,
  ...laidOut.value.map((n) => n.x + NODE_W + PAD),
));
const canvasHeight = computed(() => Math.max(
  320,
  ...laidOut.value.map((n) => n.y + NODE_H + PAD),
));

const childCount = (id) => (childrenMap.value.get(id) || []).filter((child) => !isHidden(child)).length;

// ---- Interaction -----------------------------------------------------------

const selected = computed(() => nodes.value.find((n) => n.id === selectedId.value) || null);
const metaEntries = computed(() => Object.entries(selected.value?.meta || {})
  .map(([key, value]) => ({
    key,
    value: Array.isArray(value) ? value.join(', ') : String(value),
    list: Array.isArray(value) ? value : null,
  })));

/** Live throughput for a node card, or null when no rate is reported. */
const rateLine = (node) => {
  const meta = node.meta || {};
  if (node.kind === 'endpoint') {
    if (meta['Rate in'] === undefined && meta['Rate out'] === undefined) return null;
    return { down: meta['Rate in'] ?? '—', up: meta['Rate out'] ?? '—' };
  }
  if (meta['RX rate'] === undefined && meta['TX rate'] === undefined) return null;
  return { down: meta['RX rate'] ?? '—', up: meta['TX rate'] ?? '—' };
};

/** Keys whose value is an address — rendered like code, never wrapped away. */
const ADDRESS_KEYS = new Set(['IPv4', 'IPv6', 'MAC', 'Management IP', 'PPPoE remote address', 'API endpoint']);
const isAddress = (key) => ADDRESS_KEYS.has(key);

const select = (node) => {
  selectedId.value = selectedId.value === node.id ? null : node.id;
};

const copied = ref(false);

const copyDetails = async () => {
  if (!selected.value) return;
  const lines = [
    `${kindLabel(selected.value.kind)}: ${selected.value.label}`,
    selected.value.sublabel || '',
    ...metaEntries.value.flatMap((entry) => (
      entry.list || [entry.value]
    ).map((item) => `${entry.key}: ${item}`)),
  ].filter(Boolean);

  try {
    await navigator.clipboard.writeText(lines.join('\n'));
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1500);
  } catch {
    copied.value = false;
  }
};

const expandAll = () => {
  if (anyCollapsed.value) {
    collapsed.value = new Set();
    return;
  }
  const leaves = new Set();
  for (const node of nodes.value) {
    if ((childrenMap.value.get(node.id) || []).length) leaves.add(node.id);
  }
  collapsed.value = leaves;
};

const badgeClass = (node) => ({
  router: 'bg-rose-950 text-rose-400 border-rose-800',
  bridge: 'bg-violet-950 text-violet-400 border-violet-800',
  interface: 'bg-sky-950 text-sky-400 border-sky-800',
  device: 'bg-emerald-950 text-emerald-400 border-emerald-800',
  endpoint: 'bg-cyan-950 text-cyan-400 border-cyan-800',
}[node.kind] || 'bg-zinc-800 text-zinc-400 border-zinc-700');

const cardClass = (node) => {
  const base = 'bg-slate-900 ';
  const border = {
    router: 'border-rose-800/70 ',
    bridge: 'border-violet-800/70 ',
    interface: 'border-sky-800/70 ',
    device: 'border-emerald-800/70 ',
    endpoint: 'border-cyan-800/50 ',
  }[node.kind] || 'border-slate-700 ';
  return base + border + (node.status === 'online' ? 'hover:border-slate-500' : 'opacity-80 hover:opacity-100');
};

const dotClass = (node) => ({
  online: 'bg-emerald-400',
  offline: 'bg-rose-400',
  disabled: 'bg-amber-500',
  stale: 'bg-amber-500',
  muted: 'bg-slate-600',
}[node.status] || 'bg-slate-600');

const statusTextClass = (node) => ({
  online: 'text-emerald-400',
  offline: 'text-rose-400',
  disabled: 'text-amber-400',
  stale: 'text-amber-400',
  muted: 'text-slate-500',
}[node.status] || 'text-slate-400');
</script>
