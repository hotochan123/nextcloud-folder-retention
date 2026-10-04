<template>
	<span class="fr-badge"
		:class="[`fr-badge--${tone}`, own ? 'fr-badge--own' : 'fr-badge--inherited']"
		:title="own ? t('folder_retention', 'Own rule') : t('folder_retention', 'Inherited rule')">
		{{ label }}
		<span class="hidden-visually">{{ own ? t('folder_retention', '(own rule)') : t('folder_retention', '(inherited)') }}</span>
	</span>
</template>

<script setup>
import { computed } from 'vue'
import { isNever, periodLabel } from '../format.js'
import { t } from '../l10n.js'

const props = defineProps({
	/** Ergebnis von store.effective() */
	eff: { type: Object, required: true },
})

const own = computed(() => props.eff.isOwn && !props.eff.inactive)
const tone = computed(() => {
	if (props.eff.inactive) {
		return 'off'
	}
	return isNever(props.eff.rule) ? 'keep' : 'delete'
})
const label = computed(() => props.eff.inactive ? t('folder_retention', 'No deletion') : periodLabel(props.eff.rule))
</script>

<style scoped lang="scss">
/*
 * Farben aus colors.css. Gefüllt = eigene Regel, umrandet = geerbt.
 */
.fr-badge {
	display: inline-block;
	min-width: 7.5em;
	padding: 1px 10px;
	border: 2px solid transparent;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.9em;
	font-weight: 600;
	line-height: 1.5;
	text-align: center;
	white-space: nowrap;

	&--delete.fr-badge--own {
		background: var(--fr-delete);
		border-color: var(--fr-delete);
		color: var(--fr-delete-on);
	}
	&--delete.fr-badge--inherited {
		background: transparent;
		border-color: var(--fr-delete);
		color: var(--fr-delete-text-outline);
	}
	&--keep.fr-badge--own {
		background: var(--fr-keep);
		border-color: var(--fr-keep-border);
		color: var(--fr-keep-text);
	}
	&--keep.fr-badge--inherited {
		background: transparent;
		border-color: var(--fr-keep-border);
		border-style: dashed;
		color: var(--fr-keep-text);
	}
	&--off {
		background: transparent;
		border-color: var(--color-border-dark, #999);
		border-style: dotted;
		color: var(--color-text-maxcontrast, #6b6b6b);
		font-weight: normal;
	}
}
</style>
