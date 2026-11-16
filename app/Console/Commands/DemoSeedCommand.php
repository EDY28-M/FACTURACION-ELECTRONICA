<?php

namespace App\Console\Commands;

use App\CoreFacturalo\Helpers\Number\NumberLetter;
use Carbon\Carbon;
use Hyn\Tenancy\Environment;
use Hyn\Tenancy\Models\Hostname;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills a tenant with a large, internally consistent set of fictitious sales-demo data
 * (catalog, customers, suppliers, sales, purchases, expenses, cash, banks, inventory, kardex).
 *
 * Usage:
 *   php artisan demo:seed                 # seeds net.localhost (refuses to run twice)
 *   php artisan demo:seed net.localhost --fresh   # removes previously seeded rows, then re-seeds
 *
 * How demo rows are identified (and removed by --fresh):
 *   Every primary key inserted by this command is recorded per table in
 *   storage/app/demo-seed.json (keyed by tenant fqdn). Child rows (items, payments, kardex,
 *   global payments, cash documents, ...) are removed through their parent ids. Rows created
 *   by real users are never touched. Without that file, --fresh refuses to delete anything.
 *   Seeded rows carry no visible marker; removal relies only on that ledger.

 *
 * Safety: nothing is sent to SUNAT, no XML/PDF/CDR is generated, no users or accounts are
 * created or modified, and the central (system) database is only read to locate the tenant.
 * Output is deterministic (fixed mt_srand seed) relative to the current date.
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {fqdn=net.localhost} {--fresh : Remove previously seeded demo rows first}';

    protected $description = 'Seed a tenant with realistic fictitious demo data for sales presentations';

    private const MARK = null; // no visible marker: rows are removed through the id ledger only
    private const BASE_PREFIX = '';

    private $db;
    private $fqdn;
    private $trackFile;
    private $track = [];
    private $buf = [];
    private $hasTs = [];
    private $now;
    private Carbon $today;
    private Carbon $start;

    private $company;
    private $userId;
    private $establishmentId;
    private $warehouseId;
    private $soapTypeId;
    private $establishmentJson;
    private $geo = [];
    private $idTypes = [];

    private $categories = [];
    private $brands = [];
    private $items = [];
    private $itemWeights = [];
    private $companies = [];
    private $persons = [];
    private $varios;
    private $suppliers = [];
    private $banks = ['pen' => [], 'usd' => null];
    private $cashByDate = [];
    private $cashIncome = [];
    private $seq = [];
    private $issuedInvoices = [];
    private $stats = [];

    // Models used as polymorphic types (must match what the app stores).
    private const T_DOC = 'App\Models\Tenant\Document';
    private const T_NV = 'App\Models\Tenant\SaleNote';
    private const T_PUR = 'App\Models\Tenant\Purchase';
    private const T_INV = 'Modules\Inventory\Models\Inventory';
    private const T_CASH = 'App\Models\Tenant\Cash';
    private const T_BANK = 'App\Models\Tenant\BankAccount';
    private const P_DOC = 'App\Models\Tenant\DocumentPayment';
    private const P_NV = 'App\Models\Tenant\SaleNotePayment';
    private const P_PUR = 'App\Models\Tenant\PurchasePayment';
    private const P_EXP = 'Modules\Expense\Models\ExpensePayment';
    private const P_INC = 'Modules\Finance\Models\IncomePayment';

    // ------------------------------------------------------------------------------------
    // Entry point
    // ------------------------------------------------------------------------------------

    public function handle()
    {
        ini_set('memory_limit', '1G');
        $this->fqdn = $this->argument('fqdn');
        $this->trackFile = storage_path('app/demo-seed.json');

        $hostname = Hostname::where('fqdn', $this->fqdn)->first();
        if (!$hostname || !$hostname->website) {
            $this->error("Hostname {$this->fqdn} not found.");
            return 1;
        }
        app(Environment::class)->tenant($hostname->website);
        $this->db = DB::connection('tenant');

        $all = file_exists($this->trackFile) ? (json_decode(file_get_contents($this->trackFile), true) ?: []) : [];
        $already = isset($all[$this->fqdn]) && !empty($all[$this->fqdn]);

        if ($already && !$this->option('fresh')) {
            $this->warn('Demo data already seeded for this tenant. Use --fresh to rebuild it.');
            return 0;
        }
        if ($this->option('fresh')) {
            if ($already) {
                $this->info('Removing previously seeded demo rows...');
                $this->cleanup($all[$this->fqdn]);
                unset($all[$this->fqdn]);
                $this->restoreQuantityDocuments($all);
                file_put_contents($this->trackFile, json_encode($all));
            } else {
                $this->warn('No tracking file entry found: nothing to remove.');
            }
        }

        if ($this->db->table('documents')->count() > 0 && !$this->option('fresh')) {
            $this->warn('The tenant already has documents; seeding anyway on top of real data.');
        }

        mt_srand(20261112);
        $this->today = Carbon::today();
        $this->start = $this->today->copy()->subYear()->addDay();
        $this->now = $this->today->format('Y-m-d').' 23:59:00';

        $this->info('Seeding demo data...');
        $t0 = microtime(true);
        $this->db->beginTransaction();
        try {
            $this->seed();
            $this->flush();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->error($e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            return 1;
        }

        $all[$this->fqdn] = $this->track;
        $this->syncQuantityDocuments($all);
        file_put_contents($this->trackFile, json_encode($all));
        $this->info(sprintf('Done in %.1fs. Tracking file: %s', microtime(true) - $t0, $this->trackFile));
        foreach ($this->stats as $k => $v) {
            $this->line(sprintf('  %-28s %s', $k, $v));
        }
        return 0;
    }

    // ------------------------------------------------------------------------------------
    // configurations.quantity_documents
    // The dashboard "CPE emitidos" card reads this counter, which the app increments when a document is
    // issued. Seeded rows bypass that, so the counter is set to the seeded total and the original value is
    // kept under the "_quantity_documents" key of the tracking file (never read as a tenant entry).
    // ------------------------------------------------------------------------------------

    private function syncQuantityDocuments(array &$all): void
    {
        $config = $this->db->table('configurations')->first();
        if (!$config) {
            return;
        }
        if (!isset($all['_quantity_documents'][$this->fqdn])) {
            $all['_quantity_documents'][$this->fqdn] = (int) $config->quantity_documents;
        }
        $seeded = count($this->track['documents'] ?? []);
        $this->db->table('configurations')->where('id', $config->id)->update(['quantity_documents' => $seeded]);
        $this->stats['quantity_documents'] = $seeded;
    }

    private function restoreQuantityDocuments(array &$all): void
    {
        if (!isset($all['_quantity_documents'][$this->fqdn])) {
            return;
        }
        $this->db->table('configurations')->update(['quantity_documents' => (int) $all['_quantity_documents'][$this->fqdn]]);
    }

    private function seed()
    {
        $this->loadRefs();
        $this->seedCatalog();
        $this->seedPeople();
        $this->seedBanks();
        $this->initialInventory();
        $this->runTimeline();
        $this->creditNotes();
        $this->stockAdjustments();
        $this->seedExpenses();
        $this->seedIncomes();
        $this->closeCashes();
        $this->finalizeStock();
        $this->flush();
        $this->collectStats();
    }

    // ------------------------------------------------------------------------------------
    // Random helpers
    // ------------------------------------------------------------------------------------

    private function rnd(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function chance(float $p): bool
    {
        return $this->rnd() < $p;
    }

    private function pick(array $a)
    {
        return $a[mt_rand(0, count($a) - 1)];
    }

    private function wpick(array $weights)
    {
        $total = array_sum($weights);
        $x = $this->rnd() * $total;
        foreach ($weights as $k => $w) {
            $x -= $w;
            if ($x <= 0) {
                return $k;
            }
        }
        return array_key_last($weights);
    }

    private function randn(): float
    {
        $u = max($this->rnd(), 1e-9);
        $v = $this->rnd();
        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }

    private function uuid(): string
    {
        $h = md5(mt_rand().'-'.mt_rand().'-'.mt_rand());
        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }

    private function digits(int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= mt_rand(0, 9);
        }
        return $s;
    }

    private function slug(string $s): string
    {
        $s = strtolower(strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', '&' => 'y']));
        $s = preg_replace('/\b(sac|srl|eirl|sa|saa)\b/', '', $s);
        return trim(preg_replace('/[^a-z0-9]+/', '', $s));
    }

    private function money(float $v): float
    {
        return round($v, 2);
    }

    // ------------------------------------------------------------------------------------
    // DB helpers
    // ------------------------------------------------------------------------------------

    private function stamp(string $table, array $row, ?string $ts): array
    {
        if (!isset($this->hasTs[$table])) {
            $this->hasTs[$table] = $this->db->getSchemaBuilder()->hasColumn($table, 'created_at');
        }
        if ($this->hasTs[$table]) {
            $ts = $ts ?: $this->now;
            $row['created_at'] = $ts;
            $row['updated_at'] = $ts;
        }
        return $row;
    }

    private function ins(string $table, array $row, ?string $ts = null, ?string $track = null): int
    {
        $id = $this->db->table($table)->insertGetId($this->stamp($table, $row, $ts));
        if ($track) {
            $this->track[$track][] = $id;
        }
        return (int) $id;
    }

    private function bulk(string $table, array $row, ?string $ts = null): void
    {
        $this->buf[$table][] = $this->stamp($table, $row, $ts);
        if (count($this->buf[$table]) >= 300) {
            $this->flush($table);
        }
    }

    private function flush(?string $table = null): void
    {
        $tables = $table ? [$table] : array_keys($this->buf);
        foreach ($tables as $t) {
            if (!empty($this->buf[$t])) {
                $this->db->table($t)->insert($this->buf[$t]);
                $this->buf[$t] = [];
            }
        }
    }

    private function js($v): ?string
    {
        return is_null($v) ? null : json_encode($v);
    }

    private function ts(string $ymd, int $sec): string
    {
        return $ymd.' '.gmdate('H:i:s', $sec);
    }

    private function rate(string $ymd): float
    {
        $d = Carbon::parse($ymd)->diffInDays($this->start);
        return round(3.52 + 0.08 * sin($d / 37) + 0.01 * sin($d * 1.7), 3);
    }

    // ------------------------------------------------------------------------------------
    // Reference data
    // ------------------------------------------------------------------------------------

    private function loadRefs(): void
    {
        $this->company = $this->db->table('companies')->first();
        $est = $this->db->table('establishments')->first();
        $user = $this->db->table('users')->orderBy('id')->first();
        $wh = $this->db->table('warehouses')->where('establishment_id', $est->id)->first();
        if (!$this->company || !$est || !$user || !$wh) {
            throw new \RuntimeException('Tenant must already have company, establishment, user and warehouse.');
        }
        $this->userId = $user->id;
        $this->establishmentId = $est->id;
        $this->warehouseId = $wh->id;
        $this->soapTypeId = $this->company->soap_type_id;

        foreach ($this->db->table('cat_identity_document_types')->get() as $r) {
            $this->idTypes[$r->id] = $r->description;
        }
        foreach ($this->db->table('departments')->get() as $r) {
            $this->geo['dep'][$r->id] = $r->description;
        }
        foreach ($this->db->table('provinces')->get() as $r) {
            $this->geo['prov'][$r->id] = $r->description;
        }
        foreach ($this->db->table('districts')->get() as $r) {
            $this->geo['dist'][$r->id] = $r->description;
        }
        $this->geo['country'] = $this->db->table('countries')->where('id', 'PE')->value('description') ?: 'PERU';

        $this->establishmentJson = $this->geoJson($est->district_id) + [
            'urbanization' => null,
            'address' => $est->address,
            'email' => $est->email,
            'telephone' => $est->telephone,
            'code' => $est->code,
            'trade_address' => $est->trade_address,
            'web_address' => $est->web_address,
            'aditional_information' => $est->aditional_information,
            'logo' => $est->logo,
        ];
    }

    private function geoJson(string $district): array
    {
        $prov = substr($district, 0, 4);
        $dep = substr($district, 0, 2);
        return [
            'country_id' => 'PE',
            'country' => ['id' => 'PE', 'description' => $this->geo['country']],
            'department_id' => $dep,
            'department' => ['id' => $dep, 'description' => $this->geo['dep'][$dep] ?? ''],
            'province_id' => $prov,
            'province' => ['id' => $prov, 'description' => $this->geo['prov'][$prov] ?? ''],
            'district_id' => $district,
            'district' => ['id' => $district, 'description' => $this->geo['dist'][$district] ?? ''],
        ];
    }

    // ------------------------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------------------------

    private function catalogData(): array
    {
        // name | price incl. IGV (PEN) | margin % | brand | flag (exo|ina|srv)
        return [
            ['MT', 'Mouse y Teclados', [
                'Mouse Inalámbrico M185 Gris|39.90|38|Logitech',
                'Mouse Óptico USB Alámbrico M90|24.90|42|Logitech',
                'Mouse Gamer RGB 6 Botones G203|129.00|32|Logitech',
                'Teclado Inalámbrico K270 Español|89.90|35|Logitech',
                'Combo Teclado y Mouse Inalámbrico MK235|119.00|34|Logitech',
                'Teclado USB Slim Español K120|45.00|40|Logitech',
                'Mouse Pad XL Antideslizante 70x30 cm|29.90|55|',
                'Teclado Mecánico Compacto Retroiluminado|189.00|33|HP',
            ]],
            ['AU', 'Audio y Auriculares', [
                'Audífonos con Micrófono USB H390|99.00|36|Logitech',
                'Audífonos Bluetooth Plegables Over-Ear|149.00|38|HP',
                'Audífonos In-Ear con Cable 3.5 mm y Micrófono|19.90|60|Samsung',
                'Audífonos Inalámbricos TWS con Estuche de Carga|129.00|40|Anker',
                'Parlante Bluetooth Portátil 10W|139.00|36|Anker',
                'Parlantes USB 2.0 Estéreo para PC|59.90|40|Logitech',
                'Micrófono de Condensador USB para Streaming|169.00|34|HP',
                'Adaptador de Audio Bluetooth 5.0 USB|35.90|50|TP-Link',
            ]],
            ['AL', 'Almacenamiento', [
                'Memoria USB 64 GB DataTraveler|29.90|40|Kingston',
                'Memoria USB 128 GB DataTraveler|44.90|38|Kingston',
                'Memoria USB 256 GB USB 3.2|79.90|34|Kingston',
                'Tarjeta MicroSD 128 GB con Adaptador|49.90|38|Samsung',
                'Tarjeta MicroSD 64 GB Clase 10|32.90|40|Samsung',
                'Disco Sólido SSD 480 GB SATA|189.00|28|Kingston',
                'Disco Sólido SSD 1 TB NVMe M.2|329.00|27|Samsung',
                'Disco Duro Externo 1 TB USB 3.0|219.00|26|Samsung',
            ]],
            ['CB', 'Cables y Adaptadores', [
                'Cable HDMI 2.0 de 1.8 m|24.90|55|Anker',
                'Cable USB-C a USB-C 1 m Carga Rápida|29.90|55|Anker',
                'Cable Lightning a USB Certificado 1 m|39.90|50|Anker',
                'Cable de Red UTP Cat6 de 3 m|12.90|60|TP-Link',
                'Adaptador USB-C a HDMI 4K|59.90|45|Anker',
                'Hub USB 3.0 de 4 Puertos|45.90|45|Anker',
                'Adaptador VGA a HDMI con Audio|42.00|45|',
                'Extensión USB 2.0 de 3 m|15.90|60|',
            ]],
            ['RD', 'Redes y Conectividad', [
                'Router Inalámbrico WiFi AC1200 Doble Banda|139.00|30|TP-Link',
                'Router WiFi 6 AX1500|229.00|27|TP-Link',
                'Repetidor de Señal WiFi 300 Mbps|69.90|38|TP-Link',
                'Switch de Red 8 Puertos Gigabit|89.00|32|TP-Link',
                'Adaptador WiFi USB Nano 150 Mbps|29.90|50|TP-Link',
                'Access Point Empotrable PoE|259.00|27|TP-Link',
                'Rollo de Cable UTP Cat5e 305 m|329.00|25|',
                'Conectores RJ45 Cat6 (Bolsa x 100)|49.00|50|',
            ]],
            ['IM', 'Impresoras y Tintas', [
                'Impresora Multifuncional EcoTank L3250|899.00|20|Epson',
                'Impresora Multifuncional DeskJet 2775|329.00|24|HP',
                'Botella de Tinta Negra 003 de 65 ml|39.90|45|Epson',
                'Botella de Tinta Color 003 de 65 ml|39.90|45|Epson',
                'Cartucho de Tinta Negro HP 664|59.90|40|HP',
                'Cartucho de Tinta Tricolor HP 664|69.90|40|HP',
                'Tóner Láser Negro 105A|189.00|33|HP',
                'Papel Fotográfico Glossy A4 (Paq. x 50)|45.00|45|Epson',
            ]],
            ['EN', 'Energía y Cargadores', [
                'Cargador de Pared USB-C 20W|39.90|50|Samsung',
                'Cargador Rápido 65W GaN Doble Puerto|119.00|38|Anker',
                'Power Bank 10000 mAh|99.00|38|Anker',
                'Power Bank 20000 mAh 22.5W|159.00|34|Anker',
                'Estabilizador de Voltaje 1000VA|129.00|33|',
                'UPS Interactivo 750VA|289.00|27|',
                'Regleta Protectora 6 Tomas con Supresor de Picos|49.90|50|',
                'Pilas AA Alcalinas (Pack x 8)|24.90|50|',
            ]],
            ['CM', 'Computadoras y Monitores', [
                'Laptop 15.6" Core i5 8 GB / 512 GB SSD|2599.00|16|HP',
                'Laptop 14" Ryzen 5 16 GB / 512 GB SSD|2899.00|15|HP',
                'Monitor LED 24" Full HD HDMI|429.00|25|Samsung',
                'Monitor Curvo 27" Full HD 75 Hz|649.00|22|Samsung',
                'Tablet 10.4" 64 GB WiFi|799.00|20|Samsung',
                'Webcam Full HD 1080p con Micrófono|139.00|36|Logitech',
                'Soporte Ajustable para Laptop de Aluminio|59.90|50|',
                'Proyector LED Portátil 3500 Lúmenes|1299.00|18|Epson',
            ]],
            ['PA', 'Papelería', [
                'Papel Bond A4 75 g (Paq. 500 hojas)|16.90|30|',
                'Papel Bond A4 80 g Premium (Paq. 500 hojas)|19.90|30|',
                'Papel Bond Oficio 75 g (Paq. 500 hojas)|21.90|30|',
                'Caja de Papel Bond A4 (10 Paq.)|159.00|25|',
                'Block de Notas Adhesivas 76x76 mm|6.90|60|3M',
                'Cinta de Embalaje Transparente 48 mm|5.90|60|3M',
                'Sobres Manila A4 (Paq. x 50)|28.00|45|Artesco',
                'Etiquetas Adhesivas Blancas A4 (Caja x 100)|35.90|45|',
            ]],
            ['UE', 'Útiles de Escritorio', [
                'Engrapador Metálico 24/6|19.90|55|Artesco',
                'Grapas 26/6 (Caja x 5000)|4.50|60|Artesco',
                'Perforador de 2 Huecos 20 Hojas|24.90|50|Artesco',
                'Tijera de Oficina 21 cm|8.90|55|Faber-Castell',
                'Pegamento en Barra 40 g|5.50|55|Faber-Castell',
                'Calculadora de Escritorio 12 Dígitos|34.90|45|',
                'Corrector Líquido 20 ml|3.90|60|Artesco',
                'Cinta Adhesiva Invisible 19 mm x 33 m|4.90|60|3M',
            ]],
            ['AR', 'Archivo y Organización', [
                'Folder Manila A4 (Paq. x 25)|14.90|50|Artesco',
                'Archivador de Palanca Lomo Ancho A4|11.90|50|Artesco',
                'Archivador de Palanca Tamaño Oficio|12.90|50|Artesco',
                'Clips Metálicos N°1 (Caja x 100)|2.50|60|Artesco',
                'Organizador de Escritorio 5 Compartimentos|39.90|45|',
                'Fólder Plástico con Fastener (Paq. x 12)|18.90|50|Artesco',
                'Separadores de Colores A4 (Juego x 10)|9.90|55|Artesco',
                'Bandeja Apilable para Documentos|29.90|45|',
            ]],
            ['ES', 'Escritura y Dibujo', [
                'Lapicero Azul Punta Fina (Caja x 50)|45.00|40|Faber-Castell',
                'Lapicero Negro Punta Media (Caja x 12)|18.00|45|Faber-Castell',
                'Resaltador Fluorescente (Set x 6)|19.90|45|Faber-Castell',
                'Plumón de Pizarra Acrílica (Set x 4)|16.90|50|Faber-Castell',
                'Marcador Permanente Punta Gruesa (Caja x 12)|36.00|45|Faber-Castell',
                'Lápiz HB (Caja x 12)|14.90|50|Faber-Castell',
                'Colores Largos x 12|9.90|50|Faber-Castell',
                'Cuaderno A4 Cuadriculado 100 Hojas|7.90|50|Artesco',
            ]],
            ['MO', 'Mobiliario de Oficina', [
                'Silla Ejecutiva Ergonómica con Respaldo Alto|389.00|28|',
                'Silla Operativa de Malla Giratoria|229.00|30|',
                'Escritorio Melamine 120x60 cm|329.00|28|',
                'Escritorio en L con Cajonera|649.00|25|',
                'Archivador Metálico de 4 Gavetas|599.00|24|',
                'Estante Organizador de 5 Niveles|289.00|28|',
                'Pizarra Acrílica 90x60 cm con Marco|99.00|35|',
                'Reposapiés Ajustable Ergonómico|69.90|42|',
            ]],
            ['LB', 'Libros y Material Educativo', [
                'Libro Excel Avanzado para Negocios|59.00|35||exo',
                'Libro Contabilidad Básica para Emprendedores|49.00|35||exo',
                'Libro Guía de Facturación Electrónica|55.00|35||exo',
                'Libro Marketing Digital para Pymes|62.00|35||exo',
                'Diccionario Escolar Español Ilustrado|38.00|35||exo',
                'Atlas Geográfico del Perú|45.00|35||exo',
                'Libro Inglés Técnico para Oficinas|52.00|35||exo',
                'Texto de Matemática Secundaria Vol. 1|42.00|35||exo',
            ]],
            ['SV', 'Servicios Técnicos', [
                'Soporte Técnico en Sitio (por hora)|80.00|0||srv',
                'Mantenimiento Preventivo de PC o Laptop|90.00|0||srv',
                'Instalación y Configuración de Red LAN (por punto)|120.00|0||srv',
                'Formateo e Instalación de Windows y Office|70.00|0||srv',
                'Configuración de Impresora en Red|60.00|0||srv',
                'Recuperación de Información de Disco|250.00|0||srv',
                'Curso de Ofimática Empresarial|350.00|0||ina',
                'Servicio de Delivery en Lima Metropolitana|15.00|0||srv',
            ]],
        ];
    }

    private function seedCatalog(): void
    {
        foreach (['Logitech', 'HP', 'Epson', 'Kingston', 'TP-Link', 'Samsung', 'Faber-Castell', 'Artesco', '3M', 'Anker'] as $b) {
            $this->brands[$b] = $this->ins('brands', ['name' => $b], $this->start->format('Y-m-d').' 09:00:00', 'brands');
        }

        $ean = [];
        $total = 0;
        foreach ($this->catalogData() as [$code, $catName, $rows]) {
            $catId = $this->ins('categories', ['name' => $catName], $this->start->format('Y-m-d').' 09:00:00', 'categories');
            $n = 0;
            foreach ($rows as $row) {
                $p = explode('|', $row);
                $name = $p[0];
                $price = (float) $p[1];
                $margin = (float) $p[2];
                $brand = $p[3] ?? '';
                $flag = $p[4] ?? '';
                $n++;
                $isService = $flag === 'srv' || $flag === 'ina';
                $aff = $flag === 'exo' ? '20' : ($flag === 'ina' ? '30' : '10');
                $cost = $isService ? 0.0 : round($price / (1 + $margin / 100), 2);

                do {
                    $base = '775'.$this->digits(9);
                    $sum = 0;
                    foreach (str_split($base) as $i => $d) {
                        $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
                    }
                    $barcode = $base.((10 - $sum % 10) % 10);
                } while (isset($ean[$barcode]));
                $ean[$barcode] = 1;

                $internal = $code.'-'.str_pad($n, 3, '0', STR_PAD_LEFT);
                $id = $this->ins('items', [
                    'name' => $name,
                    'second_name' => null,
                    'description' => $name,
                    'model' => null,
                    'barcode' => $isService ? null : $barcode,
                    'item_type_id' => $isService ? '02' : '01',
                    'internal_id' => $internal,
                    'item_code' => null,
                    'unit_type_id' => $isService ? 'ZZ' : 'NIU',
                    'currency_type_id' => 'PEN',
                    'sale_unit_price' => $price,
                    'purchase_has_igv' => 1,
                    'has_igv' => 1,
                    'purchase_unit_price' => $cost,
                    'has_isc' => 0,
                    'amount_plastic_bag_taxes' => 0.10,
                    'percentage_isc' => 0,
                    'suggested_price' => 0,
                    'sale_affectation_igv_type_id' => $aff,
                    'purchase_affectation_igv_type_id' => $aff,
                    'calculate_quantity' => 0,
                    'is_set' => 0,
                    'category_id' => $catId,
                    'brand_id' => $brand !== '' ? $this->brands[$brand] : null,
                    'stock' => 0,
                    'stock_min' => 0,
                    'percentage_of_profit' => $margin,
                    'has_perception' => 0,
                    'active' => 1,
                    'warehouse_id' => $this->warehouseId,
                    'status' => 1,
                    'apply_store' => 0,
                ], $this->start->format('Y-m-d').' 09:30:00', 'items');

                $pop = max(0.15, exp($this->randn() * 0.75) * pow(60 / max($price, 8), 0.3));
                $this->items[] = [
                    'id' => $id,
                    'name' => $name,
                    'internal_id' => $internal,
                    'unit' => $isService ? 'ZZ' : 'NIU',
                    'price' => $price,
                    'cost' => $cost,
                    'aff' => $aff,
                    'cat' => $code,
                    'service' => $isService,
                    'pop' => $pop,
                    'stock' => 0,
                    'rp' => 0,
                    'target' => 0,
                    'supplier' => null,
                    'restock_until' => $this->today->format('Y-m-d'),
                    'final' => null,
                ];
                $total++;
            }
        }

        // normalise popularity and set replenishment parameters
        $goods = array_filter($this->items, fn ($i) => !$i['service']);
        $mean = array_sum(array_column($goods, 'pop')) / max(1, count($goods));
        foreach ($this->items as $k => &$it) {
            $it['pop'] = $it['pop'] / $mean;
            if ($it['service']) {
                continue;
            }
            $p = $it['pop'];
            if ($it['price'] < 30) {
                $it['rp'] = (int) round(24 + 12 * $p);
                $it['target'] = (int) round($it['rp'] + 18 + 12 * $p);
                $it['step'] = 10;
            } elseif ($it['price'] < 150) {
                $it['rp'] = (int) round(14 + 6 * $p);
                $it['target'] = (int) round($it['rp'] + 9 + 6 * $p);
                $it['step'] = 5;
            } elseif ($it['price'] < 500) {
                $it['rp'] = (int) round(9 + 3 * $p);
                $it['target'] = (int) round($it['rp'] + 7 + 4 * $p);
                $it['step'] = 2;
            } else {
                $it['rp'] = (int) round(3 + 2 * $p);
                $it['target'] = (int) round($it['rp'] + 4 + 3 * $p);
                $it['step'] = 1;
            }
        }
        unset($it);

        // low stock / zero stock showcase items (picked among the least popular goods)
        $idx = array_keys($goods);
        usort($idx, fn ($a, $b) => $this->items[$a]['pop'] <=> $this->items[$b]['pop']);
        $pool = array_slice($idx, 0, 45);
        shuffle($pool);
        foreach (array_slice($pool, 0, 14) as $k) {
            $this->items[$k]['final'] = mt_rand(2, 15);
            $this->items[$k]['restock_until'] = $this->today->copy()->subDays(mt_rand(25, 70))->format('Y-m-d');
        }
        foreach (array_slice($pool, 14, 6) as $k) {
            $this->items[$k]['final'] = 0;
            $this->items[$k]['restock_until'] = $this->today->copy()->subDays(mt_rand(40, 90))->format('Y-m-d');
        }
    }

    // ------------------------------------------------------------------------------------
    // People
    // ------------------------------------------------------------------------------------

    private function rucWithCheck(string $prefix): string
    {
        $base = $prefix.$this->digits(8);
        $w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $s = 0;
        foreach (str_split($base) as $i => $d) {
            $s += (int) $d * $w[$i];
        }
        return $base.((11 - ($s % 11)) % 10);
    }

    private function seedPeople(): void
    {
        $districts = array_values(array_filter([
            '150101', '150122', '150131', '150140', '150103', '150135', '150117', '150114', '150132', '150142',
            '040101', '040112', '130101', '140101', '200101', '080101', '120101', '110101', '230101', '060101',
        ], fn ($d) => isset($this->geo['dist'][$d])));
        $limaW = array_map(fn ($d) => str_starts_with($d, '1501') ? 5 : 1, $districts);

        $streets = ['Av. Javier Prado', 'Av. Arequipa', 'Jr. de la Unión', 'Av. Larco', 'Av. Brasil', 'Calle Los Pinos', 'Av. Universitaria', 'Av. Benavides', 'Av. La Marina', 'Jr. Huallaga', 'Av. Túpac Amaru', 'Av. Venezuela', 'Calle Las Begonias', 'Av. Salaverry', 'Av. Petit Thouars', 'Av. Colonial', 'Av. Grau', 'Av. Bolognesi', 'Jr. Ayacucho', 'Av. El Sol'];

        $first = ['Luis', 'Carlos', 'José', 'Juan', 'Miguel', 'Jorge', 'Pedro', 'Víctor', 'Raúl', 'Hugo', 'César', 'Daniel', 'Alberto', 'Fernando', 'Ricardo', 'Manuel', 'Walter', 'Óscar', 'Marco', 'Diego', 'María', 'Rosa', 'Carmen', 'Ana', 'Lucía', 'Patricia', 'Julia', 'Elena', 'Sofía', 'Karen', 'Milagros', 'Yessica', 'Silvia', 'Gladys', 'Paola', 'Claudia', 'Fiorella', 'Nancy', 'Rocío', 'Vanessa'];
        $last = ['Quispe', 'Huamán', 'Mamani', 'Flores', 'Rojas', 'García', 'Rodríguez', 'Torres', 'Vargas', 'Castillo', 'Chávez', 'Mendoza', 'Ramírez', 'Salazar', 'Paredes', 'Cárdenas', 'Villanueva', 'Espinoza', 'Cruz', 'Ríos', 'Gutiérrez', 'Delgado', 'Ccopa', 'Condori', 'Soto', 'Medina', 'Aguilar', 'Núñez', 'Palomino', 'Zevallos', 'Bustamante', 'Ayala', 'Lazo', 'Valdivia', 'Cabrera', 'Peralta'];

        $mkAddress = function () use ($districts, $limaW, $streets) {
            $w = array_combine(array_keys($districts), $limaW);
            $dist = $districts[$this->wpick($w)];
            return [$dist, $this->pick($streets).' '.mt_rand(100, 2999).(mt_rand(0, 3) === 0 ? ', Of. '.mt_rand(101, 805) : '')];
        };

        $usedNumbers = [];
        $mkPerson = function (string $type, string $idType, string $number, string $name, ?string $trade, string $code, ?int $personType) use ($mkAddress, &$usedNumbers) {
            [$dist, $addr] = $mkAddress();
            $g = $this->geoJson($dist);
            $isCompany = $idType === '6';
            $email = ($isCompany ? $this->pick(['ventas', 'compras', 'contacto', 'administracion', 'logistica']).'@' : $this->slug($name).mt_rand(1, 99).'@').($isCompany ? $this->slug($trade ?: $name) : 'correo').'.pe';
            $phone = $isCompany ? '01'.$this->digits(7) : '9'.$this->digits(8);
            $id = $this->ins('persons', [
                'type' => $type,
                'identity_document_type_id' => $idType,
                'number' => $number,
                'name' => $name,
                'trade_name' => $trade,
                'internal_code' => $code,
                'country_id' => 'PE',
                'department_id' => $g['department_id'],
                'province_id' => $g['province_id'],
                'district_id' => $dist,
                'address' => $addr,
                'condition' => $isCompany ? 'HABIDO' : null,
                'state' => $isCompany ? 'ACTIVO' : null,
                'email' => $email,
                'telephone' => $phone,
                'perception_agent' => 0,
                'person_type_id' => $type === 'customers' ? ($this->chance(0.2) ? 2 : 1) : null,
                'comment' => self::MARK,
                'enabled' => 1,
                'status' => 1,
            ], $this->start->copy()->addDays(mt_rand(0, 200))->format('Y-m-d').' 10:00:00', 'persons');
            return [
                'id' => $id, 'idType' => $idType, 'number' => $number, 'name' => $name, 'trade' => $trade,
                'dist' => $dist, 'address' => $addr, 'email' => $email, 'phone' => $phone, 'geo' => $g,
            ];
        };

        // Customers: one generic consumer ("Clientes Varios")
        $this->varios = $mkPerson('customers', '0', '99999999', 'CLIENTES VARIOS', null, self::BASE_PREFIX.'CLI-0000', 1);

        // Companies
        $prefix = ['Inversiones', 'Corporación', 'Comercial', 'Grupo', 'Servicios Generales', 'Constructora', 'Consultores', 'Distribuidora', 'Importaciones', 'Agroindustrias', 'Transportes', 'Estudio', 'Clínica', 'Colegio', 'Instituto', 'Ferretería', 'Textiles', 'Panadería', 'Operadora', 'Soluciones'];
        $mid = ['Andina', 'del Sur', 'Pacífico', 'Los Olivos', 'Inti', 'San Martín', 'Horizonte', 'Nueva Era', 'Santa Rosa', 'Kuntur', 'Wari', 'Illari', 'del Norte', 'Altiplano', 'Amazonas', 'Moche', 'Chavín', 'Sol Naciente', 'Central', 'Tawantinsuyo', 'Rímac', 'Cóndor', 'Pachacútec', 'Misti', 'Lima Norte', 'Perú Digital', 'Continental', 'Los Andes', 'Valle Verde', 'Costa Azul'];
        $suffix = ['S.A.C.', 'S.A.C.', 'S.A.C.', 'E.I.R.L.', 'S.R.L.', 'S.A.'];
        $usedNames = [];
        for ($i = 1; $i <= 46; $i++) {
            do {
                $nm = $this->pick($prefix).' '.$this->pick($mid).' '.$this->pick($suffix);
            } while (isset($usedNames[$nm]));
            $usedNames[$nm] = 1;
            do {
                $ruc = $this->rucWithCheck('20');
            } while (isset($usedNumbers[$ruc]));
            $usedNumbers[$ruc] = 1;
            $trade = explode(' ', $nm);
            $c = $mkPerson('customers', '6', $ruc, mb_strtoupper($nm), null, self::BASE_PREFIX.'CLI-'.str_pad($i, 4, '0', STR_PAD_LEFT), 1);
            $c['w'] = exp($this->randn() * 1.0);
            $c['usd'] = $this->chance(0.12);
            $this->companies[] = $c;
        }

        // Natural persons
        for ($i = 47; $i <= 80; $i++) {
            do {
                $dni = (string) mt_rand(10000000, 79999999);
            } while (isset($usedNumbers[$dni]));
            $usedNumbers[$dni] = 1;
            $nm = $this->pick($last).' '.$this->pick($last).' '.$this->pick($first);
            $c = $mkPerson('customers', '1', $dni, mb_strtoupper($nm), null, self::BASE_PREFIX.'CLI-'.str_pad($i, 4, '0', STR_PAD_LEFT), 1);
            $c['w'] = exp($this->randn() * 0.8);
            $this->persons[] = $c;
        }

        // Suppliers: 0..6 are service providers (expenses), 7.. are goods suppliers
        $services = [
            ['Inmobiliaria Rímac Sur S.A.C.', '20'],
            ['Distribuidora Eléctrica Andina S.A.', '20'],
            ['Servicios de Agua Potable Lima Norte S.A.', '20'],
            ['Telecomunicaciones Andinas Perú S.A.A.', '20'],
            ['Estudio Contable Quispe & Asociados', '10'],
            ['Planilla de Colaboradores KeraAI', '0'],
            ['Servicios Generales y Mantenimiento Lima S.A.C.', '20'],
        ];
        $goods = [
            ['Importaciones Tecnoandes S.A.C.', false], ['Distribuidora Pacífico Computer E.I.R.L.', false],
            ['Comercial Sudamericana de Informática S.A.', false], ['Mayorista Útiles del Perú S.A.C.', false],
            ['Papelera Nacional del Sur S.A.', false], ['Corporación Oficentro S.A.C.', false],
            ['Inversiones Kyber Trade S.A.C.', false], ['Global Tech Supply Perú S.A.C.', true],
            ['Importadora Lima Digital S.R.L.', false], ['Redes y Sistemas Andinos S.A.C.', false],
            ['Tinta y Tóner Express S.A.C.', false], ['Muebles de Oficina Santa Rosa E.I.R.L.', false],
            ['Libros y Textos Horizonte S.A.C.', false], ['Distribuidora Escolar Arequipa S.A.C.', false],
            ['Electro Power Perú S.A.C.', false], ['Cables y Conectores Industriales S.A.C.', false],
            ['Importaciones Oriente Tech S.A.C.', true], ['Almacenes Ofiservice S.A.C.', false],
            ['Sumi-Tech Mayorista S.A.C.', false],
        ];
        $n = 0;
        foreach ($services as [$nm, $kind]) {
            $n++;
            if ($kind === '0') {
                $s = $mkPerson('suppliers', '0', '00000000', mb_strtoupper($nm), null, self::BASE_PREFIX.'PRO-'.str_pad($n, 3, '0', STR_PAD_LEFT), null);
            } else {
                $num = $kind === '10' ? $this->rucWithCheck('10') : $this->rucWithCheck('20');
                $s = $mkPerson('suppliers', '6', $num, mb_strtoupper($nm), null, self::BASE_PREFIX.'PRO-'.str_pad($n, 3, '0', STR_PAD_LEFT), null);
            }
            $s['usd'] = false;
            $this->suppliers[] = $s;
        }
        foreach ($goods as [$nm, $usd]) {
            $n++;
            $s = $mkPerson('suppliers', '6', $this->rucWithCheck('20'), mb_strtoupper($nm), null, self::BASE_PREFIX.'PRO-'.str_pad($n, 3, '0', STR_PAD_LEFT), null);
            $s['usd'] = $usd;
            $s['pseries'] = 'F'.mt_rand(100, 999);
            $s['pnum'] = mt_rand(100, 9000);
            $this->suppliers[] = $s;
        }

        // category -> suppliers (indexes into $goods list, offset 6)
        $map = [
            'MT' => [0, 6], 'AU' => [0, 8], 'AL' => [6, 7], 'CB' => [14, 0], 'RD' => [9, 6], 'IM' => [10, 1],
            'EN' => [13, 16], 'CM' => [2, 7], 'PA' => [4, 3], 'UE' => [3, 17], 'AR' => [3, 5], 'ES' => [17, 5],
            'MO' => [11, 5], 'LB' => [12, 13], 'SV' => [],
        ];
        foreach ($this->items as $k => &$it) {
            if ($it['service']) {
                continue;
            }
            $opts = $map[$it['cat']];
            $it['supplier'] = 7 + $opts[$k % count($opts)];
            $it['suppliers'] = array_map(fn ($o) => 7 + $o, $opts);
        }
        unset($it);
    }

    private function personJson(array $p): array
    {
        return [
            'identity_document_type_id' => $p['idType'],
            'identity_document_type' => ['id' => $p['idType'], 'description' => $this->idTypes[$p['idType']] ?? ''],
            'number' => $p['number'],
            'name' => $p['name'],
            'trade_name' => $p['trade'],
            'country_id' => 'PE',
            'country' => $p['geo']['country'],
            'department_id' => $p['geo']['department_id'],
            'department' => $p['geo']['department'],
            'province_id' => $p['geo']['province_id'],
            'province' => $p['geo']['province'],
            'district_id' => $p['dist'],
            'district' => $p['geo']['district'],
            'address' => $p['address'],
            'email' => $p['email'],
            'telephone' => $p['phone'],
            'perception_agent' => 0,
            'address_id' => null,
            'internal_code' => null,
        ];
    }

    private function seedBanks(): void
    {
        $ts = $this->start->format('Y-m-d').' 09:00:00';
        $this->banks['pen'] = [
            $this->ins('bank_accounts', ['bank_id' => 2, 'description' => 'Cuenta Corriente Soles', 'number' => '194-'.$this->digits(7).'-0-'.$this->digits(2), 'cci' => '002194'.$this->digits(12), 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 15000, 'show_in_documents' => 1], $ts, 'bank_accounts'),
            $this->ins('bank_accounts', ['bank_id' => 6, 'description' => 'Cuenta de Ahorros Soles', 'number' => '898-'.$this->digits(10), 'cci' => '003898'.$this->digits(12), 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 8000, 'show_in_documents' => 1], $ts, 'bank_accounts'),
        ];
        $this->banks['usd'] = $this->ins('bank_accounts', ['bank_id' => 5, 'description' => 'Cuenta Corriente Dólares', 'number' => '0011-'.$this->digits(4).'-'.$this->digits(10), 'cci' => '011'.$this->digits(15), 'currency_type_id' => 'USD', 'status' => 1, 'initial_balance' => 3000, 'show_in_documents' => 1], $ts, 'bank_accounts');
    }

    // ------------------------------------------------------------------------------------
    // Inventory
    // ------------------------------------------------------------------------------------

    private function initialInventory(): void
    {
        $date = $this->start->format('Y-m-d');
        foreach ($this->items as $k => &$it) {
            if ($it['service']) {
                continue;
            }
            $qty = max(2, (int) round($it['target'] * (0.8 + 0.2 * $this->rnd())));
            $it['stock'] = $qty;
            $this->recordInventory($it['id'], '1', 'Stock inicial', '16', $qty, $date, 9 * 3600);
        }
        unset($it);
    }

    private function recordInventory(int $itemId, string $type, string $desc, string $transaction, float $qty, string $ymd, int $sec): void
    {
        $ts = $this->ts($ymd, $sec);
        $invId = $this->ins('inventories', [
            'type' => $type,
            'description' => $desc,
            'item_id' => $itemId,
            'warehouse_id' => $this->warehouseId,
            'inventory_transaction_id' => $transaction,
            'quantity' => $qty,
        ], $ts, 'inventories');
        $this->bulk('inventory_kardex', [
            'date_of_issue' => $ymd,
            'item_id' => $itemId,
            'inventory_kardexable_id' => $invId,
            'inventory_kardexable_type' => self::T_INV,
            'warehouse_id' => $this->warehouseId,
            'quantity' => $type === '1' ? $qty : -$qty,
        ], $ts);
    }

    private function kardexOut(string $model, int $modelId, int $itemId, float $qty, string $ymd, int $sec, string $legacyKey = null, string $legacyType = null): void
    {
        $ts = $this->ts($ymd, $sec);
        $this->bulk('inventory_kardex', [
            'date_of_issue' => $ymd,
            'item_id' => $itemId,
            'inventory_kardexable_id' => $modelId,
            'inventory_kardexable_type' => $model,
            'warehouse_id' => $this->warehouseId,
            'quantity' => $qty,
        ], $ts);
        if ($legacyKey) {
            $this->bulk('kardex', [
                'date_of_issue' => $ymd,
                'type' => $legacyType,
                'item_id' => $itemId,
                'document_id' => $legacyKey === 'document_id' ? $modelId : null,
                'purchase_id' => $legacyKey === 'purchase_id' ? $modelId : null,
                'sale_note_id' => $legacyKey === 'sale_note_id' ? $modelId : null,
                'quantity' => abs($qty),
            ], $ts);
        }
    }

    // ------------------------------------------------------------------------------------
    // Timeline
    // ------------------------------------------------------------------------------------

    private function planEvents(): array
    {
        $season = [1 => 0.85, 2 => 0.8, 3 => 1.15, 4 => 1.0, 5 => 1.15, 6 => 1.0, 7 => 1.1, 8 => 0.95, 9 => 1.0, 10 => 1.1, 11 => 1.35, 12 => 1.5];
        $holidays = ['12-25', '01-01', '07-28', '07-29', '05-01', '10-08', '11-01', '06-29', '08-30', '12-08'];
        $days = [];
        $w = [];
        for ($d = $this->start->copy(); $d <= $this->today; $d->addDay()) {
            $ymd = $d->format('Y-m-d');
            $t = $d->diffInDays($this->start) / 365;
            $dow = $d->dayOfWeek;
            $weekday = $dow === 0 ? 0.3 : ($dow === 6 ? 1.25 : 1.0);
            $hol = in_array($d->format('m-d'), $holidays) ? 0.1 : 1.0;
            $w[$ymd] = (0.6 + 0.75 * $t) * $season[$d->month] * $weekday * $hol * (0.7 + 0.6 * $this->rnd());
            $days[] = $ymd;
        }
        $total = array_sum($w);
        $events = [];
        $plan = ['doc' => 780, 'nv' => 150, 'quote' => 45, 'order' => 25];
        foreach ($plan as $type => $n) {
            for ($i = 0; $i < $n; $i++) {
                $x = $this->rnd() * $total;
                $chosen = end($days);
                foreach ($w as $ymd => $wt) {
                    $x -= $wt;
                    if ($x <= 0) {
                        $chosen = $ymd;
                        break;
                    }
                }
                $events[$chosen][] = [mt_rand(8 * 3600 + 1800, 19 * 3600 + 1800), $type];
            }
        }
        foreach ($events as &$list) {
            usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
        }
        unset($list);
        return [$days, $events];
    }

    private function runTimeline(): void
    {
        [$days, $events] = $this->planEvents();
        foreach ($days as $ymd) {
            $d = Carbon::parse($ymd);
            if (in_array($d->dayOfWeek, [1, 2, 3, 4, 5], true)) {
                $this->restock($ymd);
            }
            foreach ($events[$ymd] ?? [] as [$sec, $type]) {
                switch ($type) {
                    case 'doc':
                        $this->genDocument($ymd, $sec);
                        break;
                    case 'nv':
                        $this->genSaleNote($ymd, $sec);
                        break;
                    case 'quote':
                        $this->genQuotation($ymd, $sec);
                        break;
                    case 'order':
                        $this->genOrder($ymd, $sec);
                        break;
                }
            }
        }
    }

    // ------------------------------------------------------------------------------------
    // Line / totals builder
    // ------------------------------------------------------------------------------------

    /** @param array $picks list of [itemIdx, qty, unitPriceInclIgv] */
    private function buildLines(array $picks): array
    {
        $lines = [];
        $t = ['taxed' => 0, 'exo' => 0, 'unaf' => 0, 'igv' => 0, 'total' => 0];
        foreach ($picks as [$idx, $qty, $price]) {
            $it = $this->items[$idx];
            $aff = $it['aff'];
            $total = round($price * $qty, 2);
            if ($aff === '10') {
                $tv = round($total / 1.18, 2);
                $igv = round($total - $tv, 2);
                $uv = round($price / 1.18, 6);
                $t['taxed'] += $tv;
            } else {
                $tv = $total;
                $igv = 0.0;
                $uv = $price;
                $t[$aff === '20' ? 'exo' : 'unaf'] += $tv;
            }
            $t['igv'] += $igv;
            $t['total'] += $total;
            $lines[] = [
                'item_id' => $it['id'],
                'item' => json_encode([
                    'description' => $it['name'],
                    'item_type_id' => $it['service'] ? '02' : '01',
                    'internal_id' => $it['internal_id'],
                    'item_code' => null,
                    'item_code_gs1' => null,
                    'unit_type_id' => $it['unit'],
                    'presentation' => [],
                    'amount_plastic_bag_taxes' => '0.10',
                    'is_set' => false,
                    'lots' => [],
                    'IdLoteSelected' => null,
                    'model' => null,
                ]),
                'quantity' => $qty,
                'unit_value' => $uv,
                'affectation_igv_type_id' => $aff,
                'total_base_igv' => $tv,
                'percentage_igv' => 18.00,
                'total_igv' => $igv,
                'system_isc_type_id' => null,
                'total_base_isc' => 0,
                'percentage_isc' => 0,
                'total_isc' => 0,
                'total_base_other_taxes' => 0,
                'percentage_other_taxes' => 0,
                'total_other_taxes' => 0,
                'total_taxes' => $igv,
                'price_type_id' => '01',
                'unit_price' => $price,
                'total_value' => $tv,
                'total_charge' => 0,
                'total_discount' => 0,
                'total' => $total,
                'attributes' => '[]',
                'discounts' => '[]',
                'charges' => '[]',
                '_idx' => $idx,
            ];
        }
        foreach ($t as $k => $v) {
            $t[$k] = round($v, 2);
        }
        $t['value'] = round($t['taxed'] + $t['exo'] + $t['unaf'], 2);
        return [$lines, $t];
    }

    private function headerTotals(array $t): array
    {
        return [
            'total_prepayment' => 0, 'total_charge' => 0, 'total_discount' => 0, 'total_exportation' => 0, 'total_free' => 0,
            'total_taxed' => $t['taxed'], 'total_unaffected' => $t['unaf'], 'total_exonerated' => $t['exo'],
            'total_igv' => $t['igv'], 'total_base_isc' => 0, 'total_isc' => 0, 'total_base_other_taxes' => 0,
            'total_other_taxes' => 0, 'total_taxes' => $t['igv'], 'total_value' => $t['value'], 'total' => $t['total'],
        ];
    }

    private function insertLines(string $table, string $fk, int $parentId, array $lines, array $extra = [], ?string $ts = null, array $only = null): void
    {
        foreach ($lines as $l) {
            unset($l['_idx']);
            $row = [$fk => $parentId] + $l + $extra;
            $this->bulk($table, $row, $ts);
        }
    }

    private function legends(float $total, string $currency): string
    {
        $words = mb_strtoupper(trim(preg_replace("/\s+/", " ", NumberLetter::convertToLetter($total))));
        if (stripos($words, 'SOLES') === false && stripos($words, 'DOLARES') === false) {
            $words .= $currency === 'USD' ? ' DÓLARES AMERICANOS' : ' SOLES';
        }
        return json_encode([['code' => '1000', 'value' => $words]]);
    }

    // ------------------------------------------------------------------------------------
    // Picking items
    // ------------------------------------------------------------------------------------

    private function pickItem(array $used, bool $needStock): ?int
    {
        $w = [];
        foreach ($this->items as $k => $it) {
            if (isset($used[$k])) {
                continue;
            }
            if ($needStock && !$it['service'] && $it['stock'] < 1) {
                continue;
            }
            $w[$k] = $it['pop'] * ($it['service'] ? 0.5 : 1);
        }
        if (!$w) {
            return null;
        }
        return $this->wpick($w);
    }

    private function pickLines(string $kind, bool $needStock = true): array
    {
        if ($kind === 'invoice') {
            $n = $this->wpick([1 => 20, 2 => 25, 3 => 22, 4 => 15, 5 => 10, 6 => 5, 7 => 3]);
        } elseif ($kind === 'quote') {
            $n = $this->wpick([2 => 30, 3 => 30, 4 => 20, 5 => 12, 6 => 8]);
        } else {
            $n = $this->wpick([1 => 45, 2 => 30, 3 => 15, 4 => 7, 5 => 3]);
        }
        $used = [];
        $picks = [];
        for ($i = 0; $i < $n; $i++) {
            $idx = $this->pickItem($used, $needStock);
            if ($idx === null) {
                break;
            }
            $used[$idx] = 1;
            $it = $this->items[$idx];
            $price = $it['price'];
            if ($kind === 'invoice' || $kind === 'quote') {
                $base = $price < 30 ? 8 : ($price < 150 ? 4 : ($price < 500 ? 2 : 1));
            } else {
                $base = $price < 30 ? 2 : ($price < 150 ? 1.4 : 1);
            }
            $qty = max(1, (int) round($base * exp($this->randn() * 0.6)));
            if ($it['service']) {
                $qty = $kind === 'invoice' ? mt_rand(1, 4) : 1;
            } elseif ($needStock) {
                $qty = min($qty, (int) $it['stock']);
            }
            if ($qty < 1) {
                continue;
            }
            $picks[] = [$idx, $qty, $price];
        }
        return $picks;
    }

    private function priceTo(array $picks, string $currency, float $rate): array
    {
        if ($currency === 'PEN') {
            return $picks;
        }
        return array_map(fn ($p) => [$p[0], $p[1], round($p[2] / $rate, 2)], $picks);
    }

    private function consumeStock(array $picks, string $model, int $modelId, string $ymd, int $sec, string $legacyKey): void
    {
        foreach ($picks as [$idx, $qty]) {
            $it = &$this->items[$idx];
            if ($it['service']) {
                unset($it);
                continue;
            }
            $it['stock'] -= $qty;
            $this->kardexOut($model, $modelId, $it['id'], -$qty, $ymd, $sec, $legacyKey, 'sale');
            unset($it);
        }
    }

    // ------------------------------------------------------------------------------------
    // Cash / payments
    // ------------------------------------------------------------------------------------

    private function cashFor(string $ymd): int
    {
        if (!isset($this->cashByDate[$ymd])) {
            $open = $ymd === $this->today->format('Y-m-d');
            $beginning = mt_rand(2, 8) * 50;
            $id = $this->ins('cash', [
                'user_id' => $this->userId,
                'date_opening' => $ymd,
                'time_opening' => '08:'.str_pad(mt_rand(0, 40), 2, '0', STR_PAD_LEFT).':00',
                'date_closed' => $open ? null : $ymd,
                'time_closed' => $open ? null : '19:'.str_pad(mt_rand(30, 59), 2, '0', STR_PAD_LEFT).':00',
                'beginning_balance' => $beginning,
                'final_balance' => 0,
                'income' => 0,
                'state' => $open ? 1 : 0,
                'reference_number' => null,
            ], $ymd.' 08:00:00', 'cash');
            $this->cashByDate[$ymd] = $id;
            $this->cashIncome[$id] = [$beginning, 0.0];
        }
        return $this->cashByDate[$ymd];
    }

    private function closeCashes(): void
    {
        foreach ($this->cashIncome as $id => [$beginning, $income]) {
            $date = array_search($id, $this->cashByDate, true);
            if ($date === $this->today->format('Y-m-d')) {
                $this->db->table('cash')->where('id', $id)->update(['income' => round($income, 4), 'final_balance' => round($beginning + $income, 4)]);
            } else {
                $this->db->table('cash')->where('id', $id)->update(['income' => round($income, 4), 'final_balance' => round($beginning + $income, 4)]);
            }
        }
    }

    private function bankFor(string $currency): int
    {
        if ($currency === 'USD') {
            return $this->banks['usd'];
        }
        return $this->banks['pen'][$this->chance(0.7) ? 0 : 1];
    }

    private function methodMix(bool $invoice): string
    {
        $w = $invoice ? ['01' => 20, '04' => 55, '02' => 12, '03' => 13] : ['01' => 50, '02' => 18, '03' => 17, '04' => 15];
        return (string) $this->wpick($w);
    }

    /**
     * Creates a payment row + its global payment (cash or bank destination).
     */
    private function addPayment(string $table, string $fk, int $parentId, string $payClass, string $method, float $amount, string $ymd, int $sec, string $currency, bool $hasChange = true): int
    {
        $card = in_array($method, ['02', '03'], true);
        $row = [
            $fk => $parentId,
            'date_of_payment' => $ymd,
            'payment_method_type_id' => $method,
            'has_card' => $card ? 1 : 0,
            'card_brand_id' => $card ? ($this->chance(0.6) ? '01' : '02') : null,
            'reference' => $method === '04' ? 'OP-'.$this->digits(7) : ($card ? 'POS-'.$this->digits(6) : null),
            'payment' => $amount,
        ];
        if ($hasChange) {
            $row['change'] = 0;
        }
        $track = ['document_payments' => 'document_payments', 'sale_note_payments' => 'sale_note_payments', 'purchase_payments' => 'purchase_payments', 'income_payments' => 'income_payments'][$table] ?? null;
        $payId = $this->ins($table, $row, $this->ts($ymd, $sec), $track);
        if ($method === '01' && $currency === 'PEN') {
            $destType = self::T_CASH;
            $destId = $this->cashFor($ymd);
        } else {
            $destType = self::T_BANK;
            $destId = $this->bankFor($currency);
        }
        $this->bulk('global_payments', [
            'soap_type_id' => $this->soapTypeId,
            'destination_id' => $destId,
            'destination_type' => $destType,
            'payment_id' => $payId,
            'payment_type' => $payClass,
            'user_id' => $this->userId,
        ], $this->ts($ymd, $sec));
        return $payId;
    }

    // ------------------------------------------------------------------------------------
    // Sales documents
    // ------------------------------------------------------------------------------------

    private function pickCompany(): array
    {
        $w = [];
        foreach ($this->companies as $k => $c) {
            $w[$k] = $c['w'];
        }
        return $this->companies[$this->wpick($w)];
    }

    private function pickPerson(): array
    {
        $w = [];
        foreach ($this->persons as $k => $c) {
            $w[$k] = $c['w'];
        }
        return $this->persons[$this->wpick($w)];
    }

    private function nextNumber(string $series): int
    {
        $this->seq[$series] = ($this->seq[$series] ?? 0) + 1;
        return $this->seq[$series];
    }

    private function genDocument(string $ymd, int $sec): void
    {
        $invoice = $this->chance(0.45);
        $picks = $this->pickLines($invoice ? 'invoice' : 'boleta');
        if (!$picks) {
            return;
        }
        $rate = $this->rate($ymd);
        [, $tPen] = $this->buildLines($picks);

        if ($invoice) {
            $customer = $this->pickCompany();
        } else {
            $customer = ($this->chance(0.12) && $tPen['total'] < 700) ? $this->varios : $this->pickPerson();
        }

        $currency = 'PEN';
        if ($invoice && ($customer['usd'] ?? false) && $tPen['total'] >= 1200 && $this->chance(0.5)) {
            $currency = 'USD';
        }
        $docPicks = $this->priceTo($picks, $currency, $rate);
        [$lines, $t] = $this->buildLines($docPicks);
        $totalPen = $currency === 'USD' ? round($t['total'] * $rate, 2) : $t['total'];

        // state
        $age = $this->today->diffInDays(Carbon::parse($ymd));
        if ($age <= 1) {
            $state = $this->chance(0.7) ? '01' : '05';
        } else {
            $r = $this->rnd();
            $state = $r < 0.02 ? '11' : ($r < 0.04 ? '07' : '05');
        }
        $voided = $state === '11';

        $type = $invoice ? '01' : '03';
        $series = $invoice ? 'F001' : 'B001';
        $number = $this->nextNumber($series);
        $ts = $this->ts($ymd, $sec);

        // optional quotation that originated the invoice
        $quotationId = null;
        if ($invoice && !$voided && $this->chance(0.06)) {
            $qDate = $this->today->diffInDays(Carbon::parse($ymd)) > 8 ? Carbon::parse($ymd)->subDays(mt_rand(2, 8))->format('Y-m-d') : $ymd;
            if ($qDate < $this->start->format('Y-m-d')) {
                $qDate = $this->start->format('Y-m-d');
            }
            $quotationId = $this->insertQuotation($qDate, max(8 * 3600, $sec - 3600), $customer, $docPicks, $currency, $rate, '05', true);
        }

        // payment condition
        $credit = $invoice && !$voided && $totalPen >= 500 && $this->chance(0.38);
        $method = $credit ? '05' : $this->methodMix($invoice);
        $due = $credit ? Carbon::parse($ymd)->addDays(30)->format('Y-m-d') : $ymd;

        $docId = $this->ins('documents', [
            'user_id' => $this->userId,
            'external_id' => $this->uuid(),
            'establishment_id' => $this->establishmentId,
            'establishment' => json_encode($this->establishmentJson),
            'soap_type_id' => $this->soapTypeId,
            'state_type_id' => $state,
            'ubl_version' => '2.1',
            'group_id' => $invoice ? '01' : '02',
            'document_type_id' => $type,
            'series' => $series,
            'number' => $number,
            'date_of_issue' => $ymd,
            'time_of_issue' => gmdate('H:i:s', $sec),
            'customer_id' => $customer['id'],
            'customer' => json_encode($this->personJson($customer)),
            'currency_type_id' => $currency,
            'payment_condition_id' => $credit ? '02' : '01',
            'payment_method_type_id' => $method,
            'quotation_id' => $quotationId,
            'seller_id' => $this->userId,
            'exchange_rate_sale' => $rate,
            'legends' => $this->legends($t['total'], $currency),
            'additional_information' => self::MARK,
            'filename' => $this->company->number.'-'.$type.'-'.$series.'-'.$number,
            'hash' => $state === '01' ? null : base64_encode(substr(md5(mt_rand()), 0, 20)),
            'has_xml' => 0, 'has_pdf' => 0, 'has_cdr' => 0,
            'send_server' => 0,
            'total_canceled' => 0,
        ] + $this->headerTotals($t), $ts, 'documents');

        $this->insertLines('document_items', 'document_id', $docId, $lines, ['warehouse_id' => $this->warehouseId, 'total_plastic_bag_taxes' => 0, 'name_product_pdf' => null, 'additional_information' => null]);
        $this->ins('invoices', ['document_id' => $docId, 'operation_type_id' => '0101', 'date_of_due' => $due], $ts);

        if ($quotationId) {
            $this->db->table('quotations')->where('id', $quotationId)->update(['changed' => 1]);
        }

        if ($voided) {
            $this->issuedInvoices[] = null;
            return;
        }

        $this->consumeStock($docPicks, self::T_DOC, $docId, $ymd, $sec, 'document_id');
        $cashId = $this->cashFor($ymd);
        $this->bulk('cash_documents', ['cash_id' => $cashId, 'document_id' => $docId, 'sale_note_id' => null, 'expense_payment_id' => null]);
        $this->cashIncome[$cashId][1] += $totalPen;

        $paid = 0.0;
        if ($credit) {
            $this->ins('document_fee', ['document_id' => $docId, 'date' => $due, 'currency_type_id' => $currency, 'amount' => $t['total']], $ts);
            $paid = $this->creditPayments($docId, $ymd, $sec, $t['total'], $currency, $ymd);
        } else {
            $total = $t['total'];
            if ($this->chance(0.1) && $total > 40) {
                $first = round($total * (0.3 + 0.4 * $this->rnd()), 2);
                $this->addPayment('document_payments', 'document_id', $docId, self::P_DOC, '01', $first, $ymd, $sec, $currency);
                $this->addPayment('document_payments', 'document_id', $docId, self::P_DOC, $this->pick(['02', '03', '04']), round($total - $first, 2), $ymd, $sec, $currency);
            } else {
                $this->addPayment('document_payments', 'document_id', $docId, self::P_DOC, $method, $total, $ymd, $sec, $currency);
            }
            $paid = $total;
        }
        if ($paid + 0.001 >= $t['total']) {
            $this->db->table('documents')->where('id', $docId)->update(['total_canceled' => 1]);
        }

        if ($state === '05' && $age > 3) {
            $this->issuedInvoices[] = [
                'id' => $docId, 'type' => $type, 'series' => $series, 'number' => $number, 'date' => $ymd, 'customer' => $customer,
                'currency' => $currency, 'rate' => $rate, 'picks' => $docPicks, 'group' => $invoice ? '01' : '02',
            ];
        }
    }

    private function creditPayments(int $docId, string $ymd, int $sec, float $total, string $currency, string $issue, string $table = 'document_payments', string $fk = 'document_id', string $class = self::P_DOC): float
    {
        $age = $this->today->diffInDays(Carbon::parse($issue));
        $slow = $this->chance(0.2);
        $payAfter = $slow ? mt_rand(75, 140) : mt_rand(20, 62);
        $payDate = Carbon::parse($issue)->addDays($payAfter);
        $paid = 0.0;
        if ($payDate <= $this->today) {
            $this->addPayment($table, $fk, $docId, $class, '04', $total, $payDate->format('Y-m-d'), mt_rand(9 * 3600, 17 * 3600), $currency);
            return $total;
        }
        if ($age > 12 && $this->chance(0.3)) {
            $part = round($total * (0.3 + 0.3 * $this->rnd()), 2);
            $pd = Carbon::parse($issue)->addDays(mt_rand(5, min(12, $age)));
            $this->addPayment($table, $fk, $docId, $class, '04', $part, $pd->format('Y-m-d'), mt_rand(9 * 3600, 17 * 3600), $currency);
            $paid = $part;
        }
        return $paid;
    }

    private function genSaleNote(string $ymd, int $sec): void
    {
        $picks = $this->pickLines('nv');
        if (!$picks) {
            return;
        }
        $customer = $this->chance(0.12) ? $this->varios : ($this->chance(0.3) ? $this->pickCompany() : $this->pickPerson());
        [$lines, $t] = $this->buildLines($picks);
        $rate = $this->rate($ymd);
        $age = $this->today->diffInDays(Carbon::parse($ymd));
        $voided = $age > 2 && $this->chance(0.03);
        $series = 'NV01';
        $number = $this->nextNumber($series);
        $ts = $this->ts($ymd, $sec);
        $credit = !$voided && $t['total'] > 150 && $this->chance(0.12);
        $method = $this->methodMix(false);

        $id = $this->ins('sale_notes', [
            'user_id' => $this->userId,
            'external_id' => $this->uuid(),
            'establishment_id' => $this->establishmentId,
            'establishment' => json_encode($this->establishmentJson),
            'soap_type_id' => $this->soapTypeId,
            'state_type_id' => $voided ? '11' : '01',
            'prefix' => 'NV',
            'series' => $series,
            'number' => $number,
            'date_of_issue' => $ymd,
            'time_of_issue' => gmdate('H:i:s', $sec),
            'customer_id' => $customer['id'],
            'customer' => json_encode($this->personJson($customer)),
            'currency_type_id' => 'PEN',
            'payment_method_type_id' => $credit ? null : $method,
            'exchange_rate_sale' => $rate,
            'legends' => $this->legends($t['total'], 'PEN'),
            'filename' => $series.'-'.$number,
            'total_canceled' => 0,
            'changed' => 0,
            'paid' => 0,
            'observation' => self::MARK,
        ] + $this->headerTotals($t) + [], $ts, 'sale_notes');
        // sale_notes lacks some header columns (none missing: total_* all exist)
        $this->insertLines('sale_note_items', 'sale_note_id', $id, $lines, ['inventory_kardex_id' => null, 'warehouse_id' => $this->warehouseId]);

        if ($voided) {
            return;
        }
        $this->consumeStock($picks, self::T_NV, $id, $ymd, $sec, 'sale_note_id');
        $cashId = $this->cashFor($ymd);
        $this->bulk('cash_documents', ['cash_id' => $cashId, 'document_id' => null, 'sale_note_id' => $id, 'expense_payment_id' => null]);
        $this->cashIncome[$cashId][1] += $t['total'];

        if ($credit) {
            $paid = $this->creditPayments($id, $ymd, $sec, $t['total'], 'PEN', $ymd, 'sale_note_payments', 'sale_note_id', self::P_NV);
            $isPaid = $paid + 0.001 >= $t['total'];
        } else {
            $this->addPayment('sale_note_payments', 'sale_note_id', $id, self::P_NV, $method, $t['total'], $ymd, $sec, 'PEN');
            $isPaid = true;
        }
        if ($isPaid) {
            $this->db->table('sale_notes')->where('id', $id)->update(['total_canceled' => 1, 'paid' => 1]);
        }
    }

    private function insertQuotation(string $ymd, int $sec, array $customer, array $picks, string $currency, float $rate, string $state, bool $changed): int
    {
        [$lines, $t] = $this->buildLines($picks);
        $ts = $this->ts($ymd, $sec);
        $id = $this->ins('quotations', [
            'user_id' => $this->userId,
            'seller_id' => $this->userId,
            'external_id' => $this->uuid(),
            'establishment_id' => $this->establishmentId,
            'establishment' => json_encode($this->establishmentJson),
            'soap_type_id' => $this->soapTypeId,
            'state_type_id' => $state,
            'prefix' => 'COT',
            'date_of_issue' => $ymd,
            'time_of_issue' => gmdate('H:i:s', $sec),
            'date_of_due' => Carbon::parse($ymd)->addDays(15)->format('Y-m-d'),
            'delivery_date' => Carbon::parse($ymd)->addDays(mt_rand(2, 10))->format('Y-m-d'),
            'customer_id' => $customer['id'],
            'customer' => json_encode($this->personJson($customer)),
            'shipping_address' => $customer['address'],
            'currency_type_id' => $currency,
            'payment_method_type_id' => null,
            'exchange_rate_sale' => $rate,
            'legends' => $this->legends($t['total'], $currency),
            'terms_condition' => 'Validez de la oferta: 15 días. Precios incluyen IGV. Entrega según stock disponible. Forma de pago: contado o crédito a 30 días previa evaluación.',
            'phone' => $customer['phone'],
            'contact' => null,
            'changed' => $changed ? 1 : 0,
            'description' => 'Entrega en almacén',
        ] + $this->headerTotals($t), $ts, 'quotations');
        $this->insertLines('quotation_items', 'quotation_id', $id, $lines);
        return $id;
    }

    private function genQuotation(string $ymd, int $sec): void
    {
        $picks = $this->pickLines('quote', false);
        if (!$picks) {
            return;
        }
        $customer = $this->chance(0.85) ? $this->pickCompany() : $this->pickPerson();
        $rate = $this->rate($ymd);
        $currency = ($customer['usd'] ?? false) && $this->chance(0.4) ? 'USD' : 'PEN';
        $age = $this->today->diffInDays(Carbon::parse($ymd));
        $state = $age < 10 ? $this->wpick(['01' => 70, '03' => 20, '05' => 10]) : (string) $this->wpick(['01' => 25, '03' => 15, '05' => 35, '09' => 10, '11' => 15]);
        $this->insertQuotation($ymd, $sec, $customer, $this->priceTo($picks, $currency, $rate), $currency, $rate, $state, false);
    }

    private function genOrder(string $ymd, int $sec): void
    {
        $picks = $this->pickLines('quote', false);
        if (!$picks) {
            return;
        }
        $customer = $this->pickCompany();
        [$lines, $t] = $this->buildLines($picks);
        $rate = $this->rate($ymd);
        $ts = $this->ts($ymd, $sec);
        $age = $this->today->diffInDays(Carbon::parse($ymd));
        $state = $age < 7 ? '01' : (string) $this->wpick(['01' => 25, '03' => 15, '05' => 50, '11' => 10]);
        $id = $this->ins('order_notes', [
            'user_id' => $this->userId,
            'external_id' => $this->uuid(),
            'establishment_id' => $this->establishmentId,
            'establishment' => json_encode($this->establishmentJson),
            'soap_type_id' => $this->soapTypeId,
            'state_type_id' => $state,
            'prefix' => 'PD',
            'date_of_issue' => $ymd,
            'time_of_issue' => gmdate('H:i:s', $sec),
            'date_of_due' => Carbon::parse($ymd)->addDays(15)->format('Y-m-d'),
            'delivery_date' => Carbon::parse($ymd)->addDays(mt_rand(2, 9))->format('Y-m-d'),
            'customer_id' => $customer['id'],
            'customer' => json_encode($this->personJson($customer)),
            'shipping_address' => $customer['address'],
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => $rate,
            'legends' => $this->legends($t['total'], 'PEN'),
            'observation' => 'Pedido de cliente corporativo',
        ] + $this->headerTotals($t), $ts, 'order_notes');
        $this->insertLines('order_note_items', 'order_note_id', $id, $lines, ['warehouse_id' => $this->warehouseId]);
    }

    private function creditNotes(): void
    {
        $candidates = array_values(array_filter($this->issuedInvoices));
        if (!$candidates) {
            return;
        }
        shuffle($candidates);
        $chosen = array_slice($candidates, 0, 28);
        $list = [];
        foreach ($chosen as $doc) {
            $date = Carbon::parse($doc['date'])->addDays(mt_rand(1, 20));
            if ($date > $this->today) {
                $date = $this->today->copy();
            }
            $list[] = ['doc' => $doc, 'date' => $date->format('Y-m-d'), 'sec' => mt_rand(9 * 3600, 18 * 3600)];
        }
        usort($list, fn ($a, $b) => strcmp($a['date'], $b['date']));

        foreach ($list as $e) {
            $doc = $e['doc'];
            $ymd = $e['date'];
            $sec = $e['sec'];
            $r = $this->rnd();
            if ($r < 0.4) {
                $noteType = '01';
                $desc = 'Anulación de la operación';
                $picks = $doc['picks'];
            } elseif ($r < 0.65) {
                $noteType = '06';
                $desc = 'Devolución total';
                $picks = $doc['picks'];
            } else {
                $noteType = '07';
                $desc = 'Devolución por ítem';
                $p = $doc['picks'][array_rand($doc['picks'])];
                $picks = [[$p[0], $p[1] > 1 ? max(1, (int) floor($p[1] / 2)) : $p[1], $p[2]]];
            }
            [$lines, $t] = $this->buildLines($picks);
            $series = $doc['type'] === '01' ? 'FC01' : 'BC01';
            $number = $this->nextNumber($series);
            $ts = $this->ts($ymd, $sec);
            $id = $this->ins('documents', [
                'user_id' => $this->userId,
                'external_id' => $this->uuid(),
                'establishment_id' => $this->establishmentId,
                'establishment' => json_encode($this->establishmentJson),
                'soap_type_id' => $this->soapTypeId,
                'state_type_id' => '05',
                'ubl_version' => '2.1',
                'group_id' => $doc['group'],
                'document_type_id' => '07',
                'series' => $series,
                'number' => $number,
                'date_of_issue' => $ymd,
                'time_of_issue' => gmdate('H:i:s', $sec),
                'customer_id' => $doc['customer']['id'],
                'customer' => json_encode($this->personJson($doc['customer'])),
                'currency_type_id' => $doc['currency'],
                'seller_id' => $this->userId,
                'exchange_rate_sale' => $doc['rate'],
                'legends' => $this->legends($t['total'], $doc['currency']),
                'additional_information' => self::MARK,
                'filename' => $this->company->number.'-07-'.$series.'-'.$number,
                'hash' => base64_encode(substr(md5(mt_rand()), 0, 20)),
                'has_xml' => 0, 'has_pdf' => 0, 'has_cdr' => 0,
                'send_server' => 0,
                'total_canceled' => 1,
            ] + $this->headerTotals($t), $ts, 'documents');
            $this->insertLines('document_items', 'document_id', $id, $lines, ['warehouse_id' => $this->warehouseId, 'total_plastic_bag_taxes' => 0, 'name_product_pdf' => null, 'additional_information' => null]);
            $this->ins('notes', [
                'document_id' => $id,
                'note_type' => 'credit',
                'note_credit_type_id' => $noteType,
                'note_debit_type_id' => null,
                'note_description' => $desc,
                'affected_document_id' => $doc['id'],
                'data_affected_document' => null,
            ], $ts);
            // goods come back to the warehouse
            foreach ($picks as [$idx, $qty]) {
                $it = &$this->items[$idx];
                if (!$it['service']) {
                    $it['stock'] += $qty;
                    $this->kardexOut(self::T_DOC, $id, $it['id'], $qty, $ymd, $sec);
                }
                unset($it);
            }
        }
    }

    // ------------------------------------------------------------------------------------
    // Purchases
    // ------------------------------------------------------------------------------------

    private function restock(string $ymd): void
    {
        $groups = [];
        foreach ($this->items as $k => $it) {
            if ($it['service'] || $ymd > $it['restock_until']) {
                continue;
            }
            $groups[$this->pick($it['suppliers'])][] = $k;
        }
        foreach ($groups as $sIdx => $ids) {
            $below = array_filter($ids, fn ($k) => $this->items[$k]['stock'] <= $this->items[$k]['rp']);
            $urgent = array_filter($ids, fn ($k) => $this->items[$k]['stock'] <= max(1, $this->items[$k]['rp'] / 3));
            if (!$below && !$urgent) {
                continue;
            }
            $rows = [];
            foreach ($ids as $k) {
                $it = $this->items[$k];
                if ($it['stock'] > $it['rp'] * 1.3) {
                    continue;
                }
                $need = $it['target'] - $it['stock'];
                $rows[] = [$k, (int) (ceil($need / $it['step']) * $it['step'])];
            }
            if ($rows) {
                $this->genPurchase($ymd, $sIdx, $rows);
            }
        }
    }

    private function genPurchase(string $ymd, int $sIdx, array $rows): void
    {
        $s = &$this->suppliers[$sIdx];
        $currency = $s['usd'] ? 'USD' : 'PEN';
        $rate = $this->rate($ymd);
        $picks = [];
        foreach ($rows as [$k, $qty]) {
            $cost = $this->items[$k]['cost'];
            $picks[] = [$k, $qty, $currency === 'USD' ? round($cost / $rate, 2) : $cost];
        }
        [$lines, $t] = $this->buildLines($picks);
        $sec = mt_rand(8 * 3600, 11 * 3600);
        $ts = $this->ts($ymd, $sec);
        $s['pnum'] += mt_rand(1, 60);
        $credit = $this->chance(0.4);
        $due = $credit ? Carbon::parse($ymd)->addDays($this->pick([15, 30, 30, 45]))->format('Y-m-d') : $ymd;

        $id = $this->ins('purchases', [
            'user_id' => $this->userId,
            'external_id' => $this->uuid(),
            'establishment_id' => $this->establishmentId,
            'soap_type_id' => $this->soapTypeId,
            'state_type_id' => '01',
            'group_id' => '01',
            'document_type_id' => '01',
            'series' => $s['pseries'],
            'number' => $s['pnum'],
            'date_of_issue' => $ymd,
            'date_of_due' => $due,
            'time_of_issue' => gmdate('H:i:s', $sec),
            'supplier_id' => $s['id'],
            'supplier' => json_encode($this->personJson($s)),
            'currency_type_id' => $currency,
            'exchange_rate_sale' => $rate,
            'filename' => null,
            'total_canceled' => 0,
        ] + $this->headerTotals($t), $ts, 'purchases');

        foreach ($lines as $l) {
            $idx = $l['_idx'];
            unset($l['_idx']);
            $this->bulk('purchase_items', ['purchase_id' => $id] + $l + ['lot_code' => null, 'date_of_due' => null, 'warehouse_id' => $this->warehouseId], $ts);
            $it = &$this->items[$idx];
            $it['stock'] += $l['quantity'];
            $this->kardexOut(self::T_PUR, $id, $it['id'], $l['quantity'], $ymd, $sec, 'purchase_id', 'purchase');
            unset($it);
        }
        unset($s);

        $paid = 0.0;
        if (!$credit) {
            $this->addPayment('purchase_payments', 'purchase_id', $id, self::P_PUR, $this->chance(0.8) ? '04' : '01', $t['total'], $ymd, $sec, $currency, false);
            $paid = $t['total'];
        } else {
            $payDate = Carbon::parse($due)->addDays(mt_rand(-5, 12));
            if ($payDate <= $this->today) {
                $this->addPayment('purchase_payments', 'purchase_id', $id, self::P_PUR, '04', $t['total'], $payDate->format('Y-m-d'), mt_rand(9 * 3600, 16 * 3600), $currency, false);
                $paid = $t['total'];
            }
        }
        if ($paid + 0.001 >= $t['total']) {
            $this->db->table('purchases')->where('id', $id)->update(['total_canceled' => 1]);
        }
    }

    // ------------------------------------------------------------------------------------
    // Inventory adjustments for the "low / zero stock" showcase
    // ------------------------------------------------------------------------------------

    private function stockAdjustments(): void
    {
        $ymd = $this->today->copy()->subDays(2)->format('Y-m-d');
        foreach ($this->items as $k => &$it) {
            if ($it['service'] || $it['final'] === null) {
                continue;
            }
            $excess = $it['stock'] - $it['final'];
            if ($excess > 0) {
                $this->recordInventory($it['id'], '2', 'Ajuste por inventario físico', '28', $excess, $ymd, 17 * 3600);
                $it['stock'] = $it['final'];
            }
        }
        unset($it);
    }

    private function finalizeStock(): void
    {
        foreach ($this->items as $it) {
            if ($it['service']) {
                continue;
            }
            $this->bulk('item_warehouse', ['item_id' => $it['id'], 'warehouse_id' => $this->warehouseId, 'stock' => $it['stock']]);
            $this->db->table('items')->where('id', $it['id'])->update(['stock' => $it['stock'], 'stock_min' => max(1, (int) floor($it['rp'] / 2))]);
        }
    }

    // ------------------------------------------------------------------------------------
    // Expenses and incomes
    // ------------------------------------------------------------------------------------

    private function seedExpenses(): void
    {
        $plan = [];
        $cursor = $this->start->copy()->startOfMonth();
        while ($cursor <= $this->today) {
            $m = $cursor->copy();
            $plan[] = [$m->copy()->day(5), 0, 3, 'Alquiler de local comercial - '.$this->monthName($m), mt_rand(23, 24) * 100 + 50, false, 'bank'];
            $plan[] = [$m->copy()->day(12), 1, 3, 'Servicio de energía eléctrica - '.$this->monthName($m), mt_rand(360, 560) + mt_rand(0, 99) / 100, false, 'bank'];
            $plan[] = [$m->copy()->day(14), 2, 3, 'Servicio de agua y alcantarillado - '.$this->monthName($m), mt_rand(85, 140) + mt_rand(0, 99) / 100, false, 'bank'];
            $plan[] = [$m->copy()->day(16), 3, 3, 'Internet y telefonía empresarial - '.$this->monthName($m), 189.00, false, 'bank'];
            $plan[] = [$m->copy()->day(10), 4, 2, 'Honorarios contables - '.$this->monthName($m), 450.00, false, 'bank'];
            $plan[] = [$m->copy()->endOfMonth()->day(min(28, $m->copy()->endOfMonth()->day)), 5, 1, 'Planilla de colaboradores - '.$this->monthName($m), mt_rand(31, 36) * 100, false, 'bank'];
            $cursor->addMonth();
        }
        // extra ad-hoc expenses
        $extras = [
            [6, 'Movilidad y transporte de mercadería', 60, 220, 'cash'], [6, 'Mantenimiento de equipos de oficina', 150, 480, 'bank'],
            [6, 'Publicidad en redes sociales', 200, 650, 'bank'], [6, 'Útiles de limpieza y aseo', 40, 130, 'cash'],
            [6, 'Seguridad y vigilancia', 280, 420, 'bank'], [6, 'Courier y envíos a provincias', 45, 190, 'cash'],
        ];
        for ($i = 0; $i < 12; $i++) {
            // fill to reach roughly 80 rows overall
            $plan[] = null;
        }
        $rows = array_values(array_filter($plan));
        $needExtra = max(0, 80 - count($rows));
        for ($i = 0; $i < $needExtra; $i++) {
            $e = $this->pick($extras);
            $d = $this->start->copy()->addDays(mt_rand(0, (int) $this->start->diffInDays($this->today)));
            $rows[] = [$d, $e[0], 4, $e[1], mt_rand($e[2], $e[3]) + mt_rand(0, 99) / 100, false, $e[4]];
        }
        usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);
        $n = 0;
        foreach ($rows as [$date, $sup, $type, $desc, $amount, , $dest]) {
            if ($date > $this->today || $date < $this->start) {
                continue;
            }
            $n++;
            $ymd = $date->format('Y-m-d');
            $sec = mt_rand(9 * 3600, 17 * 3600);
            $ts = $this->ts($ymd, $sec);
            $s = $this->suppliers[$sup];
            $id = $this->ins('expenses', [
                'user_id' => $this->userId,
                'soap_type_id' => $this->soapTypeId,
                'expense_type_id' => $type,
                'establishment_id' => $this->establishmentId,
                'supplier_id' => $s['id'],
                'expense_reason_id' => 1,
                'currency_type_id' => 'PEN',
                'external_id' => $this->uuid(),
                'state_type_id' => '05',
                'number' => $n,
                'date_of_issue' => $ymd,
                'time_of_issue' => gmdate('H:i:s', $sec),
                'supplier' => json_encode($this->personJson($s)),
                'exchange_rate_sale' => $this->rate($ymd),
                'total' => $amount,
            ], $ts, 'expenses');
            $this->ins('expense_items', ['expense_id' => $id, 'description' => $desc, 'total' => $amount], $ts);
            $payId = $this->ins('expense_payments', [
                'expense_id' => $id,
                'date_of_payment' => $ymd,
                'expense_method_type_id' => 1,
                'has_card' => 0,
                'card_brand_id' => null,
                'reference' => $dest === 'bank' ? 'OP-'.$this->digits(7) : null,
                'payment' => $amount,
            ], $ts, 'expense_payments');
            if ($dest === 'cash') {
                $cashId = $this->cashFor($ymd);
                $this->bulk('global_payments', ['soap_type_id' => $this->soapTypeId, 'destination_id' => $cashId, 'destination_type' => self::T_CASH, 'payment_id' => $payId, 'payment_type' => self::P_EXP, 'user_id' => $this->userId], $ts);
                $this->bulk('cash_documents', ['cash_id' => $cashId, 'document_id' => null, 'sale_note_id' => null, 'expense_payment_id' => $payId]);
                $this->cashIncome[$cashId][1] -= $amount;
            } else {
                $this->bulk('global_payments', ['soap_type_id' => $this->soapTypeId, 'destination_id' => $this->bankFor('PEN'), 'destination_type' => self::T_BANK, 'payment_id' => $payId, 'payment_type' => self::P_EXP, 'user_id' => $this->userId], $ts);
            }
        }
    }

    private function monthName(Carbon $d): string
    {
        $names = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];
        return $names[$d->month].' '.$d->year;
    }

    private function seedIncomes(): void
    {
        $rows = [
            [1, 'Intereses ganados cuenta de ahorros', 30, 90, 'INTERBANK'],
            [1, 'Intereses por depósito a plazo', 80, 190, 'BANCO DE CREDITO DEL PERU'],
            [3, 'Devolución de proveedor por producto defectuoso', 120, 480, 'IMPORTACIONES TECNOANDES S.A.C.'],
            [3, 'Reembolso de seguro por equipo dañado', 300, 900, 'SEGUROS PACIFICO ANDINO S.A.'],
        ];
        $n = 0;
        $span = (int) $this->start->diffInDays($this->today);
        $dates = [];
        for ($i = 0; $i < 14; $i++) {
            $dates[] = $this->start->copy()->addDays(mt_rand(10, $span));
        }
        sort($dates);
        foreach ($dates as $d) {
            $r = $this->pick($rows);
            $n++;
            $ymd = $d->format('Y-m-d');
            $sec = mt_rand(9 * 3600, 17 * 3600);
            $ts = $this->ts($ymd, $sec);
            $amount = mt_rand($r[2], $r[3]) + mt_rand(0, 99) / 100;
            $id = $this->ins('income', [
                'user_id' => $this->userId,
                'income_type_id' => $r[0],
                'income_reason_id' => 1,
                'soap_type_id' => $this->soapTypeId,
                'state_type_id' => '05',
                'establishment_id' => $this->establishmentId,
                'customer' => $r[4],
                'currency_type_id' => 'PEN',
                'external_id' => $this->uuid(),
                'number' => $n,
                'date_of_issue' => $ymd,
                'time_of_issue' => gmdate('H:i:s', $sec),
                'exchange_rate_sale' => $this->rate($ymd),
                'total' => $amount,
            ], $ts, 'income');
            $this->ins('income_items', ['income_id' => $id, 'description' => $r[1], 'total' => $amount], $ts);
            $this->addPayment('income_payments', 'income_id', $id, self::P_INC, '04', $amount, $ymd, $sec, 'PEN');
        }
    }

    // ------------------------------------------------------------------------------------
    // Stats
    // ------------------------------------------------------------------------------------

    private function collectStats(): void
    {
        $this->flush();
        foreach (['categories', 'brands', 'items', 'item_warehouse', 'persons', 'bank_accounts', 'documents', 'document_items', 'document_payments', 'document_fee', 'invoices', 'notes', 'sale_notes', 'sale_note_items', 'sale_note_payments', 'quotations', 'quotation_items', 'order_notes', 'order_note_items', 'purchases', 'purchase_items', 'purchase_payments', 'expenses', 'expense_items', 'expense_payments', 'income', 'income_items', 'income_payments', 'cash', 'cash_documents', 'global_payments', 'inventories', 'inventory_kardex', 'kardex'] as $t) {
            $this->stats[$t] = $this->db->table($t)->count();
        }
    }

    // ------------------------------------------------------------------------------------
    // Cleanup (--fresh)
    // ------------------------------------------------------------------------------------

    private function delIn(string $table, string $col, array $ids, array $extra = []): void
    {
        foreach (array_chunk($ids, 800) as $chunk) {
            $q = $this->db->table($table)->whereIn($col, $chunk);
            foreach ($extra as $k => $v) {
                $q->where($k, $v);
            }
            $q->delete();
        }
    }

    private function cleanup(array $t): void
    {
        $g = fn (string $k) => $t[$k] ?? [];
        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ([
                'document_payments' => self::P_DOC, 'sale_note_payments' => self::P_NV, 'purchase_payments' => self::P_PUR,
                'expense_payments' => self::P_EXP, 'income_payments' => self::P_INC,
            ] as $key => $class) {
                $this->delIn('global_payments', 'payment_id', $g($key), ['payment_type' => $class]);
            }
            $this->delIn('cash_documents', 'cash_id', $g('cash'));
            $this->delIn('cash_transactions', 'cash_id', $g('cash'));
            foreach (['documents' => self::T_DOC, 'sale_notes' => self::T_NV, 'purchases' => self::T_PUR, 'inventories' => self::T_INV] as $key => $class) {
                $this->delIn('inventory_kardex', 'inventory_kardexable_id', $g($key), ['inventory_kardexable_type' => $class]);
            }
            $this->delIn('kardex', 'document_id', $g('documents'));
            $this->delIn('kardex', 'sale_note_id', $g('sale_notes'));
            $this->delIn('kardex', 'purchase_id', $g('purchases'));
            $this->delIn('inventories', 'id', $g('inventories'));
            $this->delIn('item_warehouse', 'item_id', $g('items'));

            foreach (['document_fee', 'document_payments', 'document_items', 'invoices', 'notes'] as $c) {
                $this->delIn($c, 'document_id', $g('documents'));
            }
            $this->delIn('documents', 'id', $g('documents'));
            foreach (['sale_note_payments', 'sale_note_items'] as $c) {
                $this->delIn($c, 'sale_note_id', $g('sale_notes'));
            }
            $this->delIn('sale_notes', 'id', $g('sale_notes'));
            $this->delIn('quotation_items', 'quotation_id', $g('quotations'));
            $this->delIn('quotations', 'id', $g('quotations'));
            $this->delIn('order_note_items', 'order_note_id', $g('order_notes'));
            $this->delIn('order_notes', 'id', $g('order_notes'));
            foreach (['purchase_payments', 'purchase_items'] as $c) {
                $this->delIn($c, 'purchase_id', $g('purchases'));
            }
            $this->delIn('purchases', 'id', $g('purchases'));
            foreach (['expense_payments', 'expense_items'] as $c) {
                $this->delIn($c, 'expense_id', $g('expenses'));
            }
            $this->delIn('expenses', 'id', $g('expenses'));
            foreach (['income_payments', 'income_items'] as $c) {
                $this->delIn($c, 'income_id', $g('income'));
            }
            $this->delIn('income', 'id', $g('income'));
            $this->delIn('cash', 'id', $g('cash'));
            $this->delIn('bank_accounts', 'id', $g('bank_accounts'));
            $this->delIn('items', 'id', $g('items'));
            $this->delIn('brands', 'id', $g('brands'));
            $this->delIn('categories', 'id', $g('categories'));
            $this->delIn('persons', 'id', $g('persons'));
        } finally {
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
