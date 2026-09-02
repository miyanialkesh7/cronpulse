/* global cronpulseData, jQuery */
( function ( $ ) {
	'use strict';

	const PAGE_SIZE       = 10;
	const LOG_PAGE_SIZE   = 10;
	const EMAIL_PAGE_SIZE = 10;
	let logPage      = 1;
	let emailLogPage = 1;
	let currentPage  = 1;
	let sortKey      = null; // 'next-run' | 'duration' | null
	let sortDir      = 'asc';

	// -------------------------------------------------------------------------
	// Flash message
	// -------------------------------------------------------------------------
	const $flash = $( '<div id="cp-flash"></div>' ).appendTo( 'body' );
	let flashTimer;

	function flash( msg, type ) {
		clearTimeout( flashTimer );
		$flash
			.text( msg )
			.removeClass( 'cp-flash-success cp-flash-error' )
			.addClass( 'cp-flash-' + type )
			.fadeIn( 200 );
		flashTimer = setTimeout( function () {
			$flash.fadeOut( 400 );
		}, 3500 );
	}

	// -------------------------------------------------------------------------
	// Tabs
	// -------------------------------------------------------------------------
	function activateTab( tab ) {
		const $tab = $( '.cp-tab[data-tab="' + tab + '"]' );
		if ( ! $tab.length ) {
			return;
		}
		$( '.cp-tab' ).removeClass( 'cp-tab-active' );
		$tab.addClass( 'cp-tab-active' );
		$( '.cp-tab-panel' ).hide();
		$( '#cp-' + tab ).show();
	}

	$( '.cp-tab' ).on( 'click', function ( e ) {
		e.preventDefault();
		activateTab( $( this ).data( 'tab' ) );
	} );

	// Land on the right tab after a redirect, e.g. saving Alert settings.
	const initialTab = window.location.hash.replace( '#cp-', '' );
	if ( initialTab ) {
		activateTab( initialTab );
	}

	// -------------------------------------------------------------------------
	// Alert banner — dismiss for the session, jump to Jobs tab on link click
	// -------------------------------------------------------------------------
	if ( sessionStorage.getItem( 'cp_banner_dismissed' ) ) {
		$( '.cp-alert-banner' ).hide();
	}

	$( document ).on( 'click', '.cp-alert-banner-dismiss', function () {
		$( '.cp-alert-banner' ).slideUp( 200 );
		sessionStorage.setItem( 'cp_banner_dismissed', '1' );
	} );

	$( document ).on( 'click', '.cp-alert-banner-link', function ( e ) {
		e.preventDefault();
		const tab = $( this ).data( 'tab' ) || 'jobs';
		activateTab( tab );
	} );

	// -------------------------------------------------------------------------
	// Jobs table: search + status filter + sort + pagination
	// -------------------------------------------------------------------------
	function getAllRows() {
		return $( '#cp-jobs .cp-table tbody tr.cp-job-row[data-hook]' );
	}

	function renderTable() {
		const $tbody = $( '#cp-jobs .cp-table tbody' );
		if ( ! $tbody.length ) {
			return;
		}

		const q      = $( '#cp-search' ).val().toLowerCase().trim();
		const status = $( '#cp-status-filter' ).val();

		let rows = getAllRows().get();

		if ( sortKey ) {
			rows.sort( function ( a, b ) {
				const av = parseFloat( $( a ).data( sortKey ) );
				const bv = parseFloat( $( b ).data( sortKey ) );
				return sortDir === 'asc' ? av - bv : bv - av;
			} );

			// Re-append main rows and keep detail rows immediately after each.
			$.each( rows, function ( i, row ) {
				const $detail = $( row ).next( '.cp-job-detail' );
				$tbody.append( row );
				if ( $detail.length ) {
					$tbody.append( $detail );
				}
			} );
		}

		const $rows = $( rows );
		const $matching = $rows.filter( function () {
			const $row    = $( this );
			const hook    = $row.data( 'hook' ).toLowerCase();
			const rowStat = $row.data( 'status' );

			const matchesText   = ! q || hook.includes( q );
			const matchesStatus = ! status || rowStat === status;

			return matchesText && matchesStatus;
		} );

		// Hide all main rows and their detail rows.
		$rows.each( function () {
			$( this ).hide();
			$( this ).next( '.cp-job-detail' ).hide();
		} );

		const totalPages = Math.max( 1, Math.ceil( $matching.length / PAGE_SIZE ) );
		if ( currentPage > totalPages ) {
			currentPage = totalPages;
		}

		const start = ( currentPage - 1 ) * PAGE_SIZE;
		$matching.slice( start, start + PAGE_SIZE ).each( function () {
			$( this ).show();
			const $detail = $( this ).next( '.cp-job-detail' );
			// Show detail row only if it was already open.
			if ( $detail.length && $( this ).hasClass( 'cp-job-row--open' ) ) {
				$detail.show();
			}
		} );

		$( '#cp-pagination' ).toggle( totalPages > 1 );
		$( '#cp-page-info' ).text(
			'Page ' + currentPage + ' of ' + totalPages + ' (' + $matching.length + ' jobs)'
		);
		$( '#cp-prev-page' ).prop( 'disabled', currentPage <= 1 );
		$( '#cp-next-page' ).prop( 'disabled', currentPage >= totalPages );
	}

	$( '#cp-search' ).on( 'input', function () {
		currentPage = 1;
		renderTable();
	} );

	$( '#cp-status-filter' ).on( 'change', function () {
		currentPage = 1;
		renderTable();
	} );

	$( '#cp-prev-page' ).on( 'click', function () {
		currentPage--;
		renderTable();
	} );

	$( '#cp-next-page' ).on( 'click', function () {
		currentPage++;
		renderTable();
	} );

	$( '.cp-sortable' ).on( 'click', function () {
		const key = $( this ).data( 'sort' );

		if ( sortKey === key ) {
			sortDir = sortDir === 'asc' ? 'desc' : 'asc';
		} else {
			sortKey = key;
			sortDir = 'asc';
		}

		$( '.cp-sortable' ).removeClass( 'cp-sort-asc cp-sort-desc' );
		$( this ).addClass( sortDir === 'asc' ? 'cp-sort-asc' : 'cp-sort-desc' );

		currentPage = 1;
		renderTable();
	} );

	renderTable();

	// -------------------------------------------------------------------------
	// Expandable job rows
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-job-row', function ( e ) {
		// Don't toggle if a button inside the row was clicked.
		if ( $( e.target ).closest( 'button, a' ).length ) {
			return;
		}

		const $row    = $( this );
		const $detail = $row.next( '.cp-job-detail' );

		if ( ! $detail.length ) {
			return;
		}

		const isOpen = $row.hasClass( 'cp-job-row--open' );
		$row.toggleClass( 'cp-job-row--open', ! isOpen );
		$detail.toggle( ! isOpen );
	} );

	// -------------------------------------------------------------------------
	// Run Now
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-run-now', function () {
		const $btn  = $( this );
		const hook  = $btn.data( 'hook' );
		const args  = $btn.data( 'args' );

		$btn.addClass( 'is-loading' ).text( cronpulseData.i18n.running );

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_run_now',
			nonce  : cronpulseData.nonce,
			hook   : hook,
			args   : JSON.stringify( args ),
		} )
		.done( function ( res ) {
			if ( res.success ) {
				flash( res.data.message, 'success' );

				const $mainRow = $btn.closest( '.cp-job-detail' ).prev( '.cp-job-row' );

				if ( res.data.duration !== undefined ) {
					$mainRow.attr( 'data-duration', res.data.duration );
					$mainRow.next( '.cp-job-detail' ).find( '.cp-duration-text' ).text( res.data.duration + ' ms' );
				}

				const $detail = $mainRow.next( '.cp-job-detail' );
				$detail.find( '.cp-run-output' ).remove();
				if ( res.data.output && res.data.output.trim() !== '' ) {
					$detail.append(
						'<div class="cp-run-output">' +
						'<strong>Output</strong>' +
						'<pre>' + $( '<div>' ).text( res.data.output ).html() + '</pre>' +
						'</div>'
					);
				}

				// Flip status chip if it was overdue/pending/failing → healthy.
				$mainRow.removeClass( 'cp-status-overdue cp-status-pending cp-status-failing' )
				        .addClass( 'cp-status-healthy' )
				        .attr( 'data-status', 'healthy' );
				$mainRow.find( '.cp-chip' )
				        .removeClass( 'cp-chip-overdue cp-chip-pending cp-chip-failing' )
				        .addClass( 'cp-chip-healthy' )
				        .text( 'Healthy' );
				$mainRow.next( '.cp-job-detail' ).find( '.cp-snooze' ).remove();

				renderTable();

			} else {
				flash( res.data.message || cronpulseData.i18n.error, 'error' );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} )
		.always( function () {
			$btn.removeClass( 'is-loading' ).text( 'Run Now' );
		} );
	} );

	// -------------------------------------------------------------------------
	// System cron recommendation toggle
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-syscron-toggle', function () {
		const $btn  = $( this );
		const $body = $btn.closest( '.cp-syscron-notice' ).find( '.cp-syscron-body' );
		const open  = $body.prop( 'hidden' );
		$body.prop( 'hidden', ! open );
		$btn.attr( 'aria-expanded', open ? 'true' : 'false' );
		$btn.find( '.cp-syscron-arrow' ).text( open ? '▴' : '▾' );
	} );

	// -------------------------------------------------------------------------
	// Copy cron command to clipboard
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-copy-cmd', function () {
		const cmd = $( this ).data( 'cmd' );
		if ( ! navigator.clipboard ) {
			return;
		}
		const $btn = $( this );
		navigator.clipboard.writeText( cmd ).then( function () {
			const orig = $btn.text();
			$btn.text( 'Copied!' );
			setTimeout( function () { $btn.text( orig ); }, 2000 );
		} );
	} );

	// -------------------------------------------------------------------------
	// Clear log
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-clear-log', function () {
		if ( ! window.confirm( cronpulseData.i18n.confirmClear ) ) {
			return;
		}

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_clear_log',
			nonce  : cronpulseData.nonce,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$( '#cp-log .cp-table' ).remove();
				$( '#cp-log .cp-log-top-bar' ).remove();
				$( '#cp-log' ).append(
					'<p class="cp-empty">Log cleared.</p>'
				);
				$( '.cp-tab[data-tab="log"] .cp-badge' ).remove();
				flash( res.data.message, 'success' );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Unschedule
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-unschedule', function () {
		const $btn = $( this );
		const hook = $btn.data( 'hook' );

		const confirmMsg = cronpulseData.i18n.confirmUnschedule.replace( '%s', hook );
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( cronpulseData.ajaxUrl, {
			action    : 'cronpulse_unschedule',
			nonce     : cronpulseData.nonce,
			hook      : hook,
			timestamp : $btn.data( 'timestamp' ),
			sig       : $btn.data( 'sig' ),
		} )
		.done( function ( res ) {
			if ( res.success ) {
				flash( res.data.message, 'success' );
				const $mainRow = $btn.closest( '.cp-job-detail' ).prev( '.cp-job-row' );
				$btn.closest( '.cp-job-detail' ).remove();
				$mainRow.remove();
				renderTable();
			} else {
				flash( res.data.message || cronpulseData.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
			$btn.prop( 'disabled', false );
		} );
	} );

	// -------------------------------------------------------------------------
	// Snooze alert
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-snooze', function () {
		const $btn = $( this );
		const hook = $btn.data( 'hook' );

		$btn.prop( 'disabled', true );

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_snooze',
			nonce  : cronpulseData.nonce,
			hook   : hook,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				flash( res.data.message, 'success' );
				$btn.remove();
			} else {
				flash( res.data.message || cronpulseData.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
			$btn.prop( 'disabled', false );
		} );
	} );

	// -------------------------------------------------------------------------
	// Execution log: filter strip (active-state only; renderLogTable() owns visibility)
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-log-filter', function () {
		$( '.cp-log-filter' ).removeClass( 'is-active' );
		$( this ).addClass( 'is-active' );
		// renderLogTable() is called via the separate click handler wired below.
	} );

	// -------------------------------------------------------------------------
	// Execution log: duration bars (proportional to the max visible duration)
	// -------------------------------------------------------------------------
	function renderDurationBars() {
		const $cells = $( '.cp-duration-cell[data-duration]' );
		if ( ! $cells.length ) {
			return;
		}

		let max = 0;
		$cells.each( function () {
			const v = parseInt( $( this ).data( 'duration' ), 10 );
			if ( v > max ) {
				max = v;
			}
		} );

		if ( max <= 0 ) {
			return;
		}

		$cells.each( function () {
			const v   = parseInt( $( this ).data( 'duration' ), 10 );
			const pct = Math.round( ( v / max ) * 100 );
			$( this ).find( '.cp-duration-bar' ).css( 'width', pct + '%' );
		} );
	}

	renderDurationBars();

	// -------------------------------------------------------------------------
	// Execution log pagination
	// -------------------------------------------------------------------------
	function renderLogTable() {
		const $rows = $( '.cp-log-row' );
		if ( ! $rows.length ) {
			return;
		}

		// Only paginate currently visible rows (respects active filter).
		const $visible = $rows.filter( ':visible, [data-log-status]' ).filter( function () {
			const activeFilter = $( '.cp-log-filter.is-active' ).data( 'filter' );
			if ( ! activeFilter ) {
				return true;
			}
			return $( this ).data( 'log-status' ) === activeFilter;
		} );

		// Re-filter: hide all, then show page slice.
		$rows.hide();

		const total      = $visible.length;
		const totalPages = Math.max( 1, Math.ceil( total / LOG_PAGE_SIZE ) );
		if ( logPage > totalPages ) {
			logPage = totalPages;
		}

		const start = ( logPage - 1 ) * LOG_PAGE_SIZE;
		$visible.slice( start, start + LOG_PAGE_SIZE ).show();

		$( '#cp-log-pagination' ).toggle( totalPages > 1 );
		$( '#cp-log-page-info' ).text( 'Page ' + logPage + ' of ' + totalPages + ' (' + total + ' entries)' );
		$( '#cp-log-prev' ).prop( 'disabled', logPage <= 1 );
		$( '#cp-log-next' ).prop( 'disabled', logPage >= totalPages );
	}

	// Re-run log pagination whenever the filter changes. This handler is
	// intentionally separate from the is-active toggle above so pagination
	// stays a self-contained concern — it doesn't touch filter-button classes.
	$( document ).on( 'click', '.cp-log-filter', function () {
		logPage = 1;
		renderLogTable();
	} );

	$( '#cp-log-prev' ).on( 'click', function () {
		logPage--;
		renderLogTable();
	} );

	$( '#cp-log-next' ).on( 'click', function () {
		logPage++;
		renderLogTable();
	} );

	renderLogTable();

	// -------------------------------------------------------------------------
	// Email log pagination
	// -------------------------------------------------------------------------
	function renderEmailLogTable() {
		// Each email entry may have a sibling error row — paginate both together.
		const $mainRows = $( '.cp-email-table .cp-row' );
		if ( ! $mainRows.length ) {
			return;
		}

		$mainRows.each( function () {
			$( this ).hide();
			$( this ).next( '.cp-email-error-row' ).hide();
		} );

		const total      = $mainRows.length;
		const totalPages = Math.max( 1, Math.ceil( total / EMAIL_PAGE_SIZE ) );
		if ( emailLogPage > totalPages ) {
			emailLogPage = totalPages;
		}

		const start = ( emailLogPage - 1 ) * EMAIL_PAGE_SIZE;
		$mainRows.slice( start, start + EMAIL_PAGE_SIZE ).each( function () {
			$( this ).show();
			// Only show error row if it was already expanded.
			const $err = $( this ).next( '.cp-email-error-row' );
			if ( $( this ).attr( 'aria-expanded' ) === 'true' ) {
				$err.show();
			}
		} );

		$( '#cp-email-log-pagination' ).toggle( totalPages > 1 );
		$( '#cp-email-log-page-info' ).text( 'Page ' + emailLogPage + ' of ' + totalPages + ' (' + total + ' emails)' );
		$( '#cp-email-log-prev' ).prop( 'disabled', emailLogPage <= 1 );
		$( '#cp-email-log-next' ).prop( 'disabled', emailLogPage >= totalPages );
	}

	$( '#cp-email-log-prev' ).on( 'click', function () {
		emailLogPage--;
		renderEmailLogTable();
	} );

	$( '#cp-email-log-next' ).on( 'click', function () {
		emailLogPage++;
		renderEmailLogTable();
	} );

	renderEmailLogTable();

	// -------------------------------------------------------------------------
	// Send test email
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-test-email', function () {
		const $btn = $( this );

		$btn.prop( 'disabled', true );

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_test_email',
			nonce  : cronpulseData.nonce,
		} )
		.done( function ( res ) {
			flash( res.data.message || cronpulseData.i18n.error, res.success ? 'success' : 'error' );
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// -------------------------------------------------------------------------
	// Send test webhook
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-test-webhook', function () {
		const $btn = $( this );

		$btn.prop( 'disabled', true );

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_test_webhook',
			nonce  : cronpulseData.nonce,
		} )
		.done( function ( res ) {
			flash( res.data.message || cronpulseData.i18n.error, res.success ? 'success' : 'error' );
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// -------------------------------------------------------------------------
	// Clear email log
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-clear-email-log', function () {
		if ( ! window.confirm( cronpulseData.i18n.confirmClear ) ) {
			return;
		}

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_clear_email_log',
			nonce  : cronpulseData.nonce,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				const $section = $( '.cp-email-log-section' );
				$section.find( '.cp-table' ).remove();
				$section.find( '.cp-log-toolbar' ).remove();
				$section.append(
					'<p class="cp-empty">No emails sent yet. Alert emails (and test emails) will show up here.</p>'
				);
				$( '.cp-tab[data-tab="email-log"] .cp-badge' ).remove();
				flash( res.data.message, 'success' );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Clear debug log
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-clear-debug-log', function () {
		if ( ! window.confirm( cronpulseData.i18n.confirmClear ) ) {
			return;
		}

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_clear_debug_log',
			nonce  : cronpulseData.nonce,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				const $section = $( '.cp-debug-log-section' );
				$section.find( '.cp-debug-log' ).remove();
				$section.find( '.cp-log-toolbar' ).remove();
				$section.append(
					'<p class="cp-empty">No debug output yet. Use "Send Test Email" on the Settings tab to generate some.</p>'
				);
				flash( res.data.message, 'success' );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Email log: inline error expansion
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-email-row--expandable', function () {
		const $row       = $( this );
		const $errorRow  = $row.next( '.cp-email-error-row' );
		const isExpanded = $row.attr( 'aria-expanded' ) === 'true';

		$row.attr( 'aria-expanded', isExpanded ? 'false' : 'true' );
		$errorRow.toggle( ! isExpanded );
	} );

} )( jQuery );
