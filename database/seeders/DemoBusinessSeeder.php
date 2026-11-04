<?php

namespace Database\Seeders;

use App\Models\DemoRecord;
use App\Services\Demo\DemoRecorder;
use App\Services\Demo\DemoSandbox;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoBusinessCatalog;
use Database\Seeders\Demo\DemoCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\PaymentRegister;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Account\Models\Product;
use Webkul\Account\Models\Tax;
use Webkul\PluginManager\Package;
use Webkul\Product\Models\Category;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\UOM;

/**
 * Business demo data for one company (demo:business): customers, vendors,
 * products / services, customer invoices and vendor bills, and payments,
 * all created through the accounting models and AccountManager (line
 * accounts, taxes, totals, payment-term lines, posting and payment
 * reconciliation are the application's own), dated over the profile's
 * window (DemoBusinessCatalog).
 *
 * A demo company (ShipLine, OpenPort) gets posted invoices and bills, some
 * paid in full, some in part, a few drafts and a cancelled one. A live
 * company (Truck It In) gets drafts only: nothing is posted into its ledger.
 * Every row is recorded in demo_records (phase "business"), each document
 * and master record under its own key, so a re-run creates nothing twice and
 * demo:purge removes exactly these rows. Only installed plugins are seeded.
 */
class DemoBusinessSeeder extends Seeder
{
    private const TIMEZONE = 'Asia/Karachi';

    /** Plugins this seeder writes to: nothing is seeded unless all are installed. */
    public const REQUIRED_PLUGINS = ['accounts', 'invoices', 'products'];

    /** Business plugins this seeder does not write to (seeded only when installed: never, today). */
    public const OTHER_PLUGINS = ['sales', 'purchases', 'inventories', 'projects', 'timesheets', 'manufacturing', 'maintenance'];

    private Carbon $realNow;

    private Company $company;

    private bool $live;

    /** @var array<string, mixed> */
    private array $profile;

    private ?User $clerk = null;

    private ?User $manager = null;

    /** @var array<string, mixed> journals, accounts, taxes, units, categories, terms */
    private array $setup = [];

    /** @var array<string, Partner> */
    private array $partners = [];

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<int, string> */
    public array $warnings = [];

    public function __construct(
        protected DemoRecorder $recorder,
        protected DemoSandbox $sandbox,
    ) {}

    public static function isDemoCompany(Company $company): bool
    {
        return array_key_exists($company->name, DemoCatalog::COMPANIES);
    }

    /** @return array<int, string> required plugins that are not installed */
    public static function missingPlugins(): array
    {
        return array_values(array_filter(self::REQUIRED_PLUGINS, fn (string $name): bool => ! Package::isPluginInstalled($name)));
    }

    /**
     * @return array{
     *     company: string,
     *     live: bool,
     *     created: array<string, int>,
     *     documents: array<string, int>,
     *     warnings: array<int, string>,
     * }
     */
    public function seedCompany(Company $company): array
    {
        $this->realNow = Carbon::now();
        $this->company = $company;
        $this->live = ! self::isDemoCompany($company);
        $this->profile = DemoBusinessCatalog::profile(DemoBusinessCatalog::PROFILES[$company->name] ?? DemoBusinessCatalog::LIVE_PROFILE);
        $this->warnings = [];
        $this->partners = [];
        $this->products = [];

        $this->sandbox->isolate();
        $this->recorder->start();
        $this->recorder->context($company->id, DemoRecord::PHASE_BUSINESS);
        $this->recorder->resetCreated();
        $previousUser = Auth::guard('web')->user();

        try {
            $this->loadSetup();
            $this->loadActors();
            $this->seedPartners();
            $this->seedProducts();
            $this->seedDocuments(MoveType::OUT_INVOICE);
            $this->seedDocuments(MoveType::IN_INVOICE);
            $this->recorder->flush();
            $this->settleNotifications();
        } finally {
            $previousUser ? Auth::guard('web')->setUser($previousUser) : Auth::guard('web')->forgetUser();
            $this->recorder->stop();
            $this->recorder->context(null, DemoRecord::PHASE_STAFF);
        }

        return [
            'company'   => $company->name,
            'live'      => $this->live,
            'created'   => $this->recorder->created(),
            'documents' => $this->documentTotals(),
            'warnings'  => $this->warnings,
        ];
    }

    // ---------------------------------------------------------------------
    // Setup
    // ---------------------------------------------------------------------

    private function loadSetup(): void
    {
        $companyId = $this->company->id;
        $journal = fn (JournalType $type): ?Journal => Journal::query()->where('company_id', $companyId)->where('type', $type)->orderBy('id')->first();
        $registered = (bool) $this->company->is_sales_tax_registered && filled($this->company->strn);

        $this->setup = [
            'sale_journal'     => $journal(JournalType::SALE),
            'purchase_journal' => $journal(JournalType::PURCHASE),
            'bank_journal'     => $journal(JournalType::BANK),
            'receivable'       => $this->accountId('121000'),
            'payable'          => $this->accountId('211000'),
            // An unregistered company charges and claims no sales tax.
            'taxes'            => $registered
                ? Tax::query()->where('company_id', $companyId)->where('is_active', true)->pluck('id', 'name')->all()
                : [],
            'term'             => PaymentTerm::query()->where('company_id', $companyId)->where('name', '30 Days Net')->value('id')
                ?? PaymentTerm::query()->whereNull('company_id')->where('name', '30 Days')->value('id'),
            'country'          => Country::query()->where('code', 'PK')->value('id'),
            'units'            => UOM::query()->pluck('id', 'name')->all(),
            'categories'       => Category::query()->pluck('id', 'name')->all(),
        ];

        foreach (['sale_journal', 'purchase_journal', 'receivable', 'payable'] as $required) {
            if (! $this->setup[$required]) {
                throw new \RuntimeException("{$this->company->name} has no {$required} set up: provision its accounting first.");
            }
        }
    }

    private function accountId(string $code): ?int
    {
        return Account::query()
            ->where('code', $code)
            ->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $this->company->id))
            ->value('id');
    }

    /**
     * Who raises and posts the documents: a demo company's finance staff
     * (an accounts officer drafts, the finance manager posts and pays); for
     * a live company the shared "Demo Accountant" login, never a real person.
     */
    private function loadActors(): void
    {
        if ($this->live) {
            $this->clerk = $this->manager = User::query()->where('email', 'demo.accountant@aureus.test')->first();

            return;
        }

        $setup = DemoCatalog::COMPANIES[$this->company->name];
        $managerKey = $setup['department_managers']['Finance'] ?? null;
        $clerkKey = collect($setup['staff'] + $setup['existing'])
            ->filter(fn (array $person, string $key): bool => $key !== $managerKey && ($person['parent'] ?? null) === $managerKey)
            ->keys()
            ->first();

        $this->manager = $managerKey ? User::query()->where('email', "{$managerKey}@{$setup['domain']}")->first() : null;
        $this->clerk = ($clerkKey ? User::query()->where('email', "{$clerkKey}@{$setup['domain']}")->first() : null) ?? $this->manager;
    }

    // ---------------------------------------------------------------------
    // Master data
    // ---------------------------------------------------------------------

    private function seedPartners(): void
    {
        $since = Carbon::parse($this->profile['window']['from'], self::TIMEZONE)->subDays(45);

        foreach (['customers' => 'customer', 'vendors' => 'supplier'] as $group => $subType) {
            foreach ($this->profile[$group] as $key => $spec) {
                $demoKey = "business:{$this->company->id}:partner:{$key}";
                $existing = $this->recorder->find($demoKey);

                if ($existing) {
                    $partner = Partner::query()->find($existing->record_id);
                    $partner ? $this->partners[$key] = $partner : $this->warn("partner {$spec['name']} was deleted since it was seeded: its documents are skipped.");

                    continue;
                }

                $hash = crc32($demoKey);
                $domain = Str::of($spec['name'])->replace(['(Pvt) Ltd', 'Co.', '&'], '')->slug('')->limit(24, '').'.example';

                $this->partners[$key] = $this->at($this->workMoment($since->copy()->addDays($hash % 30)), $this->clerk, fn (): Partner => $this->recorder->transaction(function () use ($spec, $subType, $hash, $domain, $demoKey): Partner {
                    $partner = Partner::query()->create([
                        'account_type'                      => 'company',
                        'sub_type'                          => $subType,
                        'name'                              => $spec['name'],
                        'email'                             => ($subType === 'customer' ? 'accounts' : 'sales')."@{$domain}",
                        'phone'                             => sprintf('+92 300 0000 %03d', $hash % 1000),
                        'tax_id'                            => sprintf('0000%03d-%d', intdiv($hash, 10) % 1000, $hash % 10),
                        'street1'                           => $spec['street'],
                        'city'                              => $spec['city'],
                        'country_id'                        => $this->setup['country'],
                        'company_id'                        => $this->company->id,
                        'customer_rank'                     => $subType === 'customer' ? 1 : 0,
                        'supplier_rank'                     => $subType === 'supplier' ? 1 : 0,
                        'property_account_receivable_id'    => $this->setup['receivable'],
                        'property_account_payable_id'       => $this->setup['payable'],
                        'property_payment_term_id'          => $this->setup['term'],
                        'property_supplier_payment_term_id' => $this->setup['term'],
                        'comment'                           => 'Demo data (demo:business); removed by demo:purge.',
                    ]);
                    $this->recorder->mark($demoKey, $partner);

                    return $partner;
                }));
            }
        }
    }

    private function seedProducts(): void
    {
        $since = Carbon::parse($this->profile['window']['from'], self::TIMEZONE)->subDays(50);

        foreach ($this->profile['products'] as $key => $spec) {
            $demoKey = "business:{$this->company->id}:product:{$key}";
            $existing = $this->recorder->find($demoKey);

            if ($existing) {
                $product = Product::query()->find($existing->record_id);
                $product ? $this->products[$key] = $product : $this->warn("product {$spec['name']} was deleted since it was seeded: its lines are skipped.");

                continue;
            }

            $unit = $this->setup['units'][$spec['uom']] ?? $this->setup['units']['Units'] ?? null;
            $sells = (bool) ($spec['sell'] ?? false);
            $taxId = $spec['tax'] ? ($this->setup['taxes'][$spec['tax']] ?? null) : null;

            $this->products[$key] = $this->at($this->workMoment($since->copy()->addDays(crc32($demoKey) % 20)), $this->clerk, fn (): Product => $this->recorder->transaction(function () use ($spec, $unit, $sells, $taxId, $demoKey): Product {
                $product = Product::query()->create([
                    'type'                        => $spec['type'],
                    'name'                        => $spec['name'],
                    'price'                       => $spec['price'],
                    'cost'                        => $spec['cost'],
                    'uom_id'                      => $unit,
                    'uom_po_id'                   => $unit,
                    'category_id'                 => $this->setup['categories'][$spec['category']] ?? $this->setup['categories']['All'] ?? null,
                    'company_id'                  => $this->company->id,
                    'sales_ok'                    => $sells,
                    'purchase_ok'                 => ! $sells,
                    'is_storable'                 => false,
                    'property_account_income_id'  => $this->accountId($spec['income']),
                    'property_account_expense_id' => $this->accountId($spec['expense']),
                ]);

                if ($taxId) {
                    $sells ? $product->productTaxes()->sync([$taxId]) : $product->supplierTaxes()->sync([$taxId]);
                }

                $this->recorder->mark($demoKey, $product);

                return $product;
            }));
        }
    }

    // ---------------------------------------------------------------------
    // Invoices and bills
    // ---------------------------------------------------------------------

    private function seedDocuments(MoveType $type): void
    {
        $isSale = $type === MoveType::OUT_INVOICE;
        $kind = $isSale ? 'invoice' : 'bill';

        foreach ($this->plan($type) as $index => $plan) {
            $demoKey = "business:{$this->company->id}:{$kind}:{$index}";

            if ($this->recorder->has($demoKey)) {
                continue;
            }

            if (! isset($this->partners[$plan['partner']])) {
                continue;
            }

            try {
                $this->recorder->transaction(function () use ($type, $plan, $demoKey): void {
                    $move = $this->at($plan['created'], $this->clerk, fn (): Move => $this->createDocument($type, $plan));
                    $this->recorder->mark($demoKey, $move);

                    if ($plan['state'] === 'cancel') {
                        $this->at($plan['created']->copy()->addHours(3), $this->manager, fn () => AccountFacade::cancelMove($move->refresh()));

                        return;
                    }

                    if ($plan['state'] !== 'posted') {
                        return;
                    }

                    $move = $this->at($plan['posted'], $this->manager, fn (): Move => AccountFacade::confirmMove($move->refresh()));

                    if ($plan['paid_at']) {
                        $this->at($plan['paid_at'], $this->manager, fn () => $this->registerPayment($move->refresh(), $plan['pay_share'], $plan['paid_at']));
                    }
                });
            } catch (Throwable $e) {
                $this->warn("{$kind} {$index} ({$this->partners[$plan['partner']]->name}, {$plan['date']->toDateString()}) skipped: ".Str::limit($e->getMessage(), 180));
            }
        }
    }

    /**
     * The documents of one type, deterministic per company: dates spread
     * over the window (working days), partners and lines from the catalog,
     * and the lifecycle each one reaches.
     *
     * @return array<int, array{partner: string, date: Carbon, created: Carbon, posted: Carbon, state: string, paid_at: ?Carbon, pay_share: float, lines: array<int, array{product: string, quantity: float, price: float, name: string}>, reference: string, origin: ?string}>
     */
    private function plan(MoveType $type): array
    {
        $isSale = $type === MoveType::OUT_INVOICE;
        $count = $this->profile[$isSale ? 'invoices' : 'bills'];
        $partners = array_keys($this->profile[$isSale ? 'customers' : 'vendors']);
        $from = Carbon::parse($this->profile['window']['from'], self::TIMEZONE);
        $to = Carbon::parse($this->profile['window']['to'], self::TIMEZONE)->min($this->realNow->copy()->setTimezone(self::TIMEZONE)->startOfDay()->subDay());
        $span = max(1, $from->diffInDays($to));
        $yesterday = $this->realNow->copy()->setTimezone(self::TIMEZONE)->startOfDay()->subDay();
        $random = new Randomizer(new Mt19937(crc32("demo-business:{$this->company->id}:{$type->value}")));
        $plans = [];

        for ($index = 0; $index < $count; $index++) {
            $date = $this->workDay($from->copy()->addDays((int) round($span * ($index + 0.5) / $count) + $random->getInt(-2, 2))->max($from)->min($to)->copy());
            $partnerKey = $partners[($index * 3 + $random->getInt(0, 1)) % count($partners)];
            $spec = $this->profile[$isSale ? 'customers' : 'vendors'][$partnerKey];
            $offered = $spec[$isSale ? 'buys' : 'supplies'];
            $lineCount = min(count($offered), $isSale ? $random->getInt(1, 3) : $random->getInt(1, 2));
            $lines = [];

            foreach (array_slice($offered, 0, $lineCount) as $productKey) {
                $lines[] = $this->planLine($productKey, $isSale, $date, $random);
            }

            $daysOld = (int) $date->diffInDays($yesterday);
            $state = match (true) {
                $this->live                                => 'draft',
                $index === 4                               => 'cancel',
                $daysOld <= 8 && $index % 2 === 1          => 'draft',
                $index === $count - 1                      => 'draft',
                default                                    => 'posted',
            };
            $paidAt = $date->copy()->addDays($random->getInt(9, 42));
            $created = $this->workMoment($date->copy()->setTime(10, 0)->addMinutes($random->getInt(0, 360)));

            $plans[] = [
                'partner'   => $partnerKey,
                'date'      => $date,
                'created'   => $created,
                'posted'    => $created->copy()->addMinutes($random->getInt(40, 200)),
                'state'     => $state,
                'paid_at'   => $state === 'posted' && $paidAt->lessThanOrEqualTo($yesterday) ? $this->workMoment($paidAt->setTime(11, 0)->addMinutes($random->getInt(0, 300))) : null,
                'pay_share' => $index % 5 === 2 ? $random->getInt(40, 60) / 100 : 1.0,
                'lines'     => $lines,
                'reference' => $isSale
                    ? 'PO-'.Str::upper(Str::substr(Str::slug($spec['name'], ''), 0, 4)).'-'.$random->getInt(1200, 8999)
                    : Str::upper(Str::substr(Str::slug($spec['name'], ''), 0, 3)).'/'.$date->format('y').'/'.str_pad((string) $random->getInt(100, 2999), 4, '0', STR_PAD_LEFT),
                'origin'    => $isSale ? ($this->profile['plates'] ?? null ? 'Trip sheet TS-'.$date->format('ymd').'-'.$random->getInt(10, 99) : 'Booking BK-'.$date->format('ymd').'-'.$random->getInt(10, 99)) : null,
            ];
        }

        return $plans;
    }

    /** @return array{product: string, quantity: float, price: float, name: string} */
    private function planLine(string $productKey, bool $isSale, Carbon $date, Randomizer $random): array
    {
        $spec = $this->profile['products'][$productKey];
        $base = (float) ($isSale ? $spec['price'] : $spec['cost']);
        $step = $base >= 100000 ? 1000 : ($base >= 1000 ? 100 : 1);
        $price = max($step, round($base * $random->getInt(95, 106) / 100 / $step) * $step);
        $quantity = match ($spec['uom']) {
            'L'     => $random->getInt(4, 24) * 500,
            'Days'  => $random->getInt(2, 9),
            'Hours' => $random->getInt(3, 12),
            default => $base >= 1000000 ? 1 : ($spec['type'] === 'goods' ? $random->getInt(2, 12) : $random->getInt(1, 4)),
        };

        $detail = strtr($spec['detail'], [
            '{plate}'     => $this->pick($random, $this->profile['plates'] ?? ['-']),
            '{bilty}'     => (string) $random->getInt(40100, 48999),
            '{container}' => ($this->profile['container_prefix'] ?? 'MSKU').' '.$random->getInt(100000, 999999).'-'.$random->getInt(0, 9),
            '{bl}'        => Str::upper(Str::substr($this->company->name, 0, 3)).'KHI'.$date->format('ym').$random->getInt(100, 999),
            '{vessel}'    => $this->pick($random, $this->profile['vessels'] ?? ['-']),
            '{voyage}'    => $date->format('y').str_pad((string) $random->getInt(1, 60), 2, '0', STR_PAD_LEFT).($random->getInt(0, 1) ? 'E' : 'W'),
            '{month}'     => $date->format('F Y'),
        ]);

        return ['product' => $productKey, 'quantity' => (float) $quantity, 'price' => (float) $price, 'name' => $spec['name']."\n".$detail];
    }

    /** @param  array<string, mixed>  $plan */
    private function createDocument(MoveType $type, array $plan): Move
    {
        $isSale = $type === MoveType::OUT_INVOICE;
        $journal = $this->setup[$isSale ? 'sale_journal' : 'purchase_journal'];
        $partner = $this->partners[$plan['partner']];
        $date = $plan['date']->toDateString();

        $move = Move::query()->create([
            'company_id'              => $this->company->id,
            'journal_id'              => $journal->id,
            'partner_id'              => $partner->id,
            'currency_id'             => $this->company->currency_id,
            'move_type'               => $type,
            'state'                   => MoveState::DRAFT,
            'date'                    => $date,
            'invoice_date'            => $date,
            'invoice_date_due'        => $plan['date']->copy()->addDays(30)->toDateString(),
            'invoice_payment_term_id' => $this->setup['term'],
            'invoice_user_id'         => $this->clerk?->id,
            'reference'               => $plan['reference'],
            'invoice_origin'          => $plan['origin'],
        ]);

        foreach ($plan['lines'] as $sort => $line) {
            $product = $this->products[$line['product']] ?? null;

            if (! $product) {
                continue;
            }

            $moveLine = MoveLine::query()->create([
                'move_id'      => $move->id,
                'sort'         => $sort + 1,
                'display_type' => DisplayType::PRODUCT,
                'product_id'   => $product->id,
                'uom_id'       => $isSale ? $product->uom_id : $product->uom_po_id,
                'name'         => $line['name'],
                'quantity'     => $line['quantity'],
                'price_unit'   => $line['price'],
                'discount'     => 0,
                'currency_id'  => $move->currency_id,
            ]);

            $taxIds = ($isSale ? $product->productTaxes() : $product->supplierTaxes())->pluck('accounts_taxes.id')->all();

            if ($taxIds !== []) {
                $moveLine->taxes()->sync($taxIds);
            }
        }

        return AccountFacade::computeAccountMove($move->refresh());
    }

    /** A payment registered against the posted document's open terms, as the Pay action does. */
    private function registerPayment(Move $move, float $share, Carbon $paidAt): void
    {
        $journal = $this->setup['bank_journal'];

        if (! $journal) {
            $this->warn("{$move->name}: no bank journal, left unpaid.");

            return;
        }

        $lineIds = $move->paymentTermLines->filter(fn ($line): bool => ! $line->reconciled)->pluck('id')->all();

        if ($lineIds === []) {
            return;
        }

        $register = new PaymentRegister;
        $register->lines = $move->lines;
        $register->company = $move->company;
        $register->currency = $move->currency;
        $register->currency_id = $move->currency_id;
        $register->payment_date = $paidAt->toDateString();
        $register->payment_type = $move->isInbound(true) ? PaymentType::RECEIVE : PaymentType::SEND;
        $register->computeBatches();
        $register->journal_id = $journal->id;
        $register->journal = $journal;
        $register->computePaymentMethodLineId();
        $full = (float) $register->getTotalAmountsToPay($register->batches)['amount_by_default'];
        $amount = $share >= 1 ? $full : round($full * $share / 1000) * 1000;

        $paymentRegister = PaymentRegister::query()->create([
            'currency_id'                 => $move->currency_id,
            'journal_id'                  => $journal->id,
            'payment_method_line_id'      => $register->payment_method_line_id,
            'company_id'                  => $move->company_id,
            'partner_id'                  => $move->partner_id,
            'payment_type'                => $register->payment_type,
            'payment_date'                => $paidAt->toDateString(),
            'amount'                      => $amount,
            'installments_mode'           => 'full',
            'payment_difference_handling' => 'open',
            'communication'               => $move->name,
        ]);

        $paymentRegister->lines()->sync($lineIds);
        $paymentRegister->refresh();
        $paymentRegister->computeFromLines();
        $paymentRegister->amount = $amount;
        $paymentRegister->save();

        AccountFacade::createPayments($paymentRegister);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array<string, int> this company's demo documents by type, state and payment state */
    public function documentTotals(): array
    {
        $ids = DemoRecord::query()->where('company_id', $this->company->id)->where('phase', DemoRecord::PHASE_BUSINESS)
            ->where('record_table', 'accounts_account_moves')->where('demo_key', 'like', 'business:%')->pluck('record_id');

        return Move::query()->toBase()->whereIn('id', $ids)
            ->selectRaw('move_type, state, payment_state, count(*) as total')
            ->groupBy('move_type', 'state', 'payment_state')
            ->get()
            ->mapWithKeys(fn (object $row): array => ["{$row->move_type} {$row->state}".($row->state === MoveState::POSTED->value ? " / {$row->payment_state}" : '') => (int) $row->total])
            ->sortKeys()
            ->all();
    }

    /** The bell notifications of demo users about these documents are marked read a couple of hours later. */
    private function settleNotifications(): void
    {
        $ids = DemoRecord::query()->where('company_id', $this->company->id)->where('phase', DemoRecord::PHASE_BUSINESS)
            ->where('record_table', 'notifications')->pluck('record_id');

        foreach ($ids->chunk(500) as $chunk) {
            DatabaseNotification::query()->whereIn('id', $chunk->all())->whereNull('read_at')
                ->update(['read_at' => DB::raw('DATE_ADD(created_at, INTERVAL 2 HOUR)')]);
        }
    }

    /**
     * Runs the callback at $moment (never later than just before now) as
     * $actor; the clock and the signed-in user are put back afterwards.
     */
    private function at(Carbon $moment, ?User $actor, callable $callback): mixed
    {
        $moment = $moment->copy()->utc()->min($this->realNow->copy()->subMinutes(2));
        $previousNow = Carbon::getTestNow();
        $previousImmutableNow = CarbonImmutable::getTestNow();
        $previousUser = Auth::guard('web')->user();

        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow(CarbonImmutable::instance($moment));

        if ($actor) {
            Auth::guard('web')->setUser($actor);
        }

        try {
            return $callback();
        } finally {
            Carbon::setTestNow($previousNow);
            CarbonImmutable::setTestNow($previousImmutableNow);
            $previousUser ? Auth::guard('web')->setUser($previousUser) : Auth::guard('web')->forgetUser();
        }
    }

    /** A Saturday or Sunday becomes the Friday before. */
    private function workDay(Carbon $day): Carbon
    {
        return match ($day->dayOfWeekIso) {
            6       => $day->subDay(),
            7       => $day->subDays(2),
            default => $day,
        };
    }

    /** The same moment on a working day, during office hours (Karachi). */
    private function workMoment(Carbon $moment): Carbon
    {
        $moment = $this->workDay($moment->copy()->setTimezone(self::TIMEZONE));

        return $moment->hour < 9 || $moment->hour >= 18 ? $moment->setTime(11, 30) : $moment;
    }

    /** @param  array<int, string>  $values */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, count($values) - 1)];
    }

    private function warn(string $message): void
    {
        $this->warnings[] = "{$this->company->name}: {$message}";
        $this->command?->warn("{$this->company->name}: {$message}");
    }
}
