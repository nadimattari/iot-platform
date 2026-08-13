<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

const canSubmit = computed(() => email.value.length > 0 && password.value.length > 0 && !submitting.value)

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  submitting.value = true
  error.value = null
  try {
    await auth.login(email.value, password.value)
    const next = typeof route.query.next === 'string' && route.query.next.length > 0 ? route.query.next : '/dashboard'
    await router.replace(next)
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Sign in failed'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="login-page">
    <section class="login-card" aria-labelledby="login-title">
      <header class="login-brand">
        <span class="brand-mark" aria-hidden="true">IIoT</span>
        <div>
          <h1 id="login-title">IIoT Platform</h1>
          <p>Operator console</p>
        </div>
      </header>

      <form class="login-form" novalidate @submit.prevent="submit">
        <div class="field">
          <label for="login-email">Email</label>
          <InputText
            id="login-email"
            v-model="email"
            type="email"
            autocomplete="username"
            required
            :invalid="error !== null"
            @keyup.enter="submit"
          />
        </div>

        <div class="field">
          <label for="login-password">Password</label>
          <Password
            id="login-password"
            v-model="password"
            :feedback="false"
            toggle-mask
            autocomplete="current-password"
            required
            :invalid="error !== null"
            @keyup.enter="submit"
          />
        </div>

        <Transition name="fade">
          <Message v-if="error" severity="error" variant="simple">{{ error }}</Message>
        </Transition>

        <Button
          type="submit"
          label="Sign in"
          icon="pi pi-sign-in"
          icon-pos="right"
          class="login-submit"
          :loading="submitting"
          :disabled="!canSubmit"
        />
      </form>
    </section>
  </main>
</template>
