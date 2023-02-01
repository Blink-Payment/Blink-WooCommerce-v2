jQuery(function($){

    var blink_checkout_form = {
        $form: jQuery( 'form.woocommerce-checkout' ),
        init: function() {
            this.checkoutPlaceOrder();    
            jQuery( document.body ).on( 'updated_checkout', this.updated_checkout );
        },
        updated_checkout: function() {    
            var auto = {
                autoSetup: true,
                autoSubmit: true,
            };
            blink_checkout_form.$form.hostedForm(auto);
            var screen_width = (window && window.screen ? window.screen.width : '0');
            var screen_height = (window && window.screen ? window.screen.height : '0');
            var screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
            var language = (window && window.navigator ? (window.navigator.language ? window.navigator
                .language : window.navigator.browserLanguage) : '');
            var java = (window && window.navigator ? navigator.javaEnabled() : false);
            var timezone = (new Date()).getTimezoneOffset();
            
            blink_checkout_form.$form.find('input[name=device_timezone]').val(timezone);
            blink_checkout_form.$form.find('input[name=device_capabilities]').val('javascript' + (java ? ',java' : ''));
            blink_checkout_form.$form.find('input[name=device_accept_language]').val(language);
            blink_checkout_form.$form.find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' +
                screen_depth);
            
        },
        init_checkout: function() {
            $( document.body ).trigger( 'update_checkout' );
        },
        checkoutPlaceOrder: function(data) {
            blink_checkout_form.$form.on( 'checkout_place_order', blink_checkout_form.tokenRequest );
        },
        successCallback: function(data) {        
            // deactivate the tokenRequest function event
            blink_checkout_form.$form.off( 'checkout_place_order', blink_checkout_form.tokenRequest );
            // submit the form now
            blink_checkout_form.$form.submit();
        
        },
        tokenRequest: function(data) {
        
            var paymentBy = blink_checkout_form.$form.find('input[name=payment_by]').val();
        
            if(paymentBy == 'credit-card'){
                var payToken = blink_checkout_form.$form.find('input[name=paymentToken]').val();
        
                if(payToken){
                    console.log('token added');
                    blink_checkout_form.successCallback();
                } 
            }else{
                blink_checkout_form.successCallback();
            }   
            
            return false;
                
        }
    };

    blink_checkout_form.init();
    
});



  
// Resize Function
jQuery(document).ready(blinkfunction);
jQuery(window).on('resize', blinkfunction);

function blinkfunction() {
   // do whatever
   if (jQuery(".blink-api-section").width() < 500)
       jQuery('.blink-api-section').addClass('responsive-screen');
   else
       jQuery('.blink-api-section').removeClass('responsive-screen');
}








         
     
