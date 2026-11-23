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
use Modules\Inventory\Models\InventoryConfiguration;

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
     * Narrows a storefront query by a free-text term (name, description or internal code).
     */
    private function applySearch($query, ?string $term)
    {
        $term = trim(mb_substr((string) $term, 0, 100));
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '\\%_').'%';

        return $query->where(function ($q) use ($like) {
            $q->where('description', 'like', $like)
              ->orWhere('name', 'like', $like)
              ->orWhere('internal_id', 'like', $like);
        });
    }

    public function index(Request $request)
    {
      $dataPaginate = $this->applySearch($this->storeItems()->where('internal_id', '!=', null), $request->query('search'))
        ->paginate(15)
        ->appends($request->filled('search') ? ['search' => mb_substr((string) $request->query('search'), 0, 100)] : []);
      $configuration = InventoryConfiguration::first();
      return view('ecommerce::index', ['dataPaginate' => $dataPaginate, 'configuration' => optional($configuration)->stock_control]);
    }

    public function category(Request $request)
    {
      // Unknown or deleted categories do not exist for the buyer (Tag hides status = 0).
      abort_unless(is_numeric($request->category) && Tag::where('id', $request->category)->exists(), 404);

      $dataPaginate = Item::select('i.*')
        ->where([['i.apply_store', 1], ['i.active', 1], ['i.internal_id','!=', null], ['it.tag_id', $request->category]])
        ->from('items as i')
        ->join('item_tags as it', 'it.item_id','i.id')->paginate(15);
        $configuration = InventoryConfiguration::first();
      return view('ecommerce::index', ['dataPaginate' => $dataPaginate, 'configuration' => optional($configuration)->stock_control]);
    }

    public function item($id)
    {
        $row = $this->storeItems()->findOrFail($id);
        $price = StoreItemPresenter::price($row);

        $record = (object) array_merge(StoreItemPresenter::present($row), [
            'technical_specifications' => $row->technical_specifications,
            'images' => $row->images,
            'attributes' => $row->attributes ? $row->attributes : [],
            'tags' => $row->tags->pluck('tag_id')->toArray(),
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
