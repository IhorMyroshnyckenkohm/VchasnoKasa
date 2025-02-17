$(document).ready(function () {
    const queryParams = new URLSearchParams(window.location.search);
    const route = queryParams.get('route');

    // IDs from controller line 436 / $receipt_ids
    if (typeof ids === "object" && route === 'sale/order') {
        $('tr').has('td span.order-paid').each(function () {
            var checkboxValue = $(this).find('input[type="checkbox"]').val();

            if (Object.values(ids).includes(checkboxValue)) {
                var span = $(this).find('.order-paid');
                var icon = $('<i>').addClass('bi bi-receipt');
                icon.css({
                    'color': 'blue',
                    'margin-left': '3px'
                });

                span.after(icon);
            }
        });
    }

    // Button Receipt Click Event
    $('#button-receipt').on('click', function (e) {
        if ($(this).hasClass('select_device')) {
            e.preventDefault();
            $('#customModal').modal('show');
        }
    });

    // Save Device Click Event
    $('#saveDevice').on('click', function (e) {
        e.preventDefault();
        const deviceSelect = $('#deviceSelect');
        const deviceSelectName = deviceSelect.find('option:selected').text();

        if (!deviceSelect.length) {
            return;
        }

        $.ajax({
            url: 'index.php?route=extension/module/rember_vchkasa/setDevice&user_token=' + getURLVar('user_token'),
            type: 'POST',
            dataType: 'json',
            data: {
                device_id: deviceSelect.val(),
                device_name: deviceSelectName
            },
            success: function (response) {
                if (response.success) {
                    $('#customModal').modal('hide');
                    $('#button-receipt').removeClass('select_device');
                    $('#current_device').text(deviceSelectName);
                    $('#current_device_id').text(deviceSelect.val());
                }
            },
            error: function (xhr, ajaxOptions, thrownError) {
                console.warn(thrownError);
            }
        });
    });

    // Checkbox Change Event
    $('input[name^="selected"]').on('change', function () {
        var selected = $('input[name^="selected"]:checked');
        $('#button-receipt').prop('disabled', selected.length === 0);
    });

    // Add Device Click Event
    $('#add_device').on('click', function () {
        const lastId = $('#devices_container input').length;
        const newId = lastId ? lastId + 1 : 1;
        const template = $('#device_template').html();
        const newElement = template.replace(/%id%/g, newId);

        $('#devices_container').append(newElement);
    });
});