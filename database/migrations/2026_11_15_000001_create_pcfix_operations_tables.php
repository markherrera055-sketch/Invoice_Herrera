<?php

/**
 * PCFix Hub - TO-BE operations module.
 *
 * Additive only: creates new tables and adds nullable / defaulted columns to
 * existing tables. No existing column is altered or dropped.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* ---------- Extensions to existing Invoice Ninja tables ---------- */

        if (! Schema::hasColumn('company_user', 'ops_role')) {
            Schema::table('company_user', function (Blueprint $table) {
                $table->string('ops_role', 40)->nullable();
            });
        }

        if (! Schema::hasColumn('products', 'track_stock')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('track_stock')->default(false);
                $table->string('category', 100)->nullable();
                $table->string('storage_location', 150)->nullable();
                $table->index(['company_id', 'track_stock']);
            });
        }

        /* ---------- Per-company document sequences ---------- */

        Schema::create('ops_sequences', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->string('key', 40);
            $table->unsignedInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'key']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });

        /* ---------- Technicians ---------- */

        Schema::create('technician_profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('user_id');
            $table->json('skills')->nullable();
            $table->string('branch', 100)->nullable();
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('max_active_jobs')->default(5);
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        /* ---------- Customer devices ---------- */

        Schema::create('devices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('client_id');
            $table->string('device_type', 60);
            $table->string('brand', 100)->nullable();
            $table->string('model', 150)->nullable();
            $table->string('serial_number', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'serial_number']);
            $table->index(['company_id', 'client_id']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });

        /* ---------- Module 1: Service requests ---------- */

        Schema::create('service_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('client_id')->nullable();
            $table->unsignedInteger('client_contact_id')->nullable();
            $table->unsignedInteger('device_id')->nullable();
            $table->string('request_number', 40);
            $table->string('channel', 20)->default('online');
            $table->string('status', 30)->default('new');
            $table->string('customer_name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('device_type', 60);
            $table->string('device_brand', 100)->nullable();
            $table->string('device_model', 150)->nullable();
            $table->string('serial_number', 150)->nullable();
            $table->text('reported_issue');
            $table->date('preferred_service_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('branch', 100)->nullable();
            $table->string('priority', 10)->default('normal');
            $table->boolean('possible_duplicate')->default(false);
            $table->json('duplicate_candidates')->nullable();
            $table->string('submission_hash', 64)->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'request_number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'submission_hash']);
            $table->index(['company_id', 'email']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
        });

        /* ---------- Module 2: Repair jobs ---------- */

        Schema::create('repair_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('service_request_id')->nullable();
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('device_id')->nullable();
            $table->unsignedInteger('assigned_user_id')->nullable();
            $table->string('job_number', 40);
            $table->string('priority', 10)->default('normal');
            $table->string('status', 30)->default('pending');
            $table->string('required_skill', 40)->nullable();
            $table->text('reported_issue')->nullable();
            $table->text('diagnosis')->nullable();
            $table->unsignedInteger('quote_id')->nullable();
            $table->unsignedInteger('invoice_id')->nullable();
            $table->unsignedInteger('warranty_claim_id')->nullable();
            $table->boolean('is_warranty_job')->default(false);
            $table->string('assignment_mode', 10)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('completion_verified_at')->nullable();
            $table->unsignedInteger('completion_verified_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'job_number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'assigned_user_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('service_request_id')->references('id')->on('service_requests')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('quote_id')->references('id')->on('quotes')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->unsignedInteger('repair_job_id')->nullable();
            $table->unsignedInteger('quote_id')->nullable();
            $table->foreign('repair_job_id')->references('id')->on('repair_jobs')->nullOnDelete();
            $table->foreign('quote_id')->references('id')->on('quotes')->nullOnDelete();
        });

        Schema::create('repair_job_quotes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('repair_job_id');
            $table->unsignedInteger('quote_id');
            $table->string('type', 20)->default('initial'); // initial | revision | warranty
            $table->timestamps();
            $table->unique(['repair_job_id', 'quote_id']);
            $table->index(['company_id', 'quote_id']);
            $table->foreign('repair_job_id')->references('id')->on('repair_jobs')->onDelete('cascade');
            $table->foreign('quote_id')->references('id')->on('quotes')->onDelete('cascade');
        });

        Schema::create('repair_job_updates', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('repair_job_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('type', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_customer_visible')->default(false);
            $table->timestamps();
            $table->index(['repair_job_id', 'created_at']);
            $table->foreign('repair_job_id')->references('id')->on('repair_jobs')->onDelete('cascade');
        });

        /* ---------- Module 5: Inventory movements ---------- */

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('type', 30); // stock_in | stock_out | adjustment | repair_usage | purchase_receipt | repair_return
            $table->integer('quantity'); // signed
            $table->integer('balance_after');
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->string('reference_type', 40)->nullable();
            $table->unsignedInteger('reference_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'product_id', 'created_at']);
            $table->index(['company_id', 'type', 'created_at']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::create('repair_job_parts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('repair_job_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('inventory_movement_id')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->boolean('is_warranty_covered')->default(false);
            $table->timestamps();
            $table->index(['company_id', 'product_id']);
            $table->foreign('repair_job_id')->references('id')->on('repair_jobs')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('inventory_movement_id')->references('id')->on('inventory_movements')->nullOnDelete();
        });

        /* ---------- Module 4: Warranty ---------- */

        Schema::create('warranties', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('device_id')->nullable();
            $table->unsignedInteger('repair_job_id')->nullable();
            $table->unsignedInteger('invoice_id')->nullable();
            $table->string('warranty_number', 40);
            $table->string('coverage_type', 20)->default('parts_and_labor'); // parts | labor | parts_and_labor
            $table->text('covered_items')->nullable();
            $table->text('exclusions')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('active'); // active | expiring_soon | expired | claimed | void
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'warranty_number']);
            $table->index(['company_id', 'status', 'end_date']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->foreign('repair_job_id')->references('id')->on('repair_jobs')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('warranty_id')->nullable();
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('client_contact_id')->nullable();
            $table->unsignedInteger('device_id')->nullable();
            $table->unsignedInteger('repair_job_id')->nullable();
            $table->unsignedInteger('quote_id')->nullable();
            $table->string('claim_number', 40);
            $table->string('serial_number', 150)->nullable();
            $table->text('issue_description');
            $table->string('status', 20)->default('submitted'); // submitted | approved | rejected | in_repair | completed
            $table->json('eligibility')->nullable();
            $table->unsignedInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->string('submitted_via', 20)->default('portal');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'claim_number']);
            $table->index(['company_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('warranty_id')->references('id')->on('warranties')->nullOnDelete();
            $table->foreign('repair_job_id')->references('id')->on('repair_jobs')->nullOnDelete();
            $table->foreign('quote_id')->references('id')->on('quotes')->nullOnDelete();
        });

        Schema::table('repair_jobs', function (Blueprint $table) {
            $table->foreign('warranty_claim_id')->references('id')->on('warranty_claims')->nullOnDelete();
        });

        /* ---------- Module 5C: Purchase requests ---------- */

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('vendor_id')->nullable();
            $table->string('request_number', 40);
            $table->string('type', 30)->default('spare_parts'); // spare_parts | software_license | equipment
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | ordered | received | cancelled
            $table->string('source', 20)->default('manual'); // manual | low_stock
            $table->text('justification')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable(); // purchase_orders.id is BIGINT
            $table->unsignedInteger('expense_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'request_number']);
            $table->index(['company_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('purchase_request_id');
            $table->unsignedInteger('product_id')->nullable();
            $table->string('description', 255);
            $table->integer('quantity');
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->integer('received_quantity')->default(0);
            $table->timestamps();
            $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        /* ---------- Notifications + audit trail ---------- */

        Schema::create('ops_notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->string('channel', 10); // database | mail | sms
            $table->string('recipient_type', 10); // user | contact
            $table->unsignedInteger('recipient_id');
            $table->string('type', 60);
            $table->string('title', 191);
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('dedupe_key', 191);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'channel', 'recipient_type', 'recipient_id', 'dedupe_key'], 'ops_notifications_dedupe');
            $table->index(['company_id', 'recipient_type', 'recipient_id', 'read_at'], 'ops_notifications_inbox');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });

        Schema::create('ops_audit_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('client_contact_id')->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 40);
            $table->unsignedInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('repair_jobs', function (Blueprint $table) {
            $table->dropForeign(['warranty_claim_id']);
        });
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropForeign(['repair_job_id']);
            $table->dropForeign(['quote_id']);
        });

        foreach ([
            'ops_audit_logs', 'ops_notifications', 'purchase_request_items', 'purchase_requests',
            'warranty_claims', 'warranties', 'repair_job_parts', 'inventory_movements',
            'repair_job_updates', 'repair_job_quotes', 'repair_jobs', 'service_requests',
            'devices', 'technician_profiles', 'ops_sequences',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        // Added columns on existing tables are intentionally left in place to
        // avoid destroying data entered through the new modules.
    }
};
