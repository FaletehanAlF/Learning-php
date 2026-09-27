<template>
  <div class="min-h-screen bg-slate-100 text-slate-800">
    <Navbar v-if="auth.isLoggedIn" />
    <main class="mx-auto max-w-6xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import { RouterView } from 'vue-router'
import { useAuthStore } from './store/auth'
import Navbar from './components/Navbar.vue'

const auth = useAuthStore()
</script>
