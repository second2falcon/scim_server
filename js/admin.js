/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
(function () {
	'use strict'
	const root = document.getElementById('scim-server')
	if (!root) {
		return
	}
	const token = document.head.dataset.requesttoken
	const call = (url, method, body) => fetch(url, {
		method,
		headers: { requesttoken: token, 'Content-Type': 'application/json', Accept: 'application/json' },
		body: body ? JSON.stringify(body) : undefined,
	}).then((r) => r.json().then((j) => ({ ok: r.ok, json: j })))
	const $ = (id) => document.getElementById(id)
	// The endpoints need a recent password confirmation; this shows Nextcloud's dialog when it is due.
	const confirmed = (fn) => () => (window.OC && OC.PasswordConfirmation ? OC.PasswordConfirmation.requirePasswordConfirmation(fn) : fn())

	$('scim-save').addEventListener('click', confirmed(async () => {
		const r = await call(root.dataset.settingsUrl, 'PUT', {
			deleteMode: $('scim-mode').value,
			purgeDays: parseInt($('scim-days').value, 10) || 0,
			protectAdmins: $('scim-protect').checked,
			managedOnly: $('scim-managed').checked,
			groupRegex: $('scim-group-regex').value,
			groupPrefix: $('scim-group-prefix').value,
		})
		$('scim-status').textContent = r.ok ? '✓' : (r.json.error || '✗')
	}))

	$('scim-create').addEventListener('click', confirmed(async () => {
		const r = await call(root.dataset.tokensUrl, 'POST', { label: $('scim-label').value })
		if (!r.ok) {
			return
		}
		const box = $('scim-new-token')
		box.querySelector('code').textContent = r.json.token
		box.hidden = false
		const li = document.createElement('li')
		li.textContent = $('scim-label').value || 'token'
		$('scim-tokens').appendChild(li)
	}))

	document.querySelectorAll('.scim-revoke').forEach((btn) => btn.addEventListener('click', confirmed(async () => {
		if ((await call(root.dataset.tokensUrl + '/' + btn.dataset.id, 'DELETE')).ok) {
			btn.closest('li').remove()
		}
	})))
})()
