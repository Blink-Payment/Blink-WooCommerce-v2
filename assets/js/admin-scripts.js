jQuery(function ($) {
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

                $.ajax({
                    url: blinkOrders.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'generate_access_token',
                        security: blinkOrders.security,
                    },
                    success: function(response) {
                        if (response.success) {
                            var accessToken = response.data.access_token;
                            enableApplePay(accessToken);
                        } else {
                            alert('Failed to generate access token: ' + response.data.message);
                        }
                    },
                    error: function(response) {
                        alert('AJAX request failed');
                    }
                });
            });

            function enableApplePay(accessToken){
                var domain = window.location.hostname;
                $.ajax({
                    url: blinkOrders.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'generate_applepay_domains',
                        security: blinkOrders.apple_security,
                        token: accessToken,
                        domain: "https://" + domain
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(domain + ' has been successfully registered with Apple Pay.');
                            $('#woocommerce_blink_apple_pay_enabled').prop('checked', true).change(); // Adjust the ID as needed
                        } else {
                            alert(response.data.message + 'Please ensure the DVF file has been uploaded to https://' + domain + '/.well-known/apple-developer-merchantid-domain-association');
                        }
                    },
                    error: function() {
                        alert('Please ensure the DVF file has been uploaded to https://' + domain + '/.well-known/apple-developer-merchantid-domain-association');
                    }
                });
            }
        });
});
