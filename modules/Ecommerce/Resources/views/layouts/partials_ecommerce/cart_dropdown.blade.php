<div class="dropdown cart-dropdown">
    <a href="#" class="dropdown-toggle" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" data-display="static">
        <i class="icon-bag-2 fa-2x text-white"></i>
        <span class="cart-count">0</span>
    </a>
    <div class="dropdown-menu">
        <div class="dropdownmenu-wrapper">

            <div class="dropdown-cart-products">

            </div><!-- End .cart-product -->

            <div class="dropdown-cart-total">
                <span>Total</span>

                <span class="cart-total-price">S/ 0</span>
            </div><!-- End .dropdown-cart-total -->

            <div class="dropdown-cart-action">
                <a  href="{{ route('tenant_detail_cart') }}" class="btn">Ver Carrito</a>
                <!--<a href="#" class="btn">Checkout</a> -->
            </div><!-- End .dropdown-cart-total -->
        </div><!-- End .dropdownmenu-wrapper -->
    </div><!-- End .dropdown-menu -->
</div><!-- End .dropdown -->


@push('scripts')
<script type="text/javascript">

	function remove(id)
	{
		
		let array = localStorage.getItem('products_cart');
		array = JSON.parse(array);
		let indexFound = array.findIndex( x=> x.id == id)
		array.splice(indexFound, 1);
		localStorage.setItem('products_cart', JSON.stringify( array ) );
		populate();
		calculatetotal();
	
	}

	function calculatetotal()
	{
		let array = localStorage.getItem('products_cart');
		array = JSON.parse(array);
		let total = 0;
		array.forEach(element => {
			total += parseFloat(element.sale_unit_price)
		});

		$(".cart-total-price").empty();
		$(".cart-total-price").append(total.toFixed(2));

	}

	// Cart entries come from localStorage, which any script on this origin can tamper with.
	// They are rendered only through DOM text/attribute APIs, never as HTML strings.
	function cartImageUrl(file)
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

	function buildCartDropdownItem(element)
	{
		let $title = $('<a>').attr('href', '$').text(element.description);
		let $info = $('<span>').addClass('cart-product-info')
			.append($('<span>').addClass('cart-product-qty').text('1'))
			.append(document.createTextNode(' x ' + element.sale_unit_price));
		let $details = $('<div>').addClass('product-details')
			.append($('<h4>').addClass('product-title').append($title))
			.append($info);

		let $image = $('<img>').attr('alt', 'product');
		let imageUrl = cartImageUrl(element.image_small);
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

	function populate()
	{
		$(".dropdown-cart-products").empty();
			$(".cart-count").empty();
			let count = 0;
			//get data local syrogare prodicts
			let array = localStorage.getItem('products_cart');
			array = JSON.parse(array)
			count = array.length;
				
			array.forEach(element => {
				$(".dropdown-cart-products").append(buildCartDropdownItem(element));
			});
			
			$(".cart-count").append(count);
	}

	
	$(function(){
    'use strict';
		populate();
		calculatetotal();
    });
</script>
@endpush