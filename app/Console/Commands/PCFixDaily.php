<?php

namespace App\Console\Commands;

use App\Libraries\MultiDB;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Ops\InventoryService;
use App\Services\Ops\OpsNotifier;
use App\Services\Ops\SmsGateway;
use App\Services\Ops\WarrantyService;
use Illuminate\Console\Command;

/**
 * Daily automation for the PCFix operations module:
 *  - refresh warranty statuses and send "expiring soon" notices
 *  - low-stock scan (notify inventory staff, raise purchase requests)
 *  - SMS reminders for overdue invoices (only if an SMS provider is configured;
 *    email reminders remain Invoice Ninja's native reminder engine)
 * All notifications are deduplicated.
 */
class PCFixDaily extends Command
{
    protected $signature = 'pcfix:daily';

    protected $description = 'PCFix Hub daily automation (warranty, low stock, SMS overdue reminders)';

    public function handle(WarrantyService $warranties, InventoryService $inventory): int
    {
        $dbs = config('ninja.db.multi_db_enabled') ? MultiDB::$dbs : [null];

        foreach ($dbs as $db) {
            if ($db) {
                MultiDB::setDB($db);
            }

            $w = $warranties->runDaily();
            $this->info("Warranty: {$w['status_changes']} status changes, {$w['expiry_notices']} expiry notices");

            $low = 0;

            Company::query()->where('is_disabled', false)->each(function (Company $company) use ($inventory, &$low) {
                $inventory->lowStockQuery($company->id)->each(function ($product) use ($inventory, &$low) {
                    $inventory->checkLowStock($product);
                    $low++;
                });

                $this->smsOverdue($company);
            });

            $this->info("Low stock items checked: {$low}");
        }

        return self::SUCCESS;
    }

    private function smsOverdue(Company $company): void
    {
        if (! SmsGateway::isConfigured()) {
            return;
        }

        $interval = max(1, config('pcfix.defaults.sms_reminder_interval_days'));
        $bucket = intdiv(now()->dayOfYear, $interval);

        Invoice::query()
            ->where('company_id', $company->id)
            ->where('is_deleted', false)
            ->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])
            ->where('balance', '>', 0)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->with('client.contacts')
            ->chunkById(100, function ($invoices) use ($company, $bucket) {
                foreach ($invoices as $invoice) {
                    $contact = $invoice->client?->contacts->first();

                    OpsNotifier::contact(
                        $company,
                        $contact,
                        'overdue_sms',
                        'inv' . $invoice->id . ':' . now()->year . ':' . $bucket,
                        "Invoice {$invoice->number} is overdue",
                        'Balance due: ' . number_format((float) $invoice->balance, 2) . '. Please settle at your earliest convenience.',
                        null,
                        sms: true,
                        email: false,
                    );
                }
            });
    }
}
