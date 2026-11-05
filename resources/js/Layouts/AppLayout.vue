<template>
  <div class="min-h-screen bg-slate-900 text-slate-100 flex font-sans">
    <!-- Left Sidebar -->
    <aside class="w-16 md:w-60 shrink-0 bg-slate-800 border-r border-slate-700 flex flex-col sticky top-0 h-screen shadow-md z-20">
      <!-- Brand -->
      <a href="/dashboard" class="flex items-center space-x-3 px-4 py-5 border-b border-slate-700">
        <div class="w-9 h-9 shrink-0 rounded-lg bg-indigo-600 flex items-center justify-center font-bold text-white shadow-lg shadow-indigo-500/30">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
        </div>
        <div class="hidden md:block leading-tight">
          <h1 class="font-extrabold text-lg tracking-wider text-white">MIKROTIK<span class="text-indigo-400">ISP</span></h1>
          <p class="text-xs text-slate-400">Core Network Controller</p>
        </div>
      </a>

      <!-- Navigation -->
      <nav class="flex-1 overflow-y-auto py-4 space-y-1 px-3">
        <a
          v-for="item in navItems"
          :key="item.href"
          :href="item.href"
          :title="item.label"
          class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium transition"
          :class="isActive(item)
            ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20'
            : 'text-slate-300 hover:bg-slate-700 hover:text-white'"
        >
          <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
          </svg>
          <span class="hidden md:inline">{{ item.label }}</span>
        </a>
      </nav>

      <!-- System Status -->
      <div class="border-t border-slate-700 px-3 py-4 space-y-3">
        <span
          class="flex items-center justify-center md:justify-start space-x-2 px-3 py-2 rounded-full text-xs font-medium bg-emerald-900/60 text-emerald-400 border border-emerald-700/50"
        >
          <span class="w-1.5 h-1.5 bg-emerald-400 rounded-full animate-pulse"></span>
          <span class="hidden md:inline">Daemon Active</span>
        </span>

        <div v-if="$page.props.auth?.user" class="flex items-center justify-between gap-2">
          <div class="hidden md:block min-w-0">
            <p class="text-xs font-semibold text-white truncate">{{ $page.props.auth.user.name }}</p>
            <p class="text-[11px] text-slate-400 truncate">{{ $page.props.auth.user.email }}</p>
          </div>
          <Link href="/logout" method="post" as="button" class="shrink-0 text-xs px-2 py-1.5 rounded bg-slate-700 hover:bg-rose-800 text-slate-200 transition" title="Sign out">
            <span class="hidden md:inline">Sign out</span>
            <svg class="w-4 h-4 md:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
          </Link>
        </div>
      </div>
    </aside>

    <!-- Main Column -->
    <div class="flex-1 min-w-0 flex flex-col">
      <!-- Flash Notifications -->
      <div v-if="$page.props.flash?.success" class="px-6 mt-4 w-full" :class="fullWidth ? '' : 'max-w-7xl mx-auto'">
        <div class="p-4 bg-emerald-900/40 border border-emerald-600/50 rounded-lg text-emerald-300 flex items-center justify-between">
          <span>{{ $page.props.flash.success }}</span>
        </div>
      </div>
      <div v-if="$page.props.flash?.warning" class="px-6 mt-4 w-full" :class="fullWidth ? '' : 'max-w-7xl mx-auto'">
        <div class="p-4 bg-amber-900/40 border border-amber-600/50 rounded-lg text-amber-300 flex items-center justify-between">
          <span>{{ $page.props.flash.warning }}</span>
        </div>
      </div>
      <div v-if="$page.props.flash?.error" class="px-6 mt-4 w-full" :class="fullWidth ? '' : 'max-w-7xl mx-auto'">
        <div class="p-4 bg-rose-900/40 border border-rose-600/50 rounded-lg text-rose-300 flex items-center justify-between">
          <span>{{ $page.props.flash.error }}</span>
        </div>
      </div>

      <!-- Main Content Area -->
      <main class="flex-1 w-full px-6 py-8" :class="fullWidth ? 'max-w-none' : 'max-w-7xl mx-auto'">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import { usePage, Link } from '@inertiajs/vue3';

defineProps({
  fullWidth: {
    type: Boolean,
    default: false,
  },
});

const page = usePage();

const navItems = [
  {
    label: 'Dashboard',
    href: '/dashboard',
    match: (url) => url === '/' || url.startsWith('/dashboard'),
    icon: 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  },
  {
    label: 'Routers',
    href: '/routers',
    match: (url) => url.startsWith('/routers'),
    icon: 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01',
  },
  {
    label: 'Plans',
    href: '/plans',
    match: (url) => url.startsWith('/plans'),
    icon: 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
  },
  {
    label: 'Subscribers',
    href: '/clients',
    match: (url) => url.startsWith('/clients'),
    icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
  },
  {
    label: 'Inventory',
    href: '/inventory',
    match: (url) => url.startsWith('/inventory'),
    icon: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
  },
  {
    label: 'Vouchers',
    href: '/vouchers',
    match: (url) => url.startsWith('/vouchers'),
    icon: 'M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z',
  },
  {
    label: 'Payments',
    href: '/payments',
    match: (url) => url.startsWith('/payments'),
    icon: 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
  },
  {
    label: 'Invoices',
    href: '/invoices',
    match: (url) => url.startsWith('/invoices'),
    icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
  },
  {
    label: 'Tickets',
    href: '/tickets',
    match: (url) => url.startsWith('/tickets'),
    icon: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
  },
  {
    label: 'Settings',
    href: '/settings',
    match: (url) => url.startsWith('/settings'),
    icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
  },
  {
    label: 'Router Users',
    href: '/subscriptions',
    match: (url) => url.startsWith('/subscriptions'),
    icon: 'M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0',
  },
  {
    label: 'Connected Devices',
    href: '/connected-devices',
    match: (url) => url.startsWith('/connected-devices'),
    icon: 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
  },
  {
    label: 'Topology',
    href: '/topology',
    match: (url) => url.startsWith('/topology'),
    icon: 'M13 10V3L4 14h7v7l9-11h-7z',
  },
];

const isActive = (item) => item.match(page.url);
</script>
