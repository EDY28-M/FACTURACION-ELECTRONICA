# Peru "Tesorería" module: regulatory map (research as of 2026-11-23)

Method: SUNAT-hosted PDFs/pages were read directly where possible (curl + pdftotext). Where an official page was blocked (HTTP 403 on emprender.sunat.gob.pe and cpe.sunat.gob.pe via fetch), I used the official search-result snippets or the orientacion.sunat.gob.pe mirror and say so. "NOT CONFIRMED" = could not verify in an official text.

Legend: [OFFICIAL-TEXT] = I read the norm/annex text itself. [OFFICIAL-PAGE] = SUNAT guidance page. [SECONDARY] = reputable-but-not-official source or search snippet.

---

## 1. Is there a SUNAT "treasury" electronic document?

**No.** SUNAT does not issue/require a treasury-specific electronic document. Treasury (cash/bank movements) is an internal ledger concern; the only treasury-specific book is the Libro Caja y Bancos (section 6), and it is not mandatory electronic for most taxpayers. What exists are tax documents *linked to* a payment or collection:

| Document | Type code | Who issues | Linked to cash event |
|---|---|---|---|
| Comprobante de Retención electrónico (CRE) | 20 | Retention agent (buyer) | At each **payment** to supplier |
| Comprobante de Percepción electrónico (CPE) | 40 (41 = percepción venta interna, ticket) | Perception agent (seller) | At each **collection** (cobro) from customer, if payment is after the factura/boleta |
| Nota de crédito / débito | 07 / 08 | Seller | Adjusts amount to collect/pay |
| Factura negociable (title, not a new CPE) | n/a (a factura + registry) | Seller; registered in CAVALI | Receivable that can be factored; has "conformidad" step |
| Recibo por honorarios electrónico (RHE) | 02 | Independent worker | 4th-category retention 8% at payment |
| Liquidación de compra | 04 | Buyer (purchases from people with no RUC/ no comprobante) | IGV retained on the LC is paid via FV 617 |

Source for type codes: UBL/Catalogo 01 used in SUNAT annexes (see RS 123-2022 annex below) and the SUNAT CPE pages. I did not re-read Catalogo 01 itself in this session, so the codes for 20/40/41/02/04/07/08 are widely known SUNAT values but **codes 40/41 are confirmed** by SUNAT's programmer manual snippet ("40 Comprobante de Percepción, 41 Comprobante de Percepción Venta") https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20(1).pdf [OFFICIAL via search snippet]; code 20 for retención is NOT CONFIRMED by a file I opened.

### 1a. Retención del IGV (CRE)
- **Rate 3%** of the total amount the buyer must pay (sale price including taxes), not of the valor de venta. https://orientacion.sunat.gob.pe/06-preguntas-frecuentes-regimen-de-retenciones (Q17) [OFFICIAL-PAGE]. (The 6% of original RS 037-2002 is no longer applicable.)
- **Applies when**: customer is a designated agent AND operation amount > S/ 700 AND no exclusion applies (e.g., supplier is Buen Contribuyente or another agent; boletas/tickets without crédito fiscal). Same FAQ (Q1, Q9) and https://emprender.sunat.gob.pe/principales-impuestos/impuesto-general-las-ventas-igv/regimen-retencion-igv [snippet only; page returned 403].
- **Timing**: retention occurs **at the moment of payment**, regardless of when the operation occurred. Partial payments: retention is applied on each payment amount (Q2, Q18). Payment date rules (Q3): cash = delivery date; cheque = date funds are available; deferred cheque = due date; letra de cambio = due date or actual payment date, whichever is first; in-kind = delivery. Same FAQ.
- **CRE**: issued and delivered **at the time of payment**; if payment goes to a third party, issued/delivered on the payment date (Q20). Electronic issuance mandatory since 01/01/2018; series E001 (SOL) or R### (own system). https://orientacion.sunat.gob.pe/75-comprobante-de-retencion [OFFICIAL-PAGE]. So: **each payment (or partial payment) to a supplier from a designated agent generates one CRE** referencing the factura(s)/notas.
- **Declaration/payment of retained IGV**: PDT/Form 626 "Agentes de Retención", monthly, even if zero (Q21). Same FAQ.
- **Agent designation**: SUNAT designates by resolution; padrón last updated by **RS 000367-2025/SUNAT (5 Dec 2025, in force 1 Feb 2026)**, previous update RS 000229-2024/SUNAT. https://www.sunat.gob.pe/legislacion/superin/2025/000367-2025.pdf [OFFICIAL-TEXT]. Your tenant is only an agent if it appears in the padrón; otherwise it only *receives* CREs.
- On the supplier side, the factura XML carries the retention data (AllowanceChargeReasonCode "62", Catalogo 53, factor, amount, base) when the buyer is a known agent. https://www.sunat.gob.pe/legislacion/superin/2020/anexo4-193-2020.pdf [OFFICIAL-TEXT].
- **Recent (2026)**: RS 000047-2026/SUNAT (26 Mar 2026, in force **1 Jul 2026**) creates "Módulo de Pago previo retención IGV por Liquidación de Compra" in SOL and modifies Declara Fácil 617 so that payment of IGV retained on liquidaciones de compra (and IGV on services of non-domiciled) is linked to the specific comprobante, to feed RCE in SIRE. Pagos previos allowed up to one business day before FV 617 deadline. https://www.sunat.gob.pe/legislacion/superin/2026/000047-2026.pdf and https://orientacion.sunat.gob.pe/sites/default/files/2026-09/RetencionIGV_PreguntasFrecuentes_v1.1.pdf [OFFICIAL-TEXT]. (Note: that FAQ PDF is about the LC module only, despite its file name.)

### 1b. Percepción del IGV (CPE)
- **Rates** (SUNAT table): 2% of sale price for goods in Apéndice 1 of Ley 29173 / DS 091-2013-EF; **0.5%** if the buyer is also a perception agent and receives a comprobante giving crédito fiscal; **1%** for liquid fuels; imports: 10% / 5% (used goods) / 3.5%. http://contenido.app.sunat.gob.pe/insc/Percepciones+IGV/Porcentaje+de+percepcion.pdf [OFFICIAL-TEXT, undated table]. Law range: 0.5%-2% for domestic sales; 2%-5% imports. Ley 29173 consolidated text https://www.sunat.gob.pe/legislacion/tributaria/percepciones/Ley-29173.doc [OFFICIAL-TEXT]. **Recent**: DL 1714 changed only the import chapter (DAM terminology, 10% cases); SUNAT's copy is "updated to 4.2.2026". Treat the import % table as possibly stale, NOT CONFIRMED for 2026.
- **Timing**: the agent perceives **when it collects** (total or partial), regardless of operation date; percentage applied on each payment. https://orientacion.sunat.gob.pe/02-regimen-de-percepciones-venta-interna [OFFICIAL-PAGE]. CPE is issued when the collection occurs after the factura/boleta; if payment is prior or simultaneous, the perception is shown inside the comprobante itself (RS 123-2022 annex: tipo de operación 2001, Catalogo 53, "Monto total incluida la percepción"). https://orientacion.sunat.gob.pe/3462-05-requisitos-minimos-del-comprobante-de-percepcion [OFFICIAL-PAGE]; https://www.sunat.gob.pe/legislacion/superin/2022/anexo-123-2022.pdf [OFFICIAL-TEXT].
- Series: E001 (SOL) or P### (own system). Electronic mandatory since 01/01/2018 per orientacion page (another SUNAT page says 01/07/2017: inconsistent, NOT CONFIRMED which is right; irrelevant for a 2026 module).
- Final-consumer threshold quoted by SUNAT page: S/ 1,500 since 01.01.2014 (original 700). Same orientacion page.
- Exclusions include operations subject to detracción. Same page.
- Practical meaning: the perception is an *additional amount collected from the customer* and later paid to SUNAT; the collection record must separate "importe cobrado = precio venta + percepción".

### 1c. Other linked documents
- **Notas de crédito/débito**: can issue NCE to correct the "forma de pago" info (credit installments/ monto neto pendiente) in factura and RHE; see RS 193-2020 below. https://www.sunat.gob.pe/legislacion/superin/2020/193-2020.pdf [OFFICIAL-TEXT].
- **Factura negociable (Ley 29623, DL 1178, DU 013-2020, DS 239-2021-EF)**: the buyer has **8 calendar days** from the factura's availability to register conformidad/disconformidad in a SUNAT platform; silence = conformidad presunta (RS 000165-2021/SUNAT, in force 17 Dec 2021). https://www.sunat.gob.pe/legislacion/superin/2021/165-2021.pdf [OFFICIAL-TEXT]. Registration/transfer of the title in CAVALI (anotación en cuenta) and the reglamento DS 208-2015-EF: only secondary sources reached (https://elperuano.pe/noticia/223368-factura-electronica-negociable-revisa-aqui-cuales-son-los-pasos-y-requisitos-para-su-cobranza). 2025-2026 changes: NOT CONFIRMED (none found; one El Peruano piece says PRODUCE is working on factoring changes, no date).
- **RHE (4th category)**: 8% retention by designated agents when the single recibo > S/ 1,500; suspension possible for 2026 if income ≤ S/ 4,010/month and ≤ S/ 48,125/year; retained amounts declared in PDT 616/FV 616; base RS 013-2007/SUNAT. https://www.gob.pe/institucion/sunat/pages/1156-suspension-de-retenciones-y-pagos-a-cuenta-de-renta-de-cuarta-categoria (snippet via search) [OFFICIAL-PAGE snippet]; threshold/rate also https://orientacion.sunat.gob.pe/preguntas-frecuentes-renta-de-cuarta-categoria. RER and NRUS payers do not retain (SUNAT practical case, per search snippet). The treasury effect: payment to the worker = RHE amount − 8% retention (if retention applies) − (no detracción).
- **Liquidación de compra (tipo 04)**: buyer retains/pays the IGV itself; since 1 Jul 2026 the payment must go through the "Pago previo retención IGV por LC" module or FV 617 (see 1a). Reversal window per RS 047-2026 text: 7 days (RS 188-2010 art. 26); the "7→5 days" change appears in the RS text as a summary-reversal rule ("resumen diario de reversiones en el plazo de cinco días", line 448): NOT fully confirmed which one governs.

---

## 2. Detracciones (SPOT)

- **Mechanism**: the buyer/user of the good/service withholds a percentage of the amount to pay and deposits it in the supplier's **Banco de la Nación cuenta corriente de detracciones**; the supplier uses these funds to pay taxes administered/collected by SUNAT. https://orientacion.sunat.gob.pe/sites/default/files/inline-files/Cartilla_detracciones.pdf and SUNAT charla https://charlas.sunat.gob.pe/sites/default/files/2024-05/SISTEMA%20DE%20DETRACCIONES%202024x.pdf [OFFICIAL-TEXT, 2024 charla].
- **Legal base**: TUO DL 940 (DS 155-2004-EF), RS 183-2004/SUNAT and amendments. https://www.sunat.gob.pe/legislacion/tributaria/detracciones/tuo-940-ds155-2004-EF.pdf
- **Who deposits**: the buyer/user; the **supplier** deposits only if it receives the full amount without the deposit having been accredited. For transport of goods: user, or provider if it received full payment. Charla above, sections 3-4.
- **When (services, Anexo 3, construction, transport of goods)**: buyer: **by the date of the partial or full payment to the supplier, or by the 5th business day of the month after the comprobante is recorded in the Registro de Compras, whichever occurs first**. Supplier (if it got full payment): within the 5th business day after receipt. https://orientacion.sunat.gob.pe/3142-03-en-la-prestacion-de-servicios-empresas [OFFICIAL-PAGE]. Goods of Anexo 1 (sugar, alcohol): deposit **before** the transfer of the goods. Anexo 2 goods: same pattern as services (charla sec. 4).
- **Threshold**: operation > S/ 700 (services and Anexo 2 goods; except numerals 6, 16, 19, 21 of Anexo 2); transport of goods by road: > S/ 400 (importe or valor referencial); Anexo 1 goods: > 1/2 UIT. Sources: charla; https://orientacion.sunat.gob.pe/detracciones-en-el-transporte-de-bienes-por-via-terrestre [OFFICIAL-PAGE].
- **Rates (services, Anexo 3, current per orientacion page)**: 12% = intermediación laboral y tercerización, mantenimiento y reparación de bienes muebles, otros servicios empresariales, **demás servicios gravados con IGV**, and (from 01.04.2025) beneficio de minerales metálicos; 10% = arrendamiento de bienes, movimiento de carga, comisión mercantil, fabricación por encargo, transporte de personas; 4% = contratos de construcción; **4% transporte de bienes por vía terrestre** (orientacion transport page). https://orientacion.sunat.gob.pe/3142-03-en-la-prestacion-de-servicios-empresas (12% base: RS 071-2018/SUNAT, https://www.sunat.gob.pe/legislacion/superin/2018/071-2018.pdf).
- **Rates (goods, per SUNAT 2024 charla; Anexo 1/2)**: azúcar/melaza 10, alcohol etílico 10 (Anexo 1); recursos hidrobiológicos 4, maíz amarillo duro 4, caña de azúcar 10, arena y piedra 10, residuos/subproductos 15, bienes gravados por renuncia a exoneración 10, carnes y despojos 4, aceite de pescado 10, harina de pescado 4, leche 4, madera 4, oro gravado 10, paprika 10, minerales metálicos no auríferos 10, bienes exonerados 1.5, oro/minerales exonerados 1.5, minerales no metálicos 10, plomo 15. Inmuebles gravados: 4% is commonly cited but NOT CONFIRMED in files I opened. Full code list (Anexo 4, codes 1-45 of Catalogo 54): https://orientacion.sunat.gob.pe/apendices-del-sistema-de-detracciones (my fetch dropped several codes, e.g., 2, 6, 18, 27-29; transport of cargo is code 027 at 4% per a SUNAT form snippet, NOT confirmed from the full table). Do not hard-code the table: load it from Catalogo 54 / RS 183-2004 Anexo 4 as data.
- **Recent changes (2024-2026)**: RS 000086-2025/SUNAT (published 30 Mar 2025) moved gold minerals and non-auriferous metal minerals from Anexo 2 to Anexo 1 (rate stays 10%) and made "servicio de beneficio de minerales metálicos" its own Anexo 3 item at 12% (https://busquedas.elperuano.pe/dispositivo/NL/2385677-1 snippet; https://emprender.sunat.gob.pe/sites/default/files/inline-files/RS%2086-2025.pdf). **11 Sep 2026**: SUNAT head announced to Congress that detracción and retención **rates will be updated, services added and new agents designated**; no norm number or date yet. https://elperuano.pe/noticia/304544-sunat-anuncia-cambios-en-detracciones-y-retenciones-para-fortalecer-el-pago-de-impuestos [SECONDARY/press]. **Design for rate tables loaded from data, with effective dates.** No other 2026 rate-table resolution confirmed.
- **Account & proof**: supplier must hold a BN cuenta corriente de detracciones; if it has none, the buyer informs SUNAT and the BN opens it de oficio. After the BN confirms the deposit, the **constancia de depósito** is generated in SUNAT Virtual (SOL). Charla sec. 5; https://www.sunat.gob.pe/orientacion/Detracciones/InstructivoDepositoIndividualnuevaversion.pdf [OFFICIAL-TEXT]. Deposit channels: web with cargo en cuenta (mass or individual), NPD at BN, USB file (min 10 deposits). Charla sec. 9.
- **Invoice must show** (electronic): 
  - Legend code **2006** "Operación sujeta a detracción" (Catalogo 52, `cbc:Note`), with tipo de operación **1001** (Catalogo 51) when the whole invoice is subject to SPOT (1002 recursos hidrobiológicos, 1004 transporte de carga). https://www.sunat.gob.pe/legislacion/superin/2022/anexo-123-2022.pdf [OFFICIAL-TEXT]. (Legend text: "Operación sujeta al Sistema de Pago de Obligaciones Tributarias"; older text added "con el Gobierno Central".)
  - `cac:PaymentTerms/cbc:PaymentMeansID` = código de bien/servicio (Catalogo 54), `cbc:PaymentPercent` (n(3,5)), `cbc:Amount` = monto de detracción (PEN).
  - `cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID` = cuenta BN (mandatory when tipo de operación 1001); `cac:PaymentMeans/cbc:PaymentMeansCode` = medio de pago (Catalogo 59). Same annex. SUNAT page states that since 10 Apr 2023 the SOL-issued invoice must include the detracción info when applicable: https://cpe.sunat.gob.pe/noticias/factura-con-detraccion (403 to me; snippet only) [SECONDARY snippet].
  - Do not mix SPOT and non-SPOT lines in the same comprobante (charla sec. 1).
- **Treasury meaning of a detracción**: the customer pays you *invoice total − detracción* to your normal bank account; the detracción amount is deposited into your BN account. Funds there can only be used to pay SUNAT debts (tributos, multas, intereses, plus EsSalud/ONP) and can be freed when not exhausted (liberación): general procedure after funds remain unused 3 consecutive months (2 months for Buen Contribuyente/agente de retención), requests in the first 5 business days of Jan/Apr/Jul/Oct (6x/yr for BC/AR: Jan/Mar/May/Jul/Sep/Nov); Form 1697 in SOL; special procedure for Anexo 1/2 goods twice a month. Conditions: no tax debt, not "no habido", books up to date. Charla sec. 11-13. After approval, 15 business days to collect.
- **Penalties**: 50% of undeposited amount for the obliged party; 100% of the amount for using funds for other purposes. Charla sec. 10.
- **ITF**: accounts used exclusively for SPOT are ITF-exempt (Apéndice inciso b of TUO Ley 28194).

---

## 3. Bancarización (Ley 28194, TUO DS 150-2007-EF)

TUO text read directly: https://www.sunat.gob.pe/legislacion/itf/ds150_07.pdf [OFFICIAL-TEXT].
- **Threshold (art. 4)**: **S/ 2,000 or US$ 500** (since 1.4.2022, DL 1529; earlier S/ 3,500 / US$ 1,000 until 2022; 5,000/1,500 before 2008). Confirmed current as of the consolidated text; no 2026 change found. Fixed in soles for PEN-agreed obligations, USD for USD-agreed; other currencies converted using SBS weighted-average **venta** on the date the obligation arises (art. 4, 3rd paragraph).
- **Low-compliance counterparties**: if one party is rated at the two lowest compliance levels by SUNAT, the threshold is 30% of the amounts (art. 4, 2nd par., DL 1535, 19.3.2022).
- **Applies per obligation, not per payment**: obligations above the threshold must be paid with medios de pago even if cancelled through partial payments below the threshold (art. 3). Mutuos de dinero: always, any amount. Real estate, vehicles, capital participation transfers: from 1 UIT (art. 3).
- **Medios de pago (art. 5, as amended by Ley 30730, 2018)**: depósitos en cuenta; giros; transferencias de fondos; órdenes de pago; tarjetas de débito expedidas en el país; tarjetas de crédito expedidas (domestic or foreign); **cheques** (the earlier restriction to "no negociable" cheques was removed; the prior text is shown as TEXTO ANTERIOR); remesas; cartas de crédito. Note art. 5-A (DL 1529, from 1.4.2022): use is valid only if paid directly to the creditor or to a third party **designated and communicated to SUNAT before payment**. Also art. 3-A: comercio exterior (FOB > S/ 7,000 / US$ 2,000 for import for consumption), see https://www.sunat.gob.pe/legislacion/oficiosAd/2026/2026-INF-047-340000.pdf [OFFICIAL-TEXT, Informe 047-2026, 17 Apr 2026]. Per your question: "cheque no negociable" is **no longer the legal requirement for the current art. 5**; the SUNAT medio de pago Tabla 1 still lists code 007 with the old wording. Practical recommendation: still prefer "no negociable" cheques; and the SUNAT code table has not been rewritten.
- **Exceptions (art. 6)**: payments to financial system entities, to tax administrations, judicial consignation, and districts without financial agency (SBS list) under conditions.
- **Consequences (art. 8)**: payments made without medios de pago **do not give right to deduct gastos, costos or créditos (IGV crédito fiscal)**, nor compensations or refunds. Verification is done **when the payment is made** (accrual-deducted costs are checked at payment; crédito fiscal also verified at payment). If the credit was used improperly the taxpayer must rectify and pay.
- **What the module should record per payment** (to prove compliance and map PLE 1.2): medio de pago (Tabla 1 code), fecha de la operación, banco (entidad financiera, Tabla 3 PLE), cuenta bancaria propia, número de operación/transacción o cheque, beneficiario/girador (RUC/doc + nombre), monto and moneda (+ tipo de cambio), documento sustentatorio (factura serie-número), and for third-party payments the SUNAT communication. For cash payments below threshold use code 008; above threshold with exception use 009 and flag it.

---

## 4. ITF (Impuesto a las Transacciones Financieras)

- **Rate 0.005%** on the value of each taxable operation (art. 10, Ley 29667, since 1.4.2011). https://www.sunat.gob.pe/legislacion/itf/ds150_07.pdf [OFFICIAL-TEXT]; https://orientacion.sunat.gob.pe/03-tasa-del-impuesto-las-transacciones-financieras [OFFICIAL-PAGE]. No 2026 change found (one Bolivian/Colombian/Dominican result is unrelated).
- **Taxable**: credits/debits to accounts at financial entities (except transfers between accounts of the same holder), payments to financial entities without using accounts (cash), purchase of gerencia cheques/certificados, giros/remesas, etc. (art. 9). Arises at the moment of the credit/debit (art. 14).
- **Exemptions (Apéndice, art. 11)**: public sector accounts; **accounts exclusively used for SPOT detracciones** (inciso b); payroll accounts opened by employer for workers; CTS accounts; AFP funds; interbank/BCRP accounts; etc., conditional on sworn declaration to the bank in some cases.
- **Deductible** for Impuesto a la Renta for 3rd-category taxpayers under general rules (art. 19).
- **In bank movements**: the bank charges it as a separate debit line "ITF" (0.005% of each debit/credit); the module should allow a line type "ITF" (gasto financiero/tributo, account 64 in PCGE) linked to the originating bank movement. The bank-statement presentation detail is practice (NOT CONFIRMED in an official text).

---

## 5. Factura al crédito (RS 000193-2020/SUNAT)

- **Rule**: electronic factura must state "forma de pago": **Contado** (paid on issue date) or **Crédito** (paid totally or partially after issue). For Crédito: **monto neto pendiente de pago** and **fecha(s) de vencimiento + monto of the single payment or each cuota**. The "monto neto pendiente" **excludes** IGV retenciones, the detracción deposit and other deductions applicable to the comprobante. https://www.sunat.gob.pe/legislacion/superin/2020/193-2020.pdf (art. 9 inciso s) [OFFICIAL-TEXT]. Same for RHE (monto pendiente, due dates, cuotas) and NCE can correct it (within 10 business days, per article for RHE).
- **In force**: the resolution text says **1 April 2021**; secondary sources say it was later postponed to 1 Sept 2021 (NOT CONFIRMED in an official text I opened). Current state is simply "mandatory".
- **UBL 2.1 structure** (Anexo 9-A, https://www.sunat.gob.pe/legislacion/superin/2020/anexo4-193-2020.pdf [OFFICIAL-TEXT]; also in the current RS 123-2022 annex https://www.sunat.gob.pe/legislacion/superin/2022/anexo-123-2022.pdf):
  - Contado: `/Invoice/cac:PaymentTerms` with `cbc:ID`="FormaPago", `cbc:PaymentMeansID`="Contado".
  - Crédito: one `cac:PaymentTerms` with ID "FormaPago", PaymentMeansID "Credito", `cbc:Amount` = monto neto pendiente (+ currencyID); then one `cac:PaymentTerms` per cuota with ID "FormaPago", PaymentMeansID "Cuota001"..."Cuota010", `cbc:Amount` and `cbc:PaymentDueDate` (YYYY-MM-DD). The annex shows identifiers up to Cuota010 (maximum count of cuotas per annex; check current rule before enforcing).
  - Retention data (when buyer is an agent): `cac:AllowanceCharge` with reason code 62, Catalogo 53, factor, amount, base.
- **Implications for AR/AP**: 
  - Receivable schedule per invoice = list of (due date, amount) from the XML; initial amount to collect from the customer = total − retención − detracción (= "monto neto pendiente"). Sum of cuotas must equal the monto neto pendiente (modeled as an invariant).
  - Payable schedule mirrors it for purchases (and the payment may also trigger the CRE if you are the agent, and the deposit if you are the buyer under SPOT).
  - Payments made on a different date than the due date do not require a new document (practitioner view, NOT CONFIRMED officially).
  - Credit invoices are what the **factura negociable** (DU 013-2020, conformidad platform, 8 calendar days) applies to.

---

## 6. Libros y registros: Libro Caja y Bancos, PLE, SIRE

- **Obligation by regime/income** (Ley del IR art. 65 + RS 234-2006/SUNAT, as modified; MYPE: DL 1269 art. 11):
  - NRUS: no accounting books. RER: only Registro de Ventas and Registro de Compras. https://emprender.sunat.gob.pe/ruc/regimenes-tributarios-mype/regimen-especial-renta-rer (snippet; 403 to fetch) [SECONDARY snippet].
  - RMT/Régimen General ≤ 300 UIT: Registro de Ventas, Registro de Compras, Libro Diario formato simplificado. 300-500 UIT: + Libro Diario and Mayor. 500-1,700 UIT: + Libro de Inventarios y Balances. > 1,700 UIT: **contabilidad completa**, which includes **Libro Caja y Bancos**. https://orientacion.sunat.gob.pe/registros-y-libros-1 (RMT table, fetched) and RS 226-2013/SUNAT art. 12.3 https://www.sunat.gob.pe/legislacion/superin/2013/226-2013.pdf [OFFICIAL-TEXT: it lists Caja y Bancos among the complete-accounting books; the 150/500/1,700 tiers in that text are older and were later re-tiered to 300 UIT by the LIR reform: see RS 000257-2025 recitals https://www.sunat.gob.pe/legislacion/superin/2025/000257-2025.pdf]. 2026 UIT = S/ 5,500 (DS 301-2025-EF), so 1,700 UIT = S/ 9.35M. https://www.gob.pe/institucion/mef/noticias/1314665-mef-establece-en-s-5500-el-valor-de-la-uit-para-el-ano-2026
  - RS 286-2009 art. 13: Libro Caja y Bancos records **monthly** all movement of efectivo and equivalentes; **exception** if its info is contained in the Libro Mayor (art. 13, 1.3). https://www.sunat.gob.pe/legislacion/superin/2009/rs286.doc [OFFICIAL-TEXT]. This means that many taxpayers over 1,700 UIT keep the info in Mayor instead.
- **Electronic: PLE vs SIRE**: 
  - **Libro Caja y Bancos is NOT in the mandatory-electronic list** for non-PRICOS. Electronic keeping is mandatory only for PRICOS with income > 3,000 UIT ("all electronic books") and optional (voluntary, "alguno, varios, todos o ninguno") for everyone else. https://orientacion.sunat.gob.pe/preguntas-frecuentes-libros-y-registros (Q8, Q9) [OFFICIAL-PAGE]. 3,000 UIT threshold is from 2023 material, NOT CONFIRMED for 2026.
  - **SIRE (RS 112-2021, 40-2022)** replaced PLE only for Registro de Ventas e Ingresos (RVIE) and Registro de Compras (RCE). It does **not** cover Caja y Bancos. https://emprender.sunat.gob.pe/comprobantes-libros/registros-libros-electronicos/sistema-integrado-registros-electronicos-sire; RS 000125-2026/SUNAT (30 Jun 2026) postponed SIRE obligation for PRICOS with 2024 net income > 2,300 UIT from June to **October 2026**. https://www.sunat.gob.pe/legislacion/superin/2026/000125-2026.pdf [OFFICIAL-TEXT]. Relation to treasury: SIRE's RCE now shows the **medio de pago** (Tabla 10, same list as PLE Tabla 1) for compras (see RS 112-2021 annex) and the **payment of IGV retained on LC** is linked to RCE (RS 047-2026).
  - Aug 2025: RS 000257-2025 changed late-entry deadlines for Diario/Mayor/Inventarios when PDT 625 is filed (in force 1 Aug 2025); not directly relevant to Caja y Bancos.
- **PLE formats** (RS 286-2009 Anexo 2; file codes in RS 329-2010 table): 
  - **1.1** "Libro Caja y Bancos - Detalle de los movimientos del efectivo", book code **010100**, filename `LERRRRRRRRRRRAAAAMM0001010000OIM1.TXT`.
  - **1.2** "Libro Caja y Bancos - Detalle de los movimientos de la cuenta corriente", book code **010200**, filename `LERRRRRRRRRRRAAAAMM0001020000OIM1.TXT`. https://www.normaslegalesonline.pe/imagenes//15/03/2018/1521145146334_R_329_2010.pdf [OFFICIAL-TEXT mirror of RS 329-2010]. (A 2026 blog claims codes 050000/060000: wrong in my verification, treat as unreliable.)
  - Each is a separate TXT; SUNAT requires both to validate to send the book summary (SUNAT page snippet https://emprender.sunat.gob.pe/comprobantes-libros/registros-libros-electronicos/otros-libros).
- **Minimum information** (SUNAT "información mínima" PDFs, which define what must be shown; these are the legal contents):
  - **1.1 (efectivo)**: número correlativo del registro o CUO; fecha de la operación; descripción de la operación (inicial/final balances indicated); código y denominación de la(s) cuenta(s) contable(s) asociada(s) (origin/destination); saldos y movimientos (deudor/acreedor); totales. http://contenido.app.sunat.gob.pe/insc/Libros+y+Registros/Informacion+m%C3%ADnimo+formatos/Formato+1.1.pdf [OFFICIAL-TEXT].
  - **1.2 (cuenta corriente)**: header: nombre de la entidad financiera and código de la cuenta corriente; correlativo/CUO; fecha de la operación; **medio de pago utilizado (Tabla 1)**; descripción de la operación; apellidos y nombres / razón social del **girador o beneficiario** ("varios" allowed for multiple ops); **número de transacción bancaria, del documento sustentatorio o de control interno**; código y denominación de la cuenta contable asociada; saldos y movimientos (deudor/acreedor); totales. http://contenido.app.sunat.gob.pe/insc/Libros+y+Registros/Informacion+m%C3%ADnimo+formatos/FORMATO+1_2.pdf [OFFICIAL-TEXT].
  - **Field-by-field TXT layout (length, mandatory, formats)** from Anexo 2 of RS 286-2009 as modified: **NOT CONFIRMED**; I could not retrieve that annex from SUNAT-hosted files (the pdf annex URL 404ed and Scribd/third-party copies were truncated). The PLE validator and the current annex are the reference; the modules should not hard-code the TXT layout without that annex.
- **Code tables** (PLE Anexo 3 of RS 286-2009): Formato 1.2 explicitly cites "tabla 1" for medio de pago (SUNAT information-minimum PDF above). Tabla 3 = entidades financieras in the PLE numbering is my recollection and is NOT CONFIRMED (I did not open the PLE Anexo 3). The SIRE annex (RS 112-2021) uses a different numbering (Tabla 3 = tipo de comprobante, Tabla 10 = tipo de medio de pago). **Do not reuse table numbers between PLE and SIRE; store codes by name (medio_pago, entidad_financiera) with a per-export mapping.**

---

### 6a. PLE layout confirmado (Libro Caja y Bancos 1.1 / 1.2) and holidays

Implemented in `PleCashBookExporter` (modules/Treasury/Services). Sources:
- Official workbook "Estructura del PLE.xls" (Anexo 2 de la RS 286-2009/SUNAT, v5, saved 2021-02): https://cdn.www.gob.pe/uploads/document/file/1763321/Estructura%20del%20PLE.xls (sheets "Reglas de nombres de libros", "Reglas Generales", "1 Libro Caja y Bancos").
- Page of the PLE structures: https://www.gob.pe/institucion/sunat/informes-publicaciones/ (Estructura de los Libros y Registros Electrónicos en el PLE) and RS 286-2009 itself: https://www.sunat.gob.pe/legislacion/superin/2009/rs286.doc.

**Confirmed by the workbook**
- File name `LE` + RUC (11) + `AAAA` + `MM` + `00` + `LLLLLL` (010100 / 010200) + `00` + `O` + `I` + `M` + `1` + `.TXT`. O: 1 operating entity (0 = cierre/baja RUC, 2 = libro cerrado). I: 1 with information, 0 without (an empty TXT). M: 1 soles, 2 US dólares. The final `1` means generated by the PLE (fixed).
- Separator `|`. Text fields cannot contain `|`, `/` or `\`. Alphanumeric fields accept letters, digits and `( ) , . -`. Negative amounts are written `- #.##`. Amounts: up to 12 integers and 2 decimals, no thousands separator. Dates `DD/MM/AAAA`.
- Formato 1.1 (010100), 19 fields: 1 periodo `AAAAMM00`; 2 CUO (up to 40); 3 correlativo (2 to 10, first character A apertura / M movimiento / C cierre); 4 cuenta contable del efectivo (max disaggregation); 5-6 optional unit/cost center; 7 moneda (Tabla 4); 8 tipo de comprobante (Tabla 10); 9 serie (optional); 10 número (mandatory); 11 fecha contable (optional); 12 fecha de vencimiento (optional); 13 fecha de la operación; 14 glosa (up to 200); 15 glosa referencial (optional); 16 debe; 17 haber; 18 dato estructurado (only when the Diario is not consolidated); 19 estado (1 operation of the period; 8 omitted in a previous period; 9 modifies a previous period). Fields 20-38 are free: not emitted.
- Formato 1.2 (010200), 15 fields: 1 periodo; 2 CUO; 3 correlativo; 4 código de entidad financiera (2 digits, Tabla 3); 5 cuenta bancaria (up to 30); 6 fecha de la operación; 7 medio de pago (3 digits, Tabla 1); 8 descripción (up to 200); 9 tipo de documento de identidad del girador o beneficiario (Tabla 2, `-` if none or multiple operations); 10 número de documento (`-` if none); 11 nombre o razón social (`varios` allowed for multiple operations); 12 número de transacción (up to 20); 13 deudor; 14 acreedor (13 and 14 mutually exclusive, never both 0.00); 15 estado. Fields 16-30 are free: not emitted.
- Identity document rules: 1 DNI 8 numeric fixed; 4 CE up to 12 alphanumeric; 6 RUC 11 numeric with modulo 11; 7 passport up to 12 alphanumeric; A cédula diplomática 15 numeric; 0 otros up to 15 alphanumeric.
- Tabla 10 "00 Otros": serie optional (up to 20), número mandatory (up to 20). Factura/boleta/notas (01, 03, 07, 08) require a 4-character serie and a numeric número up to 8 digits; the export downgrades a document that does not satisfy that to `00`.

**Decisions of this implementation**
- One TXT per currency: 1.1 is PEN only (M=1); 1.2 always has the PEN file and adds a USD file (M=2) only when USD accounts have movements. An empty period still yields the files with I=0 and 0 bytes.
- CUO = 2-letter source code (DP document payment, SN sale note, QT quotation, CO contract, IN income, TS technical service, PC purchase, EX expense, CT cash transaction, TM treasury movement) + the source id padded to 10 digits (e.g. `DP0000000123`). It does not depend on report order, so it is stable across runs.
- Correlativo = `M` + sequence per CUO (each source row is its own asiento: `M1`). No `A` (apertura) or `C` (cierre) asientos: the files carry the movements of the month only.
- Encoding: ASCII only by default (`treasury.ple.ascii_only`): accents and ñ are transliterated and other symbols dropped. ASCII bytes are identical in UTF-8 and ISO-8859-1, so the encoding the validator assumes does not matter; no BOM. Records end with CRLF (the last one too). The trailing pipe is `treasury.ple.trailing_pipe`, default false.
- The export never guesses: accounts with movements and no `bank_accounts.sunat_financial_entity_code` (Tabla 3), rows without a Tabla 1 code ("Sin código"), an invalid RUC or a currency other than PEN/USD block the export and are listed.

**Not confirmed (validate with the official PLE validator before filing)**
1. Tabla 3 (entidad financiera) values: the workbook does not contain the table. The list in `treasury.ple.financial_entity_codes` is a referential suggestion only; the code of each account is entered by the accountant.
2. Tabla 1 values: the codes stored in `BancarizationChecker::SUNAT_PAYMENT_CODES` come from the earlier research, not from the workbook.
3. Whether the validator expects a trailing pipe after the last field (the structure lists fields separated by pipes; default off).
4. The workbook rule "the sum of debe equals the sum of haber for the same CUO" (1.1 field 16/17, note 5) cannot hold for cash-only lines (the counterpart is in the Libro Diario). Confirm whether the validator enforces it for 1.1.
5. File encoding expected by the validator (ASCII output avoids the question).
6. Official 2027 Semana Santa holidays (computed from Easter, 28 March 2027: 25 and 26 March) and any 2027 PCM decree.

**Holidays (detracción deadline)**: table `treasury_holidays` (Tesorería > Feriados), seeded with the 16 national feriados of 2026 and 2027 (DL 713 art. 6 as amended by Ley 31788 (7 Jun), Ley 31822 (23 Jul), Ley 31530 (6 Aug) and Ley 31381 (9 Dec)). Días no laborables of the public sector (DS 042-2025-PCM art. 1.2 for 2 Jan 2026; DS 075-2026-PCM art. 1.2 for 27 Jul 2026) are business days for tax purposes (TUO Código Tributario, Norma XII): stored with `is_business_day_override = true`, never treated as holidays.

## 7. Medios de pago catalogs

**SUNAT Tabla de "Tipo de medio de pago"** (identical content to PLE Tabla 1; here as SIRE Tabla 10, RS 112-2021 annex 1): https://www.sunat.gob.pe/legislacion/superin/2021/anexo-112-2021.pdf [OFFICIAL-TEXT]

| Code | Description |
|---|---|
| 001 | Depósito en cuenta |
| 002 | Giro |
| 003 | Transferencia de fondos |
| 004 | Orden de pago |
| 005 | Tarjeta de débito |
| 006 | Tarjeta de crédito emitida en el país por una empresa del sistema financiero |
| 007 | Cheques con cláusula "no negociable", "intransferibles", "no a la orden" u otra equivalente (art. 5 g, old wording) |
| 008 | Efectivo, por operaciones en las que no existe obligación de utilizar medio de pago |
| 009 | Efectivo, en los demás casos |
| 010 | Medios de pago usados en comercio exterior |
| 011 | Documentos emitidos por EDPYMES y cooperativas de ahorro y crédito no autorizadas a captar depósitos del público |
| 012 | Tarjeta de crédito emitida en el país o en el exterior por una empresa no perteneciente al sistema financiero cuyo objeto principal sea emisión/administración de tarjetas |
| 013 | Tarjetas de crédito emitidas en el exterior por empresas bancarias o financieras no domiciliadas |
| 101 | Transferencias - comercio exterior |
| 102 | Cheques bancarios - comercio exterior |
| 103 | Orden de pago simple - comercio exterior |
| 104 | Orden de pago documentario - comercio exterior |
| 105 | Remesa simple - comercio exterior |
| 106 | Remesa documentaria - comercio exterior |
| 107 | Carta de crédito simple - comercio exterior |
| 108 | Carta de crédito documentario - comercio exterior |
| 999 | Otros medios de pago |

(Note in the annex: "Uso exclusivo para el Anexo 5" of that RS, i.e., the SIRE usage; the same list is the one referenced by Formato 1.2 as "tabla 1" per the SUNAT information-minimum PDF. I did not open the PLE Anexo 3 file itself, so the equality with PLE Tabla 1 is a strong inference, NOT CONFIRMED textually.)

**Catálogo 59 (UBL)**: used in `cac:PaymentMeans/cbc:PaymentMeansCode` ("Medio de pago", listAgencyName "PE:SUNAT", listURI `...catalogo59`) for the detracción payment means (and for some tipo de operación 0302 transport). Confirmed in RS 123-2022 annex (above). The *values* of Catálogo 59: widely reported as the same list (001, 002, 003 ...), but the official Anexo 8 text was not retrieved: **NOT CONFIRMED**. Sources: https://www.sunat.gob.pe/legislacion/superin/2022/anexo-123-2022.pdf and the 2022 SUNAT Anexo 8 (search snippet) https://www.sunat.gob.pe/legislacion/superin/2022/anexo-040-2022.pdf.

Documents that evidence a payment: "Constancia de depósito - Detracción" has document code **80** in the SUNAT annex table of tipos de documento. https://www.sunat.gob.pe/legislacion/superin/2022/anexo-123-2022.pdf [OFFICIAL-TEXT].

---

## 8. Tipo de cambio and diferencia de cambio

- **Source**: SBS publishes daily the **tipo de cambio promedio ponderado compra and venta** (oferta y demanda) on business days; both the SBS portal and El Peruano publications are valid. SBS methodology https://www.sbs.gob.pe/app/stats/metodologia/metodologia_07_2018.pdf; rate page https://www.sbs.gob.pe/app/pp/sistip_portal/paginas/publicacion/tipocambiopromedio.aspx [OFFICIAL]. If not published on a date, use the last published.
- **Renta (art. 61 LIR; Reglamento art. 34)**: foreign-currency operations are recorded at the exchange rate on the operation date; at **balance date**: assets (incl. cash/bank balances in FX) at **compra**, liabilities at **venta** (SBS, close of operations of the balance date); diferencia de cambio goes to results. https://www.sunat.gob.pe/legislacion/renta/regla/cap9.pdf (art. 34 d) [OFFICIAL-TEXT]. SUNAT confirms with Informes 039-2017 and 008-2018: https://www.sunat.gob.pe/legislacion/oficios/2018/informe-oficios/i008-2018-7T0000.pdf (it cites "compra o venta" per the market that applies for operaciones; the Reglamento and informes imply: venta for obligations/purchases; compra for assets/collections; the module should store both rates and decide by direction. The informe snippet is a secondary reading of the rule, NOT CONFIRMED as universal).
- **IGV / SPOT / percepciones**: conversion uses the SBS **promedio ponderado venta** on the day the tax obligation arises (invoice emission; for SPOT: date the obligation arises or deposit date, whichever is first); if no publication that day, last published. https://www.sunat.gob.pe/legislacion/igv/regla/cap4.htm and https://www.sunat.gob.pe/legislacion/oficios/2016/informe-oficios/i118-2016.pdf. For perceptions: venta on the cobro date. SUNAT page https://www.sunat.gob.pe/orientacion/regimenEspIGV/agentesPercepcion/agentesPercepcionImport/tipocambio.htm (import only).
- **Bancarización**: threshold conversion uses SBS venta at the date the obligation is contracted (art. 4 TUO Ley 28194).
- Practical design: store per movement `moneda`, `tc_compra`, `tc_venta`, `tc_usado`, `fecha_tc`, and compute diferencia de cambio at settlement (realised) and at month/year-end revaluation (unrealised); NIC 21 is the accounting basis (not re-verified in this session).

---

## 9. Other compliance points for an SME treasury module

(Items below are mostly accounting practice; statutory support is limited and flagged.)

- **Tax payments**: NPS (Número de Pago SUNAT), cargo en cuenta bancaria (needs bank enrollment), pago con tarjeta/Yape, **cargo en cuenta de detracciones del BN** (no enrollment needed), Pago Fácil at banks for some taxes; NPS expires the day it is generated. https://orientacion.sunat.gob.pe/6643-02-modalidades-de-pago-electronico and https://www.gob.pe/9510-canales-de-pago-de-impuestos-en-sunat (snippets). For PDT 621 specifically: not confirmed in the pages opened. Model "pago de tributo" as a bank/BN-detracciones movement linked to the declaration and period; payments to tax authorities are exempt from bancarización (art. 6.b).
- **Conciliación bancaria**: not mandated by a SUNAT norm; it is internal control, but Formato 1.2 requires bank-transaction reference and the cuenta corriente code, so reconciled statement lines are the natural source of "número de transacción". NOT a statutory requirement (not confirmed).
- **Caja chica / fondo fijo, arqueo de caja**: no SUNAT rule requires a specific document; tax deductibility depends on comprobantes de pago meeting the Reglamento de Comprobantes de Pago (RS 007-99/SUNAT) and bancarización when > threshold. Statutory text NOT retrieved in this session.
- **Cheques, letras de cambio**: Ley 27287 (Títulos Valores). Cheque must be presented within **30 days** from issue (art. 207); cheque de pago diferido max 30 days; letra protest for non-payment within 15 days after maturity (excluding sight instruments). https://diariooficial.elperuano.pe/Normas/obtenerDocumento?idNorma=63 [SECONDARY via search snippet; official consolidated text exists]. Retention timing for letras: due date or actual payment, whichever first (SUNAT FAQ in section 1a). Model cheque states: emitido, entregado, cobrado, vencido (30 days) and letras: aceptada, protestada.
  - **Implementación (Fase 7) [REVISAR]:** la investigación solo da estos datos y ninguno está verificado contra el texto oficial. Cheque: 30 días de presentación desde la emisión y plazo máximo de 30 días para el pago diferido; **supuesto propio, no está en la investigación:** en el cheque de pago diferido los 30 días de presentación se cuentan desde la fecha de pago → `treasury_tax_rates` `cheque_presentation_days` / `cheque_deferred_max_days`. Letra: protesto dentro de los 15 días posteriores al vencimiento → `bill_protest_days`; la investigación no dice si son días hábiles o calendario, por eso `treasury.instruments.protest_day_type` (por defecto `business`) es configurable. No se cargaron los plazos de presentación para otras plazas (60/90/120 días) ni la cláusula «sin protesto».
- **Pagos a terceros**: payments made to a third party must be communicated to SUNAT before payment (art. 5-A TUO Ley 28194) to count as medios de pago.
- **Fiscal control**: UIF reporting duties for obliged subjects (notarios etc.) are not the SME's.

---

## 10. Implications for the Treasury module design

### Must model (core)
1. **Cuentas**: `cuenta_tesoreria` types: caja, caja chica, banco corriente/ahorro/CTS-excluded, **cuenta de detracciones (BN)**, tarjeta. Fields: entidad financiera, number, currency, ITF-exempt flag (true for BN detracciones), PCGE account code mapping (10x).
2. **Movimiento**: ingreso/egreso/transfer/ajuste with `fecha`, `monto`, `moneda`, `tc_compra`/`tc_venta` (SBS), `medio_pago` (SUNAT Tabla 1, store code 001-999), `numero_operacion`, `banco`, `cuenta`, `contraparte` (doc type + number + name), `estado`, and an `usa_medio_bancarizado` flag + `excepcion_bancarizacion` reason (art. 6) + third-party designation flag (art. 5-A). Evidence to keep at least: medio de pago, número de operación, banco, fecha.
3. **Applications (aplicaciones)**: a movement can settle multiple documents and a document can be settled by multiple movements. Link to existing SUNAT docs: factura (01), boleta (03), NC (07), ND (08), RHE (02), LC (04), plus CRE/CPE. Keep a document-level balance: `total − retención − detracción − NC + ND = saldo por cobrar/pagar` and the `monto neto pendiente de pago` from the XML.
4. **Cronograma de cuotas**: import from the factura XML (`FormaPago`, `Credito`, `Cuota001..010`, `PaymentDueDate`, amount) as the AR/AP schedule; also allow manual cuotas for purchases without XML; the sum of cuotas = monto neto pendiente.
5. **Retención IGV (CRE)**: per payment event, if tenant is a designated agent (flag + padrón date from RS 000367-2025) and operation > S/ 700 and not excluded: compute 3% of the amount paid, trigger CRE emission (type 20) tied to that payment (partial payments → one CRE per payment), and track the retained amount payable to SUNAT (PDT/Form 626; for LC: FV 617/pago previo module from 1 Jul 2026). On the receiving side: register CRE received against the customer's invoice, reducing the AR.
6. **Percepción (CPE)**: if the tenant is a perception agent: at each collection (after invoice) compute percent on the amount collected (2%/0.5%/1% by item class) and emit CPE (type 40); otherwise store the percepción field inside the invoice. For purchases: percepción paid on purchases is a tax credit asset, not treasury.
7. **Detracción**: (a) **customer-side (you are buyer)**: on payment, compute deposit = % x amount (code from Catálogo 54), schedule/deposit to supplier's BN account, store constancia (NPD/ constancia de depósito id) and deposit date; deposit deadline = earlier of payment date or 5th business day of the month after Registro de Compras recording; (b) **supplier-side**: AR for detracted invoice = total − detracción; track BN account balance, uses (pago de tributos), liberación requests (Form 1697) windows. The percentage and thresholds must come from **versioned tables** (Anexos 1-3, Catalogo 54) with effective dates, given the Sep 2026 announcement.
8. **ITF**: line type `itf` auto-suggested at 0.005% of each taxable debit/credit; exempt for BN detracciones accounts and same-holder transfers; link to the source movement.
9. **Currency**: multi-currency with SBS rates; revaluation at month-end (activos at compra, pasivos at venta); realised diferencia de cambio at settlement.
10. **Libro Caja y Bancos export (1.1 / 1.2)**: generate from movements; only mandatory (and in practice for contabilidad completa > 1,700 UIT, unless info is in Mayor). **Export as PLE TXT** (codes 010100 / 010200) is optional and voluntary except PRICOS > 3,000 UIT. Because the TXT field layout (Anexo 2 RS 286-2009) was not retrieved, treat the PLE export as a later deliverable; for v1 provide a printable/Excel detail with the *información mínima* fields above. Tax regimes: RER and NRUS generally do not need it (RER: only RV/RC), RMT ≤ 1,700 UIT: not required.

### Link to existing SUNAT documents in the product (what the module must reference)
- Factura/boleta (UBL): total, `FormaPago`, `Credito`+cuotas, `PaymentMeans` (detracción), `PaymentTerms` (detracción %, monto, code), AllowanceCharge 62 (retención), percepción block (tipo operación 2001).
- NC/ND: adjust balances; NC can correct credit installment info (RS 193-2020).
- RHE, LC: retention 8% (RHE) and IGV retention (LC) as treasury deductions.
- CRE / CPE (type 20 / 40): as outputs and inputs of payment/collection events.
- Guía de remisión: not linked to treasury.

### Optional / later
- PLE TXT generator for 1.1 / 1.2 (and PLE Tabla 3 entidades financieras), Libro Diario links.
- Bank statement import and conciliación (not statutory).
- Caja chica with arqueo (internal control), cheque/letra lifecycle (Ley 27287), factoring/factura negociable status (conformidad within 8 calendar days; CAVALI registry status).
- Payment of tributos via NPS and linking to PDT 621 declarations.
- Alerts: bancarización threshold (S/ 2,000 / US$ 500; 30% for low-compliance counterparties), detracción deadlines, cheque 30-day expiry, retention agent status.

### Open items to verify before coding
1. Current Catalogo 59 values and PLE Tabla 1/Tabla 3 (entidades financieras) from the official annex; field-by-field layout of PLE 1.1/1.2 (RS 286-2009 Anexo 2 and later versions).
2. The detracción rate table after the announced updates (Sep 2026): watch SUNAT RS and DS; current table above is "as of the orientacion pages", not 2026-11 normative text.
3. Official text for percepción agents list and import rates after DL 1714.
4. Whether the 3,000 UIT threshold for PRICOS electronic books still applies.
5. IGV perception/retention agent status per tenant must be read from the SUNAT padrón, not inferred.
