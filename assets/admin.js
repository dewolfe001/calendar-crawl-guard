/* global jQuery, CCG */
( function ( $ ) {
	'use strict';

	function escapeHtml( str ) {
		return $( '<div>' ).text( str == null ? '' : String( str ) ).html();
	}

	function runTest() {
		var url = $.trim( $( '#ccg-test-url' ).val() );
		var $result = $( '#ccg-test-result' );
		var $btn = $( '#ccg-test-btn' );

		if ( ! url ) {
			$result.removeAttr( 'hidden' ).addClass( 'is-error' ).html( 'Please enter a URL.' );
			return;
		}

		$btn.prop( 'disabled', true ).text( 'Testing…' );

		$.post( CCG.ajaxUrl, {
			action: 'ccg_test_url',
			nonce: CCG.nonce,
			url: url
		} ).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				var msg = resp && resp.data && resp.data.message ? resp.data.message : 'Unexpected response.';
				$result.removeAttr( 'hidden' ).addClass( 'is-error' ).html( escapeHtml( msg ) );
				return;
			}

			var d = resp.data;
			var action = d.action || 'allow';
			var html = '<span class="ccg-badge ' + escapeHtml( action ) + '">' + escapeHtml( d.verdict ) + '</span>';
			html += '<div class="ccg-reason">' + d.reason + '</div>';

			if ( d.target ) {
				html += '<span class="ccg-target">&rarr; ' + escapeHtml( d.target ) + '</span>';
			}

			var ctx = d.context || {};
			var bits = [];
			if ( ctx.provider ) { bits.push( 'calendar: ' + ctx.provider ); }
			if ( ctx.is_single ) { bits.push( 'single event' ); }
			if ( ctx.is_feed ) { bits.push( 'feed' ); }
			if ( ctx.view ) { bits.push( 'view: ' + ctx.view ); }
			if ( ctx.date ) { bits.push( 'date: ' + ctx.date ); }
			if ( ctx.cat ) { bits.push( 'category: ' + ctx.cat ); }
			if ( ! ctx.is_match ) { bits.push( 'not detected as a calendar URL' ); }
			if ( bits.length ) {
				html += '<span class="ccg-target">' + escapeHtml( bits.join( ' · ' ) ) + '</span>';
			}

			$result.removeAttr( 'hidden' ).removeClass( 'is-error' ).html( html );
		} ).fail( function () {
			$result.removeAttr( 'hidden' ).addClass( 'is-error' ).html( 'Request failed. Please try again.' );
		} ).always( function () {
			$btn.prop( 'disabled', false ).text( 'Test' );
		} );
	}

	$( function () {
		$( '#ccg-test-btn' ).on( 'click', runTest );
		$( '#ccg-test-url' ).on( 'keydown', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				runTest();
			}
		} );
	} );
}( jQuery ) );
