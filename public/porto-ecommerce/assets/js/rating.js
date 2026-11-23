function sendRating(value, id)
{
    var data = {
        value: value,
        item_id: Number(id),
    }

    $.ajax({
        url: '/ecommerce/rating_item',
        method: 'post',
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        data: data,
        dataType: 'JSON',
        success: function (data) {
            if (!data.success) {
                // Only signed-in buyers can rate: clear the stars and offer the login form.
                $('.rating__control').prop('checked', false);
                $('#login_register_modal').modal('show');
            }
        },
        error: function (error_data) {
            $('.rating__control').prop('checked', false);
        }
    });
}

function getRating(id)
{
   

    $.ajax({
        url: '/ecommerce/rating_item/' + id,
        method: 'get',
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        dataType: 'JSON',
        success: function (data) {
            let val = data.value
            switch (val) {
                case 1:

                        $("#rc6").prop("checked", true);
                    
                    break;
                case 2:

                        $("#rc7").prop("checked", true);
                    
                    break;
                case 3:
                        $("#rc8").prop("checked", true);
                    
                    break;
                case 4:
                        $("#rc9").prop("checked", true);
                    
                    break;
                case 5:
                    $("#rc10").prop("checked", true);
                    break;
            
            
            }


        },
        error: function (error_data) {
        }
    });
}