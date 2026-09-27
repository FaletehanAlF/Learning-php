<template>
  <div class="card border-l-4" :class="border">
    <div class="text-sm text-slate-500">{{ title }}</div>
    <div class="text-2xl font-bold">{{ value }}</div>
  </div>
</template>

<script setup>
defineProps({ title: String, value: [String, Number], border: { type: String, default: 'border-blue-500' } })
</script>
