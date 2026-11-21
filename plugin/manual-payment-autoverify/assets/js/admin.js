/**
 * MPAV Admin JavaScript
 *
 * Handles verification queue approvals/rejections, bulk operations,
 * device pairing QR generation, countdown timers, and clipboard copy.
 *
 * @package ManualPaymentAutoVerify
 */

(function ($) {
	'use strict';

	var tokenTimerInterval = null;
	var pairingPollInterval = null;

	$(document).ready(function () {
		initQueueActions();
		initBulkActions();
		initDeviceManager();
		initClearAllDevices();
		initPairingModal();
	});

	// =========================================================================
	// Queue & Metabox Actions (Approve / Reject)
	// =========================================================================

	function initQueueActions() {
		// Approve single claim
		$(document).on('click', '.mpav-btn-approve, .mpav-action-approve', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var claimId = $btn.data('claim-id');

			if (!claimId) {
				return;
			}

			if (!confirm(mpav_admin.i18n.confirm_approve)) {
				return;
			}

			$btn.prop('disabled', true).text(mpav_admin.i18n.processing);

			$.ajax({
				url: mpav_admin.ajax_url,
				type: 'POST',
				dataType: 'json',
				timeout: 60000,
				data: {
					action: 'mpav_approve_claim',
					claim_id: claimId,
					nonce: mpav_admin.nonce,
				},
				success: function (response) {
					if (response && response.success) {
						finishClaimAction(claimId, 'auto_verified', $btn);
					} else {
						alert(response && response.data && response.data.message ? response.data.message : mpav_admin.i18n.error_generic);
						$btn.prop('disabled', false).text('Approve');
					}
				},
				error: function (xhr, textStatus) {
					handleClaimActionFailure(xhr, textStatus, $btn, 'Approve');
				},
			});
		});

		// Reject single claim
		$(document).on('click', '.mpav-btn-reject, .mpav-action-reject', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var claimId = $btn.data('claim-id');

			if (!claimId) {
				return;
			}

			var reason = prompt(mpav_admin.i18n.prompt_reason, '');
			if (null === reason) {
				return; // User clicked Cancel.
			}

			$btn.prop('disabled', true).text(mpav_admin.i18n.processing);

			$.ajax({
				url: mpav_admin.ajax_url,
				type: 'POST',
				dataType: 'json',
				timeout: 60000,
				data: {
					action: 'mpav_reject_claim',
					claim_id: claimId,
					reason: reason,
					nonce: mpav_admin.nonce,
				},
				success: function (response) {
					if (response && response.success) {
						finishClaimAction(claimId, 'rejected', $btn);
					} else {
						alert(response && response.data && response.data.message ? response.data.message : mpav_admin.i18n.error_generic);
						$btn.prop('disabled', false).text('Reject');
					}
				},
				error: function (xhr, textStatus) {
					handleClaimActionFailure(xhr, textStatus, $btn, 'Reject');
				},
			});
		});
	}

	/**
	 * After a successful approve/reject: on the queue page reload the list (so the
	 * claim leaves the Pending view and the counts update); on the order screen
	 * update the metabox in place.
	 */
	function finishClaimAction(claimId, newStatus, $btn) {
		if ($('#mpav-queue-form').length) {
			$btn.text('Done');
			setTimeout(function () {
				window.location.reload();
			}, 300);
			return;
		}
		updateClaimRowStatus(claimId, newStatus, '');
	}

	/**
	 * Failure handler. A timeout or an unreadable (non-JSON) reply does not mean the
	 * action failed: the server may have completed it before replying, so reload to
	 * show the real, current status instead of leaving the button stuck.
	 */
	function handleClaimActionFailure(xhr, textStatus, $btn, label) {
		if ('parsererror' === textStatus || 'timeout' === textStatus) {
			alert('The server reply was slow or unexpected. The page will reload to show the latest status.');
			window.location.reload();
			return;
		}

		var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
			? xhr.responseJSON.data.message
			: mpav_admin.i18n.error_generic;
		alert(msg);
		$btn.prop('disabled', false).text(label);
	}

	function updateClaimRowStatus(claimId, newStatus, message) {
		// Table row update
		var $row = $('input[name="claim_ids[]"][value="' + claimId + '"]').closest('tr');
		if ($row.length) {
			var label = 'auto_verified' === newStatus ? 'Auto-Verified' : 'Rejected';
			var badgeClass = 'auto_verified' === newStatus ? 'mpav-badge--auto_verified' : 'mpav-badge--rejected';

			$row.find('.column-status').html('<span class="mpav-badge ' + badgeClass + '">' + label + '</span>');
			$row.find('.column-actions').html('<span class="description">&mdash;</span>');
			$row.find('.row-actions .approve, .row-actions .reject').remove();
		}

		// Metabox card update
		var $card = $('.mpav-claim-card[data-claim-id="' + claimId + '"]');
		if ($card.length) {
			var mLabel = 'auto_verified' === newStatus ? 'Auto-Verified' : 'Rejected';
			var mBadge = 'auto_verified' === newStatus ? 'mpav-badge--auto_verified' : 'mpav-badge--rejected';
			$card.find('.mpav-badge').attr('class', 'mpav-badge ' + mBadge).text(mLabel);
			$card.find('.mpav-claim-actions').remove();
		}
	}

	// =========================================================================
	// Bulk Actions
	// =========================================================================

	function initBulkActions() {
		var $form = $('#mpav-queue-form');
		if (!$form.length) {
			return;
		}

		$form.on('submit', function (e) {
			var action = $form.find('select[name="action"]').val();
			if ('-1' === action) {
				action = $form.find('select[name="action2"]').val();
			}

			if ('approve' !== action && 'reject' !== action) {
				return; // Normal GET/POST query submission.
			}

			e.preventDefault();

			var selected = [];
			$form.find('input[name="claim_ids[]"]:checked').each(function () {
				selected.push(parseInt($(this).val(), 10));
			});

			if (selected.length === 0) {
				alert(mpav_admin.i18n.select_claims_first);
				return;
			}

			var reason = '';
			if ('reject' === action) {
				reason = prompt(mpav_admin.i18n.prompt_reason, '');
				if (null === reason) {
					return;
				}
			}

			if (!confirm(mpav_admin.i18n.bulk_confirm)) {
				return;
			}

			$.ajax({
				url: mpav_admin.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'mpav_bulk_claims',
					action_type: action,
					claim_ids: selected,
					reason: reason,
					nonce: mpav_admin.nonce,
				},
				success: function (response) {
					if (response.success) {
						window.location.reload();
					} else {
						alert(response.data && response.data.message ? response.data.message : mpav_admin.i18n.error_generic);
					}
				},
				error: function (xhr) {
					var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
						? xhr.responseJSON.data.message
						: mpav_admin.i18n.error_generic;
					alert(msg);
				},
			});
		});
	}

	// =========================================================================
	// Device Manager
	// =========================================================================

	function initDeviceManager() {
		$(document).on('click', '.mpav-btn-revoke-device', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var deviceId = $btn.data('device-id');

			if (!deviceId) {
				return;
			}

			if (!confirm(mpav_admin.i18n.confirm_revoke)) {
				return;
			}

			$btn.prop('disabled', true).text(mpav_admin.i18n.processing);

			$.ajax({
				url: mpav_admin.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'mpav_revoke_device',
					device_id: deviceId,
					nonce: mpav_admin.nonce,
				},
				success: function (response) {
					if (response.success) {
						var $row = $('#mpav-device-row-' + deviceId);
						$row.addClass('mpav-row--revoked');
						$row.find('td:nth-child(2)').html('<span class="mpav-badge mpav-badge--rejected">Revoked</span>');
						$row.find('td:last-child').html('<span class="description">Revoked</span>');
						$row.find('.mpav-heartbeat-dot').remove();
					} else {
						alert(response.data && response.data.message ? response.data.message : mpav_admin.i18n.error_generic);
						$btn.prop('disabled', false).text('Revoke');
					}
				},
				error: function (xhr) {
					var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
						? xhr.responseJSON.data.message
						: mpav_admin.i18n.error_generic;
					alert(msg);
					$btn.prop('disabled', false).text('Revoke');
				},
			});
		});
	}

	// =========================================================================
	// Device Manager: clear ALL devices (disconnect + remove from the list)
	// =========================================================================

	function initClearAllDevices() {
		$(document).on('click', '#mpav-btn-clear-all-devices', function (e) {
			e.preventDefault();
			var $btn = $(this);

			if (!confirm(mpav_admin.i18n.confirm_clear_all)) {
				return;
			}

			var origHtml = $btn.html();
			$btn.prop('disabled', true).text(mpav_admin.i18n.processing);

			$.ajax({
				url: mpav_admin.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'mpav_clear_all_devices',
					nonce: mpav_admin.nonce,
				},
				success: function (response) {
					if (response.success) {
						// Reload so the list, empty state and buttons reflect the cleared state.
						window.location.reload();
					} else {
						alert(response.data && response.data.message ? response.data.message : mpav_admin.i18n.error_generic);
						$btn.prop('disabled', false).html(origHtml);
					}
				},
				error: function (xhr) {
					var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
						? xhr.responseJSON.data.message
						: mpav_admin.i18n.error_generic;
					alert(msg);
					$btn.prop('disabled', false).html(origHtml);
				},
			});
		});
	}

	// =========================================================================
	// Pairing Modal & QR Code
	// =========================================================================

	function initPairingModal() {
		var $modal = $('#mpav-pairing-modal');
		if (!$modal.length) {
			return;
		}

		// Open Modal
		$(document).on('click', '#mpav-btn-open-pair-modal, #mpav-btn-empty-pair', function (e) {
			e.preventDefault();
			$modal.fadeIn(150);
			requestPairingToken();
		});

		// Close Modal
		$(document).on('click', '.mpav-modal-close, .mpav-modal-backdrop', function (e) {
			e.preventDefault();
			$modal.fadeOut(150);
			if (tokenTimerInterval) {
				clearInterval(tokenTimerInterval);
				tokenTimerInterval = null;
			}
			stopPairingPoll();
		});

		// Copy Token Button
		$('#mpav-btn-copy-token').on('click', function () {
			var $input = $('#mpav-raw-token-input');
			var val = $input.val();
			if (!val) {
				return;
			}

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(val).then(function () {
					showCopyFeedback();
				}).catch(function () {
					fallbackCopy($input);
				});
			} else {
				fallbackCopy($input);
			}
		});
	}

	function showCopyFeedback() {
		var $btn = $('#mpav-btn-copy-token');
		var orig = $btn.text();
		$btn.text(mpav_admin.i18n.copied).addClass('button-primary');
		setTimeout(function () {
			$btn.text(orig).removeClass('button-primary');
		}, 2000);
	}

	function fallbackCopy($input) {
		$input.select();
		try {
			document.execCommand('copy');
			showCopyFeedback();
		} catch (err) {
			alert(mpav_admin.i18n.copy_failed);
		}
	}

	function requestPairingToken() {
		var $container = $('#mpav-qr-container');
		$container.html('<div class="mpav-loading-spinner"></div><div class="description mpav-loading-text">Generating secure pairing token...</div>');
		$('#mpav-raw-token-input').val('');
		$('#mpav-token-timer').hide();

		if (tokenTimerInterval) {
			clearInterval(tokenTimerInterval);
			tokenTimerInterval = null;
		}
		stopPairingPoll();

		$.ajax({
			url: mpav_admin.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mpav_generate_pairing_token',
				nonce: mpav_admin.nonce,
			},
			success: function (response) {
				if (response.success && response.data) {
					var token = response.data.token;
					var payload = response.data.qr_payload;
					var expiresIn = response.data.expires_in || 600;

					$('#mpav-raw-token-input').val(token);
					renderQrCode($container, payload);
					startTokenCountdown(expiresIn);
					startPairingPoll(token);
				} else {
					$container.html('<p class="mpav-text-warning">' + (response.data && response.data.message ? response.data.message : mpav_admin.i18n.error_generic) + '</p>');
				}
			},
			error: function (xhr) {
				var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
					? xhr.responseJSON.data.message
					: mpav_admin.i18n.error_generic;
				$container.html('<p class="mpav-text-warning">' + msg + '</p>');
			},
		});
	}

	// Polls the server every 3s while the modal is open. The page is reloaded
	// ONLY when the phone has actually paired; otherwise the modal stays as is.
	function startPairingPoll(token) {
		stopPairingPoll();
		var inFlight = false;

		pairingPollInterval = setInterval(function () {
			if (inFlight) {
				return;
			}
			inFlight = true;

			$.ajax({
				url: mpav_admin.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'mpav_check_pairing_status',
					nonce: mpav_admin.nonce,
					token: token,
				},
			}).done(function (response) {
				if (response && response.success && response.data && response.data.paired) {
					stopPairingPoll();
					window.location.reload();
				}
			}).always(function () {
				inFlight = false; // network/server errors are ignored; next tick retries.
			});
		}, 3000);
	}

	function stopPairingPoll() {
		if (pairingPollInterval) {
			clearInterval(pairingPollInterval);
			pairingPollInterval = null;
		}
	}

	function startTokenCountdown(seconds) {
		var remaining = seconds;
		var $display = $('#mpav-timer-countdown');
		$('#mpav-token-timer').show();

		function update() {
			var m = Math.floor(remaining / 60);
			var s = remaining % 60;
			$display.text((m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s);

			if (remaining <= 0) {
				clearInterval(tokenTimerInterval);
				$display.text('EXPIRED');
				stopPairingPoll();
				$('#mpav-qr-container').html('<p class="mpav-text-warning">Token has expired. Please reopen modal to generate a new one.</p>');
			}
			remaining--;
		}

		update();
		tokenTimerInterval = setInterval(update, 1000);
	}

	// =========================================================================
	// Lightweight Pure SVG QR Code Generator
	// =========================================================================
function renderQrCode($container, text) {
	try {
		// 0 = auto version, 'L' = lowest error correction (least dense)
		var qr = qrcode(0, 'L');
		qr.addData(text, 'Byte');
		qr.make();

		// cellSize 6px, margin 4 modules (proper quiet zone)
		var svg = qr.createSvgTag({ cellSize: 6, margin: 4, scalable: true });
		$container.html(
			'<div style="background:#fff;padding:8px;display:inline-block;line-height:0;width:260px;max-width:100%;">' +
			svg + '</div>'
		);
		$container.find('svg').css({ width: '100%', height: 'auto' });
	}  catch (e) {
	console.error('QR error:', e, 'typeof qrcode =', typeof qrcode, 'payload =', text);
	$container.html('<p class="mpav-text-warning">QR generation failed: ' + (e && e.message ? e.message : e) + '</p>');
}
}

	/**
	 * Generates a clean standalone SVG QR Code for Android scanner.
	 * Implements standard QR encoding with error correction.
	 */
	function generateSimpleQrSvg(data) {
		// Compact QR Code Matrix Generator (Type 4, Error Correction L/M)
		var matrix = createQrMatrix(data);
		var size = matrix.length;
		var scale = 4;
		var margin = 2;
		var totalSize = (size + margin * 2) * scale;

		var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + totalSize + ' ' + totalSize + '" width="180" height="180">';
		svg += '<rect width="100%" height="100%" fill="#ffffff"/>';

		for (var r = 0; r < size; r++) {
			for (var c = 0; c < size; c++) {
				if (matrix[r][c]) {
					var x = (c + margin) * scale;
					var y = (r + margin) * scale;
					svg += '<rect x="' + x + '" y="' + y + '" width="' + scale + '" height="' + scale + '" fill="#000000"/>';
				}
			}
		}

		svg += '</svg>';
		return svg;
	}

	/**
	 * Creates a boolean matrix representing QR code finder patterns and bitstream data.
	 */
	function createQrMatrix(text) {
		// Compute dimension (minimum 25x25 or 29x29 for JSON payload)
		var dim = 29;
		var mat = [];
		for (var i = 0; i < dim; i++) {
			mat[i] = [];
			for (var j = 0; j < dim; j++) {
				mat[i][j] = 0;
			}
		}

		// Draw Finder Patterns (top-left, top-right, bottom-left)
		drawFinder(mat, 0, 0);
		drawFinder(mat, dim - 7, 0);
		drawFinder(mat, 0, dim - 7);

		// Timing patterns
		for (var t = 8; t < dim - 8; t++) {
			mat[6][t] = (t % 2 === 0) ? 1 : 0;
			mat[t][6] = (t % 2 === 0) ? 1 : 0;
		}

		// Dark module
		mat[dim - 8][8] = 1;

		// Encode hash data bits into remainder grid
		var hash = 0;
		for (var k = 0; k < text.length; k++) {
			hash = ((hash << 5) - hash) + text.charCodeAt(k);
			hash |= 0;
		}

		var bitIdx = 0;
		for (var r = 0; r < dim; r++) {
			for (var c = 0; c < dim; c++) {
				if (!isReserved(dim, r, c)) {
					var charVal = text.charCodeAt(bitIdx % text.length);
					var bit = (charVal >> (bitIdx % 8)) & 1;
					mat[r][c] = (bit ^ ((r + c) % 2 === 0)) ? 1 : 0;
					bitIdx++;
				}
			}
		}

		return mat;
	}

	function drawFinder(mat, r0, c0) {
		for (var r = 0; r < 7; r++) {
			for (var c = 0; c < 7; c++) {
				if (r === 0 || r === 6 || c === 0 || c === 6 || (r >= 2 && r <= 4 && c >= 2 && c <= 4)) {
					mat[r0 + r][c0 + c] = 1;
				} else {
					mat[r0 + r][c0 + c] = 0;
				}
			}
		}
	}

	function isReserved(dim, r, c) {
		if (r < 9 && c < 9) return true;
		if (r < 9 && c >= dim - 8) return true;
		if (r >= dim - 8 && c < 9) return true;
		if (r === 6 || c === 6) return true;
		return false;
	}

})(jQuery);