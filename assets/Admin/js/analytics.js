( function ( $ ) {
	'use strict';

	/* istanbul ignore next */
	if ( typeof module !== 'undefined' ) {
		module.exports = { computeRange, fmt, esc };
		return;
	}

	const config = window.awtsAnalyticsData || {};
	const s      = config.strings || {};

	let state = { from: '', to: '', chart: null };

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	$( function () {
		bindPresets();
		bindCustomRange();
		setPreset( 'this_month' );
	} );

	// -------------------------------------------------------------------------
	// Preset logic
	// -------------------------------------------------------------------------

	function setPreset( preset ) {
		const range = computeRange( preset );

		if ( preset === 'custom' ) {
			$( '.awts-analytics__custom-range' ).prop( 'hidden', false );
			return;
		}

		$( '.awts-analytics__custom-range' ).prop( 'hidden', true );
		$( '.awts-analytics__preset-btn' ).removeClass( 'awts-analytics__preset-btn--active' );
		$( '.awts-analytics__preset-btn[data-preset="' + preset + '"]' ).addClass( 'awts-analytics__preset-btn--active' );

		state.from = range.from;
		state.to   = range.to;

		loadAll();
	}

	function bindPresets() {
		$( document ).on( 'click', '.awts-analytics__preset-btn', function () {
			setPreset( $( this ).data( 'preset' ) );
		} );
	}

	function bindCustomRange() {
		$( document ).on( 'click', '.awts-analytics__apply-btn', function () {
			const from = $( '#awts-analytics-from' ).val();
			const to   = $( '#awts-analytics-to' ).val();

			if ( ! from || ! to || from > to ) return;

			$( '.awts-analytics__preset-btn' ).removeClass( 'awts-analytics__preset-btn--active' );
			$( '.awts-analytics__preset-btn[data-preset="custom"]' ).addClass( 'awts-analytics__preset-btn--active' );

			state.from = from;
			state.to   = to;

			loadAll();
		} );
	}

	function computeRange( preset ) {
		const now     = new Date();
		const today   = fmt( now );
		const year    = now.getFullYear();
		const month   = now.getMonth();

		switch ( preset ) {
			case 'today':
				return { from: today, to: today };

			case 'this_month':
				return { from: fmt( new Date( year, month, 1 ) ), to: today };

			case 'last_month': {
				const first = new Date( year, month - 1, 1 );
				const last  = new Date( year, month, 0 );
				return { from: fmt( first ), to: fmt( last ) };
			}

			case 'last_3_months':
				return { from: fmt( new Date( year, month - 3, now.getDate() ) ), to: today };

			case 'last_6_months':
				return { from: fmt( new Date( year, month - 6, now.getDate() ) ), to: today };

			case 'last_year':
				return { from: fmt( new Date( year - 1, month, now.getDate() ) ), to: today };

			case 'all_time':
				return { from: '2000-01-01', to: today };

			default:
				return { from: today, to: today };
		}
	}

	function fmt( d ) {
		const mm = String( d.getMonth() + 1 ).padStart( 2, '0' );
		const dd = String( d.getDate() ).padStart( 2, '0' );
		return d.getFullYear() + '-' + mm + '-' + dd;
	}

	// -------------------------------------------------------------------------
	// Data loading
	// -------------------------------------------------------------------------

	function loadAll() {
		setLoading( true );
		hideError();

		const params = { from: state.from, to: state.to };

		$.when(
			apiFetch( '/analytics/summary',   params ),
			apiFetch( '/analytics/by-date',   params ),
			apiFetch( '/analytics/assignees', params ),
			apiFetch( '/analytics/products',  params )
		).done( function ( summaryRes, byDateRes, assigneesRes, productsRes ) {
			const summary   = summaryRes[0]   || {};
			const byDate    = byDateRes[0]     || {};
			const assignees = assigneesRes[0]  || {};
			const products  = productsRes[0]   || {};

			renderCards( summary );
			renderChart( byDate.rows || [] );
			renderAssignees( assignees.assignees || [] );
			renderProducts( products.products || [] );

			setLoading( false );
		} ).fail( function () {
			setLoading( false );
			showError( s.error || 'Failed to load analytics.' );
		} );
	}

	function apiFetch( endpoint, params ) {
		return $.ajax( {
			url:     ( config.apiBase || '' ) + endpoint,
			method:  'GET',
			data:    params,
			headers: { 'X-WP-Nonce': config.nonce || '' },
		} );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	function renderCards( summary ) {
		$( '[data-stat="total"]' ).text( summary.total ?? 0 );
		$( '[data-stat="solved"]' ).text( summary.solved ?? 0 );
		$( '[data-stat="pending"]' ).text( summary.pending ?? 0 );
		$( '[data-stat="avg_rating"]' ).text( summary.avg_rating !== null && summary.avg_rating !== undefined
			? summary.avg_rating + ' ★'
			: s.na || 'N/A'
		);
	}

	function renderChart( rows ) {
		if ( typeof window.Chart === 'undefined' ) return;

		const labels  = rows.map( r => r.date );
		const totals  = rows.map( r => r.total );
		const solved  = rows.map( r => r.solved );

		if ( state.chart ) {
			state.chart.destroy();
			state.chart = null;
		}

		const ctx = document.getElementById( 'awts-analytics-chart' );
		if ( ! ctx ) return;

		state.chart = new window.Chart( ctx, {
			type: 'line',
			data: {
				labels,
				datasets: [
					{
						label:           s.ticketsLabel || 'Tickets',
						data:            totals,
						borderColor:     '#2563eb',
						backgroundColor: 'rgba(37,99,235,0.08)',
						tension:         0.3,
						fill:            true,
						pointRadius:     rows.length > 60 ? 0 : 3,
					},
					{
						label:           s.solvedLabel || 'Solved',
						data:            solved,
						borderColor:     '#16a34a',
						backgroundColor: 'rgba(22,163,74,0.08)',
						tension:         0.3,
						fill:            true,
						pointRadius:     rows.length > 60 ? 0 : 3,
					},
				],
			},
			options: {
				responsive:          true,
				maintainAspectRatio: false,
				plugins: {
					legend: { position: 'top' },
					tooltip: { mode: 'index', intersect: false },
				},
				scales: {
					x: { grid: { display: false } },
					y: { beginAtZero: true, ticks: { precision: 0 } },
				},
			},
		} );
	}

	function renderAssignees( rows ) {
		const $tbody = $( '#awts-analytics-assignees-tbody' );

		if ( ! rows.length ) {
			$tbody.html( '<tr><td colspan="5">' + esc( s.noData || 'No data for this period.' ) + '</td></tr>' );
			return;
		}

		const html = rows.map( function ( r ) {
			const rating = r.avg_rating !== null ? r.avg_rating + ' ★' : ( s.na || 'N/A' );
			return '<tr>'
				+ '<td>' + esc( r.name ) + '</td>'
				+ '<td>' + esc( r.total ) + '</td>'
				+ '<td>' + esc( r.solved ) + '</td>'
				+ '<td>' + esc( r.pending ) + '</td>'
				+ '<td>' + esc( rating ) + '</td>'
				+ '</tr>';
		} ).join( '' );

		$tbody.html( html );
	}

	function renderProducts( rows ) {
		const $tbody = $( '#awts-analytics-products-tbody' );

		if ( ! rows.length ) {
			$tbody.html( '<tr><td colspan="4">' + esc( s.noData || 'No data for this period.' ) + '</td></tr>' );
			return;
		}

		const adminUrl = ( window.awtsAnalyticsData && awtsAnalyticsData.adminUrl ) ? awtsAnalyticsData.adminUrl : '';

		const html = rows.map( function ( r ) {
			var nameCell;
			if ( adminUrl && r.product_id ) {
				var productUrl = adminUrl + '?post=' + encodeURIComponent( r.product_id ) + '&action=edit';
				nameCell = '<a href="' + productUrl + '">' + esc( r.name ) + '</a>';
			} else {
				nameCell = esc( r.name );
			}
			return '<tr>'
				+ '<td>' + nameCell + '</td>'
				+ '<td>' + esc( r.total ) + '</td>'
				+ '<td>' + esc( r.solved ) + '</td>'
				+ '<td>' + esc( r.pending ) + '</td>'
				+ '</tr>';
		} ).join( '' );

		$tbody.html( html );
	}

	// -------------------------------------------------------------------------
	// UI helpers
	// -------------------------------------------------------------------------

	function setLoading( loading ) {
		$( '.awts-analytics__cards, .awts-analytics__chart-wrap, .awts-analytics__tables' )
			.toggleClass( 'awts-analytics--loading', loading );
	}

	function showError( msg ) {
		$( '.awts-analytics__error' ).text( msg ).prop( 'hidden', false );
	}

	function hideError() {
		$( '.awts-analytics__error' ).prop( 'hidden', true );
	}

	function esc( val ) {
		const node = document.createElement( 'span' );
		node.textContent = String( val );
		return node.innerHTML;
	}

} )( window.jQuery );
