<?php

namespace App\Console\Commands;

use App\CoreFacturalo\Helpers\Igv\IgvRate;
use App\Models\Tenant\Catalogs\AffectationIgvType;
use App\Models\Tenant\Person;
use App\Models\Tenant\User;
use Carbon\Carbon;
use Hyn\Tenancy\Environment;
use Hyn\Tenancy\Models\Hostname;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Document\Http\Controllers\DocumentController;

/**
 * Seeds the Hotel module of a tenant with a coherent demo setup: rates, categories, floors,
 * rooms (each with its billable item, exactly as the "Habitaciones" screen creates it) and
 * per-room prices.
 *
 * Usage:
 *   php artisan hotel:demo-seed                      # seeds net.localhost (refuses to run twice)
 *   php artisan hotel:demo-seed net.localhost --fresh   # removes the rows it seeded, then re-seeds
 *
 * Safety:
 *   - Every inserted id is recorded in storage/app/demo-seed-hotel.json (keyed by tenant fqdn);
 *     --fresh removes only those rows. Rows created by real users are never touched.
 *   - It refuses to run on a tenant that already has hotel data it did not seed.
 *   - A few rooms are seeded OCUPADO with an active stay (rent + room line) built exactly like
 *     "Recepción" builds it, so the check-out screen works on them. No payment or document is created
 *     until someone checks out.
 *   - Nothing is sent to SUNAT and no document is generated.
 */
class HotelDemoSeedCommand extends Command
{
    protected $signature = 'hotel:demo-seed {fqdn=net.localhost} {--fresh : Remove previously seeded hotel rows first}';

    protected $description = 'Seed the Hotel module of a tenant with demo rates, categories, floors, rooms and prices';

    /** Sale price (PEN, IGV included) of the public rate by category. */
    private const CATEGORIES = [
        'Simple' => 90,
        'Doble' => 130,
        'Matrimonial' => 140,
        'Triple' => 180,
        'Suite Junior' => 220,
        'Suite Ejecutiva' => 320,
    ];

    /** Rate name => multiplier applied over the public price. */
    private const RATES = [
        'Tarifa pública' => 1.00,
        'Fin de semana' => 1.15,
        'Corporativa' => 0.88,
    ];

    private const FLOORS = ['Piso 1', 'Piso 2', 'Piso 3', 'Piso 4'];

    /** Room number => [floor index, category, status, description]. */
    private const ROOMS = [
        '101' => [0, 'Simple', 'DISPONIBLE', 'Cama de 1 plaza, baño privado, TV por cable y Wi-Fi.'],
        '102' => [0, 'Simple', 'DISPONIBLE', 'Cama de 1 plaza, baño privado, TV por cable y Wi-Fi.'],
        '103' => [0, 'Doble', 'LIMPIEZA', 'Dos camas de 1 plaza, baño privado, TV por cable y Wi-Fi.'],
        '104' => [0, 'Doble', 'DISPONIBLE', 'Dos camas de 1 plaza, baño privado, TV por cable y Wi-Fi.'],
        '105' => [0, 'Matrimonial', 'DISPONIBLE', 'Cama de 2 plazas, baño privado, TV por cable y Wi-Fi.'],
        '201' => [1, 'Simple', 'DISPONIBLE', 'Cama de 1 plaza, baño privado, TV por cable y Wi-Fi.'],
        '202' => [1, 'Doble', 'DISPONIBLE', 'Dos camas de 1 plaza, baño privado, TV por cable y Wi-Fi.'],
        '203' => [1, 'Doble', 'DISPONIBLE', 'Dos camas de 1 plaza, baño privado, TV por cable y Wi-Fi.'],
        '204' => [1, 'Matrimonial', 'MANTENIMIENTO', 'Cama de 2 plazas, baño privado, TV por cable y Wi-Fi.'],
        '205' => [1, 'Matrimonial', 'DISPONIBLE', 'Cama de 2 plazas, baño privado, TV por cable y Wi-Fi.'],
        '301' => [2, 'Matrimonial', 'DISPONIBLE', 'Cama de 2 plazas, baño privado, frigobar y Wi-Fi.'],
        '302' => [2, 'Matrimonial', 'DISPONIBLE', 'Cama de 2 plazas, baño privado, frigobar y Wi-Fi.'],
        '303' => [2, 'Triple', 'DISPONIBLE', 'Una cama de 2 plazas y una de 1 plaza, baño privado y frigobar.'],
        '304' => [2, 'Triple', 'LIMPIEZA', 'Una cama de 2 plazas y una de 1 plaza, baño privado y frigobar.'],
        '401' => [3, 'Suite Junior', 'DISPONIBLE', 'Cama king, sala de estar, frigobar, aire acondicionado y Wi-Fi.'],
        '402' => [3, 'Suite Junior', 'DISPONIBLE', 'Cama king, sala de estar, frigobar, aire acondicionado y Wi-Fi.'],
        '403' => [3, 'Suite Ejecutiva', 'DISPONIBLE', 'Cama king, sala y escritorio, jacuzzi, aire acondicionado y Wi-Fi.'],
        '404' => [3, 'Suite Ejecutiva', 'DISPONIBLE', 'Cama king, sala y escritorio, jacuzzi, aire acondicionado y Wi-Fi.'],
    ];

    /**
     * Active stays. Room => [nights, check-in days ago (0 = today), check-in time, payment status,
     * guests, towels, notes]. These rooms are stored as OCUPADO, with the rent the "Recepción"
     * screen would have created.
     */
    private const STAYS = [
        '102' => [2, 1, '15:20', 'PAID', 1, 1, 'Viaje de trabajo. Solicita factura a nombre de la empresa.'],
        '105' => [3, 1, '13:05', 'PAID', 2, 2, 'Pareja en paseo. Solicita salida tardía el último día.'],
        '202' => [1, 0, null, 'DEBT', 2, 2, 'Pago pendiente: se cobra al momento de la salida.'],
        '402' => [2, 0, null, 'PAID', 2, 2, 'Aniversario. Solicita decoración de bienvenida.'],
    ];

    private $db;
    private $trackFile;
    private $track = [];
    private $now;
    private $customerOptions = [];

    public function handle()
    {
        $fqdn = $this->argument('fqdn');
        $this->trackFile = storage_path('app/demo-seed-hotel.json');

        $hostname = Hostname::where('fqdn', $fqdn)->first();
        if (!$hostname || !$hostname->website) {
            $this->error("Hostname {$fqdn} not found.");
            return 1;
        }
        app(Environment::class)->tenant($hostname->website);
        $this->db = DB::connection('tenant');

        $all = file_exists($this->trackFile) ? (json_decode(file_get_contents($this->trackFile), true) ?: []) : [];
        $already = !empty($all[$fqdn]);

        if ($already && !$this->option('fresh')) {
            $this->warn('Hotel demo data already seeded for this tenant. Use --fresh to rebuild it.');
            return 0;
        }

        if ($this->option('fresh') && $already) {
            $this->info('Removing previously seeded hotel rows...');
            try {
                $this->db->transaction(function () use ($all, $fqdn) {
                    $this->cleanup($all[$fqdn]);
                });
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return 1;
            }
            unset($all[$fqdn]);
            file_put_contents($this->trackFile, json_encode($all));
        } elseif (!$already && $this->hasHotelData()) {
            $this->warn('This tenant already has hotel data that was not seeded by this command; nothing was changed.');
            return 0;
        }

        $this->now = Carbon::now()->format('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $stats = $this->seed();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->error($e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            return 1;
        }

        $all[$fqdn] = $this->track;
        file_put_contents($this->trackFile, json_encode($all));

        $this->info('Hotel demo data seeded.');
        foreach ($stats as $label => $count) {
            $this->line(sprintf('  %-22s %d', $label, $count));
        }
        return 0;
    }

    private function hasHotelData(): bool
    {
        foreach (['hotel_rates', 'hotel_categories', 'hotel_floors', 'hotel_rooms'] as $table) {
            if ($this->db->table($table)->exists()) {
                return true;
            }
        }
        return false;
    }

    private function seed(): array
    {
        $warehouse = $this->db->table('warehouses')->orderBy('id')->first();
        if (!$warehouse) {
            throw new \RuntimeException('The tenant needs at least one warehouse before seeding rooms.');
        }

        $rateIds = [];
        foreach (array_keys(self::RATES) as $description) {
            $rateIds[$description] = $this->insert('hotel_rates', ['description' => $description, 'active' => 1]);
        }

        $categoryIds = [];
        foreach (array_keys(self::CATEGORIES) as $description) {
            $categoryIds[$description] = $this->insert('hotel_categories', ['description' => $description, 'image' => null, 'active' => 1]);
        }

        $floorIds = [];
        foreach (self::FLOORS as $index => $description) {
            $floorIds[$index] = $this->insert('hotel_floors', ['description' => $description, 'active' => 1]);
        }

        $roomRates = 0;
        $rooms = [];
        foreach (self::ROOMS as $name => [$floor, $category, $status, $description]) {
            $itemId = $this->insertRoomItem($name, $category, $warehouse->id);

            $roomId = $this->insert('hotel_rooms', [
                'item_id' => $itemId,
                'hotel_category_id' => $categoryIds[$category],
                'hotel_floor_id' => $floorIds[$floor],
                'name' => $name,
                'description' => $description,
                'status' => isset(self::STAYS[$name]) ? 'OCUPADO' : $status,
                'active' => 1,
            ]);

            $publicPrice = 0.0;
            foreach (self::RATES as $rate => $factor) {
                $price = $this->price(self::CATEGORIES[$category] * $factor);
                $this->insert('hotel_room_rates', [
                    'hotel_room_id' => $roomId,
                    'hotel_rate_id' => $rateIds[$rate],
                    'price' => $price,
                ]);
                $roomRates++;
                $publicPrice = $publicPrice ?: $price; // first rate is the public one
            }

            $rooms[$name] = ['id' => $roomId, 'item_id' => $itemId, 'price' => $publicPrice];
        }

        $stays = $this->seedStays($rooms);

        return [
            'Tarifas' => count($rateIds),
            'Categorías' => count($categoryIds),
            'Pisos' => count($floorIds),
            'Habitaciones' => count(self::ROOMS),
            'Precios por habitación' => $roomRates,
            'Estadías activas' => $stays,
        ];
    }

    /**
     * Creates the rent and its room line for each stay, in the exact shape HotelRentController@store
     * persists (customer option as the screen sends it, product row as calculateRowItem builds it).
     */
    private function seedStays(array $rooms): int
    {
        $customers = $this->customerOptions();
        if (count($customers) < count(self::STAYS)) {
            throw new \RuntimeException('At least '.count(self::STAYS).' customers with DNI or RUC are needed to seed stays.');
        }

        $this->actAsFirstUser();

        $created = 0;
        foreach (self::STAYS as $roomName => [$nights, $daysAgo, $time, $payment, $guests, $towels, $notes]) {
            $room = $rooms[$roomName];
            $customer = $customers[$created];

            $checkIn = Carbon::now();
            if ($daysAgo > 0) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $checkIn = $checkIn->copy()->subDays($daysAgo)->setTime($hour, $minute, 0);
            } else {
                $checkIn = $checkIn->copy()->subHours(2);
            }

            $rentId = $this->insert('hotel_rents', [
                'customer_id' => $customer['id'],
                'customer' => json_encode($customer),
                'notes' => $notes,
                'towels' => $towels,
                'hotel_room_id' => $room['id'],
                'duration' => $nights,
                'quantity_persons' => $guests,
                'payment_status' => $payment,
                'input_date' => $checkIn->format('Y-m-d'),
                'input_time' => $checkIn->format('H:i:s'),
                'output_date' => $checkIn->copy()->addDays($nights)->format('Y-m-d'),
                'output_time' => '12:00',
                'arrears' => 0,
                'status' => 'INICIADO',
            ]);

            $this->insert('hotel_rent_items', [
                'type' => 'HAB',
                'hotel_rent_id' => $rentId,
                'item_id' => $room['item_id'],
                'item' => json_encode($this->roomLine($room, $nights, $checkIn->format('Y-m-d'))),
                'payment_status' => $payment,
            ]);

            $created++;
        }

        return $created;
    }

    /** Customers shaped like the options the "Recepción" screen offers (HotelRentController::customers). */
    private function customerOptions(): array
    {
        if (!$this->customerOptions) {
            $this->customerOptions = Person::with('addresses')
                ->whereType('customers')
                ->whereIsEnabled()
                ->whereIn('identity_document_type_id', [1, 6])
                ->orderBy('id')
                ->take(count(self::STAYS))
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => $row->id,
                        'description' => $row->number.' - '.$row->name,
                        'name' => $row->name,
                        'number' => $row->number,
                        'identity_document_type_id' => $row->identity_document_type_id,
                        'identity_document_type_code' => $row->identity_document_type->code,
                        'addresses' => $row->addresses,
                        'address' => $row->address,
                        'internal_code' => $row->internal_code,
                    ];
                })->all();
        }

        return $this->customerOptions;
    }

    /** Item search endpoints read the signed-in user's establishment, so act as the first tenant user. */
    private function actAsFirstUser(): void
    {
        $user = User::orderBy('id')->first();
        if (!$user) {
            throw new \RuntimeException('The tenant has no users.');
        }
        Auth::setUser($user);
    }

    /**
     * The room line of a rent: the item exactly as /documents/search/item/{id} returns it, priced with
     * the public rate and rated like calculateRowItem does for a taxed (10) row with no discounts.
     */
    private function roomLine(array $room, int $nights, string $issueDate): array
    {
        $response = app(DocumentController::class)->searchItemById($room['item_id']);
        $payload = $response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : (array) $response;
        $item = $payload['items'][0] ?? null;
        if (!$item) {
            throw new \RuntimeException("Item {$room['item_id']} was not found by the document item search.");
        }

        $price = (float) $room['price'];
        $item['unit_price'] = $price; // the item carries IGV, so the typed price is already the unit price
        $igv = IgvRate::forTenant($issueDate, (bool) ($item['igv_general_rate'] ?? false));
        $unitValue = $price / (1 + $igv / 100);
        $totalValue = $unitValue * $nights;
        $totalIgv = $totalValue * $igv / 100;

        return [
            'item_id' => $room['item_id'],
            'item' => $item,
            'currency_type_id' => 'PEN',
            'quantity' => $nights,
            'unit_value' => $unitValue,
            'affectation_igv_type_id' => '10',
            'affectation_igv_type' => AffectationIgvType::find('10'),
            'total_base_igv' => round($totalValue, 2),
            'percentage_igv' => $igv,
            'igv_rate_locked' => false,
            'total_igv' => round($totalIgv, 2),
            'system_isc_type_id' => null,
            'total_base_isc' => 0,
            'percentage_isc' => 0,
            'total_isc' => 0,
            'total_base_other_taxes' => 0,
            'percentage_other_taxes' => 0,
            'total_other_taxes' => 0,
            'total_plastic_bag_taxes' => 0,
            'total_taxes' => round($totalIgv, 2),
            'price_type_id' => '01',
            'unit_price' => $price,
            'input_unit_price_value' => $price,
            'total_value' => round($totalValue, 2),
            'total_discount' => 0,
            'total_charge' => 0,
            'total' => round($totalValue + $totalIgv, 2),
            'attributes' => [],
            'charges' => [],
            'discounts' => [],
        ];
    }

    /** Whole soles, rounded to the nearest 5, which is how hotels usually publish rates. */
    private function price(float $value): float
    {
        return (float) (round($value / 5) * 5);
    }

    /**
     * The billable item behind a room. Mirrors the payload the "Habitaciones" screen posts to /items
     * (ZZ unit, product type, IGV included), so renting and checking out behave identically.
     */
    private function insertRoomItem(string $room, string $category, int $warehouseId): int
    {
        $name = 'Habitación '.$room;
        $itemId = $this->insert('items', [
            'name' => $name,
            'second_name' => $name,
            'description' => $name,
            'item_type_id' => '01',
            'unit_type_id' => 'ZZ',
            'currency_type_id' => 'PEN',
            'sale_unit_price' => self::CATEGORIES[$category],
            'purchase_unit_price' => 0,
            'purchase_has_igv' => 0,
            'has_igv' => 1,
            'has_isc' => 0,
            'amount_plastic_bag_taxes' => 0.10,
            'percentage_isc' => 0,
            'suggested_price' => 0,
            'sale_affectation_igv_type_id' => '10',
            'purchase_affectation_igv_type_id' => '10',
            'calculate_quantity' => 0,
            'is_set' => 0,
            'stock' => 0,
            'stock_min' => 1,
            'percentage_of_profit' => 0,
            'has_perception' => 0,
            'active' => 1,
            'warehouse_id' => $warehouseId,
            'status' => 1,
            'apply_store' => 0,
        ]);

        $this->insert('item_warehouse', ['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'stock' => 0]);

        return $itemId;
    }

    private function insert(string $table, array $row): int
    {
        if ($this->hasTimestamps($table)) {
            $row['created_at'] = $this->now;
            $row['updated_at'] = $this->now;
        }
        $id = (int) $this->db->table($table)->insertGetId($row);
        $this->track[$table][] = $id;

        return $id;
    }

    private function hasTimestamps(string $table): bool
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $cache[$table] = $this->db->getSchemaBuilder()->hasColumn($table, 'created_at');
        }

        return $cache[$table];
    }

    /** Removes exactly what a previous run inserted; refuses when a seeded room has rents it did not create. */
    private function cleanup(array $ids): void
    {
        $roomIds = $ids['hotel_rooms'] ?? [];
        $rentIds = $ids['hotel_rents'] ?? [];
        if ($roomIds && $this->db->table('hotel_rents')->whereIn('hotel_room_id', $roomIds)->whereNotIn('id', $rentIds)->exists()) {
            throw new \RuntimeException('Seeded rooms have rents created from Recepción; finish or remove them before using --fresh.');
        }
        if ($rentIds && $this->db->table('hotel_rents')->whereIn('id', $rentIds)->where('status', '!=', 'INICIADO')->exists()) {
            throw new \RuntimeException('A seeded stay was already checked out; --fresh would erase that history, so it was not run.');
        }

        $this->db->table('hotel_rent_items')->whereIn('hotel_rent_id', $rentIds)->delete();
        $this->db->table('hotel_rents')->whereIn('id', $rentIds)->delete();

        $itemIds = $ids['items'] ?? [];
        $usedItems = $itemIds
            ? $this->db->table('document_items')->whereIn('item_id', $itemIds)->distinct()->pluck('item_id')->all()
            : [];
        $removableItems = array_values(array_diff($itemIds, $usedItems));

        $this->db->table('hotel_room_rates')->whereIn('id', $ids['hotel_room_rates'] ?? [])->delete();
        $this->db->table('hotel_rooms')->whereIn('id', $roomIds)->delete();
        $this->db->table('hotel_rates')->whereIn('id', $ids['hotel_rates'] ?? [])->delete();
        $this->db->table('hotel_categories')->whereIn('id', $ids['hotel_categories'] ?? [])->delete();
        $this->db->table('hotel_floors')->whereIn('id', $ids['hotel_floors'] ?? [])->delete();
        $this->db->table('item_warehouse')->whereIn('item_id', $removableItems)->delete();
        $this->db->table('items')->whereIn('id', $removableItems)->delete();

        if ($usedItems) {
            $this->warn(count($usedItems).' room item(s) were kept because documents already reference them.');
        }
    }
}
