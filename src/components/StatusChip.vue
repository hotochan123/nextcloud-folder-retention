<template>
	<span class="fr-status" :class="`fr-status--${tone}`">{{ label }}</span>
</template>

<script setup>
defineProps({
	/** delete | sim | old | skip | error, see CATEGORY_TONE */
	tone: { type: String, required: true },
	label: { type: String, required: true },
})
</script>

<style scoped lang="scss">
.fr-status {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.9em;
	font-weight: normal;

	&--delete {
		background: var(--fr-delete);
		color: var(--fr-delete-on);
	}

	&--sim {
		border: 1px solid var(--fr-delete);
		color: var(--fr-delete-text-outline);
	}

	// superseded simulated hit: like "would delete", but faded out – it no longer applies
	&--old {
		border: 1px dashed var(--color-border-maxcontrast);
		color: var(--color-text-maxcontrast);
	}

	&--skip {
		background: var(--color-background-dark);
		color: var(--color-main-text);
	}

	&--error {
		// in Nextcloud 34, --color-error is a light background tone; white text on it is unreadable
		background: var(--color-element-error, #c00);
		color: #fff;
	}
}
</style>
