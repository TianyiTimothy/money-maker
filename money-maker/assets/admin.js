/**
 * Questrade Tracker & Tax Assistant — admin UI.
 *
 * Only behaviour here is the "Test connection" button (present on the Dashboard
 * and Connection screens): it POSTs to admin-ajax, which calls Questrade's
 * GET v1/time, and shows the result inline.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'mm-test-connection' );
		var result = document.getElementById( 'mm-test-result' );

		if ( ! button || ! result || typeof window.mmAdmin === 'undefined' ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.textContent = window.mmAdmin.strings.testing;
			result.className = 'mm-test-result';

			var body = new URLSearchParams();
			body.set( 'action', window.mmAdmin.testAction );
			body.set( '_wpnonce', window.mmAdmin.nonce );

			fetch( window.mmAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					var ok = payload && payload.success;
					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: window.mmAdmin.strings.failed;

					result.textContent = message;
					result.className = 'mm-test-result ' + ( ok ? 'mm-ok' : 'mm-warning' );
				} )
				.catch( function () {
					result.textContent = window.mmAdmin.strings.failed;
					result.className = 'mm-test-result mm-warning';
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	} );
} )();
