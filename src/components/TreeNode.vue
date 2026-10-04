<template>
	<li class="fr-node" :class="{ 'fr-node--selected': selected }">
		<div class="fr-row" :style="{ '--depth': depth }">
			<div class="fr-row__main">
				<button v-if="expandable"
					type="button"
					class="fr-chevron"
					:class="{ 'fr-chevron--open': expanded }"
					:aria-expanded="expanded ? 'true' : 'false'"
					:aria-label="expanded ? t('folder_retention', 'Collapse {name}', { name: label }) : t('folder_retention', 'Expand {name}', { name: label })"
					@click="onToggle">
					<NcLoadingIcon v-if="loadingChildren" :size="16" />
					<NcIconSvgWrapper v-else :path="mdiChevronRight" :size="20" />
				</button>
				<span v-else class="fr-chevron fr-chevron--none" />
				<NcIconSvgWrapper class="fr-icon" :path="icon" :size="20" />
				<button v-if="selectable"
					type="button"
					class="fr-name"
					:title="node?.path ?? label"
					:aria-current="selected ? 'true' : undefined"
					@click="state.selected = nodeKey">
					<span class="fr-name__text">{{ label }}</span>
					<span v-if="dirty" class="fr-dirty" :title="t('folder_retention', 'Unsaved change')">
						<span aria-hidden="true">●</span><span class="hidden-visually">{{ t('folder_retention', '(unsaved)') }}</span>
					</span>
				</button>
				<span v-else class="fr-name fr-name--static">{{ label }}</span>
			</div>
			<div class="fr-row__badge">
				<RuleBadge v-if="eff" :eff="eff" />
			</div>
			<div class="fr-row__source">
				{{ source }}
			</div>
		</div>
		<ul v-if="expandable && expanded && childIds" class="fr-children">
			<TreeNode v-for="id in childIds"
				:key="id"
				:node-key="id"
				:depth="depth + 1" />
			<li v-if="childIds.length === 0" class="fr-empty" :style="{ '--depth': depth + 1 }">
				{{ t('folder_retention', 'No subfolders') }}
			</li>
		</ul>
	</li>
</template>

<script setup>
import { computed } from 'vue'
import { mdiAccount, mdiAccountGroup, mdiAccountMultiple, mdiChevronRight, mdiFolder, mdiFolderAccount, mdiFolderClock } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import RuleBadge from './RuleBadge.vue'
import { DEFAULT_KEY, PERSONAL_KEY, effective, isDirty, nameOf, state, toggle } from '../store.js'
import { sourceText } from '../format.js'
import { t } from '../l10n.js'

defineOptions({ name: 'TreeNode' })

const props = defineProps({
	nodeKey: { type: [Number, String], required: true },
	depth: { type: Number, default: 0 },
})

const isDefault = computed(() => props.nodeKey === DEFAULT_KEY)
const isPersonalGroup = computed(() => props.nodeKey === PERSONAL_KEY)
const node = computed(() => typeof props.nodeKey === 'number' ? state.nodes[props.nodeKey] : null)

const label = computed(() => {
	if (isDefault.value) {
		return t('folder_retention', 'Default rule · all other files')
	}
	if (isPersonalGroup.value) {
		return t('folder_retention', 'Personal folders ({count})', { count: state.children[PERSONAL_KEY]?.length ?? 0 })
	}
	// persönliche Wurzel: nur das Konto zeigen – der Name trägt das (übersetzte) Präfix „Persönlich · “
	return node.value?.isRoot && node.value.kind === 'home' ? (node.value.accountId ?? node.value.name) : node.value?.name
})

const icon = computed(() => {
	if (isDefault.value) {
		return mdiFolderClock
	}
	if (isPersonalGroup.value) {
		return mdiAccountMultiple
	}
	if (node.value?.isRoot) {
		return { team: mdiFolderAccount, workspace: mdiAccountGroup }[node.value.kind] ?? mdiAccount
	}
	return mdiFolder
})

const selectable = computed(() => true)
const selected = computed(() => state.selected === props.nodeKey)
const dirty = computed(() => selectable.value && isDirty(props.nodeKey))
const expanded = computed(() => !!state.expanded[props.nodeKey])
const loadingChildren = computed(() => !!state.loadingChildren[props.nodeKey])

const childIds = computed(() => {
	if (isDefault.value) {
		const teams = state.children[DEFAULT_KEY] ?? []
		return (state.children[PERSONAL_KEY]?.length ?? 0) > 0 ? [...teams, PERSONAL_KEY] : teams
	}
	return state.children[props.nodeKey] ?? null
})

const expandable = computed(() => {
	if (isDefault.value || isPersonalGroup.value) {
		return true
	}
	return (node.value?.childCount ?? 0) > 0
})

const eff = computed(() => effective(props.nodeKey))

const source = computed(() => {
	if (isDefault.value || isPersonalGroup.value) {
		return t('folder_retention', 'Own rule')
	}
	return sourceText(eff.value, (node.value?.childCount ?? 0) > 0, nameOf)
})

function onToggle() {
	toggle(props.nodeKey)
}
</script>

<style scoped lang="scss">
.fr-node {
	list-style: none;
}

.fr-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 9em 13em;
	align-items: center;
	gap: 8px;
	min-height: 40px;
	padding-inline-end: 8px;
	border-radius: var(--border-radius-large, 8px);

	&:hover {
		background: var(--color-background-hover);
	}

	.fr-node--selected > & {
		background: var(--color-primary-element-light);
	}

	&__main {
		display: flex;
		align-items: center;
		gap: 4px;
		min-width: 0;
		padding-inline-start: calc(var(--depth) * 20px);
	}

	&__source {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
}

.fr-chevron {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 32px;
	width: 32px;
	height: 32px;
	min-height: 32px;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: transparent;
	cursor: pointer;

	:deep(svg) {
		transition: transform 0.15s ease;
	}

	&--open :deep(svg) {
		transform: rotate(90deg);
	}

	&:hover,
	&:focus-visible {
		background: var(--color-background-dark);
	}

	&--none {
		cursor: default;
	}
}

.fr-icon {
	flex: 0 0 auto;
	color: var(--color-primary-element);
}

.fr-name {
	display: flex;
	flex: 1 1 auto;
	align-items: center;
	gap: 6px;
	min-width: 0;
	max-width: 100%;
	min-height: 32px;
	margin: 0;
	padding: 0 6px;
	border: none;
	border-radius: var(--border-radius, 4px);
	background: transparent;
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
	cursor: pointer;

	&__text {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&[aria-current='true'] {
		font-weight: bold;
	}

	&--static {
		cursor: default;
		color: var(--color-text-maxcontrast);
	}
}

.fr-dirty {
	color: var(--color-warning-text, #a37200);
	font-size: 0.8em;
}

.fr-children {
	margin: 0;
	padding: 0;
}

.fr-empty {
	list-style: none;
	padding-inline-start: calc(var(--depth) * 20px + 60px);
	color: var(--color-text-maxcontrast);
	font-style: italic;
	min-height: 32px;
	line-height: 32px;
}

@media (max-width: 700px) {
	.fr-row {
		grid-template-columns: minmax(0, 1fr) auto;

		&__source {
			display: none;
		}
	}
}
</style>
