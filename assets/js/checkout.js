jQuery(function ($) {

    var hasClassicCheckoutForm = $('form.woocommerce-checkout, form.checkout[name="checkout"]').length > 0;
    var hasBlocksCheckout = document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout, .wc-block-components-checkout, .wc-block-components-checkout-place-order-button, form.wc-block-checkout__form');

    if (hasBlocksCheckout && !hasClassicCheckoutForm) {
        return;
    }

    var blink_checkout_form = {
        $form: $('form.woocommerce-checkout'),
        creditContainerSelector: '[data-blink-credit]',
        recoveryAttempts: 0,
        lastIntentId: '',
        lastIntentExpiryDate: '',
        lastCreditContainer: null,
        lastPaymentBox: null,
        init: function () {
            $(document.body).on('update_checkout', this.update_checkout);
            $(document.body).on('updated_checkout', this.updated_checkout);
        },
        get_checkout_form: function () {
            var $form = $('form.woocommerce-checkout');
            return $form.length ? $form : $('form[name="checkout"]');
        },
        get_payment_box: function () {
            return blink_checkout_form.get_checkout_form().find('.payment_box.payment_method_blink');
        },
        get_credit_container: function () {
            return blink_checkout_form.get_payment_box().find(blink_checkout_form.creditContainerSelector).first();
        },
        get_hosted_form_target: function () {
            // Blink hostedForm only supports FORM elements. The hosted-field inputs now
            // live inside WooCommerce's checkout form to avoid invalid nested forms.
            return blink_checkout_form.get_checkout_form();
        },
        ensure_payment_by: function () {
            var $form = blink_checkout_form.get_checkout_form();
            var $paymentBy = $form.find('input[name=payment_by]').first();
            var paymentBy = $paymentBy.val();
            var checkedPaymentBy = $form.find('input[name="switchPayment"]:checked').val();

            if (!paymentBy) {
                paymentBy = checkedPaymentBy || '';
            }

            if (!paymentBy && ($form.find('input[name="switchPayment"][value="credit-card"], input#credit-card').length || $form.find(blink_checkout_form.creditContainerSelector).length)) {
                paymentBy = 'credit-card';
            }

            if (!paymentBy) {
                paymentBy = $form.find('input[name="switchPayment"]').first().val() || '';
            }

            if (paymentBy && !$paymentBy.length) {
                var $paymentBox = blink_checkout_form.get_payment_box();
                $paymentBy = $('<input>', {
                    type: 'hidden',
                    name: 'payment_by',
                    id: 'payment_by'
                });
                ($paymentBox.length ? $paymentBox : $form).append($paymentBy);
            }

            if (paymentBy) {
                $paymentBy.val(paymentBy);
            }

            return paymentBy;
        },
        ensure_credit_card_data: function () {
            var $form = blink_checkout_form.get_checkout_form();
            if ($form.find('input[name="credit-card-data"]').length) {
                return;
            }

            var $paymentBox = blink_checkout_form.get_payment_box();
            ($paymentBox.length ? $paymentBox : $form).append($('<input>', {
                type: 'hidden',
                name: 'credit-card-data',
                id: 'credit-card-data',
                value: ''
            }));
        },
        collect_credit_card_data: function () {
            var $form = blink_checkout_form.get_checkout_form();
            var $creditContainer = blink_checkout_form.get_credit_container();
            var requiredFormFields = [
                'paymentToken',
                'paymenttoken',
                'type',
                'merchantID',
                'customer_name',
                'customer_email',
                'customer_address',
                'customer_postcode',
                'device_timezone',
                'device_capabilities',
                'device_accept_language',
                'device_screen_resolution',
                'remote_address',
                'device_ip_address',
                'payment_by',
                'intent_id',
                'intent_expiry_date'
            ];
            var fields = [];

            var addField = function () {
                if (!this.name || this.disabled || fields.indexOf(this) !== -1) {
                    return;
                }

                fields.push(this);
            };

            $creditContainer.find(':input[name]').each(addField);
            $form.find(':input[name]').filter(function () {
                return requiredFormFields.indexOf(this.name) !== -1;
            }).each(addField);

            return $(fields).serialize();
        },
        serialized_data_has_value: function (serializedData, fieldName) {
            var pairs = serializedData ? serializedData.split('&') : [];

            for (var i = 0; i < pairs.length; i++) {
                var pair = pairs[i].split('=');
                var name = decodeURIComponent((pair.shift() || '').replace(/\+/g, ' '));
                var value = decodeURIComponent(pair.join('=').replace(/\+/g, ' '));

                if (name === fieldName && value) {
                    return true;
                }
            }

            return false;
        },
        hosted_fields_mounted: function ($creditContainer) {
            if (!$creditContainer.length || !$.fn.hostedForm) {
                return false;
            }

            return $creditContainer.find('iframe').length > 0;
        },
        get_hosted_form_instance: function () {
            var $hostedForm = blink_checkout_form.get_hosted_form_target();
            if (!$hostedForm.length || !$.fn.hostedForm) {
                return null;
            }

            try {
                return $hostedForm.hostedForm('instance') || null;
            } catch (error) {
                return null;
            }
        },
        destroy_hosted_form_instance: function () {
            var $hostedForm = blink_checkout_form.get_hosted_form_target();
            if (!$hostedForm.length || !$.fn.hostedForm) {
                return;
            }

            try {
                var hostedForm = blink_checkout_form.get_hosted_form_instance();
                if (hostedForm && typeof hostedForm.destroy === 'function') {
                    hostedForm.destroy();
                }
                $hostedForm.removeData('hostedform');
            } catch (error) {
                console.error("An error occurred:", error);
            }
        },
        get_hosted_context: function () {
            var $form = blink_checkout_form.get_checkout_form();
            var $creditContainer = blink_checkout_form.get_credit_container();
            var $paymentBox = blink_checkout_form.get_payment_box();

            return {
                intentId: $form.find('input[name="intent_id"]').first().val() || '',
                intentExpiryDate: $form.find('input[name="intent_expiry_date"]').first().val() || '',
                creditContainer: $creditContainer.length ? $creditContainer.get(0) : null,
                paymentBox: $paymentBox.length ? $paymentBox.get(0) : null
            };
        },

        hosted_context_changed: function () {
            var context = blink_checkout_form.get_hosted_context();

            return (
                blink_checkout_form.lastIntentId !== context.intentId ||
                blink_checkout_form.lastIntentExpiryDate !== context.intentExpiryDate ||
                blink_checkout_form.lastCreditContainer !== context.creditContainer ||
                blink_checkout_form.lastPaymentBox !== context.paymentBox
            );
        },

        store_hosted_context: function () {
            var context = blink_checkout_form.get_hosted_context();

            blink_checkout_form.lastIntentId = context.intentId;
            blink_checkout_form.lastIntentExpiryDate = context.intentExpiryDate;
            blink_checkout_form.lastCreditContainer = context.creditContainer;
            blink_checkout_form.lastPaymentBox = context.paymentBox;
        },

        reset_hosted_form_state: function () {
            blink_checkout_form.destroy_hosted_form_instance();
            blink_checkout_form.lastIntentId = '';
            blink_checkout_form.lastIntentExpiryDate = '';
            blink_checkout_form.lastCreditContainer = null;
            blink_checkout_form.lastPaymentBox = null;
        },

        is_stale_hosted_form_error: function (error) {
            var message = error && error.message ? error.message : String(error || '');

            return (
                message.indexOf('postMessage') !== -1 ||
                message.indexOf('null') !== -1 ||
                message.indexOf('iframe') !== -1 ||
                message.indexOf('destroy') !== -1 ||
                message.indexOf('stale') !== -1
            );
        },

        wait: function (ms) {
            return new Promise(function (resolve) {
                window.setTimeout(resolve, ms);
            });
        },
        initialise_hosted_fields: function ($creditContainer) {
            var $hostedForm = blink_checkout_form.get_hosted_form_target();

            if (!$creditContainer.length || !$hostedForm.length || !$.fn.hostedForm || $creditContainer.data('blink-hosted-form-initialising')) {
                return;
            }

            var contextChanged = blink_checkout_form.hosted_context_changed();

            if (contextChanged) {
                blink_checkout_form.reset_hosted_form_state();
            }

            if (!contextChanged && blink_checkout_form.hosted_fields_mounted($creditContainer)) {
                return;
            }

            $creditContainer.data('blink-hosted-form-initialising', true);

            var auto = {
                autoSetup: true,
                autoSubmit: false
            };

            try {
                var hostedForm = blink_checkout_form.get_hosted_form_instance();

                if (hostedForm && typeof hostedForm.autoSetup === 'function') {
                    hostedForm.autoSetup();
                } else {
                    $hostedForm.hostedForm(auto);
                }

                blink_checkout_form.store_hosted_context();
            } catch (error) {
                console.error('Unable to initialise Blink hosted fields:', error);
                $creditContainer.removeData('blink-hosted-form-initialising');
                return;
            }

            window.setTimeout(function () {
                $creditContainer.removeData('blink-hosted-form-initialising');

                if (!blink_checkout_form.hosted_fields_mounted($creditContainer)) {
                    blink_checkout_form.destroy_hosted_form_instance();

                    try {
                        $hostedForm.hostedForm(auto);
                        blink_checkout_form.store_hosted_context();
                    } catch (error) {
                        console.error('Unable to reinitialise Blink hosted fields:', error);
                    }
                }
            }, 500);
        },
        recover_missing_fields: function () {
            if (blink_checkout_form.recoveryAttempts >= 2) {
                return;
            }

            blink_checkout_form.recoveryAttempts++;
            window.setTimeout(function () {
                $(document.body).trigger('update_checkout');
            }, 50);
        },
        update_checkout: function () {
            // Remove the Google Pay and Apple Pay elements if they exist
            const blinkGooglePay = document.querySelector('#blinkGooglePay');
            if (blinkGooglePay) {
                blinkGooglePay.remove();
            }

            const blinkApplePay = document.querySelector('#blinkApplePay');
            if (blinkApplePay) {
                blinkApplePay.remove();
            }

        },
        updated_checkout: function () {

            document.querySelectorAll('#gpay-button-online-api-id').forEach(el => el.remove());

            var paymentMode = $('input[name=payment_method]:checked').val();
            if (paymentMode !== 'blink') {
                blink_checkout_form.recoveryAttempts = 0;
                blink_checkout_form.reset_hosted_form_state();
                return;
            }
            var $form = blink_checkout_form.get_checkout_form();
            var $paymentBox = blink_checkout_form.get_payment_box();
            if (!$form.length || !$paymentBox.length) {
                blink_checkout_form.reset_hosted_form_state();
                return;
            }

            var paymentBy = blink_checkout_form.ensure_payment_by();
            if (!paymentBy && !$paymentBox.find('input[name="switchPayment"], ' + blink_checkout_form.creditContainerSelector + ', #blinkGooglePay, #blinkApplePay').length) {
                blink_checkout_form.recover_missing_fields();
                return;
            }

            if ($form.length && paymentBy == 'credit-card') {
                var $creditContainer = blink_checkout_form.get_credit_container();

                if (!$creditContainer.length) {
                    blink_checkout_form.reset_hosted_form_state();
                    blink_checkout_form.recover_missing_fields();
                    return;
                }

                blink_checkout_form.recoveryAttempts = 0;
                blink_checkout_form.ensure_credit_card_data();

                $('#cc_customer_email').hide();
                $('#cc_customer_postcode').hide();
                $('#cc_customer_address').hide();
                $(".blink-form__label.field-label").each(function () {
                    // Check if the label contains the text "Email"
                    if ($(this).text().trim() === "Email" || $(this).text().trim() === "Address") {
                        // Hide the label
                        $(this).hide();
                    }
                });
                blink_checkout_form.initialise_hosted_fields($creditContainer);

            }

            if (paymentBy && paymentBy !== 'credit-card') {
                blink_checkout_form.recoveryAttempts = 0;
            }

            if ($form.length && paymentBy == 'open-banking') {
                $form.find('input[name=user_name]').val($('input[name="billing_first_name"]').val() + ' ' + $('input[name="billing_last_name"]').val());
                $form.find('input[name=user_email]').val($('input[name="billing_email"]').val());
            }

            if ($form.length && paymentBy == 'direct-debit') {
                $form.find('input[name=given_name]').val($('input[name="billing_first_name"]').val() + ' ' + $('input[name="billing_last_name"]').val());
                $form.find('input[name=email]').val($('input[name="billing_email"]').val());
                $form.find('input[name=family_name]').val();
                $form.find('input[name=account_holder_name]').val();
            }

            if ($form.find('[id="blinkGooglePay"]').length) {
                var scriptElement = document.querySelector('#blinkGooglePay script[src="https://pay.google.com/gp/p/js/pay.js"]');

                if (scriptElement) {

                    // Extract the onload attribute value
                    var onloadValue = scriptElement.getAttribute('onload');
                    // Execute the onload function call
                    setTimeout(function () {
                        try {
                            eval(onloadValue);
                        } catch (err) {

                        }
                    }, 1000);
                } else {
                    console.error('Script element not found.');
                }
            }

            var screen_width = (window && window.screen ? window.screen.width : '0');
            var screen_height = (window && window.screen ? window.screen.height : '0');
            var screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
            var language = (window && window.navigator ? (window.navigator.language ? window.navigator
                .language : window.navigator.browserLanguage) : '');
            var java = (window && window.navigator ? navigator.javaEnabled() : false);
            var timezone = (new Date()).getTimezoneOffset();

            $form.find('input[name=customer_name]').val($('input[name="billing_first_name"]').val() + ' ' + $('input[name="billing_last_name"]').val());
            $form.find('input[name=customer_email]').val($('input[name="billing_email"]').val());
            $form.find('input[name=customer_address]').val($('input[name="billing_address_1"]').val() + ', ' + $('input[name="billing_address_2"]').val());
            $form.find('input[name=customer_postcode]').val($('input[name="billing_postcode"]').val());
            $form.find('input[name=device_timezone]').val(timezone);
            $form.find('input[name=device_capabilities]').val('javascript' + (java ? ',java' : ''));
            $form.find('input[name=device_accept_language]').val(language);
            $form.find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' +
                screen_depth);
            $form.find('input[name=remote_address]').val(blink_params.remoteAddress);
            $form.find('input[name=device_ip_address]').val(blink_params.remoteAddress);

            setupApplePayButtonObserver();

        }
    };

    $(document).on('click', '#gpay-button-online-api-id', function () {
        $('form[name="checkout"]').find('[id="payment_by"]').val('google-pay');
    });


    $('form[name="checkout"]').on('click', '#place_order', async function (e) {
        e.preventDefault();

        var activeTab = blink_checkout_form.ensure_payment_by();
        if (activeTab === 'google-pay' || activeTab === 'apple-pay') {
            activeTab = $('input[name="switchPayment"]:checked').val();
            $('#payment_by').val(activeTab);
        }
        var isCreditCard = activeTab === 'credit-card';

        if (isCreditCard) {

            try {
                var $creditContainer = blink_checkout_form.get_credit_container();
                if (!$.fn.hostedForm || !$creditContainer.length) {
                    alert('There was an issue processing the payment. Please try again.');
                    return false;
                }

                blink_checkout_form.initialise_hosted_fields($creditContainer);

                // Retrieve the payment details
                const activeHostedForm = blink_checkout_form.get_hosted_form_instance();
                if (!activeHostedForm) {
                    alert('There was an issue processing the payment. Please try again.');
                    return false;
                }

                let paymentDetails;

                try {
                    paymentDetails = await activeHostedForm.getPaymentDetails();
                } catch (error) {
                    console.error('Error retrieving payment details:', error);

                    if (!blink_checkout_form.is_stale_hosted_form_error(error)) {
                        throw error;
                    }

                    blink_checkout_form.reset_hosted_form_state();
                    blink_checkout_form.initialise_hosted_fields($creditContainer);
                    await blink_checkout_form.wait(800);

                    const refreshedHostedForm = blink_checkout_form.get_hosted_form_instance();

                    if (!refreshedHostedForm) {
                        throw error;
                    }

                    paymentDetails = await refreshedHostedForm.getPaymentDetails();
                }

                if (!paymentDetails) {
                    alert('There was an issue processing the payment. Please try again.');
                    return false;
                }

                // Check if the payment was successful
                if (paymentDetails.success) {
                    const paymentToken = paymentDetails.paymentToken;
                    if (!paymentToken) {
                        alert('Invalid Payment Token!');
                        return false;
                    }
                    // Set the payment token in a hidden input field (or another required field)
                    const formForToken = blink_checkout_form.get_hosted_form_instance() || activeHostedForm;
                    formForToken.addPaymentToken(paymentToken);

                    blink_checkout_form.ensure_credit_card_data();
                    var $paymentData = blink_checkout_form.collect_credit_card_data();
                    if (!blink_checkout_form.serialized_data_has_value($paymentData, 'paymentToken') && !blink_checkout_form.serialized_data_has_value($paymentData, 'paymenttoken')) {
                        alert('Invalid Payment Token!');
                        return false;
                    }
                    $('#credit-card-data').val($paymentData);

                } else {
                    // Show an alert if payment was unsuccessful
                    alert(paymentDetails.message);
                    return false;
                }
            } catch (error) {
                console.error('Error retrieving payment details:', error);
                alert('There was an issue processing the payment. Please try again.');
                return false;
            }

        }

        blink_checkout_form.get_checkout_form().trigger('submit');

    });



    blink_checkout_form.init();

    if ($(".blink-api-section").width() < 500)
        $('.blink-api-section').addClass('responsive-screen');
    else
        $('.blink-api-section').removeClass('responsive-screen');


    $(document).on('change', 'input[name="payment_method"]', function () {
        $('form.checkout').trigger('update');
    });


    $(document).on('click', 'input[name="switchPayment"]', function () {
        if ($('input#credit-card').is(':checked')) {
            blink_checkout_form.ensure_payment_by();
            $('#payment_by').val('credit-card');
        }
        if ($('input#direct-debit').is(':checked')) {
            blink_checkout_form.ensure_payment_by();
            $('#payment_by').val('direct-debit');
        }
        if ($('input#open-banking').is(':checked')) {
            blink_checkout_form.ensure_payment_by();
            $('#payment_by').val('open-banking');
        }
        $('form.checkout').trigger('update');
    });

});






// Define a function to set up the observer and check for the Apple Pay button
function setupApplePayButtonObserver() {

    function overrideApplePayButtonClicked() {
        // Save a reference to the original onApplePayButtonClicked function
        const originalOnApplePayButtonClicked = window.onApplePayButtonClicked;

        // Override the onApplePayButtonClicked function
        window.onApplePayButtonClicked = function (...args) {
            // Set the value of the hidden input
            $('form[name="checkout"]').find('[id="payment_by"]').val('apple-pay');

            // Call the original function with the provided arguments
            if (typeof originalOnApplePayButtonClicked === 'function') {
                originalOnApplePayButtonClicked.apply(this, args);
            }
        };
    }

    // Function to check if the Apple Pay button script is loaded and the button is present
    function checkApplePayButton() {
        if (typeof window.onApplePayButtonClicked === 'function') {
            observer.disconnect();

            overrideApplePayButtonClicked();
            // Disconnect the observer once the button is found
        }
    }

    // Observe for changes in the document to detect when the Apple Pay button script is loaded
    const observer = new MutationObserver(() => {
        checkApplePayButton();
    });

    // Start observing the document
    observer.observe(document, { childList: true, subtree: true });

}
