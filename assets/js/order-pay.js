jQuery(function ($) {

    const targetDiv = '.payment_box.payment_method_blink';
    const creditForm = 'form[name="blink-credit"]';
    const orderForm = 'form[id="order_review"]';

    $(window).on('load', function() {

        var selectedMethod = $('input[name="payment_method"]:checked').val();

        if (selectedMethod === 'blink') {
            getFields(selectedMethod, '');
        }

    });

    // Listen to the change event of payment method selection
    $('form#order_review').on('change', 'input[name="payment_method"]', function() {
        var selectedMethod = $('input[name="payment_method"]:checked').val();
        getFields(selectedMethod, '');
        
    });

    $(document).on('change', 'input[name="switchPayment"]', function() {
        var paymentBy = '';
        var selectedMethod = $('input[name="payment_method"]:checked').val();

        if ($('input#credit-card').is(':checked')) {
            paymentBy = 'credit-card';
        }
        if ($('input#direct-debit').is(':checked')) {
            paymentBy = 'direct-debit';
        }
        if ($('input#open-banking').is(':checked')) {
            paymentBy = 'open-banking';
        } 

        getFields(selectedMethod, paymentBy);

    });

    const getFields = (selectedMethod = '', paymentBy = '') => {
        $(orderForm).block({ 
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });

        if (selectedMethod === 'blink') {
            // Prepare AJAX request
            $.ajax({
                url: blink_params.ajaxurl,
                type: 'POST',
                data: {
                    action: 'blink_payment_fields',
                    payment_method: selectedMethod,
                    payment_by: paymentBy
                },
                success: function(response) {
                    if (response.success) {
                        $.when( effect(response.data.html) ).done(function() {
                            jQuery(document.body).trigger('update');
                        });
                    } else {
                        console.error('Failed to load payment fields.');
                    }
                },
                error: function(error) {
                    console.error('AJAX error:', error);
                }

            });
        }

    }

    $(document.body).on('update', function() {
        
        var paymentBy = $(this).find('input[name=payment_by]').val();

        var screen_width = (window && window.screen ? window.screen.width : '0');
        var screen_height = (window && window.screen ? window.screen.height : '0');
        var screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
        var language = (window && window.navigator ? (window.navigator.language ? window.navigator
            .language : window.navigator.browserLanguage) : '');
        var java = (window && window.navigator ? navigator.javaEnabled() : false);
        var timezone = (new Date()).getTimezoneOffset();

         $(this).find('input[name=customer_name]').val(order_params.billing_first_name + ' ' + order_params.billing_last_name );
         $(this).find('input[name=customer_email]').val(order_params.billing_email );
         $(this).find('input[name=customer_address]').val(order_params.billing_address_1  + ', ' + order_params.billing_address_2 );
         $(this).find('input[name=customer_postcode]').val(order_params.billing_postcode );
         $(this).find('input[name=device_timezone]').val(timezone);
         $(this).find('input[name=device_capabilities]').val('javascript' + (java ? ',java' : ''));
         $(this).find('input[name=device_accept_language]').val(language);
         $(this).find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' +
            screen_depth);
         $(this).find('input[name=remote_address]').val(blink_params.remoteAddress);

         setupApplePayButtonObserver();

        if (paymentBy == 'credit-card') {
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
                stylesheets: '#hostedfield-stylesheet',
                classes: {
                    invalid: 'error'
                }
            };
            try{
                var hf = $formCard.hostedForm(auto);

            }catch (error) {
                // Handle any errors that occur during the execution of the try block
                console.error("An error occurred:", error);
            }
            
        }

        if($(this).find('[id="blinkGooglePay"]').length){
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

        $(orderForm).unblock(); 

    });

    $(document).on('click', '#place_order', async function(e) {
        e.preventDefault();
        $(orderForm).block({ 
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });
        var paymentBy = $(orderForm).find('input[name=payment_by]').val();

        if(paymentBy === 'credit-card'){

            try {
                // Get the hosted form instance for "blink-credit"
                const hostedForm = window.jQuery(creditForm).hostedForm('instance');
          
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
    
                    $paymentData = jQuery(creditForm).serialize();
                    $('#credit-card-data').val($paymentData);
    
                    // Submit the main form
                    document.querySelector(orderForm).submit();
                  } else {
    
                    $(orderForm).unblock(); 
    
                    // Show an alert if payment was unsuccessful
                    alert(paymentDetails.message);
                  }
                }
              } catch (error) {
                $(orderForm).unblock(); 
    
                console.error('Error retrieving payment details:', error);
                alert('There was an issue processing the payment. Please try again.');
              }

        } else {
            document.querySelector(orderForm).submit();
        }

        
    });


    var effect = function(html) {
        return $(targetDiv).empty().html(html);;
    };
    
    jQuery(document).on('click', '#gpay-button-online-api-id', function() {
        jQuery(orderForm).find('[id="payment_by"]').val('google-pay');
    });
    
        // Define a function to set up the observer and check for the Apple Pay button
    function setupApplePayButtonObserver() {
    
        function overrideApplePayButtonClicked() {
            // Save a reference to the original onApplePayButtonClicked function
            const originalOnApplePayButtonClicked = window.onApplePayButtonClicked;
    
            // Override the onApplePayButtonClicked function
            window.onApplePayButtonClicked = function(...args) {
                // Set the value of the hidden input
                jQuery(orderForm).find('[id="payment_by"]').val('apple-pay');
    
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
    

});



