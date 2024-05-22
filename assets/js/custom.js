jQuery(function ($) {

    var blink_checkout_form = {
        $form: jQuery('form.woocommerce-checkout'),
        init: function () {
            jQuery(document.body).on('updated_checkout', this.updated_checkout);
        },
        updated_checkout: function () {


            var paymentMode = jQuery('input[name=payment_method]:checked').val();
            if(paymentMode !== 'blink'){
                return;
            }
            var paymentBy = blink_checkout_form.$form.find('input[name=payment_by]').val();
            var $form = jQuery('form[name="checkout"]');

            if ($form.length && paymentBy == 'credit-card') {
                var $formCard = jQuery('form[name="blink-credit"]');

                
                jQuery('#cc_customer_email').hide();
                jQuery(".blink-form__label.field-label").each(function(){
                    // Check if the label contains the text "Email"
                    if (jQuery(this).text().trim() === "Email") {
                        // Hide the label
                        jQuery(this).hide();
                    }
                });
                var auto = {
                    autoSetup: true,
                    autoSubmit: true,
                };
                try{
                    var hf = $formCard.hostedForm(auto);

                }catch (error) {
                    // Handle any errors that occur during the execution of the try block
                    console.error("An error occurred:", error);
                }
                
            }

            if($form.length && paymentBy == 'open-banking'){
                $form.find('input[name=user_name]').val(jQuery('input[name="billing_first_name"]').val()+ ' ' + jQuery('input[name="billing_last_name"]').val());
                $form.find('input[name=user_email]').val(jQuery('input[name="billing_email"]').val());
            }

            if($form.length && paymentBy == 'direct-debit'){
                $form.find('input[name=given_name]').val(jQuery('input[name="billing_first_name"]').val()+ ' ' + jQuery('input[name="billing_last_name"]').val());
                $form.find('input[name=email]').val(jQuery('input[name="billing_email"]').val());
                $form.find('input[name=family_name]').val();
                $form.find('input[name=account_holder_name]').val();
            }

            if($form.find('[id="blinkGooglePay"]').length){
                var scriptElement = document.querySelector('#blinkGooglePay script[src="https://pay.google.com/gp/p/js/pay.js"]');
        
                    if (scriptElement) {  
                        // Extract the onload attribute value
                        var onloadValue = scriptElement.getAttribute('onload');                        
                        // Execute the onload function call
                        setTimeout(function() {
                            try{
                                eval(onloadValue);
                            }catch(err){
                                
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

            setupApplePayButtonObserver();

        }
    };
    


    var $form1 = jQuery('form[name="checkout"]');
    $form1.on('checkout_place_order', function(event, checkoutForm) {
        var activeTab = jQuery('#payment_by').val();
        var isCreditCard = activeTab === 'credit-card';
        $childform = jQuery('form[name="blink-credit"]');

        if(isCreditCard){
            

            if ($childform.find('[name="paymentToken"]').length > 0) {
                $paymentData = $childform.serialize();
                jQuery('#credit-card-data').val($paymentData);
                // If paymentToken is present, allow the form submission to proceed
                return true;
            } else {
                // If paymentToken is not present, trigger the check_payment_token event
                jQuery(document.body).trigger('check_payment_token');
                return false;
            }

        }
            
       
    });

    
    jQuery(document).ready(function($) {
        var $form = jQuery('form[name="blink-credit"]');
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
                // If paymentToken is found, submit the form
                $form.trigger('submit');
            } else {
                // If paymentToken is not found, log a message (you can customize this)
                console.log('PaymentToken not found. Waiting for it to be added...');
            }
    
            // Set the flag to true to indicate paymentToken has been checked
            paymentTokenChecked = true;
        });
    });
    

    blink_checkout_form.init();
    

    if (jQuery(".blink-api-section").width() < 500)
        jQuery('.blink-api-section').addClass('responsive-screen');
    else
        jQuery('.blink-api-section').removeClass('responsive-screen');

});

    jQuery(document).on('click', '#gpay-button-online-api-id', function() {
        // Your click event handling code goes here
        // For example, you can set the value of another element when this button is clicked
        jQuery('form[name="checkout"]').find('[id="payment_by"]').val('google-pay');
    });

    jQuery(document).on('click', '#apple-pay-btn', function() {
        // Your click event handling code goes here
        // For example, you can set the value of another element when this button is clicked
        jQuery('form[name="checkout"]').find('[id="payment_by"]').val('apple-pay');
    });

    jQuery(document).on('click', '.apple-pay-btn', function() {
        // Your click event handling code goes here
        // For example, you can set the value of another element when this button is clicked
        jQuery('form[name="checkout"]').find('[id="payment_by"]').val('apple-pay');
    });

    jQuery(document).on('click', '#place_order', function() {
        // Your click event handling code goes here
        // For example, you can set the value of another element when this button is clicked
        var payment_by = jQuery('form[name="checkout"]').find('[id="payment_by"]').val();
        if('credit-card' === payment_by){
            jQuery('#blink-credit-submit').click();

        }
    });

    jQuery(document).on('change', 'input[name="payment_method"]', function() {
        jQuery('form.checkout').trigger('update');
    });

    jQuery(document).on('click', '#selectBatch', function() {
            if ($('input#credit-card').is(':checked')) {
                jQuery('#payment_by').val('credit-card');
            }
            if ($('input#direct-debit').is(':checked')) {
                jQuery('#payment_by').val('direct-debit');
            }
            if ($('input#open-banking').is(':checked')) {
                jQuery('#payment_by').val('open-banking');
            }  
        jQuery('form.checkout').trigger('update');
    });
    

// Define a function to set up the observer and check for the Apple Pay button
function setupApplePayButtonObserver() {

    function overrideApplePayButtonClicked() {
        // Save a reference to the original onApplePayButtonClicked function
        const originalOnApplePayButtonClicked = window.onApplePayButtonClicked;

        // Override the onApplePayButtonClicked function
        window.onApplePayButtonClicked = function(...args) {
            // Set the value of the hidden input
            jQuery('form[name="checkout"]').find('[id="payment_by"]').val('apple-pay');

            // Call the original function with the provided arguments
            if (typeof originalOnApplePayButtonClicked === 'function') {
                originalOnApplePayButtonClicked.apply(this, args);
            }
        };
    }

    // Function to check if the Apple Pay button script is loaded and the button is present
    function checkApplePayButton() {
        if (typeof window.onApplePayButtonClicked === 'function') {
            overrideApplePayButtonClicked();
            // Disconnect the observer once the button is found
            observer.disconnect();
        }
    }

    // Observe for changes in the document to detect when the Apple Pay button script is loaded
    const observer = new MutationObserver(() => {
        checkApplePayButton();
    });

    // Start observing the document
    observer.observe(document, { childList: true, subtree: true });

    // Check for the Apple Pay button immediately in case it's already present
    checkApplePayButton();

    // If the Apple Pay button is not found after a certain time, disconnect the observer
    setTimeout(() => {
        if (typeof window.onApplePayButtonClicked !== 'function') {
            observer.disconnect();
        }
    }, 2000); // Adjust the timeout value as needed
}

