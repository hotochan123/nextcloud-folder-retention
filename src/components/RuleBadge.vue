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
 * Orange = Löschfrist (dunkel), Blau = Nie löschen (hell) – unterscheidbar auch ohne Farbsehen.
 * Gefüllt = eigene Regel, umrandet = geerbt.
 */
.fr-badge {
	--fr-delete: #a84300;
	--fr-delete-text-outline: #8a3700;
	--fr-keep: #cfe2ff;
	--fr-keep-border: #2f63b8;
	--fr-keep-text: #0a3274;
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
		color: #fff;
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

// Dunkles Theme: Orange heller, Blau dunkler – Helligkeitsabstand bleibt erhalten
:global(body[data-themes*='dark']) .fr-badge,
:global(body[data-theme-dark]) .fr-badge {
	--fr-delete: #ff8a3d;
	--fr-delete-text-outline: #ffa466;
	--fr-keep: #1b3d73;
	--fr-keep-border: #7fb0ff;
	--fr-keep-text: #d6e6ff;

	&.fr-badge--delete.fr-badge--own {
		color: #1f0d00;
	}
}
</style>
