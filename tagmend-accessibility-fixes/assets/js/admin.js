/**
 * Tagmend - Reports screen: scans in the administrator's own browser.
 *
 * Flow (blueprint section 10.3): the candidate addresses go to the REST route
 * softglaze-a11y/v1/scan-targets, which keeps only pages of this site and
 * returns each with a scan link carrying a fresh nonce. Each page is then
 * requested once, one at a time, with the administrator's own cookies; the
 * site stores the report at the end of that request. The summary is read
 * from softglaze-a11y/v1/report and announced in the polite live region.
 *
 * Data comes from data-* attributes on the scanner section only.
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n;
	var apiFetch = window.wp && window.wp.apiFetch;
	var panel = document.querySelector( '[data-softglaze-a11y-scanner]' );

	focusStatusMessage();

	if ( ! panel || ! i18n || ! apiFetch ) {
		return;
	}

	var __ = i18n.__;
	var _n = i18n._n;
	var sprintf = i18n.sprintf;
	var statusRegion = panel.querySelector( '.softglaze-a11y-scan-status' );
	var resultsList = panel.querySelector( '.softglaze-a11y-scan-results' );
	var controls = panel.querySelector( '.softglaze-a11y-scan-controls' );
	var noScript = panel.querySelector( '.softglaze-a11y-nojs' );
	var busy = false;

	if ( controls ) {
		controls.hidden = false;
	}
	if ( noScript ) {
		noScript.hidden = true;
	}

	panel.querySelectorAll( '[data-softglaze-a11y-scan]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var kind = button.getAttribute( 'data-softglaze-a11y-scan' );
			if ( 'suggested' === kind ) {
				run( readList( panel.getAttribute( 'data-suggested' ) ) );
			} else if ( 'home' === kind ) {
				run( [ panel.getAttribute( 'data-home-url' ) || '' ] );
			}
		} );
	} );

	var form = panel.querySelector( '[data-softglaze-a11y-scan-form]' );
	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var field = form.querySelector( 'input[type="url"]' );
			if ( field && field.value.trim() ) {
				run( [ field.value.trim() ] );
			}
		} );
	}

	// "Rescan" links work without JavaScript (they open the page in scan
	// mode); here they scan in place and report in the live region.
	document.querySelectorAll( 'a.softglaze-a11y-rescan[data-url]' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			run( [ link.getAttribute( 'data-url' ) || '' ] );
		} );
	} );

	/**
	 * Parses the JSON list of suggested addresses.
	 *
	 * @param {string|null} value Attribute value.
	 * @return {string[]} Addresses.
	 */
	function readList( value ) {
		try {
			var list = JSON.parse( value || '[]' );
			return Array.isArray( list ) ? list.filter( function ( item ) {
				return 'string' === typeof item && '' !== item;
			} ) : [];
		} catch ( error ) {
			return [];
		}
	}

	/**
	 * Replaces the text of the live region.
	 *
	 * @param {string} text Message.
	 */
	function announce( text ) {
		statusRegion.textContent = text;
	}

	/**
	 * Adds one line to the visible list of results.
	 *
	 * @param {string} url  Page address.
	 * @param {string} text What happened.
	 */
	function addResult( url, text ) {
		var item = document.createElement( 'li' );
		var address = document.createElement( 'span' );
		address.className = 'softglaze-a11y-scan-url-text';
		address.textContent = url;
		item.appendChild( address );
		item.appendChild( document.createTextNode( ' ' + text ) );
		resultsList.appendChild( item );
		resultsList.hidden = false;
	}

	/**
	 * Turns a rejected apiFetch() into a message: a REST error is a plain
	 * object with a message, a network failure an Error.
	 *
	 * @param {*} error Rejection value.
	 * @return {string} Message.
	 */
	function errorMessage( error ) {
		if ( error && 'string' === typeof error.message && '' !== error.message ) {
			return error.message;
		}
		return __( 'the site did not answer.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * Enables or disables every scan control.
	 *
	 * @param {boolean} disabled Whether to disable.
	 */
	function setBusy( disabled ) {
		busy = disabled;
		document.querySelectorAll( '[data-softglaze-a11y-scan], [data-softglaze-a11y-scan-form] button, a.softglaze-a11y-rescan' ).forEach( function ( control ) {
			if ( 'A' === control.tagName ) {
				control.setAttribute( 'aria-disabled', disabled ? 'true' : 'false' );
			} else {
				control.disabled = disabled;
			}
		} );
	}

	/**
	 * Words for a stored report summary.
	 *
	 * @param {Object} summary Response of the report route.
	 * @return {string} Text.
	 */
	function describe( summary ) {
		var changes = summary.changes || {};
		var text = sprintf(
			/* translators: 1: Live changes, 2: Preview changes, 3: changes of fixes that are Off, 4: items that need a person */
			__( '%1$d Live, %2$d Preview, %3$d Off (would change), %4$d need a person.', 'tagmend-accessibility-fixes' ),
			changes.live || 0,
			changes.preview || 0,
			changes.off || 0,
			summary.findings || 0
		);
		if ( 'content' === summary.scope ) {
			text += ' ' + ( 'needs_wp69' === summary.scope_reason ?
				__( 'Content only: page-level checks need WordPress 6.9 or later.', 'tagmend-accessibility-fixes' ) :
				__( 'Content only: post content, template parts, widgets and menus.', 'tagmend-accessibility-fixes' ) );
		}
		return text;
	}

	/**
	 * Scans one target: requests the page, waits for the whole response (the
	 * report is stored when the request ends), then reads the summary.
	 *
	 * @param {Object} target Target from scan-targets: url, scan_url, excluded.
	 * @return {Promise<Object>} ok and text.
	 */
	async function scanOne( target ) {
		if ( target.excluded ) {
			return { ok: false, text: __( 'Not scanned (excluded path).', 'tagmend-accessibility-fixes' ) };
		}
		var response;
		try {
			response = await window.fetch( target.scan_url, {
				credentials: 'same-origin',
				cache: 'no-store',
				redirect: 'manual',
			} );
		} catch ( error ) {
			return { ok: false, text: __( 'Not scanned: the page could not be loaded.', 'tagmend-accessibility-fixes' ) };
		}
		if ( 'opaqueredirect' === response.type || ( response.status >= 300 && response.status < 400 ) ) {
			return { ok: false, text: __( 'This page redirects; open it and use "Scan this page" in the admin bar.', 'tagmend-accessibility-fixes' ) };
		}
		try {
			await response.text();
		} catch ( error ) {
			// The body is read only to wait for the end of the request.
		}
		if ( ! response.ok ) {
			return {
				ok: false,
				/* translators: %d: HTTP status code, for example 404 */
				text: sprintf( __( 'Not scanned: the page answered with HTTP status %d.', 'tagmend-accessibility-fixes' ), response.status ),
			};
		}
		try {
			var summary = await apiFetch( {
				path: '/softglaze-a11y/v1/report?url=' + encodeURIComponent( target.url ),
			} );
			return { ok: true, text: describe( summary ) };
		} catch ( error ) {
			if ( error && 'softglaze_a11y_no_report' === error.code ) {
				return { ok: false, text: __( 'Not scanned: no report was stored for this page.', 'tagmend-accessibility-fixes' ) };
			}
			return { ok: false, text: errorMessage( error ) };
		}
	}

	/**
	 * Scans a list of addresses, one page at a time.
	 *
	 * @param {string[]} urls Addresses.
	 */
	async function run( urls ) {
		if ( busy ) {
			return;
		}
		urls = urls.filter( function ( url ) {
			return '' !== url;
		} );
		if ( 0 === urls.length ) {
			announce( __( 'There is no page to scan.', 'tagmend-accessibility-fixes' ) );
			return;
		}
		setBusy( true );
		resultsList.textContent = '';
		resultsList.hidden = true;

		var targets;
		try {
			var response = await apiFetch( {
				path: '/softglaze-a11y/v1/scan-targets',
				method: 'POST',
				data: { urls: urls },
			} );
			targets = Array.isArray( response.targets ) ? response.targets : [];
		} catch ( error ) {
			/* translators: %s: reason, for example "Only pages on this site can be scanned." */
			announce( sprintf( __( 'The scan could not start: %s', 'tagmend-accessibility-fixes' ), errorMessage( error ) ) );
			setBusy( false );
			return;
		}

		announce( sprintf(
			/* translators: %d: number of pages */
			_n( 'Scanning %d page. This can take a moment.', 'Scanning %d pages. This can take a moment.', targets.length, 'tagmend-accessibility-fixes' ),
			targets.length
		) );

		var scanned = 0;
		var last = '';
		for ( var i = 0; i < targets.length; i++ ) {
			var outcome = await scanOne( targets[ i ] );
			if ( outcome.ok ) {
				scanned++;
			}
			addResult( targets[ i ].url, outcome.text );
			last = sprintf(
				/* translators: 1: page number, 2: number of pages, 3: page address, 4: result */
				__( 'Page %1$d of %2$d, %3$s: %4$s', 'tagmend-accessibility-fixes' ),
				i + 1,
				targets.length,
				targets[ i ].url,
				outcome.text
			);
			// The last page's result is read together with the closing message,
			// so a quick second update cannot replace it before it is heard.
			if ( i < targets.length - 1 ) {
				announce( last );
			}
		}

		announce( last + ' ' + sprintf(
			/* translators: 1: pages scanned, 2: pages sent for scanning */
			__( 'Scan finished: %1$d of %2$d pages scanned. Reload the screen to see the updated reports.', 'tagmend-accessibility-fixes' ),
			scanned,
			targets.length
		) );
		addReloadLink();
		setBusy( false );
	}

	/**
	 * Adds a link that reloads the screen with the new reports.
	 */
	function addReloadLink() {
		var url = panel.getAttribute( 'data-reload-url' );
		if ( ! url || panel.querySelector( '.softglaze-a11y-reload' ) ) {
			return;
		}
		var paragraph = document.createElement( 'p' );
		var link = document.createElement( 'a' );
		paragraph.className = 'softglaze-a11y-reload';
		link.href = url;
		link.textContent = __( 'Reload to see the updated reports', 'tagmend-accessibility-fixes' );
		paragraph.appendChild( link );
		resultsList.parentNode.insertBefore( paragraph, resultsList.nextSibling );
	}

	/**
	 * After a redirect back from an action, moves focus to the inline status
	 * message so it is read first (blueprint section 12).
	 */
	function focusStatusMessage() {
		var message = document.querySelector( '.softglaze-a11y-status[tabindex="-1"]' );
		if ( message ) {
			message.focus();
		}
	}
}() );
