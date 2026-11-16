<?php

namespace App\Services\Ops;

use App\Models\Ops\OpsAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for sensitive operations.
 */
class AuditLogger
{
    public static function log(int $company_id, string $action, ?Model $subject = null, array $changes = [], ?int $user_id = null, ?int $contact_id = null): void
    {
        $log = new OpsAuditLog();
        $log->company_id = $company_id;
        $log->user_id = $user_id ?? (auth()->guard('user')->check() ? auth()->guard('user')->id() : null);
        $log->client_contact_id = $contact_id;
        $log->action = $action;
        $log->subject_type = $subject ? class_basename($subject) : 'System';
        $log->subject_id = $subject?->getKey();
        $log->changes = $changes ?: null;
        $log->ip_address = app()->runningInConsole() ? null : request()->ip();
        $log->save();
    }
}
