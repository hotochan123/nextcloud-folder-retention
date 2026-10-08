/**
 * Files app: badge with the deletion date next to the file name and a sidebar tab with
 * date, rule and reference. Loaded as init script (lib/Listener/FilesScriptListener.php).
 */
import { mdiDeleteClockOutline } from '@mdi/js'
import { getSidebar, registerFileAction, registerSidebarTab } from '@nextcloud/files'
import { registerDavProperty } from '@nextcloud/files/dav'
import { badge, infoOf } from './filesInfo.js'
import { t } from './l10n.js'

const TAB_ID = 'folder_retention'
const TAG_NAME = 'folder_retention-files-sidebar-tab'
const ICON = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="${mdiDeleteClockOutline}"/></svg>`

registerDavProperty('nc:folder-retention', { nc: 'http://nextcloud.org/ns' })

const now = () => Math.floor(Date.now() / 1000)

registerFileAction({
	id: 'folder_retention-deletion-date',
	displayName: ({ nodes }) => badge(infoOf(nodes[0]), now())?.text ?? '',
	title: ({ nodes }) => badge(infoOf(nodes[0]), now())?.title ?? '',
	iconSvgInline: () => ICON,
	enabled: ({ nodes }) => nodes.length === 1 && badge(infoOf(nodes[0]), now()) !== null,
	inline: () => true,
	order: 90,
	async exec({ nodes }) {
		getSidebar().open(nodes[0], TAB_ID)
		return null
	},
})

registerSidebarTab({
	id: TAB_ID,
	// getter: the app's translations may load after this init script
	get displayName() {
		return t('folder_retention', 'Deletion')
	},
	iconSvgInline: ICON,
	order: 90,
	tagName: TAG_NAME,
	enabled: ({ node }) => infoOf(node) !== null,
	async onInit() {
		const { defineTab } = await import('./files/SidebarTab.js')
		defineTab(TAG_NAME)
	},
})
