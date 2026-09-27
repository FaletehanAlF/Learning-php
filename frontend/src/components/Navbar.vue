<template>
  <nav class="bg-slate-900 text-white">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-2 px-4 py-3">
      <RouterLink to="/dashboard" class="mr-4 text-lg font-bold">{{ appName }}</RouterLink>
      <RouterLink v-for="l in links" :key="l.to" :to="l.to" class="rounded px-3 py-1.5 text-sm hover:bg-slate-700" active-class="bg-slate-700">
        {{ l.label }}
      </RouterLink>
      <div class="ml-auto flex items-center gap-3 text-sm">
        <span class="text-slate-300">{{ auth.user?.username }} ({{ auth.user?.role }})</span>
        <button class="rounded border border-slate-500 px-3 py-1 hover:bg-slate-700" @click="logout">Logout</button>
      </div>
    </div>
  </nav>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../store/auth'

const auth = useAuthStore()
const router = useRouter()
const appName = import.meta.env.VITE_APP_NAME || 'Kas Management'

const links = computed(() => {
  const all = [
    { to: '/dashboard', label: 'Dashboard', show: true },
    { to: '/transactions', label: 'Transaksi', show: true },
    { to: '/reports', label: 'Laporan', show: true },
    { to: '/categories', label: 'Kategori', show: true },
    { to: '/logs', label: 'Audit Log', show: auth.canAudit },
    { to: '/users', label: 'Users', show: auth.isAdmin },
  ]
  return all.filter((l) => l.show)
})

async function logout() {
  await auth.logout()
  router.push('/login')
}
</script>
