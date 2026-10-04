<template>
	<NcSettingsSection :name="t('folder_retention', 'Settings')">
		<NcCheckboxRadioSwitch type="switch"
			:model-value="state.settings.simulation"
			:loading="busy === 'simulation'"
			:disabled="!!busy"
			@update:model-value="set('simulation', $event)">
			{{ t('folder_retention', 'Simulation mode – delete nothing, only log') }}
		</NcCheckboxRadioSwitch>
		<p class="fr-hint">
			{{ t('folder_retention', 'Before switching it off, check the simulation log and disable the old flows or files_retention rules (see README, section “Migration”). Changes require password confirmation.') }}
		</p>

		<NcCheckboxRadioSwitch type="switch"
			:model-value="state.settings.tags"
			:loading="busy === 'tags'"
			:disabled="!!busy"
			@update:model-value="set('tags', $event)">
			{{ t('folder_retention', 'Show the retention period as a tag on files and folders') }}
		</NcCheckboxRadioSwitch>
		<p class="fr-hint">
			{{ t('folder_retention', 'Every file and folder in the managed areas gets a tag such as “Retention: 2 weeks” or “Retention: unlimited” – for folders, the period for the files directly inside. The tags are for information only: deletion follows the folder rules exclusively, and only admins can assign the tags. New and moved files are tagged immediately; after rule changes, the tags follow within a few minutes.') }}
			<template v-if="state.settings.tags && state.settings.tagSyncPending">
				{{ t('folder_retention', 'The tags are currently being distributed.') }}
			</template>
		</p>

		<p class="fr-hint">
			{{ t('folder_retention', 'Last complete run: {date}', { date: state.settings.lastCycleCompleted ? formatDate(state.settings.lastCycleCompleted) : t('folder_retention', 'none yet') }) }}
			<template v-if="state.settings.cycleInProgress">
				· {{ t('folder_retention', 'a run is currently in progress') }}
			</template>
			· {{ t('folder_retention', 'Time zone {timezone}', { timezone: state.settings.timezone }) }}
		</p>
	</NcSettingsSection>
</template>

<script setup>
import { ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { showError } from '@nextcloud/dialogs'
import { state, updateSettings } from '../store.js'
import { formatDate } from '../format.js'
import { t } from '../l10n.js'

const busy = ref(null)

async function set(field, value) {
	busy.value = field
	try {
		await updateSettings({ [field]: value })
	} catch (e) {
		// Cancelling the password confirmation is not an error
		if (e?.response) {
			showError(e.response.data?.message ?? t('folder_retention', 'Setting could not be saved'))
		}
	} finally {
		busy.value = null
	}
}
</script>

<style scoped>
.fr-hint {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}
</style>
