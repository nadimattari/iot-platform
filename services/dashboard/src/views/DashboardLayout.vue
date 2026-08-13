<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout(): Promise<void> {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="layout">
    <header class="topbar">
      <div class="topbar-inner">
        <RouterLink to="/dashboard" class="brand" aria-label="IIoT Platform home">
          <span class="brand-mark" aria-hidden="true">IIoT</span>
          <span class="brand-name">IIoT Platform</span>
        </RouterLink>

        <nav class="nav" aria-label="Primary">
          <RouterLink to="/dashboard" class="nav-item">Overview</RouterLink>
          <RouterLink to="/dashboard/devices" class="nav-item">Devices</RouterLink>
          <RouterLink to="/dashboard/insights" class="nav-item">Insights</RouterLink>
        </nav>

        <div class="account">
          <span class="account-email">{{ auth.user?.email }}</span>
          <Button label="Sign out" severity="secondary" text @click="logout" />
        </div>
      </div>
    </header>

    <main class="content">
      <router-view />
    </main>
  </div>
</template>
