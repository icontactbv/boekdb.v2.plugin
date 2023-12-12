jQuery(document).ready(function($) {
    $(document).on( 'click', '.notice-dismiss', function() {
        $.ajax({
            url: boekdb_admin.ajax_url,
            data: {
                action: 'dismiss_boekdb_update_notice',
                nonce:  boekdb_admin.nonce
            },
        });
    });
});