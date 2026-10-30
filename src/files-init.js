import { FileType, registerSidebarTab } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import './files-init.css'

const TAB_TAG = 'abonnieren-files-subscription-tab'

function appUrl(path) {
	return typeof OC?.generateUrl === 'function' ? OC.generateUrl(path) : path
}

class SubscriptionTab extends HTMLElement {

	constructor() {
		super()
		this._node = null
		this._active = false
		this._loadedNodeId = null
		this._loadingSequence = 0
	}

	set node(value) {
		this._node = value
		this._loadedNodeId = null
		if (this.isConnected && this._active) this.load()
	}

	get node() { return this._node }

	set folder(value) { this._folder = value }
	get folder() { return this._folder }
	set view(value) { this._view = value }
	get view() { return this._view }
	set active(value) {
		this._active = Boolean(value)
		if (this.isConnected && this._active) this.load()
	}

	get active() { return this._active }

	connectedCallback() {
		this.renderState(t('abonnieren', 'Loading subscription…'))
		if (this._active) this.load()
	}

	getNodeId() {
		return String(this._node?.id || this._node?.fileid || this._node?.attributes?.fileid || '')
	}

	isFolder() {
		return this._node?.type === FileType.Folder || this._node?.type === 'folder'
	}

	async request(action, data = {}) {
		const body = new FormData()
		body.append('action', action)
		Object.entries(data).forEach(([key, value]) => body.append(key, String(value)))
		const response = await fetch(appUrl('/apps/abonnieren/subscriptions'), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken },
			body,
		})
		const payload = await response.json()
		if (!response.ok || !payload.success) throw new Error(payload.message || `HTTP ${response.status}`)
		return payload
	}

	async load() {
		const nodeId = this.getNodeId()
		if (!nodeId || nodeId === this._loadedNodeId) return
		const sequence = ++this._loadingSequence
		this.renderState(t('abonnieren', 'Loading subscription…'))
		try {
			const payload = await this.request('get_rule', { nodeId })
			if (sequence !== this._loadingSequence) return
			this._loadedNodeId = nodeId
			this.renderForm(payload.rule)
		} catch (error) {
			console.error('Abonnieren: failed to load subscription', error)
			this.renderState(t('abonnieren', 'The subscription could not be loaded.'), true)
		}
	}

	renderState(message, error = false) {
		const container = document.createElement('div')
		container.className = `abonnieren-subscription-tab abonnieren-subscription-state${error ? ' abonnieren-subscription-error' : ''}`
		container.textContent = message
		this.replaceChildren(container)
	}

	renderForm(rule) {
		const container = document.createElement('div')
		container.className = 'abonnieren-subscription-tab'
		const heading = document.createElement('h3')
		heading.textContent = t('abonnieren', 'Notifications for this object')
		const description = document.createElement('p')
		description.className = 'abonnieren-subscription-description'
		description.textContent = t('abonnieren', 'Select the events and notification methods for this subscription.')
		container.append(heading, description)

		const eventMask = Number(rule?.eventMask || 0)
		const checkboxes = new Map()
		;[[8, 'Download'], [1, 'Upload'], [2, 'Modification'], [4, 'Deletion']].forEach(([bit, text]) => {
			if (bit === 1 && !this.isFolder()) return
			const label = document.createElement('label')
			label.className = 'abonnieren-subscription-option'
			const checkbox = document.createElement('input')
			checkbox.type = 'checkbox'
			checkbox.checked = (eventMask & bit) !== 0
			label.append(checkbox, document.createTextNode(t('abonnieren', text)))
			container.appendChild(label)
			checkboxes.set(bit, checkbox)
		})

		let recursive = null
		if (this.isFolder()) {
			const separator = document.createElement('hr')
			separator.className = 'abonnieren-subscription-separator'
			const label = document.createElement('label')
			label.className = 'abonnieren-subscription-option'
			recursive = document.createElement('input')
			recursive.type = 'checkbox'
			recursive.checked = Boolean(rule?.recursive)
			label.append(recursive, document.createTextNode(t('abonnieren', 'Include subfolders')))
			container.append(separator, label)
		}

		const channelTitle = document.createElement('h4')
		channelTitle.className = 'abonnieren-preferences-title'
		channelTitle.textContent = t('abonnieren', 'Delivery methods')
		const channelMask = Number(rule?.channelMask ?? 1)
		const emailLabel = document.createElement('label')
		emailLabel.className = 'abonnieren-subscription-option'
		const emailChannel = document.createElement('input')
		emailChannel.type = 'checkbox'
		emailChannel.checked = (channelMask & 1) !== 0
		emailLabel.append(emailChannel, document.createTextNode(t('abonnieren', 'Email')))
		const pushLabel = document.createElement('label')
		pushLabel.className = 'abonnieren-subscription-option'
		const nativeChannel = document.createElement('input')
		nativeChannel.type = 'checkbox'
		nativeChannel.checked = (channelMask & 2) !== 0
		pushLabel.append(nativeChannel, document.createTextNode(t('abonnieren', 'Nextcloud notifications (including supported push clients)')))
		container.append(channelTitle, emailLabel, pushLabel)

		const settingsTitle = document.createElement('h4')
		settingsTitle.className = 'abonnieren-preferences-title'
		settingsTitle.textContent = t('abonnieren', 'Notification filters')

		const delayLabel = document.createElement('label')
		delayLabel.className = 'abonnieren-subscription-field'
		delayLabel.textContent = t('abonnieren', 'Suppress repeated notifications for')
		const delay = document.createElement('select')
		for (const [seconds, caption] of [
			[0, 'Off'], [15, '15 seconds'], [30, '30 seconds'], [60, '1 minute'],
			[120, '2 minutes'], [300, '5 minutes'], [600, '10 minutes'],
			[1800, '30 minutes'], [3600, '1 hour'],
		]) {
			const option = document.createElement('option')
			option.value = String(seconds)
			option.textContent = t('abonnieren', caption)
			delay.appendChild(option)
		}
		delay.value = String(rule?.debounceSeconds ?? 600)
		delayLabel.append(delay)

		const extensionsLabel = document.createElement('label')
		extensionsLabel.className = 'abonnieren-subscription-field'
		extensionsLabel.textContent = t('abonnieren', 'Exclude file extensions (comma-separated)')
		const extensions = document.createElement('input')
		extensions.type = 'text'
		extensions.placeholder = 'tmp, log, .bak, tar.gz'
		extensions.value = (rule?.excludedExtensions || []).join(', ')
		extensions.maxLength = 500
		extensionsLabel.append(extensions)
		const help = document.createElement('p')
		help.className = 'abonnieren-subscription-description'
		help.textContent = t('abonnieren', 'Excluded file types do not generate notifications for this subscription. Folder events are unaffected.')
		container.append(settingsTitle, delayLabel, extensionsLabel, help)

		const feedback = document.createElement('p')
		feedback.className = 'abonnieren-subscription-feedback'
		const actions = document.createElement('div')
		actions.className = 'abonnieren-subscription-actions'
		const save = document.createElement('button')
		save.type = 'button'
		save.className = 'primary'
		save.textContent = t('abonnieren', 'Save')
		actions.appendChild(save)
		if (rule) {
			const remove = document.createElement('button')
			remove.type = 'button'
			remove.textContent = t('abonnieren', 'Remove subscription')
			remove.addEventListener('click', () => this.saveRule(0, false, save, remove, feedback))
			actions.appendChild(remove)
		}
		container.append(feedback, actions)
		save.addEventListener('click', () => {
			let mask = 0
			checkboxes.forEach((checkbox, bit) => { if (checkbox.checked) mask |= bit })
			this.saveRule(mask, Boolean(recursive?.checked), save, null, feedback, {
				debounceSeconds: Number(delay.value),
				excludedExtensions: extensions.value,
				channelMask: Number(emailChannel.checked) + 2 * Number(nativeChannel.checked),
			})
		})
		this.replaceChildren(container)
	}

	async saveRule(eventMask, recursive, save, second, feedback, preferences = {}) {
		save.disabled = true
		if (second) second.disabled = true
		feedback.textContent = ''
		try {
			await this.request(eventMask === 0 ? 'delete_rule' : 'save_rule', {
				nodeId: this.getNodeId(), eventMask, recursive, ...preferences,
			})
			this._loadedNodeId = null
			feedback.className = 'abonnieren-subscription-feedback success'
			feedback.textContent = t('abonnieren', eventMask === 0 ? 'Subscription removed.' : 'Subscription saved.')
			await this.load()
		} catch (error) {
			feedback.className = 'abonnieren-subscription-feedback error'
			feedback.textContent = t('abonnieren', 'The subscription could not be saved.')
		} finally {
			save.disabled = false
			if (second) second.disabled = false
		}
	}

}

const sidebarTab = {
	id: 'abonnieren-subscriptions',
	displayName: t('abonnieren', 'Subscribe'),
	iconSvgInline: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 22a2.4 2.4 0 0 0 2.3-1.7H9.7A2.4 2.4 0 0 0 12 22Zm7-6.2-1.6-2V9a5.4 5.4 0 0 0-4.2-5.3V3a1.2 1.2 0 1 0-2.4 0v.7A5.4 5.4 0 0 0 6.6 9v4.8l-1.6 2A1.3 1.3 0 0 0 6 18h12a1.3 1.3 0 0 0 1-2.2Z"/></svg>',
	order: 50,
	tagName: TAB_TAG,
	enabled: ({ node }) => Boolean(node && (node.type === FileType.File || node.type === FileType.Folder)),
	async onInit() {
		if (!customElements.get(TAB_TAG)) {
			customElements.define(TAB_TAG, SubscriptionTab)
		}
	},
}

registerSidebarTab(sidebarTab)

/*
 * @nextcloud/files keeps extension registries in a versioned global scope.
 * An app-bundled library can therefore register in v4_0 while the server's
 * Files app reads a newer v4_x scope. Mirror the validated definition into
 * every v4 scope that is present during Files startup.
 */
function mirrorSidebarTabToServerScopes() {
	const root = window._nc_files_scope
	if (!root || typeof root !== 'object') return

	for (const [key, scope] of Object.entries(root)) {
		if (!/^v4(?:_|$)/.test(key) || !scope || typeof scope !== 'object') continue
		if (!(scope.filesSidebarTabs instanceof Map)) scope.filesSidebarTabs = new Map()
		if (!scope.filesSidebarTabs.has(sidebarTab.id)) {
			scope.filesSidebarTabs.set(sidebarTab.id, sidebarTab)
		}
	}
}

function scheduleSidebarTabMirroring() {
	mirrorSidebarTabToServerScopes()
	requestAnimationFrame(mirrorSidebarTabToServerScopes)
	for (const delay of [0, 100, 500, 1500, 5000]) {
		setTimeout(mirrorSidebarTabToServerScopes, delay)
	}
}

scheduleSidebarTabMirroring()
