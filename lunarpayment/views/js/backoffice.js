/** Check if page was completely loaded. */
 function onReady(method) {
    var readyStateCheckInterval = setInterval(function() {
        if (document && document.readyState === 'complete') {
            clearInterval(readyStateCheckInterval);
            method();
        }
    }, 10);
}

/** Call function when document is ready. */
onReady(lunarSettings);

/**
 * Add & change some html elements
 * Add change logos & change language even listeners
 */
function lunarSettings() {
    var html = '<a href="#" class="add-more-btn" data-toggle="modal" data-target="#logoModal"><i class="process-icon-plus" data-toggle="tooltip" title="Add your own logo"></i></a>';
    jQuery('select[name="LUNAR_PAYMENT_METHOD_CREDITCARD_LOGO[]"]').parent('div').append(html);
    jQuery('[data-toggle="tooltip"]').tooltip();

    jQuery('.lunar-config').each(function(index, item){
        if( jQuery(item).hasClass('has-error') ) {
            jQuery(item).parents('.form-group').addClass('has-error');
        }
    });

    jQuery('.lunar-language').on('change', lunarLanguageChange);

    jQuery('#logo_form').on('submit', ajaxSaveLogo);
}

/**
 * Change Language
 */
function lunarLanguageChange(e){
    var lang_code = jQuery(e.currentTarget).val();
    window.location = admin_orders_uri + "&change_language&lang_code="+lang_code;
}

/**
 * Save newly added logos
 */
function ajaxSaveLogo(e) {
    e.preventDefault();
    jQuery('#save_logo').button('loading');
    jQuery('#alert').html("").hide();
    var url = jQuery('#logo_form').attr('action');
    console.log(url);
    //grab all form data
    var formData = new FormData(jQuery(this)[0]);
    jQuery.ajax({
        url : url,
        type : 'POST',
        data : formData,
        dataType : 'json',
        async: false,
        cache: false,
        contentType: false,
        processData: false,
        success : function(response) {
            console.log(response);
            jQuery('#save_logo').button('reset');
            if(response.status == 0) {
                var html = "<strong>Error !</strong> " + response.message;
                jQuery('#alert').html(html)
                    .show()
                    .removeClass('alert-success')
                    .removeClass('alert-danger')
                    .addClass('alert-danger');
            } else if(response.status == 1) {
                var html = "<strong>Success !</strong> " + response.message;
                jQuery('#alert').html(html)
                    .show()
                    .removeClass('alert-success')
                    .removeClass('alert-danger')
                    .addClass('alert-success');

                window.location = window.location;
            }
        },
        error : function(response) {
            console.log(response);
        },
    });

    return false;
}