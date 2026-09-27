<template>
  <div class="mx-auto mt-10 max-w-md">
    <h1 class="mb-4 text-center text-2xl font-bold">{{ appName }}</h1>
    <div class="card">
      <h2 class="mb-3 text-lg font-semibold">Login</h2>
      <div v-if="error" class="mb-3 rounded bg-red-100 px-3 py-2 text-sm text-red-700">{{ error }}</div>
      <form @submit.prevent="submit" class="space-y-3">
        <div>
          <label class="label">Username / Email</label>
          <input v-model="form.login" class="input" required autofocus />
        </div>
        <div>
          <label class="label">Password</label>
          <input v-model="form.password" type="password" class="input" required />
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.remember" type="checkbox" /> Remember me
        </label>
        <button class="btn-primary w-full" :disabled="loading">{{ loading ? 'Masuk...' : 'Masuk' }}</button>
      </form>
      <p class="mt-3 text-center text-sm"><RouterLink to="/forgot" class="text-blue-600">Lupa password?</RouterLink></p>
      <p class="mt-2 text-center text-xs text-slate-400">admin/admin123 • kasir/kasir123 • supervisor/spv123</p>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../store/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const appName = import.meta.env.VITE_APP_NAME || 'Kas Management'
const form = reactive({ login: '', password: '', remember: false })
const error = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await auth.login(form.login, form.password)
    router.push(route.query.redirect || '/dashboard')
  } catch (e) {
    error.value = e.response?.data?.message || 'Login gagal.'
  } finally {
    loading.value = false
  }
}
</script>
