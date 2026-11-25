<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Item;
use App\Models\Tenant\Catalogs\Tag;
use App\Http\Resources\Tenant\ItemCollection;
use Illuminate\Support\Facades\Auth;
use App\Models\Tenant\User;
use App\Models\Tenant\Person;
use Illuminate\Support\Str;
use App\Models\Tenant\Order;
use App\Models\Tenant\ItemsRating;
use App\Models\Tenant\ConfigurationEcommerce;
use Modules\Ecommerce\Http\Resources\ItemBarCollection;
use stdClass;
use Illuminate\Support\Facades\Mail;
use App\Mail\Tenant\CulqiEmail;
use App\Http\Controllers\Tenant\Api\ServiceController;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Services\Payments\CartPricing;
use App\Services\Ecommerce\StoreItemPresenter;
use App\Services\Ecommerce\CheckoutRules;
use App\Services\Ecommerce\SafeMail;
use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryConfiguration;
use Modules\Item\Models\Brand;
use Modules\Item\Models\Category;

class EcommerceController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Response
     */
    public function __construct(){
        // Route/console commands must not require a tenant database just to
        // instantiate the controller (php artisan route:list, queue workers).
        if (!app()->runningInConsole()) {
            view()->share('records', $this->storeItems()->orderBy('id', 'DESC')->take(2)->get());
        }
    }

    /**
     * Items visible on the storefront: published and active.
     */
    private function storeItems()
    {
        return Item::where('apply_store', 1)->where('active', 1);
    }

    /**
     * Narrows a storefront query by a free-text term (name, description, internal code, category or brand).
     */
    private function applySearch($query, ?string $term)
    {
        $term = trim(mb_substr((string) $term, 0, 100));
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '\\%_').'%';

        return $query->where(function ($q) use ($like) {
            $q->where('items.description', 'like', $like)
              ->orWhere('items.name', 'like', $like)
              ->orWhere('items.internal_id', 'like', $like)
              ->orWhereHas('brand', function ($b) use ($like) {
                  $b->where('name', 'like', $like);
              })
              ->orWhereHas('category', function ($c) use ($like) {
                  $c->where('name', 'like', $like);
              });
        });
    }

    public function index(Request $request)
    {
        return $this->renderCatalog($request, null);
    }

    public function category(Request $request)
    {
        // Unknown or deleted categories do not exist for the buyer (Tag hides status = 0).
        abort_unless(is_numeric($request->category) && Tag::where('id', $request->category)->exists(), 404);

        return $this->renderCatalog($request, (int) $request->category);
    }

    private function renderCatalog(Request $request, ?int $activeTagId)
    {
        $search = trim(mb_substr((string) $request->query('search', ''), 0, 100));
        $activeCategoryId = $request->filled('cat') && is_numeric($request->query('cat'))
            ? (int) $request->query('cat')
            : null;

        $rawBrands = $request->query('brand', []);
        if (!is_array($rawBrands)) {
            $rawBrands = [$rawBrands];
        }
        $activeBrands = array_values(array_unique(array_filter(array_map(function ($v) {
            return is_numeric($v) ? (int) $v : null;
        }, $rawBrands))));

        $allowedSorts = ['relevance', 'newest', 'price_asc', 'price_desc', 'name_asc'];
        $activeSort = in_array((string) $request->query('sort', 'relevance'), $allowedSorts, true)
            ? (string) $request->query('sort', 'relevance')
            : 'relevance';

        $baseFilter = function ($q) {
            $q->where('items.apply_store', 1)
              ->where('items.active', 1)
              ->whereNotNull('items.internal_id');
        };

        $query = Item::without(['item_type', 'unit_type', 'item_unit_types'])
            ->with(['brand:id,name', 'category:id,name', 'currency_type', 'warehouses', 'tags'])
            ->where($baseFilter);

        if ($activeTagId !== null) {
            $query->whereExists(function ($sub) use ($activeTagId) {
                $sub->select(DB::raw(1))
                    ->from('item_tags as it')
                    ->whereColumn('it.item_id', 'items.id')
                    ->where('it.tag_id', $activeTagId);
            });
        }

        if ($activeCategoryId !== null) {
            $query->where('items.category_id', $activeCategoryId);
        }

        if (!empty($activeBrands)) {
            $query->whereIn('items.brand_id', $activeBrands);
        }

        $this->applySearch($query, $search);

        switch ($activeSort) {
            case 'price_asc':
                $query->orderBy('items.sale_unit_price', 'asc')->orderBy('items.id', 'desc');
                break;
            case 'price_desc':
                $query->orderBy('items.sale_unit_price', 'desc')->orderBy('items.id', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('items.description', 'asc');
                break;
            case 'newest':
                $query->orderBy('items.id', 'desc');
                break;
            default:
                $query->orderBy('items.id', 'desc');
                break;
        }

        $appendParams = [];
        if ($search !== '') {
            $appendParams['search'] = $search;
        }
        if ($activeCategoryId !== null) {
            $appendParams['cat'] = $activeCategoryId;
        }
        if (!empty($activeBrands)) {
            $appendParams['brand'] = $activeBrands;
        }
        if ($activeSort !== 'relevance') {
            $appendParams['sort'] = $activeSort;
        }

        $dataPaginate = $query->paginate(12)->appends($appendParams);

        $db = DB::connection('tenant');

        $totalCatalogCount = (int) $db->table('items')
            ->where('apply_store', 1)
            ->where('active', 1)
            ->whereNotNull('internal_id')
            ->count();

        $filterCategories = $db->table('categories as c')
            ->join('items as i', 'i.category_id', '=', 'c.id')
            ->where('i.apply_store', 1)
            ->where('i.active', 1)
            ->whereNotNull('i.internal_id')
            ->select('c.id', 'c.name', DB::raw('COUNT(i.id) as products_count'))
            ->groupBy('c.id', 'c.name')
            ->orderBy('c.name')
            ->get();

        $filterBrands = $db->table('brands as b')
            ->join('items as i', 'i.brand_id', '=', 'b.id')
            ->where('i.apply_store', 1)
            ->where('i.active', 1)
            ->whereNotNull('i.internal_id')
            ->select('b.id', 'b.name', DB::raw('COUNT(i.id) as products_count'))
            ->groupBy('b.id', 'b.name')
            ->orderBy('b.name')
            ->get();

        $filterTags = $db->table('tags as t')
            ->join('item_tags as it', 'it.tag_id', '=', 't.id')
            ->join('items as i', 'i.id', '=', 'it.item_id')
            ->where('t.status', 1)
            ->where('i.apply_store', 1)
            ->where('i.active', 1)
            ->whereNotNull('i.internal_id')
            ->select('t.id', 't.name', DB::raw('COUNT(DISTINCT i.id) as products_count'))
            ->groupBy('t.id', 't.name')
            ->orderBy('t.name')
            ->get();

        $activeCategoryName = null;
        if ($activeCategoryId !== null) {
            $matchedCat = $filterCategories->firstWhere('id', $activeCategoryId);
            $activeCategoryName = $matchedCat ? $matchedCat->name : optional(Category::find($activeCategoryId))->name;
        } elseif ($activeTagId !== null) {
            $matchedTag = $filterTags->firstWhere('id', $activeTagId);
            $activeCategoryName = $matchedTag ? $matchedTag->name : optional(Tag::find($activeTagId))->name;
        }

        $configuration = InventoryConfiguration::first();

        return view('ecommerce::index', [
            'dataPaginate' => $dataPaginate,
            'configuration' => optional($configuration)->stock_control,
            'totalCatalogCount' => $totalCatalogCount,
            'filterCategories' => $filterCategories,
            'filterBrands' => $filterBrands,
            'filterTags' => $filterTags,
            'activeCategoryId' => $activeCategoryId,
            'activeTagId' => $activeTagId,
            'activeCategoryName' => $activeCategoryName,
            'activeBrands' => $activeBrands,
            'activeSort' => $activeSort,
            'activeSearch' => $search,
        ]);
    }

    public function item($id)
    {
        $row = $this->storeItems()->with(['brand:id,name', 'category:id,name'])->findOrFail($id);
        $price = StoreItemPresenter::price($row);
        $stockControl = (bool) optional(InventoryConfiguration::first())->stock_control;
        $outOfStock = false;
        if ($stockControl) {
            $totalStock = 0;
            foreach ($row->warehouses as $w) {
                $totalStock += (float) $w->stock;
            }
            $outOfStock = $totalStock <= 0;
        }

        $record = (object) array_merge(StoreItemPresenter::present($row), [
            'technical_specifications' => $row->technical_specifications,
            'images' => $row->images,
            'attributes' => $row->attributes ? $row->attributes : [],
            'tags' => $row->tags->pluck('tag_id')->toArray(),
            'category_id' => $row->category_id,
            'category_name' => optional($row->category)->name,
            'brand_id' => $row->brand_id,
            'brand_name' => optional($row->brand)->name,
            'out_of_stock' => $outOfStock,
        ]);
        // Only the whitelisted fields are ever serialized into the page (data-product).
        $record->cart = StoreItemPresenter::present($row);
        $record->price_label = $price['currency_type_symbol'].' '.number_format($price['amount'], 2);

        $rating = $this->ratingSummary($row->id);

        return view('ecommerce::items.record', compact('record', 'rating'));
    }

    /**
     * Real average and number of ratings of a product (shown only when someone rated it).
     */
    private function ratingSummary($itemId): array
    {
        $row = ItemsRating::where('item_id', $itemId)->selectRaw('COUNT(*) as total, AVG(value) as average')->first();

        return [
            'count' => (int) optional($row)->total,
            'average' => (float) optional($row)->average,
        ];
    }

    public function items()
    {
        $records = $this->storeItems()->get();
        return view('ecommerce::items.index', compact('records'));
    }

    public function itemsBar(Request $request)
    {
        // Server-side autocomplete: never ship the whole catalogue to every visitor.
        $term = trim((string) $request->query('q'));
        $records = mb_strlen($term) < 2
            ? collect()
            : $this->applySearch($this->storeItems(), $term)->orderBy('description')->limit(10)->get();

        return new ItemBarCollection($records);

    }

    public function partialItem($id)
    {
        $record = $this->storeItems()->findOrFail($id);
        $public = StoreItemPresenter::present($record);

        $rating = $this->ratingSummary($record->id);

        return view('ecommerce::items.partial', compact('record', 'public', 'rating'));
    }

    public function detailCart()
    {
        $configuration = ConfigurationEcommerce::first();
        return view('ecommerce::cart.detail', compact('configuration'));
    }

    public function showLogin()
    {
        // The store login is a modal included by the store layout. Keep the
        // direct URL working instead of returning a missing view.
        return redirect()->route('tenant.ecommerce.index');
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
        if (Auth::attempt($credentials)) {
           $request->session()->regenerate();
           return[
               'success' => true,
               'message' => 'Login Success'
           ];
        }
        else{
            return[
                'success' => false,
                'message' => 'Usuario o Password incorrectos'
            ];
        }

    }

    public function logout()
    {
        Auth::logout();
        return[
            'success' => true,
            'message' => 'Logout Success'
        ];
    }

    public function storeUser(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email')));

        $validator = Validator::make(
            array_merge($request->only(['name', 'pswd', 'pswd_rpt']), ['email' => $email]),
            [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'pswd' => 'required|string|min:8|max:72',
                'pswd_rpt' => 'required|same:pswd',
            ],
            [
                'name.required' => 'El nombre es obligatorio.',
                'name.string' => 'El nombre no es válido.',
                'name.max' => 'El nombre no debe superar 255 caracteres.',
                'email.required' => 'El correo electrónico es obligatorio.',
                'email.email' => 'El correo electrónico no es válido.',
                'email.max' => 'El correo electrónico no debe superar 255 caracteres.',
                'pswd.required' => 'La contraseña es obligatoria.',
                'pswd.string' => 'La contraseña no es válida.',
                'pswd.min' => 'La contraseña debe tener al menos 8 caracteres.',
                'pswd.max' => 'La contraseña no debe superar 72 caracteres.',
                'pswd_rpt.required' => 'Repita la contraseña.',
                'pswd_rpt.same' => 'Las contraseñas no coinciden.',
            ]
        );

        if ($validator->fails()) {
            // HTTP 200 with success=false: the register modal prints `message` for that shape.
            return [
                'success' => false,
                'message' => $validator->errors()->first(),
            ];
        }

        try{

            $verify = User::where('email', $email)->first();
            if($verify)
            {
                return [
                    'success' => false,
                    'message' => 'Email no disponible'
                ];
            }

            $user = new User();
            $user->name = trim((string) $request->name);
            $user->email = $email;
            $user->establishment_id = 1;
            $user->type = 'client';
            $user->api_token = null;
            $user->password = bcrypt($request->pswd);
            $user->save();
            $user->modules()->sync([10]);

            $credentials = [ 'email' => $user->email, 'password' => $request->pswd ];
            if (Auth::attempt($credentials)) {
                $request->session()->regenerate();
            }
            return [
                'success' => true,
                'message' => 'Usuario registrado'
            ];
        }catch(Exception $e)
        {
            report($e);

            return [
                'success' => false,
                'message' => 'No se pudo registrar el usuario.',
            ];
        }

    }

    public function quote(Request $request)
    {
        try {
            $cart = CartPricing::forStore((array) $request->items);

            return ['success' => true, 'total' => $cart['total'], 'lines' => $cart['lines'], 'exchange_rate_sale' => $cart['exchange_rate_sale']];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function paymentCash(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Inicie sesión para registrar su pedido.'], 401);
        }

        $customer = CheckoutRules::sanitize(is_array($request->customer) ? $request->customer : [], $user->email);
        if (($customer['apellidos_y_nombres_o_razon_social'] ?? '') === '') {
            $customer['apellidos_y_nombres_o_razon_social'] = trim((string) $user->name);
        }

        $validator = CheckoutRules::validate($customer);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            // The total is always recomputed from database prices; client amounts are ignored.
            $cart = CartPricing::forStore((array) $request->items);

            if ($identityError = CheckoutRules::identityErrorForTotal($customer, $cart['total'])) {
                return response()->json($identityError, 422);
            }

            // The comprobante is rebuilt server-side from these stored lines when the back office
            // issues it; the browser-built `purchase` payload is never stored.
            $order = Order::create([
                'external_id' => Str::uuid()->toString(),
                'customer' => $customer,
                'shipping_address' => 'direccion 1',
                'items' => $cart['lines'],
                'total' => $cart['total'],
                'reference_payment' => 'efectivo',
                'status_order_id' => 1,
                'purchase' => null,
            ]);

            $document = new stdClass;
            $document->client = $customer['apellidos_y_nombres_o_razon_social'];
            $document->product = 'Compras Ecommerce Facturador Pro';
            $document->total = number_format($cart['total'], 2, '.', '');
            $document->items = $cart['lines'];
            $document->mail_type = 'cash';
            $document->order_number = '#'.str_pad($order->id, 6, '0', STR_PAD_LEFT);

            $this->paymentCashEmail($customer['correo_electronico'], $document);
            $this->notifyStoreOwner($document);

            return [
                'success' => true,
                'order' => [
                    'id' => $order->id,
                    'order_id' => str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'external_id' => $order->external_id,
                    'total' => $order->total,
                ],
            ];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (Exception $e) {
            report($e);

            return ['success' => false, 'message' => 'No se pudo registrar el pedido.'];
        }
    }

    public function paymentCashEmail($customer_email, $document)
    {
        // The order is already saved: a mail failure must not fail the purchase (it is logged).
        return SafeMail::send($customer_email, new CulqiEmail($document));
    }

    /**
     * Tells the store's contact address about a new order (skipped while the default placeholder is in place).
     */
    private function notifyStoreOwner($document): void
    {
        $email = optional(ConfigurationEcommerce::first())->information_contact_email;
        if (! $email || $email === 'admin@mail.com') {
            return;
        }

        $owner = clone $document;
        $owner->mail_type = 'owner';
        SafeMail::send($email, new CulqiEmail($owner));
    }

    public function ratingItem(Request $request)
    {
        if (auth()->user()) {
            $request->validate([
                'item_id' => ['required', 'integer', Rule::exists('tenant.items', 'id')->where('apply_store', 1)->where('active', 1)],
                'value'   => 'required|numeric|min:1|max:5',
            ]);

            $user_id = auth()->id();
            $row = ItemsRating::firstOrNew(['user_id' => $user_id, 'item_id' => $request->item_id]);
            $row->value = $request->value;
            $row->save();

            return [
                'success' => true,
                'message' => 'Rating Guardado'
            ];
        }

        return [
            'success' => false,
            'message' => 'No se guardo Rating'
        ];
    }

    public function getRating($id)
    {
        if(auth()->user())
        {
            $user_id = auth()->id();
            $row = ItemsRating::where('user_id', $user_id)->where('item_id', $id)->first();
            return[
                'success' => true,
                'value' => ($row) ? $row->value : 0,
                'message' => 'Valor Obtenido'
            ];
        }
        return[
            'success' => false,
            'value' => 0,
            'message' => 'No se obtuvo valor'
        ];

    }

    public function saveDataUser(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $validator = Validator::make($request->only(['address', 'telephone']), [
            'address' => 'nullable|string|max:255',
            'telephone' => 'nullable|string|max:20',
        ], [
            'address.max' => 'La dirección no debe superar 255 caracteres.',
            'address.string' => 'La dirección no es válida.',
            'telephone.max' => 'El teléfono no debe superar 20 caracteres.',
            'telephone.string' => 'El teléfono no es válido.',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($request->filled('address')) {
            $user->address = $request->address;
        }
        if ($request->filled('telephone')) {
            $user->telephone = $request->telephone;
        }

        $user->save();

        return ['success' => true];

    }
}
