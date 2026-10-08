/**
 * Sidebar tab as a web component without shadow DOM – Nextcloud's CSS variables apply directly.
 * The Files app sets the properties node and active (ISidebarTab in @nextcloud/files).
 */
import { details, infoOf } from '../filesInfo.js'
import { t } from '../l10n.js'

const STYLE = `
.fr-tab { padding: 12px 4px; }
.fr-tab dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0 0 12px; }
.fr-tab dt { color: var(--color-text-maxcontrast); text-align: start; }
.fr-tab dd { margin: 0; overflow-wrap: anywhere; }
.fr-tab p { margin: 0 0 8px; color: var(--color-text-maxcontrast); }
.fr-tab p.fr-tab__pause { color: var(--color-warning-text, var(--color-main-text)); }
`

class SidebarTab extends HTMLElement {
	#node = null

	set node(node) {
		this.#node = node
		this.render()
	}

	get node() {
		return this.#node
	}

	set active(on) {
		if (on) {
			this.render()
		}
	}

	connectedCallback() {
		this.render()
	}

	render() {
		const info = infoOf(this.#node)
		const root = document.createElement('div')
		root.className = 'fr-tab'
		if (!info) {
			const p = document.createElement('p')
			p.textContent = t('folder_retention', 'No deletion rule applies here.')
			root.append(p)
		} else {
			const { rows, notes } = details(info)
			if (rows.length) {
				const dl = document.createElement('dl')
				for (const row of rows) {
					const dt = document.createElement('dt')
					dt.textContent = row.label
					const dd = document.createElement('dd')
					dd.textContent = row.value
					dl.append(dt, dd)
				}
				root.append(dl)
			}
			for (const [i, note] of notes.entries()) {
				const p = document.createElement('p')
				p.textContent = note
				// pause notes come first, the general hint last
				if (i < notes.length - 1 && (info.simulation || info.halted || info.blocked || info.noCron)) {
					p.className = 'fr-tab__pause'
				}
				root.append(p)
			}
		}
		const style = document.createElement('style')
		style.textContent = STYLE
		this.replaceChildren(style, root)
	}
}

export function defineTab(tagName) {
	if (!customElements.get(tagName)) {
		customElements.define(tagName, SidebarTab)
	}
}
