<script setup lang="ts">
// ServerOS's own servers icon, drawn in the sidebar's icon style (Solar
// duotone: a half-tone body, solid details): two units, each with a vent
// and a status light. Sized by its class, coloured by currentColor.
</script>

<template>
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <g opacity=".5">
            <rect x="2" y="2.5" width="20" height="8.25" rx="3" />
            <rect x="2" y="13.25" width="20" height="8.25" rx="3" />
        </g>
        <rect x="5.25" y="5.875" width="6.5" height="1.5" rx=".75" />
        <rect x="5.25" y="16.625" width="6.5" height="1.5" rx=".75" />
        <circle cx="17.5" cy="6.625" r="1.4" />
        <circle cx="17.5" cy="17.375" r="1.4" />
    </svg>
</template>
