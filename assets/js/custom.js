jQuery(function ($) {

    var blink_checkout_form = {
        $form: jQuery('form.woocommerce-checkout'),
        init: function () {
            jQuery(document.body).on('updated_checkout', this.updated_checkout);
        },
        updated_checkout: function () {

            var paymentMode = jQuery('input[name=payment_method]:checked').val();
            var paymentBy = blink_checkout_form.$form.find('input[name=payment_by]').val();

            console.log(paymentBy);
            console.log('i m here now');
            var $form = jQuery('form[name="checkout"]');
            console.log($form.length);
            if ($form.length && paymentBy == 'credit-card') {
                var $form = jQuery('form[name="blink-credit"]');

                
                jQuery('#cc_customer_email').hide();
                jQuery(".blink-form__label.field-label").each(function(){
                    // Check if the label contains the text "Email"
                    if (jQuery(this).text().trim() === "Email") {
                        // Hide the label
                        jQuery(this).hide();
                    }
                });
                //cc_customer_email
                console.log('hit2');
                var auto = {
                    autoSetup: true,
                    autoSubmit: true,
                };
                try{
                    var hf = $form.hostedForm(auto);
                    console.log(hf);
                    //var hfs = $form.hostedForm('destroy');

                    //var hf = $form.hostedForm(auto);


                }catch (error) {
                    // Handle any errors that occur during the execution of the try block
                    console.error("An error occurred:", error);
                }
                var screen_width = (window && window.screen ? window.screen.width : '0');
                var screen_height = (window && window.screen ? window.screen.height : '0');
                var screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
                var language = (window && window.navigator ? (window.navigator.language ? window.navigator
                    .language : window.navigator.browserLanguage) : '');
                var java = (window && window.navigator ? navigator.javaEnabled() : false);
                var timezone = (new Date()).getTimezoneOffset();

                $form.find('input[name=customer_name]').val(jQuery('input[name="billing_first_name"]').val()+ ' ' + jQuery('input[name="billing_last_name"]').val());
                $form.find('input[name=customer_email]').val(jQuery('input[name="billing_email"]').val());
                $form.find('input[name=customer_address]').val(jQuery('input[name="billing_address_1"]').val() + ', ' + jQuery('input[name="billing_address_2"]').val());
                $form.find('input[name=customer_postcode]').val(jQuery('input[name="billing_postcode"]').val());
                $form.find('input[name=device_timezone]').val(timezone);
                $form.find('input[name=device_capabilities]').val('javascript' + (java ? ',java' : ''));
                $form.find('input[name=device_accept_language]').val(language);
                $form.find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' +
                    screen_depth);
                $form.find('input[name=remote_address]').val(blink_params.remoteAddress);
            }

            if($form.length && paymentBy == 'open-banking'){

                $form.find('input[name=user_name]').val(jQuery('input[name="billing_first_name"]').val()+ ' ' + jQuery('input[name="billing_last_name"]').val());
                $form.find('input[name=user_email]').val(jQuery('input[name="billing_email"]').val());
                $form.find('input[name=customer_name]').val(jQuery('input[name="billing_first_name"]').val()+ ' ' + jQuery('input[name="billing_last_name"]').val());
                $form.find('input[name=customer_email]').val(jQuery('input[name="billing_email"]').val());

                $form.find('input[name=customer_address]').val(jQuery('input[name="billing_address_1"]').val() + ', ' + jQuery('input[name="billing_address_2"]').val());
                $form.find('input[name=customer_postcode]').val(jQuery('input[name="billing_postcode"]').val());
            }

            if($form.length && paymentBy == 'direct-debit'){
                $form.find('input[name=given_name]').val(jQuery('input[name="billing_first_name"]').val()+ ' ' + jQuery('input[name="billing_last_name"]').val());
                $form.find('input[name=email]').val(jQuery('input[name="billing_email"]').val());
                $form.find('input[name=family_name]').val();
                $form.find('input[name=account_holder_name]').val();
                $form.find('input[name=customer_address]').val(jQuery('input[name="billing_address_1"]').val() + ', ' + jQuery('input[name="billing_address_2"]').val());
                $form.find('input[name=customer_postcode]').val(jQuery('input[name="billing_postcode"]').val());
            }

            console.log('dasdasdsadasdsads');
            //onGooglePayLoaded('TEST','140841','BCR2DN4TYCZ6ZYTD','wpdev.local','Product','eyJ0eXAiOiJKV1QiLCJhbGciOiJFUzI1NiJ9.eyJtZXJjaGFudE9yaWdpbiI6IndwZGV2LmxvY2FsIiwibWVyY2hhbnRJZCI6IkJDUjJETjRUWUNaNlpZVEQiLCJpYXQiOjE3MTUwNzI1MTl9.zYmnNGXleH7uqaRUjqT8LxqnsrZpj0uk_aPyYorz9Ojnj4kZgURHY6AYiSQQOAmTYyIuc9dy4X0lGijTnAIMAQ','GBP','18');


        }
    };

    var $form1 = jQuery('form[name="checkout"]');
    $form1.on('checkout_place_order', function(event, checkoutForm) {
        var activeTab = jQuery('.blink-pay-options.active');
        var isCreditCard = activeTab.data('tab') === 'credit-card';

        console.log('check',isCreditCard);

        if(isCreditCard){


            if ($form1.find('[name="paymentToken"]').length > 0) {
                console.log('get payment token');
                $childform = jQuery('form[name="blink-credit"]');
                $paymentData = $childform.serialize();
                jQuery('#credit-card-data').val($paymentData);
                console.log($paymentData);
                console.log($form1.serialize());
                // If paymentToken is present, allow the form submission to proceed
                return true;
            } else {
                // If paymentToken is not present, trigger the check_payment_token event
                console.log('gourab', checkoutForm);
                jQuery(document.body).trigger('check_payment_token');
                return false;
            }

        }
            
       
    });

    
    jQuery(document).ready(function($) {
        var $form = jQuery('form[name="checkout"]');
        var paymentTokenChecked = false; // Flag to track if paymentToken has been checked
    
        // Define a custom asynchronous event handler to check for paymentToken
        jQuery(document.body).on('check_payment_token', async function() {
            // Check if paymentToken has already been checked
            if (paymentTokenChecked) {
                return; // If already checked, exit the function to prevent rechecking
            }
    
            // Simulate an asynchronous operation to check for paymentToken
            await new Promise(resolve => setTimeout(resolve, 2000)); // Example: Wait for 2 seconds
    
            // Check if the form contains paymentToken
            if ($form.find('[name="paymentToken"]').length > 0) {
                console.log($form.find('[name="paymentToken"]').val());
                // If paymentToken is found, submit the form
                $form.trigger('submit');
            } else {
                // If paymentToken is not found, log a message (you can customize this)
                console.log('PaymentToken not found. Waiting for it to be added...');
                
    
            // Set the flag to true to indicate paymentToken has been checked
            paymentTokenChecked = true;
        });
    });
    

    blink_checkout_form.init();

    if (jQuery(".blink-api-section").width() < 500)
        jQuery('.blink-api-section').addClass('responsive-screen');
    else
        jQuery('.blink-api-section').removeClass('responsive-screen');

    jQuery('form.checkout').on('change', 'input[name="payment_method"]', function () {
        var paymentMode = jQuery('input[name=payment_method]:checked').val();
        // can call other method or remove
    });
});


var updatePaymentBy = function (method) {
    // if( blink_params.card === '1'){
    //     var redirectURL = blink_params.checkout_url;
    //     redirectURL += '&payment_by=' + method;
    //     window.location.href = redirectURL;
    // }else{

        jQuery('#payment_by').val(method);
        jQuery( 'form.checkout' ).trigger('update');
    //}

}

// $("#googlePayToken").closest("form").submit(function(event) {
//     // Prevent the default form submission behavior
//     event.preventDefault();

//     // Perform any necessary actions before submitting the form
//     // For example, you might want to validate the form fields or display a loading spinner

//     // Then submit the form
//     //$(this).submit();
//     alert('hit');

//     return false;
// });


