jQuery(function($){

    var blink_checkout_form = {
        $form: jQuery( 'form.woocommerce-checkout' ),
        init: function() {
            jQuery( document.body ).on( 'updated_checkout', this.updated_checkout );
        },
        updated_checkout: function() {  
            
            var paymentMode = jQuery('input[name=payment_method]:checked').val();
            if(paymentMode == 'blink')
            {
                jQuery('#place_order').hide();
            }else{
                jQuery('#place_order').show();

            }
            
            var paymentBy = blink_checkout_form.$form.find('input[name=payment_by]').val();
            console.log(paymentBy);
        
            if(paymentBy != ''){
                blink_checkout_form.$form.submit();
                return;
            }  
              
            
        }
    };

    blink_checkout_form.init();
    jQuery(document).ready(function () {
        var $form = $('#blink-card');
        var auto = {
        autoSetup: true,
        autoSubmit: true,
        };
        try {
        var hf = $form.hostedForm(auto);
        var screen_width = (window && window.screen ? window.screen.width : '0');
        var screen_height = (window && window.screen ? window.screen.height : '0');
        var screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
        var language = (window && window.navigator ? (window.navigator.language ? window.navigator
            .language : window.navigator.browserLanguage) : '');
        var java = (window && window.navigator ? navigator.javaEnabled() : false);
        var timezone = (new Date()).getTimezoneOffset();
        
        $form.find('input[name=device_timezone]').val(timezone);
        $form.find('input[name=device_capabilities]').val('javascript' + (java ? ',java' : ''));
        $form.find('input[name=device_accept_language]').val(language);
        $form.find('input[name=device_screen_resolution]').val(screen_width + 'x' + screen_height + 'x' +
            screen_depth);
        } catch(e) {
        //Add your exception handling code here
        }
        
        });
    
    if (jQuery(".blink-api-section").width() < 500)
    jQuery('.blink-api-section').addClass('responsive-screen');
    else
    jQuery('.blink-api-section').removeClass('responsive-screen');

    jQuery('form.checkout').on('change', 'input[name="payment_method"]', function(){
        var paymentMode = jQuery('input[name=payment_method]:checked').val();
            if(paymentMode == 'blink')
            {
                jQuery('#place_order').hide();
            }else{
                jQuery('#place_order').show();

            }
    });
     
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


var updatePaymentBy = function(data) {
    jQuery('#payment_by').val(data);
    jQuery( document.body ).trigger( 'update_checkout' );

}





  











         
     
