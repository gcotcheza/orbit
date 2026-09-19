<script setup>
import { ref, useAttrs } from 'vue'
import LeavingOrbitDialog from '@/Components/LeavingOrbitDialog.vue'

const attrs = useAttrs()

const leaving = ref(false)
let opener = null

// A held modifier or a non-primary button is a choice already made about where
// this opens, and it is the browser's to honour.
function onClick(event) {
  if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
    return
  }

  event.preventDefault()
  opener = event.currentTarget
  leaving.value = true
}

function stay() {
  leaving.value = false
  opener?.focus()
}
</script>

<template>
  <a target="_blank" rel="noopener" @click="onClick">
    <slot />
    <span class="sr-only"> (opens in a new tab)</span>

    <!-- Written inside the anchor so one root keeps attribute fallthrough; it
         teleports itself to the body, so no anchor ever contains it. -->
    <LeavingOrbitDialog v-if="leaving" :href="attrs.href" @close="stay" />
  </a>
</template>
