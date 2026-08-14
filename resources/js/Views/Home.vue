<script setup>
/*
 * The globe home screen (design/README.md §1), which arrives in a later PR.
 *
 * `name` IS LOAD-BEARING and must stay 'Home': App.vue's <KeepAlive> keeps this
 * view mounted across tab switches by matching on it, and the globe it will
 * hold — a WebGL scene, Earth textures and a running camera tour — is exactly
 * the thing that must not be rebuilt every time somebody looks at the calendar.
 * Renaming this component silently turns that caching off.
 */
import ScreenPlaceholder from '@/Components/ScreenPlaceholder.vue'

defineOptions({ name: 'Home' })
</script>

<template>
  <ScreenPlaceholder title="Good morning" arrives="The 3D globe and its route tour arrive in a later PR." />
</template>
