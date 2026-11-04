<?php

namespace Database\Seeders\Demo;

/**
 * The business demo data of demo:business: per company profile, its
 * products / services, customers and vendors (invented Pakistani firms:
 * obviously-not-real NTNs "0000xxx-x", .example e-mail domains, +92 300 0000
 * phone numbers), and how many customer invoices and vendor bills to raise.
 *
 * Products: "uom" is a unit name (Units, Days, Hours, L), "income" /
 * "expense" an account code of the shared chart, "category" an existing
 * product category name, "detail" the second line of an invoice line
 * ({plate}, {bilty}, {container}, {bl}, {vessel}, {voyage}, {month}
 * placeholders), "tax" the sale or purchase tax name used when the company is
 * sales-tax registered (none otherwise).
 *
 * Partners: "buys" / "supplies" are the product keys they are invoiced for /
 * bill the company for.
 */
final class DemoBusinessCatalog
{
    /** Profiles of the demo companies; any live company uses "trucking". */
    public const PROFILES = ['ShipLine' => 'shipline', 'OpenPort' => 'openport'];

    public const LIVE_PROFILE = 'trucking';

    /**
     * @return array{
     *     window: array{from: string, to: string},
     *     invoices: int,
     *     bills: int,
     *     products: array<string, array<string, mixed>>,
     *     customers: array<string, array<string, mixed>>,
     *     vendors: array<string, array<string, mixed>>,
     *     plates?: array<int, string>,
     *     vessels?: array<int, string>,
     *     container_prefix?: string,
     * }
     */
    public static function profile(string $profile): array
    {
        return match ($profile) {
            'shipline' => self::shipLine(),
            'openport' => self::openPort(),
            default    => self::trucking(),
        };
    }

    /** Truck It In: road freight, its fleet's fuel, tyres, spares and tolls. */
    private static function trucking(): array
    {
        return [
            // After the live books' period lock (2026-09-01): drafts only.
            'window'   => ['from' => '2026-09-07', 'to' => '2026-11-03'],
            'invoices' => 14,
            'bills'    => 12,
            'plates'   => ['TLR-482', 'JU-7731', 'TKF-219', 'TLE-905', 'JV-3318', 'TKD-664', 'TLA-127', 'JW-5402'],
            'products' => [
                'ftl-khi-lhe'   => ['name' => 'FTL 22-Wheeler Trailer - Karachi to Lahore', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 285000, 'cost' => 238000, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => '15% Sales Tax on Services (SRB)', 'detail' => 'Vehicle {plate} - Bilty No. {bilty}'],
                'ftl-khi-isb'   => ['name' => 'FTL 22-Wheeler Trailer - Karachi to Islamabad', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 342000, 'cost' => 289000, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => '15% Sales Tax on Services (SRB)', 'detail' => 'Vehicle {plate} - Bilty No. {bilty}'],
                'ftl-khi-mux'   => ['name' => 'FTL 10-Wheeler - Karachi to Multan', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 168000, 'cost' => 139000, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => '15% Sales Tax on Services (SRB)', 'detail' => 'Vehicle {plate} - Bilty No. {bilty}'],
                'haul-40-fsd'   => ['name' => '40ft Container Haulage - Port Qasim to Faisalabad', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 246000, 'cost' => 205000, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => '15% Sales Tax on Services (SRB)', 'detail' => 'Vehicle {plate} - Bilty No. {bilty}'],
                'haul-20-skt'   => ['name' => '20ft Container Haulage - KICT to Sialkot Dry Port', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 231000, 'cost' => 194000, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => '15% Sales Tax on Services (SRB)', 'detail' => 'Vehicle {plate} - Bilty No. {bilty}'],
                'city-mazda'    => ['name' => 'Mazda 16ft Intra-city Delivery - Karachi', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 18500, 'cost' => 14200, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => '15% Sales Tax on Services (SRB)', 'detail' => 'Vehicle {plate} - {month} drops'],
                'detention'     => ['name' => 'Truck Detention Charges (per vehicle per day)', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Days', 'price' => 12000, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => '15% Sales Tax on Services (SRB)', 'detail' => 'Vehicle {plate} held at consignee - Bilty No. {bilty}'],
                'labour'        => ['name' => 'Loading & Unloading Labour (per trip)', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 6500, 'cost' => 4500, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => '15% Sales Tax on Services (SRB)', 'detail' => 'Bilty No. {bilty}'],
                'hsd'           => ['name' => 'HSD Diesel - Bulk Supply (per litre)', 'type' => 'goods', 'category' => 'Fleet Operations & Fuel', 'uom' => 'L', 'price' => 0, 'cost' => 279, 'income' => '400000', 'expense' => '613000', 'buy' => true, 'tax' => null, 'detail' => 'Fleet fuelling - {month}'],
                'tyre'          => ['name' => 'Truck Tyre 12R22.5 Radial (Steel Belted)', 'type' => 'goods', 'category' => 'Fleet Maintenance & Spares', 'uom' => 'Units', 'price' => 0, 'cost' => 78500, 'income' => '400000', 'expense' => '614000', 'buy' => true, 'tax' => '18% Input Tax (Purchases)', 'detail' => 'Fitted on {plate}'],
                'brake-lining'  => ['name' => 'Brake Lining Set - Hino 500 Series', 'type' => 'goods', 'category' => 'Fleet Maintenance & Spares', 'uom' => 'Units', 'price' => 0, 'cost' => 14600, 'income' => '400000', 'expense' => '614000', 'buy' => true, 'tax' => '18% Input Tax (Purchases)', 'detail' => 'Fitted on {plate}'],
                'engine-oil'    => ['name' => 'Engine Oil 15W-40 (20 L Pail)', 'type' => 'goods', 'category' => 'Fleet Maintenance & Spares', 'uom' => 'Units', 'price' => 0, 'cost' => 21800, 'income' => '400000', 'expense' => '614000', 'buy' => true, 'tax' => '18% Input Tax (Purchases)', 'detail' => 'Workshop stock - {month}'],
                'filter-kit'    => ['name' => 'Air & Fuel Filter Kit - Isuzu FVR', 'type' => 'goods', 'category' => 'Fleet Maintenance & Spares', 'uom' => 'Units', 'price' => 0, 'cost' => 9800, 'income' => '400000', 'expense' => '614000', 'buy' => true, 'tax' => '18% Input Tax (Purchases)', 'detail' => 'Fitted on {plate}'],
                'workshop'      => ['name' => 'Workshop Labour - Preventive Maintenance', 'type' => 'service', 'category' => 'Fleet Maintenance & Spares', 'uom' => 'Units', 'price' => 0, 'cost' => 8500, 'income' => '400000', 'expense' => '614000', 'buy' => true, 'tax' => '15% Input Tax on Services (SRB/PRA)', 'detail' => 'Service of {plate}'],
                'toll'          => ['name' => 'Motorway Toll Tag Recharge', 'type' => 'service', 'category' => 'Fleet Operations & Fuel', 'uom' => 'Units', 'price' => 0, 'cost' => 50000, 'income' => '400000', 'expense' => '615000', 'buy' => true, 'tax' => null, 'detail' => 'Tag top-up for {plate}'],
                'market-truck'  => ['name' => 'Market Vehicle Hire - Karachi to Lahore (broker)', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 0, 'cost' => 252000, 'income' => '400000', 'expense' => '500000', 'buy' => true, 'tax' => null, 'detail' => 'Sub-contracted trip - Bilty No. {bilty}'],
            ],
            'customers' => [
                'chenab-fabrics'   => ['name' => 'Chenab Fabrics (Pvt) Ltd', 'city' => 'Faisalabad', 'street' => 'Plot 41, Sargodha Road Industrial Estate', 'buys' => ['haul-40-fsd', 'ftl-khi-lhe', 'detention']],
                'mehran-bay'       => ['name' => 'Mehran Bay Beverages (Pvt) Ltd', 'city' => 'Karachi', 'street' => 'D-112, SITE Area', 'buys' => ['city-mazda', 'ftl-khi-mux', 'labour']],
                'margalla-cement'  => ['name' => 'Margalla Cement Traders', 'city' => 'Islamabad', 'street' => 'Office 7, I-9 Markaz', 'buys' => ['ftl-khi-isb', 'detention']],
                'sportsline'       => ['name' => 'Sialkot Sportsline Exports', 'city' => 'Sialkot', 'street' => 'Small Industrial Estate, Shahab Pura Road', 'buys' => ['haul-20-skt', 'labour']],
                'indus-delta-rice' => ['name' => 'Indus Delta Rice Mills', 'city' => 'Hyderabad', 'street' => 'Site Area, Phase II', 'buys' => ['ftl-khi-lhe', 'ftl-khi-mux', 'labour']],
                'bolan-crest'      => ['name' => 'Bolan Crest FMCG Distributors', 'city' => 'Quetta', 'street' => 'Sariab Road Warehouse Complex', 'buys' => ['ftl-khi-mux', 'detention']],
                'ravi-steel'       => ['name' => 'Ravi Steel Re-Rolling (Pvt) Ltd', 'city' => 'Lahore', 'street' => '18 km Sheikhupura Road', 'buys' => ['ftl-khi-lhe', 'labour', 'detention']],
                'thal-ceramics'    => ['name' => 'Thal Valley Ceramics', 'city' => 'Multan', 'street' => 'Industrial Estate, Phase III', 'buys' => ['ftl-khi-mux', 'city-mazda']],
            ],
            'vendors' => [
                'saqib-petroleum'  => ['name' => 'Al-Saqib Petroleum Services', 'city' => 'Karachi', 'street' => 'Northern Bypass, Near Toll Plaza', 'supplies' => ['hsd']],
                'lyari-tyre'       => ['name' => 'Lyari Auto Tyre House', 'city' => 'Karachi', 'street' => 'Shop 14, Tyre Market, Shershah', 'supplies' => ['tyre']],
                'shershah-spares'  => ['name' => 'Shershah Truck Spares', 'city' => 'Karachi', 'street' => 'Shop 3, Kabari Market, Shershah', 'supplies' => ['brake-lining', 'filter-kit', 'engine-oil']],
                'highway-link'     => ['name' => 'Highway Link Goods Transport Co.', 'city' => 'Karachi', 'street' => 'Truck Adda, Mauripur Road', 'supplies' => ['market-truck']],
                'tollway-tag'      => ['name' => 'Tollway Tag Recharge Services', 'city' => 'Islamabad', 'street' => 'Office 22, Blue Area', 'supplies' => ['toll']],
                'korangi-workshop' => ['name' => 'Korangi Diesel Workshop', 'city' => 'Karachi', 'street' => 'Sector 15, Korangi Industrial Area', 'supplies' => ['workshop', 'engine-oil']],
            ],
        ];
    }

    /** ShipLine: a shipping line / freight forwarder out of Karachi. */
    private static function shipLine(): array
    {
        return [
            'window'           => ['from' => '2026-08-03', 'to' => '2026-11-03'],
            'invoices'         => 18,
            'bills'            => 14,
            'container_prefix' => 'SLNU',
            'vessels'          => ['MV Arabian Pearl', 'MV Indus Star', 'MV Makran Breeze', 'MV Gulf Horizon'],
            'products'         => [
                'of-40-jea'      => ['name' => 'Ocean Freight - 40ft HC Karachi to Jebel Ali', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 385000, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'Container {container} - B/L {bl}'],
                'of-20-sll'      => ['name' => 'Ocean Freight - 20ft GP Karachi to Salalah', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 248000, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'Container {container} - B/L {bl}'],
                'of-40-cmb'      => ['name' => 'Ocean Freight - 40ft GP Karachi to Colombo', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 412000, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'Container {container} - B/L {bl}'],
                'bl-fee'         => ['name' => 'Bill of Lading Documentation Fee', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 9500, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'B/L {bl}'],
                'thc-40'         => ['name' => 'Terminal Handling Charges (THC) - 40ft', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 48000, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'Container {container}'],
                'detention'      => ['name' => 'Container Detention (per day)', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Days', 'price' => 4500, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'Container {container} beyond free days'],
                'clearance'      => ['name' => 'Customs Clearance & Forwarding (per GD)', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 18000, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'B/L {bl}'],
                'slot-charter'   => ['name' => 'Feeder Slot Charter (per TEU)', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 0, 'cost' => 141000, 'income' => '400000', 'expense' => '500000', 'buy' => true, 'tax' => null, 'detail' => '{vessel} V.{voyage}'],
                'wharfage'       => ['name' => 'Port Wharfage & Dues', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 0, 'cost' => 35500, 'income' => '400000', 'expense' => '500000', 'buy' => true, 'tax' => null, 'detail' => '{vessel} V.{voyage}'],
                'repair-wash'    => ['name' => 'Container Repair & Washing', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 0, 'cost' => 12500, 'income' => '400000', 'expense' => '614000', 'buy' => true, 'tax' => null, 'detail' => 'Container {container}'],
                'cargo-insure'   => ['name' => 'Marine Cargo Insurance Premium', 'type' => 'service', 'category' => 'Logistics & Freight Services', 'uom' => 'Units', 'price' => 0, 'cost' => 28000, 'income' => '400000', 'expense' => '600000', 'buy' => true, 'tax' => null, 'detail' => 'Open cover - {month}'],
                'office-rent'    => ['name' => 'Office Rent - Keamari Head Office (monthly)', 'type' => 'service', 'category' => 'Expenses', 'uom' => 'Units', 'price' => 0, 'cost' => 450000, 'income' => '400000', 'expense' => '612000', 'buy' => true, 'tax' => null, 'detail' => 'Rent for {month}'],
                'courier'        => ['name' => 'Courier - Original B/L Dispatch', 'type' => 'service', 'category' => 'Expenses', 'uom' => 'Units', 'price' => 0, 'cost' => 2800, 'income' => '400000', 'expense' => '600000', 'buy' => true, 'tax' => null, 'detail' => 'B/L {bl}'],
            ],
            'customers' => [
                'hala-textile'    => ['name' => 'Hala Textile Exporters (Pvt) Ltd', 'city' => 'Hyderabad', 'street' => 'Plot 9, Kotri Industrial Area', 'buys' => ['of-40-jea', 'bl-fee', 'thc-40']],
                'kasur-leather'   => ['name' => 'Kasur Leather Works', 'city' => 'Lahore', 'street' => '27 Ferozepur Road', 'buys' => ['of-40-cmb', 'bl-fee', 'clearance']],
                'gwadar-seafood'  => ['name' => 'Gwadar Seafood Processors', 'city' => 'Karachi', 'street' => 'West Wharf, Fish Harbour', 'buys' => ['of-20-sll', 'thc-40', 'detention']],
                'pindi-surgical'  => ['name' => 'Pindi Surgical Instruments Co.', 'city' => 'Sialkot', 'street' => 'Daska Road, Ugoki', 'buys' => ['of-20-sll', 'bl-fee', 'clearance']],
                'sukkur-dates'    => ['name' => 'Sukkur Date Packers', 'city' => 'Khairpur', 'street' => 'Date Market, Station Road', 'buys' => ['of-40-jea', 'detention']],
                'onyx-house'      => ['name' => 'Lahore Marble & Onyx House', 'city' => 'Lahore', 'street' => '5 Multan Road, Chungi Amar Sidhu', 'buys' => ['of-40-cmb', 'thc-40']],
                'basmati-exports' => ['name' => 'Karachi Basmati Exports', 'city' => 'Karachi', 'street' => 'Office 404, Business Centre, I.I. Chundrigar Road', 'buys' => ['of-40-jea', 'of-20-sll', 'bl-fee']],
            ],
            'vendors' => [
                'arabian-feeder'  => ['name' => 'Arabian Sea Feeder Lines', 'city' => 'Karachi', 'street' => 'Office 12, Dock Labour Board Building, Keamari', 'supplies' => ['slot-charter']],
                'keamari-cs'      => ['name' => 'Keamari Container Services', 'city' => 'Karachi', 'street' => 'Yard 3, Napier Mole Road', 'supplies' => ['repair-wash', 'wharfage']],
                'crescent-marine' => ['name' => 'Crescent Marine Insurance Brokers', 'city' => 'Karachi', 'street' => 'Office 601, Shaheen Complex', 'supplies' => ['cargo-insure']],
                'clifton-estates' => ['name' => 'Clifton Properties & Estates', 'city' => 'Karachi', 'street' => 'Block 5, Clifton', 'supplies' => ['office-rent']],
                'swiftpak'        => ['name' => 'SwiftPak Courier Services', 'city' => 'Karachi', 'street' => 'Shahrah-e-Faisal, Block 6 PECHS', 'supplies' => ['courier']],
            ],
        ];
    }

    /** OpenPort: a container terminal / port operator at Port Qasim. */
    private static function openPort(): array
    {
        return [
            'window'           => ['from' => '2026-08-03', 'to' => '2026-11-03'],
            'invoices'         => 18,
            'bills'            => 14,
            'container_prefix' => 'OPTU',
            'vessels'          => ['MV Qasim Trader', 'MV Sea Falcon', 'MV Lahore Express', 'MV Karakoram Bay'],
            'products'         => [
                'handling-40'    => ['name' => 'Container Handling - Load/Discharge (40ft)', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 21000, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => '{vessel} V.{voyage}'],
                'handling-20'    => ['name' => 'Container Handling - Load/Discharge (20ft)', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 14000, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => '{vessel} V.{voyage}'],
                'yard-storage'   => ['name' => 'Yard Storage (per TEU per day)', 'type' => 'service', 'category' => 'Cold Chain & Warehousing', 'uom' => 'Days', 'price' => 1800, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'Container {container}'],
                'reefer-plug'    => ['name' => 'Reefer Plug-in & Monitoring (per day)', 'type' => 'service', 'category' => 'Cold Chain & Warehousing', 'uom' => 'Days', 'price' => 7500, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'Container {container}'],
                'crane-hire'     => ['name' => 'Mobile Harbour Crane Hire (per hour)', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Hours', 'price' => 65000, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => '{vessel} V.{voyage}'],
                'stuffing'       => ['name' => 'Container Stuffing / De-stuffing', 'type' => 'service', 'category' => 'Cold Chain & Warehousing', 'uom' => 'Units', 'price' => 16500, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'Container {container}'],
                'vgm'            => ['name' => 'Weighbridge & VGM Certificate', 'type' => 'service', 'category' => 'Port & Customs Drayage', 'uom' => 'Units', 'price' => 3500, 'cost' => 0, 'income' => '400000', 'expense' => '500000', 'sell' => true, 'tax' => null, 'detail' => 'Container {container}'],
                'hsd'            => ['name' => 'HSD Diesel - Terminal Equipment (per litre)', 'type' => 'goods', 'category' => 'Fleet Operations & Fuel', 'uom' => 'L', 'price' => 0, 'cost' => 279, 'income' => '400000', 'expense' => '613000', 'buy' => true, 'tax' => null, 'detail' => 'Reach stackers and terminal tractors - {month}'],
                'hose-kit'       => ['name' => 'Reach Stacker Hydraulic Hose Kit', 'type' => 'goods', 'category' => 'Fleet Maintenance & Spares', 'uom' => 'Units', 'price' => 0, 'cost' => 68000, 'income' => '400000', 'expense' => '614000', 'buy' => true, 'tax' => null, 'detail' => 'Workshop issue - {month}'],
                'otr-tyre'       => ['name' => 'Straddle Carrier Tyre 16.00R25', 'type' => 'goods', 'category' => 'Fleet Maintenance & Spares', 'uom' => 'Units', 'price' => 0, 'cost' => 410000, 'income' => '400000', 'expense' => '614000', 'buy' => true, 'tax' => null, 'detail' => 'Replacement set - {month}'],
                'power'          => ['name' => 'Electricity - Reefer Yard & Terminal (monthly)', 'type' => 'service', 'category' => 'Expenses', 'uom' => 'Units', 'price' => 0, 'cost' => 1850000, 'income' => '400000', 'expense' => '600000', 'buy' => true, 'tax' => null, 'detail' => 'Billing month {month}'],
                'security'       => ['name' => 'Terminal Gate Security Services (monthly)', 'type' => 'service', 'category' => 'Expenses', 'uom' => 'Units', 'price' => 0, 'cost' => 620000, 'income' => '400000', 'expense' => '600000', 'buy' => true, 'tax' => null, 'detail' => 'Guards for {month}'],
                'twist-locks'    => ['name' => 'Lashing Gear - Twist Locks (box of 50)', 'type' => 'goods', 'category' => 'Fleet Maintenance & Spares', 'uom' => 'Units', 'price' => 0, 'cost' => 95000, 'income' => '400000', 'expense' => '600000', 'buy' => true, 'tax' => null, 'detail' => 'Stores issue - {month}'],
            ],
            'customers' => [
                'bahria-ocean'    => ['name' => 'Bahria Ocean Agencies (Pvt) Ltd', 'city' => 'Karachi', 'street' => 'Office 301, Marine Tower, Kemari', 'buys' => ['handling-40', 'handling-20', 'crane-hire']],
                'qasim-freight'   => ['name' => 'Qasim Freight Forwarders', 'city' => 'Karachi', 'street' => 'Plot 15, Port Qasim Industrial Zone', 'buys' => ['stuffing', 'vgm', 'yard-storage']],
                'northern-dry'    => ['name' => 'Northern Dry Port Logistics', 'city' => 'Lahore', 'street' => 'Dry Port, Mughalpura', 'buys' => ['handling-40', 'yard-storage']],
                'seaview-agency'  => ['name' => 'Seaview Shipping Agencies', 'city' => 'Karachi', 'street' => 'Office 9, Bahria Complex III, M.T. Khan Road', 'buys' => ['handling-20', 'handling-40', 'vgm']],
                'frontier-cold'   => ['name' => 'Frontier Cold Chain (Pvt) Ltd', 'city' => 'Peshawar', 'street' => 'Ring Road, Hayatabad Industrial Estate', 'buys' => ['reefer-plug', 'yard-storage']],
                'makran-coastal'  => ['name' => 'Makran Coastal Traders', 'city' => 'Gwadar', 'street' => 'Marine Drive, Gwadar', 'buys' => ['stuffing', 'reefer-plug']],
                'punjab-reefer'   => ['name' => 'Punjab Reefer Logistics', 'city' => 'Lahore', 'street' => '22 km Raiwind Road', 'buys' => ['reefer-plug', 'handling-40']],
            ],
            'vendors' => [
                'qasim-fuel'      => ['name' => 'Bin Qasim Fuel Depot', 'city' => 'Karachi', 'street' => 'National Highway, Bin Qasim Town', 'supplies' => ['hsd']],
                'hydrolift'       => ['name' => 'Hydro-Lift Equipment Spares', 'city' => 'Karachi', 'street' => 'Shop 8, Plaza Square, Shershah', 'supplies' => ['hose-kit']],
                'port-tyre'       => ['name' => 'Port Tyre & Rubber Co.', 'city' => 'Karachi', 'street' => 'Plot 22, Landhi Industrial Area', 'supplies' => ['otr-tyre']],
                'guardian-shield' => ['name' => 'Guardian Shield Security Services', 'city' => 'Karachi', 'street' => 'Office 5, Bahadurabad', 'supplies' => ['security']],
                'qasim-power'     => ['name' => 'Qasim Power Utilities', 'city' => 'Karachi', 'street' => 'Grid Station Road, Port Qasim', 'supplies' => ['power']],
                'anchor-lashing'  => ['name' => 'Anchor Lashing & Rigging Supplies', 'city' => 'Karachi', 'street' => 'Shop 31, Boulton Market', 'supplies' => ['twist-locks']],
            ],
        ];
    }
}
