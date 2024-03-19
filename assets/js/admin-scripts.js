jQuery(function ($) {
    // JavaScript code to handle cancel transaction AJAX call
    jQuery(document).ready(function ($) {
        // Function to handle cancel transaction AJAX call
        $(".cancel-order").on("click", function (e) {
            e.preventDefault();
            $(this).after(
                `<div class="loader-container"><img src="${blinkOrders.spin_gif}" alt="Processing.."></div>`
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
});
