/**
 * Bypass LaLigaGate - Admin JavaScript
 * @package Bypass_LaLigaGate
 */
( function() {
	'use strict';

	var btnTest      = document.getElementById( 'blg-btn-test' );
	var btnCheck     = document.getElementById( 'blg-btn-check' );
	var btnOff       = document.getElementById( 'blg-btn-proxy-off' );
	var btnOn        = document.getElementById( 'blg-btn-proxy-on' );
	var authSelect   = document.getElementById( 'blg-field-auth-type' );
	var testStatus   = document.getElementById( 'blg-test-status' );
	var actionStatus = document.getElementById( 'blg-action-status' );
	var allButtons   = [ btnTest, btnCheck, btnOff, btnOn ];

	function setButtonsDisabled( d ) {
		allButtons.forEach( function( b ) { if ( b ) { b.disabled = d; } } );
	}

	function showMsg( el, msg, type ) {
		if ( ! el ) { return; }
		el.textContent = msg;
		el.className = 'ayudawp-blg-action-msg ayudawp-blg-action-msg--visible';
		if ( type === 'success' ) { el.classList.add( 'blg-msg-success' ); }
		else if ( type === 'error' ) { el.classList.add( 'blg-msg-error' ); }
	}

	function val( id ) {
		var el = document.getElementById( id );
		return el ? el.value : '';
	}

	function ajaxPost( action, sendCreds, callback ) {
		setButtonsDisabled( true );
		var data = new FormData();
		data.append( 'action', action );
		data.append( 'nonce', ayudawpBlg.nonce );
		if ( sendCreds ) {
			data.append( 'auth_type', val( 'blg-field-auth-type' ) );
			data.append( 'cf_email', val( 'blg-field-email' ) );
			data.append( 'cf_api_key', val( 'blg-field-apikey' ) );
			data.append( 'cf_zone_id', val( 'blg-field-zoneid' ) );
		}
		fetch( ayudawpBlg.ajaxUrl, {
			method: 'POST', body: data, credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		} )
		.then( function( r ) { return r.json(); } )
		.then( function( res ) { callback( res ); } )
		.catch( function( e ) { callback( { success: false, data: { message: 'Error de red: ' + e.message } } ); } )
		.finally( function() { setButtonsDisabled( false ); } );
	}

	function updateStatus( d ) {
		var b = document.getElementById( 'blg-status-blocked' );
		var p = document.getElementById( 'blg-status-bypass' );
		var l = document.getElementById( 'blg-status-lastcheck' );
		var db = document.getElementById( 'blg-detail-blocked' );
		var dp = document.getElementById( 'blg-detail-bypass' );
		if ( b && d.blocked !== undefined ) {
			var isB = d.blocked === 'SI';
			b.innerHTML = '<span class="blg-badge ' + ( isB ? 'blg-badge-danger' : 'blg-badge-ok' ) + '">' + ( isB ? 'SI' : 'NO' ) + '</span>';
			if ( db ) {
				var txt = isB ? 'Hay partidos con bloqueos activos.' : 'No se detectan bloqueos en este momento.';
				db.innerHTML = txt + ' <a href="https://hayahora.futbol/" target="_blank" rel="noopener">Ver hayahora.futbol</a>';
			}
		}
		if ( p && d.bypass !== undefined ) {
			var isP = d.bypass === 'SI';
			p.innerHTML = '<span class="blg-badge ' + ( isP ? 'blg-badge-warning' : 'blg-badge-ok' ) + '">' + ( isP ? 'SI' : 'NO' ) + '</span>';
			if ( dp ) {
				var bypassText = isP
					? ( d.manual ? 'Forzado manualmente. Pulsa "Restaurar proxy ON" para devolver el control al cron.' : 'Activado automáticamente por detección de bloqueos.' )
					: 'Proxy activo (CDN). Funcionamiento normal.';
				dp.textContent = bypassText;
			}
		}
		if ( l && d.lastCheck ) { l.textContent = d.lastCheck; }
	}

	function refreshDns( html ) {
		var w = document.getElementById( 'blg-dns-records' );
		if ( w && html ) { w.innerHTML = html; bindSelectAll(); }
	}

	/* Test connection + load DNS (single button) */
	if ( btnTest ) {
		btnTest.addEventListener( 'click', function( e ) {
			e.preventDefault();
			showMsg( testStatus, 'Conectando y cargando DNS...', '' );
			ajaxPost( 'ayudawp_blg_test_and_load', true, function( r ) {
				showMsg( testStatus, ( r.data && r.data.message ) || 'Sin respuesta', r.success ? 'success' : 'error' );
				if ( r.success && r.data && r.data.html ) { refreshDns( r.data.html ); }
			} );
		} );
	}

	/* Manual check */
	if ( btnCheck ) {
		btnCheck.addEventListener( 'click', function( e ) {
			e.preventDefault();
			showMsg( actionStatus, 'Comprobando...', '' );
			ajaxPost( 'ayudawp_blg_check', false, function( r ) {
				if ( r.success ) {
					showMsg( actionStatus, r.data.message, 'success' );
					updateStatus( r.data );
				} else {
					showMsg( actionStatus, ( r.data && r.data.message ) || 'Error', 'error' );
				}
			} );
		} );
	}

	/* Force proxy OFF */
	if ( btnOff ) {
		btnOff.addEventListener( 'click', function( e ) {
			e.preventDefault();
			if ( ! confirm( 'Esto desactivará el proxy (DNS Only) en los registros seleccionados.\nEl cron automático NO lo cambiará hasta que pulses "Restaurar proxy ON".\n\n¿Continuar?' ) ) { return; }
			showMsg( actionStatus, 'Desactivando proxy...', '' );
			ajaxPost( 'ayudawp_blg_proxy_off', false, function( r ) {
				if ( r.success ) {
					var hasErr = r.data.message && r.data.message.indexOf( 'Error' ) !== -1;
					showMsg( actionStatus, r.data.message, hasErr ? 'error' : 'success' );
					updateStatus( { bypass: r.data.bypass || 'SI', manual: true } );
					if ( r.data.html ) { refreshDns( r.data.html ); }
				} else {
					showMsg( actionStatus, ( r.data && r.data.message ) || 'Error', 'error' );
				}
			} );
		} );
	}

	/* Restore proxy ON */
	if ( btnOn ) {
		btnOn.addEventListener( 'click', function( e ) {
			e.preventDefault();
			if ( ! confirm( 'Esto reactivará el proxy (CDN) y el control automático del cron.\n\n¿Continuar?' ) ) { return; }
			showMsg( actionStatus, 'Restaurando proxy...', '' );
			ajaxPost( 'ayudawp_blg_proxy_on', false, function( r ) {
				if ( r.success ) {
					var hasErr = r.data.message && r.data.message.indexOf( 'Error' ) !== -1;
					showMsg( actionStatus, r.data.message, hasErr ? 'error' : 'success' );
					updateStatus( { bypass: r.data.bypass || 'NO', manual: false } );
					if ( r.data.html ) { refreshDns( r.data.html ); }
				} else {
					showMsg( actionStatus, ( r.data && r.data.message ) || 'Error', 'error' );
				}
			} );
		} );
	}

	/* Auth type toggle */
	function toggleAuth() {
		if ( ! authSelect ) { return; }
		var t = authSelect.value === 'token';
		[ ['blg-row-email',!t], ['blg-help-apikey-global',!t], ['blg-help-apikey-token',t],
		  ['blg-help-auth-global',!t], ['blg-help-auth-token',t] ].forEach( function( p ) {
			var el = document.getElementById( p[0] );
			if ( el ) { el.style.display = p[1] ? '' : 'none'; }
		} );
		var lbl = document.getElementById( 'blg-label-apikey' );
		if ( lbl ) { lbl.textContent = t ? 'API Token' : 'Global API Key'; }
	}
	if ( authSelect ) { authSelect.addEventListener( 'change', toggleAuth ); toggleAuth(); }

	/* Select all */
	function bindSelectAll() {
		var sa = document.getElementById( 'blg-select-all' );
		if ( sa ) {
			sa.addEventListener( 'change', function() {
				document.querySelectorAll( '.ayudawp-blg-dns-table tbody input[type="checkbox"]' )
					.forEach( function( cb ) { cb.checked = sa.checked; } );
			} );
		}
	}
	bindSelectAll();
} )();
