<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Requests\Tenant\ConfigurationRequest;
use App\Http\Resources\Tenant\ConfigurationResource;
use App\Models\Tenant\Configuration;
use App\Models\Tenant\Item;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Tenant\Catalogs\AffectationIgvType;
use GuzzleHttp\Client;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use App\CoreFacturalo\Template;
use App\Models\Tenant\Company;
use App\Services\PdfTemplateCatalogService;

class ConfigurationController extends Controller
{
	public function create()
	{
		return view('tenant.configurations.form');
	}

	public function generateDispatch(Request $request)
	{
		$preprintedRoot = app_path('CoreFacturalo' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'preprinted_pdf');
		$base_pdf_template = $this->resolvePreprintedTemplate((string) $request->input('base_pdf_template'), $preprintedRoot);

		$template = new Template();
		$pdf_margin_top = 15;
		$pdf_margin_bottom = 15;
		$pdf_margin_right = 15;
		$pdf_margin_left = 15;

		$pdf_font_regular = config('tenant.pdf_name_regular');
		$pdf_font_bold = config('tenant.pdf_name_bold');

		if ($pdf_font_regular != false) {
			$defaultConfig = (new ConfigVariables())->getDefaults();
			$fontDirs = $defaultConfig['fontDir'];

			$defaultFontConfig = (new FontVariables())->getDefaults();
			$fontData = $defaultFontConfig['fontdata'];

			$pdf = new Mpdf([
				'fontDir' => array_merge($fontDirs, [
					app_path('CoreFacturalo' . DIRECTORY_SEPARATOR . 'Templates' .
											 DIRECTORY_SEPARATOR . 'pdf' .
											 DIRECTORY_SEPARATOR . $base_pdf_template .
											 DIRECTORY_SEPARATOR . 'font')
				]),
				'fontdata' => $fontData + [
					'custom_bold' => [
						'R' => $pdf_font_bold . '.ttf',
					],
					'custom_regular' => [
						'R' => $pdf_font_regular . '.ttf',
					],
				],
				'margin_top'    => $pdf_margin_top,
				'margin_right'  => $pdf_margin_right,
				'margin_bottom' => $pdf_margin_bottom,
				'margin_left'   => $pdf_margin_left,
			]);
		} else {
			$pdf = new Mpdf([
				'margin_top'    => $pdf_margin_top,
				'margin_right'  => $pdf_margin_right,
				'margin_bottom' => $pdf_margin_bottom,
				'margin_left'   => $pdf_margin_left
			]);
		}
		$path_css = $preprintedRoot . DIRECTORY_SEPARATOR . $base_pdf_template . DIRECTORY_SEPARATOR . 'style.css';
		$stylesheet = is_file($path_css) ? file_get_contents($path_css) : '';

		$html = $template->preprintedpdf($base_pdf_template, 'dispatch', Company::active(), 'a4');
		$pdf->WriteHTML($stylesheet, HTMLParserMode::HEADER_CSS);
		$pdf->WriteHTML($html, HTMLParserMode::HTML_BODY);

		Storage::put('preprintedpdf' . DIRECTORY_SEPARATOR . $base_pdf_template . '.pdf', $pdf->output('', 'S'));

		return $base_pdf_template;
	}

	public function show($template)
	{
		$preprintedRoot = app_path('CoreFacturalo' . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'preprinted_pdf');
		$safeTemplate = $this->resolvePreprintedTemplate((string) $template, $preprintedRoot);
		$pdfPath = storage_path('app' . DIRECTORY_SEPARATOR . 'preprintedpdf' . DIRECTORY_SEPARATOR . $safeTemplate . '.pdf');
		abort_unless(is_file($pdfPath), 404, 'Archivo PDF preimpreso no encontrado.');

		return response()->file($pdfPath);
	}

	private function resolvePreprintedTemplate(string $template, string $preprintedRoot): string
	{
		$entries = is_dir($preprintedRoot) ? (scandir($preprintedRoot) ?: []) : [];
		$allowed = array_values(array_filter($entries, function ($entry) use ($preprintedRoot) {
			return $entry !== '.' && $entry !== '..' && is_dir($preprintedRoot . DIRECTORY_SEPARATOR . $entry);
		}));

		abort_unless(in_array($template, $allowed, true), 422, 'Plantilla PDF preimpresa inválida.');

		return $template;
	}

	// public function dispatch(Request $request) {
	//     dd($request);
	//     return 'prueba';

	//     $fact = DB::connection('tenant')->transaction(function () use($request) {
	//         $facturalo = new Facturalo();
	//         $facturalo->save($request->all());
	//         $facturalo->createXmlUnsigned();
	//         $facturalo->signXmlUnsigned();
	//         $facturalo->createPdf();
	//         $facturalo->senderXmlSignedBill();

	//         return $facturalo;
	//     });

	//     $document = $fact->getDocument();
	//     $response = $fact->getResponse();

	//     return [
	//         'success' => true,
	//         'message' => "Se creo la guía de remisión {$document->series}-{$document->number}",
	//         'data' => [
	//             'id' => $document->id,
	//         ],
	//     ];
	// }

	public function addSeeder()
	{
		(new PdfTemplateCatalogService())->sync(PdfTemplateCatalogService::TYPE_PDF);

		return [
			'success' => true,
			'message' => 'Configuración actualizada'
		];
	}

	public function addPreprintedSeeder()
	{
		(new PdfTemplateCatalogService())->sync(PdfTemplateCatalogService::TYPE_PREPRINTED);

		return [
			'success' => true,
			'message' => 'Configuración actualizada'
		];
	}

	public function changeFormat(Request $request)
	{
		$format = Configuration::first();
		$format->fill($request->all());
		$format->save();

		$config_format = config(['tenant.pdf_template' => $format->formats]);
		// $fp = fopen(base_path() .'/config/tenant.php' , 'w');
		// fwrite($fp, '<?php return ' . var_export(config('tenant'), true) . ';');
		// fclose($fp);
		return [
			'success' => true,
			'message' => 'Configuración actualizada'
		];
	}

	public function getFormats()
	{
		return (new PdfTemplateCatalogService())->all(PdfTemplateCatalogService::TYPE_PDF);
	}

	public function getPreprintedFormats()
	{
		return (new PdfTemplateCatalogService())->all(PdfTemplateCatalogService::TYPE_PREPRINTED);
	}

	public function pdfTemplates()
	{
		return view('tenant.advanced.pdf_templates');
	}

	public function pdfGuideTemplates()
	{
		return view('tenant.advanced.pdf_guide_templates');
	}

	public function pdfPreprintedTemplates()
	{
		return view('tenant.advanced.pdf_preprinted_templates');
	}

	public function record()
	{
		$configuration = Configuration::first();
		$record = new ConfigurationResource($configuration);

		return  $record;
	}

	public function store(ConfigurationRequest $request)
	{
		$id = $request->input('id');
		$configuration = Configuration::findOrFail($id);
		$configuration->fill($request->all());
		$configuration->save();

		return [
			'success' => true,
			'message' => 'Configuración actualizada'
		];
	}

	public function icbper(Request $request)
	{
		DB::connection('tenant')->transaction(function () use ($request) {
			$id = $request->input('id');
			$configuration = Configuration::findOrFail($id);
			$configuration->amount_plastic_bag_taxes = $request->amount_plastic_bag_taxes;
			$configuration->save();

			$items = Item::get(['id', 'amount_plastic_bag_taxes']);

			foreach ($items as $item) {
				$item->amount_plastic_bag_taxes = $configuration->amount_plastic_bag_taxes;
				$item->update();
			}
		});

		return [
			'success' => true,
			'message' => 'Configuración actualizada'
		];
	}

	public function tables()
	{
		$affectation_igv_types = AffectationIgvType::whereActive()->get();

		return compact('affectation_igv_types');
	}

	public function visualDefaults()
	{
		$defaults = [
			'bg'       => 'light',
			'header'   => 'light',
			'sidebars' => 'light',
		];
		$configuration = Configuration::firstOrFail();
		$configuration->visual = $defaults;
		$configuration->save();

		return [
			'success' => true,
			'message' => 'Configuración actualizada'
		];
	}

	public function visualSettings(Request $request)
	{
		$visuals = [
			'bg'       => $request->bg,
			'header'   => $request->header,
			'sidebars' => $request->sidebars,
            'navbar' => $request->navbar,
            'sidebar_theme' => $request->sidebar_theme
		];

		$configuration = Configuration::findOrFail(1);
		$configuration->visual = $visuals;
		$configuration->save();

		return [
			'success' => true,
			'message' => 'Configuración actualizada'
		];
	}

	public function getSystemPhone()
	{
		// $configuration = Configuration::first();
		// $ws = $configuration->enable_whatsapp;

		// $current = url('/phone');
		// $parse_current = parse_url($current);
		// $explode_current = explode('.', $parse_current['host']);
		// $app_url = config('app.url');
		// if(!array_key_exists('port', $parse_current)){
		//     $path = $app_url.$parse_current['path'];
		// }else{
		//     $path = $app_url.':'.$parse_current['port'].$parse_current['path'];
		// }

		// $http = new Client(['verify' => false]);
		// $response = $http->request('GET', $path);
		// if($response->getStatusCode() == '200'){
		//     $body = $response->getBody();

		//     $configuration->phone_whatsapp = $body;
		//     $configuration->save();
		// }
		// return 'error';
	}

	public function uploadFile(Request $request)
	{
		if ($request->hasFile('file') && $request->file('file')->isValid()) {
			$request->validate(['file' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048']);

			$configuration = Configuration::firstOrFail();

			$file = $request->file('file');
			$ext = $file->extension();
			$name = date('Ymd') . '_' . $configuration->id . '.' . $ext;

			$file->storeAs('public/uploads/header_images', $name);

			$configuration->header_image = $name;

			$configuration->save();

			return [
				'success' => true,
				'message' => __('app.actions.upload.success'),
				'name'    => $name,
			];
		}

		return [
			'success' => false,
			'message' => __('app.actions.upload.error'),
		];
	}

    public function changeMode()
    {
		$configuration = Configuration::firstOrFail();
		$visual = $configuration->visual ?? (object) ['bg' => 'light', 'header' => 'light', 'sidebars' => 'light'];
		$bg = $visual->bg ?? 'light';
        $visual->bg = $bg === 'dark' ? 'light' : 'dark';
        $configuration->visual = $visual;
		$configuration->save();

		return redirect()->back();
    }
}
