jQuery(function ($) {
    // Preauthorization confirmation popup
    jQuery(document).ready(function ($) {
        // Handle preauthorization checkbox toggle
        $('input[data-preauth-toggle="true"]').on('change', function() {
            if ($(this).is(':checked')) {
                showPreauthConfirmation();
            }
        });

        function showPreauthConfirmation() {
            // Create modal overlay
            var modal = $('<div class="blink-preauth-modal-overlay"></div>');
            var modalContent = $('<div class="blink-preauth-modal-content"></div>');
            
            modalContent.html(`
                <div class="blink-preauth-modal-header">
                    <h3>Enable preauthorisation and manual capture</h3>
                </div>
                <div class="blink-preauth-modal-body">
                    <p>Enabling this the customer will Preauthorise the order at checkout, the payment is processed manually when processing the order.</p>
                    <p><strong>This is only available for card payments.</strong></p>
                    <div class="blink-preauth-warning">
                        <p><strong>Note:</strong> When preauthorization is enabled:</p>
                        <ul>
                            <li>Open Banking will be disabled</li>
                            <li>Direct Debit will be disabled</li>
                            <li>Only credit card payments will be available</li>
                        </ul>
                    </div>
                </div>
                <div class="blink-preauth-modal-footer">
                    <button type="button" class="button button-secondary blink-preauth-cancel">Cancel</button>
                    <button type="button" class="button button-primary blink-preauth-confirm">Confirm</button>
                </div>
            `);
            
            modal.append(modalContent);
            $('body').append(modal);
            
            // Handle cancel
            $('.blink-preauth-cancel').on('click', function() {
                $('input[data-preauth-toggle="true"]').prop('checked', false);
                modal.remove();
            });
            
            // Handle confirm
            $('.blink-preauth-confirm').on('click', function() {
                // Disable open banking and direct debit
                disableNonCardPaymentMethods();
                modal.remove();
            });
        }

        function disableNonCardPaymentMethods() {
            // Disable open banking and direct debit checkboxes
            $('input[name*="open-banking"]').prop('checked', false).prop('disabled', true);
            $('input[name*="direct-debit"]').prop('checked', false).prop('disabled', true);
            
            // Enable only credit card
            $('input[name*="credit-card"]').prop('checked', true).prop('disabled', false);
            
            // Show admin notice
            showPreauthAdminNotice();
        }

        function showPreauthAdminNotice() {
            var notice = $('<div class="notice notice-info is-dismissible"><p><strong>Preauthorization Enabled:</strong> Open Banking and Direct Debit have been disabled. Only credit card payments are available.</p></div>');
            $('.woocommerce-save-button').before(notice);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut();
            }, 5000);
        }

        // Handle unchecking preauthorization
        $('input[data-preauth-toggle="true"]').on('change', function() {
            if (!$(this).is(':checked')) {
                // Re-enable all payment methods
                $('input[name*="open-banking"]').prop('disabled', false);
                $('input[name*="direct-debit"]').prop('disabled', false);
                $('input[name*="credit-card"]').prop('disabled', false);
            }
        });
    });

    // JavaScript code to handle cancel transaction AJAX call
    jQuery(document).ready(function ($) {
        // Function to handle cancel transaction AJAX call
        $(".cancel-order").on("click", function (e) {
            e.preventDefault();
            $(this).after(
                `<div class="loader-container"><img src="${blinkOrders.spin_gif}" alt="Processing..."></div>`
            );
            var orderId = $(this).data("order-id");
            var data = {
                action: "cancel_transaction",
                cancel_order: blinkOrders.cancel_order,
                order_id: orderId,
            };

            // AJAX call to cancel transaction
            $.post(blinkOrders.ajaxurl, data, function (response) {
                if(typeof response === "string") response = JSON.parse(response);
                $(".loader-container").remove();
                if (response.success) {
                    // Reload page or perform other actions
                    location.reload();
                } else {
                    // Handle error
                    console.log(response);
                    alert(response.data.message ? "Failed to cancel Transaction: " + response.data.message : response.data);
                }
            });
        });
    });

    jQuery(document).ready(function($) {
        $('#enable-apple-pay').on('click', function(e) {
            e.preventDefault();
            $(this).after(
                `<div class="loader-container"><img src="${blinkOrders.spin_gif}" alt="Processing..."></div>`
            );

                $.ajax({
                    url: blinkOrders.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'blink_generate_access_token',
                        security: blinkOrders.security,
                    },
                    success: function(response) {
                        if(typeof response === "string") response = JSON.parse(response);
                        if (response.success) {
                            var accessToken = response.data.access_token;
                            enableApplePay(accessToken);
                        } else {
                            alert('Failed to generate access token: ' + response.data.message);
                            $(".loader-container").remove();
                        }
                    },
                    error: function(response) {
                        alert('AJAX request failed');
                        $(".loader-container").remove();

                    }
                });
            });

            function enableApplePay(accessToken){
                var domain = window.location.hostname;
                $.ajax({
                    url: blinkOrders.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'blink_generate_applepay_domains',
                        security: blinkOrders.apple_security,
                        token: accessToken,
                        domain: "https://" + domain
                    },
                    success: function(response) {
                        if(typeof response === "string") response = JSON.parse(response);
                        if (response.success) {
                            alert(domain + ' has been successfully registered with Apple Pay.');
                            $('#woocommerce_blink_apple_pay_enabled').prop('checked', true).prop('disabled', false).change(); // Adjust the ID as needed

                        } else {
                            alert(response.data.message + ' Please ensure the DVF file has been uploaded to https://' + domain + '/.well-known/apple-developer-merchantid-domain-association');

                        }
                        $(".loader-container").remove();

                    },
                    error: function() {
                        alert('Please ensure the DVF file has been uploaded to https://' + domain + '/.well-known/apple-developer-merchantid-domain-association');
                        $(".loader-container").remove();

                    }
                });
            }
        });
});
