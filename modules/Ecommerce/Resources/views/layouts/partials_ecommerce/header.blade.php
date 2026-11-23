 <style>
#header_bar .header-menu {
    max-height: 300px !important;
    overflow:auto;
    overflow-y: auto;
}
#header_bar .header-menu::-webkit-scrollbar-track
{
	-webkit-box-shadow: inset 0 0 6px rgba(0,0,0,0.1);
	background-color: #fdfdfd;
}

#header_bar .header-menu::-webkit-scrollbar
{
	width: 6px;
	background-color: #fdfdfd;
}

#header_bar .header-menu::-webkit-scrollbar-thumb
{
	background-color: #0187cc;
}
.header-dropdown a img {
    border-radius: 8px;
    padding: 4px;
}

@media (max-width: 768px) {
    .header-dropdown {
        min-width: 100px !important;
    }
}


.header-menu ul a {
    padding: 3px 6px;
}

.header-menu {
    box-shadow: 0 0 2px rgba(0,0,0,0.1);
    padding: 0 !important;
    border: none;
    /*border-radius:10px;*/
 }

 .header-menu a:hover, .header-menu a:focus {
    color: #0187cc;
    background-color: #f4f4f4;
}

.header-menu ul a {
    text-transform: capitalize !important;
}

.search_input {
    margin-bottom: 0.1rem;
    border-radius: 20px !important;
}

.store-logo-text {
    display: inline-block;
    max-width: 180px;
    font-size: 2rem;
    font-weight: 700;
    line-height: 1.1;
    color: #222529;
    /* One line: long names end in an ellipsis instead of breaking mid-word. */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: middle;
}
.search_title{

}
.search_price{

}
.search_btn{

}

.search_input:focus {
    background-color: #fff;
    border-color: #fff;
    box-shadow: none;

}

.header-contact span {
    font-weight: normal;
}


 </style>

 <header class="header">

     <div class="header-middle">
         <div   class="container">
             <div class="header-left">
                 <a href="{{ route("tenant.ecommerce.index") }}" class="logo store-logo" style="max-width: 180px">
                    @if($storeLogoUrl)
                        <img src="{{ $storeLogoUrl }}" alt="{{ $storeName }}" />
                    @else
                        <span class="store-logo-text">{{ $storeName }}</span>
                    @endif
                 </a>
             </div><!-- End .header-left -->

             <div id="header_bar" class="header-center header-dropdowns">

                 <div class="header-dropdown" style="min-width:400px;">

                    <input placeholder="Buscar..." type="text" class="search_input form-control form-control-lg" v-model="value" aria-label="Buscar productos" v-on:keyup="autoComplete" v-on:keyup.enter="goSearch" />
                     <div class="header-menu">
                         <ul v-if="results.length > 0">
                            <li v-for="result in results">
                                <a :href="'/ecommerce/item/' + result.id" class="d-flex">
                                    <div class="flex-grow-1"><img style="max-width: 80px" :src="result.image_url_small" alt="">
                                    <span class="search_title" style="font-size: 1.0em;"> @{{ result.description }} </span>
                                    </div>
                                    <span class="search_price">@{{result.sale_unit_price}}</span>
                                    {{-- <div class="search_btn btn btn-default">@{{result.sale_unit_price}}</div> --}}
                                </a>
                            </li>
                         </ul>
                     </div><!-- End .header-menu -->
                 </div><!-- End .header-dropown -->


             </div><!-- End .headeer-center -->

             <div class="header-right">
                 <button class="mobile-menu-toggler" type="button">
                     <i class="icon-menu"></i>
                 </button>
                 <div class="header-contact">
                     <span> Atención al</span>
                     <i class="fab fa-whatsapp"></i> <a href="#"><strong>{{$information->information_contact_phone}}</strong></a>
                 </div><!-- End .header-contact -->

                @include('ecommerce::layouts.partials_ecommerce.cart_dropdown')

                @include('ecommerce::partials.headers.session')

             </div><!-- End .header-right -->
         </div><!-- End .container -->
     </div><!-- End .header-middle -->

     <div class="header-bottom sticky-header">
        <div class="container d-flex">
            <nav class="main-nav flex-grow-1">

             </nav>
         </div><!-- End .header-bottom -->
     </div><!-- End .header-bottom -->
 </header><!-- End .header -->

 @push('scripts')

 <script type="text/javascript">
     var app = new Vue({
         el: '#header_bar',
         data: {
             value: new URLSearchParams(window.location.search).get('search') || '',
             suggestions: [],
             timer: null,
             resource: 'ecommerce',
             results: [],
         },
         methods: {
             // Debounced server-side lookup (the catalogue is not downloaded in advance).
             autoComplete(event) {
                 if (event && event.key === 'Enter') return
                 clearTimeout(this.timer)
                 const term = (this.value || '').trim()
                 if (term.length < 2) {
                     this.results = []
                     return
                 }
                 this.timer = setTimeout(() => this.getItems(term), 250)
             },
             goSearch() {
                 const term = (this.value || '').trim()
                 if (term) {
                     window.location.href = '/ecommerce?search=' + encodeURIComponent(term)
                 }
             },
             getItems(term) {
                 let contex = this
                 fetch(`/${this.resource}/items_bar?q=` + encodeURIComponent(term))
                     .then(function (response) {
                         return response.json();
                     })
                     .then(function (myJson) {
                         contex.results = myJson.data || []
                     })
                     .catch(function () {
                         contex.results = []
                     });
             }
         }
     })

 </script>

 @endpush
