jQuery(function ($) {

    var blink_checkout_form = {
        $form: $('form.woocommerce-checkout'),
        $creditForm: $('form[name="blink-credit"]'),
        init: function () {
            $(document.body).on('updated_checkout', this.updated_checkout);
        },
        updated_checkout: function () {


            var paymentMode = $('input[name=payment_method]:checked').val();
            if(paymentMode !== 'blink'){
                return;
            }
            var paymentBy = blink_checkout_form.$form.find('input[name=payment_by]').val();
            var $form = $('form[name="checkout"]');

            if ($form.length && paymentBy == 'credit-card') {
                var $formCard = $('form[name="blink-credit"]');

                
                $('#cc_customer_email').hide();
                $('#cc_customer_postcode').hide();
                $('#cc_customer_address').hide();
                $(".blink-form__label.field-label").each(function(){
                    // Check if the label contains the text "Email"
                    if ($(this).text().trim() === "Email" || $(this).text().trim() === "Address") {
                        // Hide the label
                        $(this).hide();
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
                $form.find('input[name=user_name]').val($('input[name="billing_first_name"]').val()+ ' ' + $('input[name="billing_last_name"]').val());
                $form.find('input[name=user_email]').val($('input[name="billing_email"]').val());
            }

            if($form.length && paymentBy == 'direct-debit'){
                $form.find('input[name=given_name]').val($('input[name="billing_first_name"]').val()+ ' ' + $('input[name="billing_last_name"]').val());
                $form.find('input[name=email]').val($('input[name="billing_email"]').val());
                $form.find('input[name=family_name]').val();
                $form.find('input[name=account_holder_name]').val();
            }

            if($form.find('[id="blinkGooglePay"]').length){
                var scriptElement = document.querySelector('#blinkGooglePay script[src="https://pay.google.com/gp/p/js/pay.js"]');
                var googleElement = document.querySelector('#gpay-button-online-api-id');
                
                    if (scriptElement) { 
                        if (googleElement) { 
                            googleElement.remove();
                        }

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

            $form.find('input[name=customer_name]').val($('input[name="billing_first_name"]').val()+ ' ' + $('input[name="billing_last_name"]').val());
            $form.find('input[name=customer_email]').val($('input[name="billing_email"]').val());
            $form.find('input[name=customer_address]').val($('input[name="billing_address_1"]').val() + ', ' + $('input[name="billing_address_2"]').val());
            $form.find('input[name=customer_postcode]').val($('input[name="billing_postcode"]').val());
            $form.find('input[name=device_timezone]').val(timezone);
            $form.find('input[name=device_capabilities]').val('javascript' + (java ? ',java' : ''));
            $form.find('input[name=device_accept_language]').val(language);
            $form.find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' +
                screen_depth);
            $form.find('input[name=remote_address]').val(blink_params.remoteAddress);

            setupApplePayButtonObserver();

        }
    };

    $(document).on('click', '#gpay-button-online-api-id', function() {
        $('form[name="checkout"]').find('[id="payment_by"]').val('google-pay');
    });
    

    $('form[name="checkout"]').on('click', '#place_order', async function(e) {
        e.preventDefault();

        var activeTab = $('#payment_by').val();
        var isCreditCard = activeTab === 'credit-card';

        if(isCreditCard){
            
            try {
                // Get the hosted form instance for "blink-credit"
                const hostedForm = window.$('form[name="blink-credit"]').hostedForm('instance');
                console.log(hostedForm);
          
                // Retrieve the payment details
                const paymentDetails = await hostedForm.getPaymentDetails();
                console.log('Payment details:', paymentDetails);
          
                if (paymentDetails) {
                  // Check if the payment was successful
                  if (paymentDetails.success) {
                    const paymentToken = paymentDetails.paymentToken;
                    console.log('Payment token:', paymentToken);
                    // Set the payment token in a hidden input field (or another required field)
                    hostedForm.addPaymentToken(paymentToken);
    
                    $paymentData = $('form[name="blink-credit"]').serialize();
                    $('#credit-card-data').val($paymentData);

                    blink_checkout_form.$form.trigger('submit');
    
                  } else {
                    // Show an alert if payment was unsuccessful
                    alert(paymentDetails.message);
                    return false;
                  }
                }
              } catch (error) {
                console.error('Error retrieving payment details:', error);
                alert('There was an issue processing the payment. Please try again.');
              }

        }

        blink_checkout_form.$form.trigger('submit');            
       
    });

    

    blink_checkout_form.init();

    // When a coupon is applied successfully
    $(document.body).on('applied_coupon', function() {
        location.reload(); // Reload the page
    });

    // If the coupon is removed
    $(document.body).on('removed_coupon', function() {
        location.reload(); // Reload the page
    });
    

    if ($(".blink-api-section").width() < 500)
        $('.blink-api-section').addClass('responsive-screen');
    else
        $('.blink-api-section').removeClass('responsive-screen');

        
        $(document).on('change', 'input[name="payment_method"]', function() {
            $('form.checkout').trigger('update');
        });


        $(document).on('click', 'input[name="switchPayment"]', function() {
                if ($('input#credit-card').is(':checked')) {
                    $('#payment_by').val('credit-card');
                }
                if ($('input#direct-debit').is(':checked')) {
                    $('#payment_by').val('direct-debit');
                }
                if ($('input#open-banking').is(':checked')) {
                    $('#payment_by').val('open-banking');
                }  
            $('form.checkout').trigger('update');
        }); 
        
        $(window).on('load', function() {

            var selectedMethod = $('input[name="payment_method"]:checked').val();
    
            if (selectedMethod === 'blink') {
                $('form.checkout').trigger('update');
            }
    
        });

});

    

    
    

    // Define a function to set up the observer and check for the Apple Pay button
function setupApplePayButtonObserver() {

    function overrideApplePayButtonClicked() {
        // Save a reference to the original onApplePayButtonClicked function
        const originalOnApplePayButtonClicked = window.onApplePayButtonClicked;

        // Override the onApplePayButtonClicked function
        window.onApplePayButtonClicked = function(...args) {
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

