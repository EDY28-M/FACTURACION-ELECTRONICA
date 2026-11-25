<?php

namespace App\Http\Controllers\Tenant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Item;
use App\Models\Tenant\Person;
use App\Models\Tenant\Catalogs\AffectationIgvType;
use App\Models\Tenant\Establishment;
use App\Models\Tenant\Series;
use App\Models\Tenant\PaymentMethodType;
use App\Models\Tenant\CardBrand;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\User;
use Modules\Inventory\Models\Warehouse;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Configuration;
use Modules\Inventory\Models\InventoryConfiguration;
use Modules\Inventory\Models\ItemWarehouse;
use Exception;
use Modules\Item\Models\Category;
use Modules\Finance\Traits\FinanceTrait;
use App\Models\Tenant\Company;
use Modules\BusinessTurn\Models\BusinessTurn;
use App\Http\Resources\Tenant\PosCollection;
use App\CoreFacturalo\Helpers\Pos\ItemSearch;


class PosController extends Controller
{

    use FinanceTrait;

    public function index()
    {
        $cash = Cash::where([['user_id', auth()->user()->id],['state', true]])->first();

        if(!$cash) return redirect()->route('tenant.cash.index')->with('warning', 'Para usar el Punto de Venta (POS) primero debes aperturar una caja chica en esta pantalla.');

        $configuration = Configuration::first();

        $company = Company::select('soap_type_id')->first();
        $soap_company  = $company->soap_type_id;
        $business_turns = BusinessTurn::select('active')->where('id', 4)->first();

        return view('tenant.pos.index', compact('configuration', 'soap_company', 'business_turns'));
    }

    public function index_full()
    {
        $cash = Cash::where([['user_id', auth()->user()->id],['state', true]])->first();

        if(!$cash) return redirect()->route('tenant.cash.index')->with('warning', 'Para usar el Punto de Venta (POS) primero debes aperturar una caja chica en esta pantalla.');

        return view('tenant.pos.index_full');
    }

    public function search_items(Request $request)
    {
        $configuration = Configuration::first();

        $query = Item::with(['category:id,name', 'brand:id,name', 'sets.individual_item:id,description'])
            ->whereWarehouse()
            ->whereIsActive()
            ->where('series_enabled', 0)
            ->where('unit_type_id', '!=', 'ZZ');

        ItemSearch::apply($query, $request->input_item, true);

        $items = $query->orderBy('description')->limit(50)->get()->transform(function($row) use($configuration){
                                $full_description = ($row->internal_id)?$row->internal_id.' - '.$row->description:$row->description;
                                return [
                                    'id' => $row->id,
                                    'item_id' => $row->id,
                                    'full_description' => $full_description,
                                    'description' => $row->description,
                                    'currency_type_id' => $row->currency_type_id,
                                    'internal_id' => $row->internal_id,
                                    'currency_type_symbol' => $row->currency_type->symbol,
                                    'sale_unit_price' => number_format($row->sale_unit_price, $configuration->decimal_quantity, ".",""),
                                    'purchase_unit_price' => $row->purchase_unit_price,
                                    'unit_type_id' => $row->unit_type_id,
                                    'sale_affectation_igv_type_id' => $row->sale_affectation_igv_type_id,
                                    'purchase_affectation_igv_type_id' => $row->purchase_affectation_igv_type_id,
                                    'calculate_quantity' => (bool) $row->calculate_quantity,
                                    'is_set' => (bool) $row->is_set,
                                    'edit_unit_price' => false,
                                    'has_igv' => (bool) $row->has_igv,
                                    'igv_general_rate' => (bool) $row->igv_general_rate,
                                    'aux_quantity' => 1,
                                    'aux_sale_unit_price' => number_format($row->sale_unit_price, $configuration->decimal_quantity, ".",""),
                                    'edit_sale_unit_price' => number_format($row->sale_unit_price, $configuration->decimal_quantity, ".",""),
                                    'image_url' => ($row->image !== 'imagen-no-disponible.jpg') ? asset('storage'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR.$row->image) : asset("/logo/{$row->image}"),
                                    'image_url_small' => $row->imageSmallUrl(),
                                    'sets' => collect($row->sets)->transform(function($r){
                                        return [
                                            $r->individual_item->description
                                        ];
                                    }),
                                    'warehouses' => collect($row->warehouses)->transform(function ($row) {
                                        return [
                                            'warehouse_description' => $row->warehouse->description,
                                            'stock' => $row->stock,
                                        ];
                                    }),
                                    'unit_type' => $row->item_unit_types,
                                    'category' => ($row->category) ? $row->category->name : null,
                                    'brand' => ($row->brand) ? $row->brand->name : null,
                                    'has_plastic_bag_taxes' => (bool) $row->has_plastic_bag_taxes,
                                    'amount_plastic_bag_taxes' => $row->amount_plastic_bag_taxes,
                                ];
                            });

        return compact('items');

    }

    public function tables()
    {
        $affectation_igv_types = AffectationIgvType::whereActive()->get();
        $establishment = Establishment::where('id', auth()->user()->establishment_id)->first();
        $currency_types = CurrencyType::whereActive()->get();

        $customers = $this->table('customers');
        $user = User::without(['establishment'])->findOrFail(auth()->user()->id);

        // POS fetches paginated products via GET /pos/items in parallel; avoid 250+ wasted queries here.
        $items = [];

        $categories = Category::select('id', 'name')->get();

        return compact('items', 'customers','affectation_igv_types','establishment','user','currency_types', 'categories');

    }

    public function payment_tables(){

        $company = Company::active();
        $soap_type_id = $company ? (string) $company->soap_type_id : '01';
        $document_types = ($soap_type_id === '03') ? ['80'] : ['01','03','80'];
        $missing_for_production = $company ? Company::missingForProduction($company) : [];

        $series = Series::whereIn('document_type_id', $document_types)
                        ->where([['establishment_id', auth()->user()->establishment_id],['contingency',false]])
                        ->get();

        $payment_method_types = PaymentMethodType::all();
        $cards_brand = CardBrand::all();
        $payment_destinations = $this->getPaymentDestinations();


        return compact('series','payment_method_types','cards_brand', 'payment_destinations', 'soap_type_id', 'missing_for_production');

    }

    public function table($table)
    {
        if ($table === 'customers') {
            return DB::connection('tenant')
                ->table('v_pos_customers_active')
                ->orderBy('name')
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => $row->id,
                        'description' => $row->number.' - '.$row->name,
                        'name' => $row->name,
                        'number' => $row->number,
                        'identity_document_type_id' => $row->identity_document_type_id,
                        'identity_document_type_code' => null,
                    ];
                });
        }

        if ($table === 'items') {

            $configuration =  Configuration::first();

            $items = Item::whereWarehouse()->whereIsActive()->where('unit_type_id', '!=', 'ZZ')->where('series_enabled', 0)->orderBy('description')->take(100)
                            ->get()->transform(function($row) use ($configuration) {
                                $full_description = ($row->internal_id)?$row->internal_id.' - '.$row->description:$row->description;
                                return [
                                    'id' => $row->id,
                                    'item_id' => $row->id,
                                    'full_description' => $full_description,
                                    'description' => $row->description,
                                    'currency_type_id' => $row->currency_type_id,
                                    'internal_id' => $row->internal_id,
                                    'currency_type_symbol' => $row->currency_type->symbol,
                                    'sale_unit_price' => number_format($row->sale_unit_price, $configuration->decimal_quantity, ".",""),
                                    'purchase_unit_price' => $row->purchase_unit_price,
                                    'unit_type_id' => $row->unit_type_id,
                                    'sale_affectation_igv_type_id' => $row->sale_affectation_igv_type_id,
                                    'purchase_affectation_igv_type_id' => $row->purchase_affectation_igv_type_id,
                                    'calculate_quantity' => (bool) $row->calculate_quantity,
                                    'has_igv' => (bool) $row->has_igv,
                                    'igv_general_rate' => (bool) $row->igv_general_rate,
                                    'is_set' => (bool) $row->is_set,
                                    'edit_unit_price' => false,
                                    'aux_quantity' => 1,
                                    'edit_sale_unit_price' => number_format($row->sale_unit_price, $configuration->decimal_quantity, ".",""),
                                    'aux_sale_unit_price' => number_format($row->sale_unit_price, $configuration->decimal_quantity, ".",""),
                                    'image_url' => ($row->image !== 'imagen-no-disponible.jpg') ? asset('storage'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR.$row->image) : asset("/logo/{$row->image}"),
                                    'image_url_small' => $row->imageSmallUrl(),
                                    'warehouses' => collect($row->warehouses)->transform(function($row) {
                                        return [
                                            'warehouse_description' => $row->warehouse->description,
                                            'stock' => $row->stock,
                                        ];
                                    }),
                                    'category_id' => ($row->category) ? $row->category->id : null,
                                    'sets' => collect($row->sets)->transform(function($r){
                                        return [
                                            $r->individual_item->description
                                        ];
                                    }),
                                    'unit_type' => $row->item_unit_types,
                                    'category' => ($row->category) ? $row->category->name : null,
                                    'brand' => ($row->brand) ? $row->brand->name : null,
                                    'has_plastic_bag_taxes' => (bool) $row->has_plastic_bag_taxes,
                                    'amount_plastic_bag_taxes' => $row->amount_plastic_bag_taxes,

                                ];
                            });
            return $items;
        }


        if ($table === 'card_brands') {

            $card_brands = CardBrand::all();
            return $card_brands;

        }

        return [];
    }

    public function payment()
    {
        return view('tenant.pos.payment');
    }

    public function status_configuration(){

        $configuration = Configuration::first();

        return $configuration;
    }

    public function validate_stock($item_id, $quantity){

        $inventory_configuration = InventoryConfiguration::firstOrFail();
        $warehouse = Warehouse::where('establishment_id', auth()->user()->establishment_id)->first();
        $item_warehouse = ItemWarehouse::where([['item_id',$item_id], ['warehouse_id',$warehouse->id]])->first();
        $item = Item::findOrFail($item_id);

        if($item->is_set){

            $sets = $item->sets;

            foreach ($sets as $set) {

                $individual_item = $set->individual_item;
                $item_warehouse = ItemWarehouse::where([['item_id',$individual_item->id], ['warehouse_id',$warehouse->id]])->first();

                if(!$item_warehouse)
                    return [
                        'success' => false,
                        'message' => "El producto seleccionado no está disponible en su almacén!"
                    ];

                $stock = $item_warehouse->stock - $quantity;


                if($item_warehouse->item->unit_type_id !== 'ZZ'){
                    if (($inventory_configuration->stock_control) && ($stock < 0)){
                        return [
                            'success' => false,
                            'message' => "El producto {$item_warehouse->item->description} registrado en el conjunto {$item->description} no tiene suficiente stock!"
                        ];
                    }
                }
                // dd($individual_item);
            }



        }else{


            if(!$item_warehouse)
                return [
                    'success' => false,
                    'message' => "El producto seleccionado no está disponible en su almacén!"
                ];

            $stock = $item_warehouse->stock - $quantity;


            if($item_warehouse->item->unit_type_id !== 'ZZ'){
                if (($inventory_configuration->stock_control) && ($stock < 0)){
                    return [
                        'success' => false,
                        'message' => "El producto {$item_warehouse->item->description} no tiene suficiente stock!"
                    ];
                }
            }

        }



        return [
            'success' => true,
            'message' => ''
        ];


    }

    public function item(Request $request)
    {
        $query = Item::with(['category:id,name', 'brand:id,name', 'sets.individual_item:id,description'])
            ->whereWarehouse()
            ->whereIsActive()
            ->where('series_enabled', 0)
            ->where('unit_type_id', '!=', 'ZZ')
            ->orderBy('description');

        if ($request->cat) {
            $query->where('category_id', $request->cat);
        }

        return new PosCollection($query->paginate(50));
    }

    public function search_items_cat(Request $request)
    {
        $query = Item::with(['category:id,name', 'brand:id,name', 'sets.individual_item:id,description'])
            ->whereWarehouse()
            ->whereIsActive()
            ->where('series_enabled', 0)
            ->where('unit_type_id', '!=', 'ZZ');

        if ($request->filled('cat')) {
            $query->where('category_id', $request->cat);
        }

        ItemSearch::apply($query, $request->input_item, false);

        return new PosCollection($query->orderBy('description')->paginate(50));
    }

}
