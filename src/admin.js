/* global OC, $ */
import Vue from 'vue'
import AdminSettings from './components/AdminSettings'

// CSP config for webpack dynamic chunk loading
// eslint-disable-next-line
__webpack_nonce__ = btoa(OC.requestToken)

// Correct the root of the app for chunk loading
// OC.linkTo matches the apps folders
// eslint-disable-next-line
__webpack_public_path__ = OC.linkTo('richdocuments', 'js/')

Vue.prototype.t = t
Vue.prototype.n = n
Vue.prototype.OC = OC
Vue.prototype.OCA = OCA

const element = document.getElementById('admin-vue')

/* eslint-disable-next-line no-new */
new Vue({
	render: h => h(AdminSettings, { props: { initial: JSON.parse(element.dataset.initial) } })
}).$mount('#admin-vue')

const documentsSettings = {
	_createExtApp: function() {
		var app1 = document.createElement('div')
		app1.setAttribute('class', 'external-app')

		var appname1 = document.createElement('input')
		appname1.setAttribute('class', 'external-apps-name')
		$(app1).append(appname1)

		var apptoken1 = document.createElement('input')
		apptoken1.setAttribute('class', 'external-apps-token')
		$(app1).append(apptoken1)

		var apptokenbutton = document.createElement('button')
		apptokenbutton.setAttribute('class', 'external-apps-gen-token-button')
		apptokenbutton.innerHTML = 'Generate Token'
		$(app1).append(apptokenbutton)

		var appremovebutton = document.createElement('button')
		appremovebutton.setAttribute('class', 'external-apps-remove-button')
		appremovebutton.innerHTML = 'Remove'
		$(app1).append(appremovebutton)

		return app1
	},

	save: function() {
		$('#wopi_apply, #disable_certificate_verification').attr('disabled', true)
		var data = {
			wopi_url: $('#wopi_url').val().replace(/\/$/, ''),
			disable_certificate_verification: document.getElementById('disable_certificate_verification').checked
		}

		OC.msg.startAction('#documents-admin-msg', t('richdocuments', 'Saving…'))
		$.post(
			OC.filePath('richdocuments', 'ajax', 'admin.php'),
			data,
			documentsSettings.afterSave
		)
	},

	saveGroups: function(data) {
		$.post(
			OC.filePath('richdocuments', 'ajax', 'admin.php'),
			data
		)
	},

	saveDocFormat: function(format) {
		$.post(
			OC.filePath('richdocuments', 'ajax', 'admin.php'),
			{ 'doc_format': format }
		)
	},

	afterSave: function(response) {
		$('#wopi_apply, #disable_certificate_verification').attr('disabled', false)
		OC.msg.finishedAction('#documents-admin-msg', response)
	},

	saveExternalApps: function(externalAppsData) {
		var data = {
			'external_apps': externalAppsData
		}

		OC.msg.startAction('#enable-external-apps-section-msg', t('richdocuments', 'Saving…'))
		$.post(
			OC.filePath('richdocuments', 'ajax', 'admin.php'),
			data,
			documentsSettings.afterSaveExternalApps
		)
	},

	saveWebroot: function(value) {
		var data = {
			'canonical_webroot': value
		}
		$.post(
			OC.filePath('richdocuments', 'ajax', 'admin.php'),
			data
		)
	},

	afterSaveExternalApps: function(response) {
		OC.msg.finishedAction('#enable-external-apps-section-msg', response)
	},

	initGroups: function() {
		var selectorPrefixes = [
			'edit',
			'use'
		]

		for (var i = 0; i < selectorPrefixes.length; i++) {
			var selectorPrefix = selectorPrefixes[i]

			var groups = $('#' + selectorPrefix + '_group_select').val()
			if (groups !== '') {
				OC.Settings.setupGroupsSelect($('#' + selectorPrefix + '_group_select'))
				$('.' + selectorPrefix + '-groups-enable').attr('checked', 'checked')
			} else {
				$('.' + selectorPrefix + '-groups-enable').attr('checked', null)
			}
		}
	},

	initExternalApps: function() {
		var externalAppsRaw = $(document).find('#external-apps-raw').val()
		var apps = externalAppsRaw.split(',')
		for (var i = 0; i < apps.length; ++i) {
			if (apps[i] !== '') {
				var app = apps[i].split(':')
				var app1 = this._createExtApp()
				// create a placeholder for adding new app
				$('#external-apps-section').append(app1)
				$(app1).find('.external-apps-name').val(app[0])
				$(app1).find('.external-apps-token').val(app[1])
			}
		}
	},

	initialize: function() {
		documentsSettings.initGroups()
		documentsSettings.initExternalApps()

		var page = $('#richdocuments')

		$('#wopi_apply').on('click', documentsSettings.save)

		// destroy or create app name and token fields depending on whether the checkbox is on or off
		$(document).on('change', '#enable_external_apps_cb-richdocuments', function() {
			page.find('#enable-external-apps-section').toggleClass('hidden', !this.checked)
			if (this.checked) {
				var app1 = documentsSettings._createExtApp()
				$('#external-apps-section').append(app1)
			} else {
				page.find('.external-app').remove()
				page.find('#external-apps-raw').val('')
				documentsSettings.saveExternalApps('')
			}
		})

		$(document).on('click', '.external-apps-gen-token-button', function() {
			var appToken = page.find('.external-apps-token')

			// generate a random string
			var len = 3
			var array = new Uint32Array(len)
			window.crypto.getRandomValues(array)
			var random = ''
			for (var i = 0; i < len; ++i) {
				random += array[i].toString(36)
			}

			// set the token in the field
			appToken.val(random)
		})

		$(document).on('click', '.external-apps-remove-button', function() {
			$(this).parent().remove()
		})

		$(document).on('click', '#external-apps-save-button', function() {
			// read all the data in input fields, save the data in input-raw and send to backedn
			var extAppsSection = $(this).parent()
			var apps = extAppsSection.find('.external-app')
			// convert all values into one single string and store it in raw input field
			// as well as send the data to server
			var raw = ''
			for (var i = 0; i < apps.length; ++i) {
				var appname = $(apps[i]).find('.external-apps-name')
				var apptoken = $(apps[i]).find('.external-apps-token')
				raw += appname.val() + ':' + apptoken.val() + ','
			}

			extAppsSection.find('#external-apps-raw').val(raw)
			documentsSettings.saveExternalApps(raw)
		})

		$(document).on('click', '#external-apps-add-button', function() {
			// create a placeholder for adding new app
			var app1 = documentsSettings._createExtApp()
			$('#external-apps-section').append(app1)
		})

		$(document).on('click', '#test_wopi_apply', function() {
			var groups = page.find('#test_server_group_select').val()
			var testserver = page.find('#test_wopi_url').val()

			if (groups !== '' && testserver !== '') {
				documentsSettings.saveTestWopi(groups, testserver)
			} else {
				OC.msg.finishedError('#test-documents-admin-msg', 'Both fields required')
			}
		})

		$(document).on('change', '.doc-format-ooxml', function() {
			var ooxml = this.checked
			documentsSettings.saveDocFormat(ooxml ? 'ooxml' : 'odf')
		})

		$(document).on('change', '#edit_group_select', function() {
			var groups = $(this).val()
			documentsSettings.saveGroups({ edit_groups: groups })
		})

		$(document).on('change', '.edit-groups-enable', function() {
			var $select = page.find('#edit_group_select')
			$select.val('')

			if (this.checked) {
				OC.Settings.setupGroupsSelect($select, {
					placeholder: t('core', 'All')
				})
			} else {
				$select.select2('destroy')
			}

			$select.change()
		})

		$(document).on('change', '#use_group_select', function() {
			var groups = $(this).val()
			documentsSettings.saveGroups({ use_groups: groups })
		})

		$(document).on('change', '.use-groups-enable', function() {
			var $select = page.find('#use_group_select')
			$select.val('')

			if (this.checked) {
				OC.Settings.setupGroupsSelect($select, {
					placeholder: t('core', 'All')
				})
			} else {
				$select.select2('destroy')
			}

			$select.change()
		})

		$(document).on('change', '#enable_canonical_webroot_cb-richdocuments', function() {
			page.find('#enable-canonical-webroot-section').toggleClass('hidden', !this.checked)
			if (!this.checked) {
				documentsSettings.saveWebroot('')
			} else {
				var val = $('#canonical-webroot').val()
				if (val) { documentsSettings.saveWebroot() }
			}
		})

		$(document).on('change', '#canonical-webroot', function() {
			documentsSettings.saveWebroot(this.value)
		})
	}
}

/**
 * Append a new template to the dom
 *
 * @param {Object} data the template data from the template controller response
 */
function appendTemplateFromData(data) {
	var template = document.querySelector('.template-model').cloneNode(true)
	template.className = ''
	template.querySelector('img').src = data.preview
	template.querySelector('figcaption').textContent = data.name
	template.querySelector('.delete-template').href = data.delete

	document.querySelector('#richdocuments-templates > ul').appendChild(template)
	template.querySelector('.delete-template').addEventListener('click', deleteTemplate)
}

/**
 * Delete template event handler
 *
 * @param {Event} event
 */
function deleteTemplate(event) {
	event.preventDefault()
	var emptyElmt = document.querySelector('#richdocuments-templates #emptycontent')
	var tplListElmt = document.querySelector('#richdocuments-templates > ul')
	var elmt = event.target

	// ensure no request is in progress
	if (elmt.className.indexOf('loading') === -1 && elmt.textContent === '') {
		var remote = event.target.href
		elmt.classList.add('icon-loading')
		elmt.classList.remove('icon-delete')

		// send request
		$.ajax({
			url: remote,
			type: 'DELETE'
		})
			.done(function() {
			// remove template
				elmt.parentElement.remove()
				// is list empty? Only the default template is left
				if (tplListElmt.querySelectorAll('li').length === 1) {
					tplListElmt.classList.add('hidden')
					emptyElmt.classList.remove('hidden')
				}
			})
			.fail(function(e) {
			// failure, show warning
				elmt.textContent = t('richdocuments', 'Error')
				elmt.classList.remove('icon-loading')
				setTimeout(function() {
					elmt.classList.add('icon-delete')
					elmt.textContent = ''
				}, 2000)
			})
	}
}

/**
 * Init the upload manager and the delete template handler
 */
function initTemplateManager() {
	var inputElmt = document.querySelector('#add-template')
	var buttonElmt = document.querySelector('.icon-add')
	var deleteElmts = document.querySelectorAll('.delete-template')
	var emptyElmt = document.querySelector('#richdocuments-templates #emptycontent')
	var tplListElmt = document.querySelector('#richdocuments-templates > ul')

	deleteElmts.forEach(function(elmt) {
		elmt.addEventListener('click', deleteTemplate)
	})

	// fileupload plugin
	$('#richdocuments-templates').fileupload({
		dataType: 'json',
		url: OC.generateUrl('apps/richdocuments/template'),
		type: 'POST',

		add: function(e, data) {
			// submit on file selection
			data.submit()
			inputElmt.disabled = true
			buttonElmt.className = 'icon-loading-small'
		},

		submit: function(e, data) {
			data.formData = _.extend(data.formData || {}, {
				requesttoken: OC.requestToken
			})
		},

		success: function(e) {
			inputElmt.disabled = false
			buttonElmt.className = 'icon-add'
			// add template to dom
			appendTemplateFromData(e.data)
			tplListElmt.classList.remove('hidden')
			emptyElmt.classList.add('hidden')
		},

		fail: function(e, data) {
			// failure, show warning
			buttonElmt.className = 'icon-add'
			buttonElmt.textContent = t('richdocuments', 'An error occurred') + ': ' + data.jqXHR.responseJSON.data.message
			setTimeout(function() {
				inputElmt.disabled = false
				buttonElmt.textContent = ''
			}, 2000)
		}
	})
}

$(document).ready(function() {
	documentsSettings.initialize()
	initTemplateManager()
})
