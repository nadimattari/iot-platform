<script setup lang="ts">
import { computed } from 'vue'
import Tag from 'primevue/tag'
import type { DeviceStatus } from '@/api/types'

const props = defineProps<{
  status: DeviceStatus | null
}>()

const online = computed(() => props.status?.online === true)
const lastSeen = computed(() => {
  if (!props.status?.last_seen) return null
  const ms = Date.now() - new Date(props.status.last_seen).getTime()
  if (ms < 0) return 'now'
  if (ms < 60_000) return `${Math.floor(ms / 1000)}s ago`
  if (ms < 3_600_000) return `${Math.floor(ms / 60_000)}m ago`
  if (ms < 86_400_000) return `${Math.floor(ms / 3_600_000)}h ago`
  return `${Math.floor(ms / 86_400_000)}d ago`
})
</script>

<template>
  <span class="status-tag">
    <Tag
      :value="online ? 'Online' : 'Offline'"
      :severity="online ? 'success' : 'secondary'"
      :class="{ pulse: online }"
    />
    <small v-if="lastSeen" class="status-tag__last-seen">{{ lastSeen }}</small>
  </span>
</template>

<style scoped>
.status-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  white-space: nowrap;
}
.status-tag__last-seen {
  color: var(--p-text-muted-color);
}
@keyframes status-pulse {
  0% {
    box-shadow: 0 0 0 0 rgb(34 197 94 / 40%);
  }
  70% {
    box-shadow: 0 0 0 6px rgb(34 197 94 / 0%);
  }
  100% {
    box-shadow: 0 0 0 0 rgb(34 197 94 / 0%);
  }
}
.pulse :deep(.p-tag) {
  animation: status-pulse 2s infinite;
}
</style>
