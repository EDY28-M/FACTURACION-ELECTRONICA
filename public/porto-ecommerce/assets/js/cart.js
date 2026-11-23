
// Cart entries come from localStorage / product JSON, which are untrusted. They are rendered only
// through DOM text/attribute APIs, never as HTML strings.
function cartItemImageUrl(file)
{
    // Fixed same-origin prefix: a value can never switch scheme or host.
    if (typeof file !== 'string' || file === '') return null;
    if (/[\u0000-\u001f\\]/.test(file) || /(^|\/)\.\.(\/|$)/.test(file)) return null;
    try {
        return '/storage/uploads/items/' + encodeURI(file);
    } catch (e) {
        return null;
    }
}

function buildCartProductNode(element)
{
    // Quantity stored by the cart page; entries added from the catalogue have none (1 unit).
    let qty = Number(element && element.cantidad);
    qty = Number.isFinite(qty) && qty > 0 ? qty : 1;
    let $title = $('<a>').attr('href', '$').text(element.description);
    let $info = $('<span>').addClass('cart-product-info')
        .append($('<span>').addClass('cart-product-qty').text(String(qty)))
        .append(document.createTextNode(' x ' + element.sale_unit_price));
    let $details = $('<div>').addClass('product-details')
        .append($('<h4>').addClass('product-title').append($title))
        .append($info);

    let $image = $('<img>').attr('alt', 'product');
    let imageUrl = cartItemImageUrl(element.image_small);
    if (imageUrl) $image.attr('src', imageUrl);

    let $figure = $('<figure>').addClass('product-image-container')
        .append($('<a>').attr('href', '#').addClass('product-image').append($image));

    let id = Number(element.id);
    if (Number.isFinite(id)) {
        let $remove = $('<a>').attr('href', '#').addClass('btn-remove').attr('title', 'Remove Product')
            .append($('<i>').addClass('icon-cancel'));
        $remove.on('click', function (event) {
            event.preventDefault();
            remove(id);
        });
        $figure.append($remove);
    }

    return $('<div>').addClass('product').append($details).append($figure);
}

 function cart_add(data) {

    try {

        let array = localStorage.getItem('products_cart');
        array = JSON.parse(array);

        let item = JSON.parse(data)
        let found = array.find(x => x.id == item.id)

        if (!found) {
            array.push(item);
            localStorage.setItem('products_cart', JSON.stringify(array));
            productsCartDropDown();

            jQuery('#moda-succes-add-product').modal('show');

            calculateTotalCart();

            $('#product_added').empty()
                .append($('<h1>').addClass('product-title').text(item.description))
                .append($('<div>').addClass('price-box')
                    .append($('<span>').addClass('product-price').text('S/ ' + Number(item.sale_unit_price).toFixed(2))))
                .append($('<div>').addClass('product-desc').append($('<p>').text(item.name)));

            let $addedImage = $('<img>').addClass('img').attr('alt', 'product');
            let addedImageUrl = cartItemImageUrl(item.image_medium);
            if (addedImageUrl) $addedImage.attr('src', addedImageUrl);
            $('#product_added_image').empty().append($addedImage);
        }
        else {
            jQuery('#modal-already-product').modal('show');
        }

    } catch (error) {
        console.log(error)
    }


}

function productsCartDropDown()
{

	jQuery(".dropdown-cart-products").empty();
	jQuery(".cart-count").empty();
	let count = 0;
	//get data local syrogare prodicts
	let array = localStorage.getItem('products_cart');
	array = JSON.parse(array)
	count = array.length;

	array.forEach(element => {

		jQuery(".dropdown-cart-products").append(buildCartProductNode(element));
	});

    jQuery(".cart-count").append(count);

}


function calculateTotalCart()
{

	let array = localStorage.getItem('products_cart');
	array = JSON.parse(array);
	let total = 0;
	array.forEach(element => {
		let qty = Number(element.cantidad);
		total += parseFloat(element.sale_unit_price) * (Number.isFinite(qty) && qty > 0 ? qty : 1)
	});

	$(".cart-total-price").empty();
    $(".cart-total-price").append(total.toFixed(2));


}

function logout()
{
	$.ajax({
		url: "/ecommerce/logout",
		method: 'post',
		headers: {
			'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
		},
		success: function (data) {
			location.reload()
		},
		error: function (error_data) {

		}
	});
}
