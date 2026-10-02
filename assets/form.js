(function ($) {
	'use strict';

	function submitPaymentForm(recaptchaToken) {
		var $form = $('#anz-worldline-payment-form');
		var $btn = $('#anz-worldline-pay-now');
		var $msg = $('#anz-worldline-message');
		var $loader = $('#anz-worldline-loader');

		$msg.hide().removeClass('anz-worldline-error anz-worldline-success');
		$btn.prop('disabled', true);
		$loader.show();

		var data = {
			action: 'anz_worldline_create_checkout',
			nonce: typeof anzWorldlineForm !== 'undefined' ? anzWorldlineForm.nonce : '',
			company: $form.find('[name="company"]').val(),
			email: $form.find('[name="email"]').val(),
			invoice_number: $form.find('[name="invoice_number"]').val(),
			amount: $form.find('[name="amount"]').val(),
			currency: $form.find('[name="currency"]').val() || 'AUD',
			form_page_url: window.location.href
		};
		if (recaptchaToken) {
			data['g-recaptcha-response'] = recaptchaToken;
		}

		$.post(typeof anzWorldlineForm !== 'undefined' ? anzWorldlineForm.ajax_url : '', data)
			.done(function (res) {
				if (res.success && res.data && res.data.redirect_url) {
					window.location.href = res.data.redirect_url;
					return;
				}
				$msg.text(res.data && res.data.message ? res.data.message : 'An error occurred.').addClass('anz-worldline-error').show();
				$btn.prop('disabled', false);
				$loader.hide();
			})
			.fail(function (xhr) {
				var msg = 'Request failed. Please try again.';
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				$msg.text(msg).addClass('anz-worldline-error').show();
				$btn.prop('disabled', false);
				$loader.hide();
			});
	}

	window.anzWorldlineRecaptchaSubmit = function (token) {
		submitPaymentForm(token);
	};

	$('#anz-worldline-payment-form').on('submit', function (e) {
		e.preventDefault();
		if (typeof anzWorldlineForm !== 'undefined' && anzWorldlineForm.recaptcha_enabled) {
			return;
		}
		submitPaymentForm();
	});

	var amountField = document.getElementById('anz-worldline-amount');
	if (amountField) {
		amountField.addEventListener('change', function () {
			var v = parseFloat(this.value);
			if (isNaN(v)) {
				this.value = '';
			} else {
				this.value = v.toFixed(2);
			}
		});
	}
})(jQuery);
