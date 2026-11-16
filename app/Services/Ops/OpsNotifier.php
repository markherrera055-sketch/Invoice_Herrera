<?php

namespace App\Services\Ops;

use App\Jobs\Mail\NinjaMailer;
use App\Jobs\Mail\NinjaMailerJob;
use App\Jobs\Mail\NinjaMailerObject;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\Ops\OpsNotification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Sends operations notifications through the existing Invoice Ninja mailer
 * (NinjaMailerJob, respecting each company's mail settings), an in-app inbox,
 * and optionally SMS.
 *
 * Every delivery is recorded in ops_notifications with a unique dedupe key per
 * channel + recipient, so the same event never notifies anyone twice.
 */
class OpsNotifier
{
    /**
     * Notify staff users holding an ability (in-app + email).
     */
    public static function staff(Company $company, string $ability, string $type, string $dedupe, string $title, string $body, ?string $url = null, bool $email = true): void
    {
        foreach (OpsAccess::usersWithAbility($company, $ability) as $user) {
            self::user($company, $user, $type, $dedupe, $title, $body, $url, $email);
        }
    }

    public static function user(Company $company, User $user, string $type, string $dedupe, string $title, string $body, ?string $url = null, bool $email = true): void
    {
        self::record($company, 'database', 'user', $user->id, $type, $dedupe, $title, $body, $url);

        if ($email && $user->email && self::record($company, 'mail', 'user', $user->id, $type, $dedupe, $title, $body, $url)) {
            self::mail($company, $user, $title, $body, $url);
        }
    }

    /**
     * Notify a customer contact by email (and SMS when configured & requested).
     */
    public static function contact(Company $company, ?ClientContact $contact, string $type, string $dedupe, string $title, string $body, ?string $url = null, bool $sms = false, bool $email = true): void
    {
        if (! $contact) {
            return;
        }

        if ($email && $contact->email && self::record($company, 'mail', 'contact', $contact->id, $type, $dedupe, $title, $body, $url)) {
            self::mail($company, $contact, $title, $body, $url);
        }

        if ($sms && $contact->phone && SmsGateway::isConfigured() && self::record($company, 'sms', 'contact', $contact->id, $type, $dedupe, $title, $body, $url)) {
            SmsGateway::send($contact->phone, $title . ': ' . $body);
        }
    }

    /**
     * Returns true when the record is new (i.e. not a duplicate).
     */
    private static function record(Company $company, string $channel, string $recipient_type, int $recipient_id, string $type, string $dedupe, string $title, string $body, ?string $url): bool
    {
        try {
            $n = new OpsNotification();
            $n->company_id = $company->id;
            $n->channel = $channel;
            $n->recipient_type = $recipient_type;
            $n->recipient_id = $recipient_id;
            $n->type = $type;
            $n->title = mb_substr($title, 0, 191);
            $n->body = $body;
            $n->url = $url ? mb_substr($url, 0, 500) : null;
            $n->dedupe_key = mb_substr($type . ':' . $dedupe, 0, 191);
            $n->save();

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) == 1062) {
                return false; // duplicate notification suppressed
            }

            throw $e;
        }
    }

    private static function mail(Company $company, User|ClientContact $recipient, string $title, string $body, ?string $url): void
    {
        try {
            $settings = $company->settings;

            $mail_obj = new \stdClass();
            $mail_obj->subject = $title;
            $mail_obj->markdown = 'email.admin.generic';
            $mail_obj->tag = $company->company_key;
            $mail_obj->text_view = 'email.template.text';
            // The generic template prints `content` unescaped, so escape user-provided text here.
            $safe = e($body);
            $mail_obj->data = [
                'title' => $title,
                'content' => $safe,
                'url' => $url,
                'button' => 'texts.view',
                'signature' => $settings->email_signature ?? '',
                'logo' => $company->present()->logo(),
                'settings' => $settings,
                'whitelabel' => $company->account->isPaid(),
                'text_body' => $body,
                'template' => $company->account->isPremium() ? 'email.template.admin_premium' : 'email.template.admin',
            ];

            $nmo = new NinjaMailerObject();
            $nmo->mailable = new NinjaMailer($mail_obj);
            $nmo->company = $company;
            $nmo->settings = $settings;
            $nmo->to_user = $recipient;

            NinjaMailerJob::dispatch($nmo);
        } catch (\Throwable $e) {
            // A failed email must never roll back a business transaction.
            Log::warning('PCFix notification email failed: ' . $e->getMessage());
        }
    }

    public static function unreadFor(int $company_id, int $user_id, int $limit = 10)
    {
        return OpsNotification::query()
            ->where('company_id', $company_id)
            ->where('channel', 'database')
            ->where('recipient_type', 'user')
            ->where('recipient_id', $user_id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
