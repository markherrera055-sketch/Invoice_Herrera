<?php

/**
 * PCFix Hub operations module configuration.
 *
 * Roles map to "ops abilities" that are enforced server-side through Laravel
 * Gates (see App\Providers\PCFixServiceProvider). Invoice Ninja owners and
 * admins implicitly hold every ability.
 */

return [

    'enabled' => env('PCFIX_ENABLED', true),

    /* Business defaults (editable per deployment through .env) */
    'defaults' => [
        'warranty_days' => (int) env('PCFIX_WARRANTY_DAYS', 90),
        'warranty_expiring_days' => (int) env('PCFIX_WARRANTY_EXPIRING_DAYS', 30),
        'labor_product_key' => env('PCFIX_LABOR_PRODUCT_KEY', 'LABOR'),
        'labor_rate' => (float) env('PCFIX_LABOR_RATE', 500),
        'diagnostic_fee' => (float) env('PCFIX_DIAGNOSTIC_FEE', 300),
        'duplicate_submission_minutes' => 10,
        'sms_reminder_interval_days' => (int) env('PCFIX_SMS_REMINDER_INTERVAL_DAYS', 7),
    ],

    /* Optional SMS provider (Twilio REST). When unset, SMS is skipped and logged. */
    'sms' => [
        'from' => env('PCFIX_SMS_FROM'),
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
    ],

    'branches' => ['Bongabong (HQ)', 'Calapan City', 'Batangas City', 'Remote'],

    'device_types' => ['Laptop', 'Desktop', 'Tablet', 'Mobile Phone', 'Printer', 'Monitor', 'Network Device', 'UPS', 'Other'],

    'skills' => [
        'hardware' => 'Hardware repair',
        'software' => 'Software / OS',
        'network' => 'Networking',
        'data_recovery' => 'Data recovery',
        'mobile' => 'Mobile devices',
        'printer' => 'Printers & peripherals',
    ],

    'priorities' => [
        'low' => 1,
        'normal' => 2,
        'high' => 3,
        'urgent' => 4,
    ],

    'repair_statuses' => [
        'pending' => 'Pending',
        'awaiting_approval' => 'Awaiting Approval',
        'approved' => 'Approved',
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'waiting_for_parts' => 'Waiting for Parts',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    /* Allowed manual transitions for repair jobs (system transitions bypass this). */
    'repair_transitions' => [
        'pending' => ['awaiting_approval', 'approved', 'cancelled'],
        'awaiting_approval' => ['approved', 'cancelled'],
        'approved' => ['assigned', 'cancelled'],
        'assigned' => ['in_progress', 'waiting_for_parts', 'cancelled'],
        'in_progress' => ['waiting_for_parts', 'awaiting_approval', 'completed', 'cancelled'],
        'waiting_for_parts' => ['in_progress', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ],

    /* ---------------- Role based access control ---------------- */

    'abilities' => [
        'dashboard.view' => 'View operations dashboard',
        'reports.financial' => 'View financial reports & analytics',
        'service_requests.view' => 'View service requests',
        'service_requests.manage' => 'Review, quote and convert service requests',
        'repair_jobs.view_all' => 'View all repair jobs',
        'repair_jobs.manage' => 'Assign technicians / override assignments',
        'repair_jobs.work' => 'Update progress, record parts, complete jobs',
        'technicians.manage' => 'Manage technician skills & availability',
        'billing.manage' => 'Verify completion and generate invoices',
        'billing.view' => 'View payment monitoring',
        'warranty.view' => 'View warranty records & claims',
        'warranty.manage' => 'Create / edit warranties and decide claims',
        'inventory.view' => 'View inventory',
        'inventory.manage' => 'Manage inventory items and stock adjustments',
        'suppliers.manage' => 'Manage supplier records',
        'purchase_requests.create' => 'Create purchase requests',
        'purchase_requests.approve' => 'Approve / reject purchase requests',
        'purchase_requests.receive' => 'Receive stock against approved requests',
        'expenses.manage' => 'Record and review expenses',
        'admin.users' => 'Assign roles and technician profiles',
        'audit.view' => 'View audit log',
    ],

    'roles' => [
        'administrator' => [
            'label' => 'Administrator',
            'abilities' => ['*'],
        ],
        'service_manager' => [
            'label' => 'Service Manager',
            'abilities' => [
                'dashboard.view', 'service_requests.view', 'service_requests.manage',
                'repair_jobs.view_all', 'repair_jobs.manage', 'repair_jobs.work', 'technicians.manage',
                'warranty.view', 'warranty.manage', 'inventory.view', 'purchase_requests.create', 'billing.view',
            ],
        ],
        'technician' => [
            'label' => 'Technician',
            'abilities' => [
                'dashboard.view', 'repair_jobs.work', 'warranty.view', 'inventory.view', 'purchase_requests.create',
            ],
        ],
        'accounting' => [
            'label' => 'Accounting Staff',
            'abilities' => [
                'dashboard.view', 'reports.financial', 'billing.manage', 'billing.view', 'repair_jobs.view_all',
                'service_requests.view', 'warranty.view', 'expenses.manage', 'suppliers.manage', 'inventory.view',
            ],
        ],
        'inventory' => [
            'label' => 'Inventory Staff',
            'abilities' => [
                'dashboard.view', 'inventory.view', 'inventory.manage', 'suppliers.manage',
                'purchase_requests.create', 'purchase_requests.receive',
            ],
        ],
        'management' => [
            'label' => 'Management',
            'abilities' => [
                'dashboard.view', 'reports.financial', 'billing.view', 'service_requests.view', 'repair_jobs.view_all',
                'warranty.view', 'inventory.view', 'purchase_requests.approve', 'purchase_requests.create', 'audit.view',
            ],
        ],
    ],

    /*
     * Invoice Ninja native permissions applied (merged) when a role is
     * assigned from the operations console, so the existing admin app
     * behaves consistently with the operations role.
     */
    'native_permissions' => [
        'service_manager' => ['view_client', 'create_client', 'edit_client', 'view_quote', 'create_quote', 'edit_quote', 'view_product'],
        'technician' => ['view_product'],
        'accounting' => ['view_client', 'edit_client', 'view_invoice', 'create_invoice', 'edit_invoice', 'view_payment', 'create_payment', 'edit_payment', 'view_quote', 'view_expense', 'create_expense', 'edit_expense', 'view_vendor', 'create_vendor', 'edit_vendor', 'view_reports'],
        'inventory' => ['view_product', 'create_product', 'edit_product', 'view_vendor', 'create_vendor', 'edit_vendor', 'view_purchase_order', 'create_purchase_order', 'edit_purchase_order'],
        'management' => ['view_all', 'view_reports', 'view_dashboard'],
    ],
];
