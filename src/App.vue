<template>
	<div class="section fr-main">
		<h2>{{ t('folder_retention', 'Folder retention') }}</h2>
		<p class="fr-intro">
			{{ t('folder_retention', 'Retention periods are attached to folders. A daily background job moves expired files to the trash – what counts is always the current location.') }}
		</p>
		<NcNoteCard v-if="state.settings.simulation" type="warning" class="fr-banner">
			<strong>{{ t('folder_retention', 'Simulation mode active.') }}</strong>
			{{ t('folder_retention', 'Nothing is deleted; due files are only recorded in the log.') }}
		</NcNoteCard>
		<NcNoteCard v-else type="error" class="fr-banner">
			<strong>{{ t('folder_retention', 'Deletion active.') }}</strong> {{ t('folder_retention', 'Due files are moved to the trash daily.') }}
		</NcNoteCard>
		<NcNoteCard v-if="state.settings.cronMode && state.settings.cronMode !== 'cron'" type="warning" class="fr-banner">
			<strong>{{ t('folder_retention', 'No system cron.') }}</strong>
			{{ t('folder_retention', 'Background jobs run via {mode}.', { mode: state.settings.cronMode === 'ajax' ? 'AJAX' : 'Webcron' }) }}
			{{ t('folder_retention', 'For safety reasons, the app only deletes (and simulates) in system cron or via') }}
			<code>occ folder_retention:run</code>
			{{ t('folder_retention', '– in a web request, the trash could be bypassed.') }}
			{{ t('folder_retention', 'Fix: Administration settings → Basic settings → Background jobs → Cron (recommended).') }}
		</NcNoteCard>
		<NcNoteCard v-if="blocked.length" type="error" class="fr-banner">
			<p>
				<strong>{{ t('folder_retention', 'Deletion blocked.') }}</strong>
				{{ t('folder_retention', 'Nextcloud deleted files permanently instead of moving them to the trash. The app no longer deletes anything in these areas until the cause has been resolved and the block has been lifted (details in the log, status “Error”):') }}
			</p>
			<ul class="fr-blocked">
				<li v-for="b in blocked" :key="b.key" class="fr-blocked__item">
					<span><strong>{{ b.label }}</strong> · {{ formatDate(b.at) }} · {{ b.reason }}</span>
					<NcButton variant="secondary" :disabled="unblocking" @click="onUnblock(b)">
						{{ t('folder_retention', 'Lift block') }}
					</NcButton>
				</li>
			</ul>
		</NcNoteCard>

		<NcLoadingIcon v-if="state.loading" :size="40" />
		<NcNoteCard v-else-if="state.error" type="error">
			{{ state.error }}
		</NcNoteCard>

		<div v-else class="fr-layout">
			<section class="fr-tree" aria-labelledby="fr-tree-title">
				<div class="fr-tree__head">
					<h3 id="fr-tree-title">
						{{ t('folder_retention', 'Folders') }}
					</h3>
					<span v-if="dirtyCount" class="fr-unsaved" role="status">
						{{ n('folder_retention', '%n unsaved change', '%n unsaved changes', dirtyCount) }}
					</span>
					<div class="fr-tree__buttons">
						<NcButton variant="tertiary" :disabled="expanding" @click="onExpandAll">
							<template #icon>
								<NcLoadingIcon v-if="expanding" :size="20" />
								<NcIconSvgWrapper v-else :path="mdiArrowExpandVertical" />
							</template>
							{{ t('folder_retention', 'Expand all') }}
						</NcButton>
						<NcButton variant="tertiary" @click="collapseAll">
							<template #icon>
								<NcIconSvgWrapper :path="mdiArrowCollapseVertical" />
							</template>
							{{ t('folder_retention', 'Collapse all') }}
						</NcButton>
					</div>
				</div>
				<div class="fr-tree__cols" aria-hidden="true">
					<span>{{ t('folder_retention', 'Folder') }}</span><span>{{ t('folder_retention', 'Period') }}</span><span>{{ t('folder_retention', 'Source') }}</span>
				</div>
				<ul class="fr-tree__list" :aria-label="t('folder_retention', 'Folder tree')">
					<TreeNode :node-key="DEFAULT_KEY" :depth="0" />
				</ul>
			</section>

			<DetailPanel class="fr-layout__detail" />
		</div>
	</div>

	<LogPanel v-if="!state.loading && !state.error" />
	<SettingsPanel v-if="!state.loading && !state.error" />
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { mdiArrowCollapseVertical, mdiArrowExpandVertical } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError, showSuccess } from '@nextcloud/dialogs'
import TreeNode from './components/TreeNode.vue'
import DetailPanel from './components/DetailPanel.vue'
import SettingsPanel from './components/SettingsPanel.vue'
import LogPanel from './components/LogPanel.vue'
import { DEFAULT_KEY, collapseAll, dirtyCount, expandAll, loadAll, state, updateSettings } from './store.js'
import { formatDate, unblockPayload } from './format.js'
import { n, t } from './l10n.js'

const expanding = ref(false)

async function onExpandAll() {
	expanding.value = true
	try {
		await expandAll()
	} catch (e) {
		showError(t('folder_retention', 'Folders could not be loaded completely'))
	} finally {
		expanding.value = false
	}
}

onMounted(loadAll)

/** Areas in which nothing is deleted anymore after a permanent deletion */
const blocked = computed(() => state.settings.blockedRoots ?? [])
const unblocking = ref(false)

/** Unblock only this area – others (including ones blocked again in the meantime) stay blocked */
async function onUnblock(entry) {
	unblocking.value = true
	try {
		await updateSettings(unblockPayload([entry]))
		const still = blocked.value.some(b => b.key === entry.key)
		if (still) {
			showError(t('folder_retention', '“{name}” has been blocked again in the meantime – please check the reason', { name: entry.label }))
		} else {
			showSuccess(t('folder_retention', 'Block for “{name}” lifted – the next run will delete there again', { name: entry.label }))
		}
	} catch (e) {
		if (!e?.cancelled) {
			showError(e?.response?.data?.message ?? t('folder_retention', 'Block could not be lifted'))
		}
	} finally {
		unblocking.value = false
	}
}

// Warn when leaving with unsaved changes
window.addEventListener('beforeunload', (e) => {
	if (dirtyCount.value > 0) {
		e.preventDefault()
	}
})
</script>

<style scoped lang="scss">
.fr-main {
	max-width: 1500px;

	h2 {
		margin-top: 0;
		font-size: 1.5em;
		font-weight: bold;
	}
}

.fr-intro {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.fr-banner {
	margin-bottom: 16px;
}

.fr-blocked {
	margin: 8px 0;
	padding-inline-start: 20px;
	list-style: disc;

	&__item {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-bottom: 6px;
	}
}

.fr-layout {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(320px, 420px);
	align-items: start;
	gap: 24px;

	&__detail {
		position: sticky;
		top: 12px;
	}
}

.fr-tree {
	min-width: 0;

	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-bottom: 4px;

		h3 {
			margin: 0;
			font-weight: bold;
		}
	}

	&__buttons {
		display: flex;
		gap: 4px;
		margin-inline-start: auto;
	}

	&__cols {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 9em 13em;
		gap: 8px;
		padding: 4px 8px 4px 44px;
		border-bottom: 1px solid var(--color-border);
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__list {
		margin: 4px 0 0;
		padding: 0;
	}
}

.fr-unsaved {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-warning, #eca700);
	color: var(--color-warning-text-dark, #000);
	font-size: 0.85em;
	font-weight: bold;
}

@media (max-width: 1100px) {
	.fr-layout {
		grid-template-columns: minmax(0, 1fr);

		&__detail {
			position: static;
		}
	}
}

@media (max-width: 700px) {
	.fr-tree__cols {
		grid-template-columns: minmax(0, 1fr) auto;

		span:last-child {
			display: none;
		}
	}
}
</style>
