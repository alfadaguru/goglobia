<?php
// FILE: app/lib/supplier_comms_templates.php
// Supplier-flow email TEMPLATES — seeds the four supplier lifecycle emails into the
// platform's notification_templates table (the same engine customer/agent/password/
// payment emails use), so an operator can edit them in admin instead of them being
// hardcoded in route files. Idempotent: inserts each template only if its name is
// absent, so existing installs + fresh imports converge. Placeholder syntax matches the
// shipped templates: {$users.<field>} and {$settings.<key>} (rendered by
// renderEmailTemplate / getTemplatePlaceholders). NOT runtime-verified (no MySQL here).

if (!function_exists('supplier_comms_templates')) {
    /** The four supplier lifecycle email templates (name => row fields). */
    function supplier_comms_templates(): array
    {
        // Shared parameter catalogue — the user fields + settings keys these bodies use.
        $params = json_encode([
            'users'    => ['id', 'user_id', 'first_name', 'last_name', 'email', 'phone', 'title', 'role', 'created_at', 'supplier_rejected_reason', 'reset_token'],
            'settings' => ['site_url', 'business_name', 'email_sender_name'],
        ]);

        return [
            'supplier_application_received' => [
                'group'   => 'supplier',
                'subject' => 'Confirm your email to complete your supplier application',
                'body'    =>
                    '<p>Hi {$users.first_name} {$users.last_name},</p>'
                    . '<p>Thanks for applying to become a supplier on {$settings.business_name}. '
                    . 'First, please <strong>confirm your email address</strong> by clicking below:</p>'
                    . '<div style="text-align:center;margin:28px 0;">'
                    . '<a href="{$settings.site_url}verify-email?token={$users.reset_token}" '
                    . 'style="background:#7c3aed;color:#fff;padding:12px 24px;text-decoration:none;'
                    . 'border-radius:6px;font-weight:bold;">Confirm email</a></div>'
                    . '<p>After confirming, your application goes to our team for <strong>approval</strong>. '
                    . 'You will be emailed once a decision is made, and you can sign in after approval.</p>'
                    . '<p>This confirmation link expires in 24 hours.</p>'
                    . '<p>— {$settings.business_name}</p>',
            ],
            'supplier_pending_admin' => [
                'group'   => 'supplier',
                'subject' => 'New supplier application pending approval',
                'body'    =>
                    '<p>A new supplier has registered and is awaiting approval:</p>'
                    . '<ul>'
                    . '<li><strong>Name:</strong> {$users.first_name} {$users.last_name}</li>'
                    . '<li><strong>Company:</strong> {$users.title}</li>'
                    . '<li><strong>Email:</strong> {$users.email}</li>'
                    . '<li><strong>Phone:</strong> {$users.phone}</li>'
                    . '</ul>'
                    . '<p><a href="{$settings.site_url}admin/suppliers">Review pending suppliers</a></p>',
            ],
            'supplier_approved' => [
                'group'   => 'supplier',
                'subject' => 'Your supplier account has been approved',
                'body'    =>
                    '<p>Hi {$users.first_name} {$users.last_name},</p>'
                    . '<p>Good news — your supplier account on {$settings.business_name} has been '
                    . '<strong>approved</strong>. You can now sign in and access your supplier dashboard '
                    . 'to configure your properties and services.</p>'
                    . '<div style="text-align:center;margin:28px 0;">'
                    . '<a href="{$settings.site_url}login" style="background:#7c3aed;color:#fff;padding:12px 24px;'
                    . 'text-decoration:none;border-radius:6px;font-weight:bold;">Sign in</a></div>'
                    . '<p>— {$settings.business_name}</p>',
            ],
            'supplier_rejected' => [
                'group'   => 'supplier',
                'subject' => 'Update on your supplier application',
                'body'    =>
                    '<p>Hi {$users.first_name} {$users.last_name},</p>'
                    . '<p>Thank you for your interest in becoming a supplier on {$settings.business_name}. '
                    . 'After review, we are unable to approve your application at this time.</p>'
                    . '<p><strong>Reason:</strong> {$users.supplier_rejected_reason}</p>'
                    . '<p>If you believe this was in error or would like to re-apply with more detail, '
                    . 'please contact our support team.</p>'
                    . '<p>— {$settings.business_name}</p>',
            ],
        ];
    }
}

if (!function_exists('ensureSupplierCommsTemplates')) {
    /**
     * Idempotently seed the supplier email templates. Insert-if-absent by name so it is
     * safe to run on every page load (via ensureSupplierStaysSchema). Never overwrites an
     * operator's edits to an existing template. Non-fatal.
     */
    function ensureSupplierCommsTemplates($db): void
    {
        try {
            foreach (supplier_comms_templates() as $name => $tpl) {
                $exists = $db->has('notification_templates', ['name' => $name, 'type' => 'email']);
                if ($exists) { continue; }
                $db->insert('notification_templates', [
                    'name'                 => $name,
                    'status'               => 1,
                    'type'                 => 'email',
                    'group'                => $tpl['group'],
                    'subject'              => $tpl['subject'],
                    'body'                 => $tpl['body'],
                    'available_parameters' => json_encode([
                        'users'    => ['id', 'user_id', 'first_name', 'last_name', 'email', 'phone', 'title', 'role', 'created_at', 'supplier_rejected_reason', 'reset_token'],
                        'settings' => ['site_url', 'business_name', 'email_sender_name'],
                    ]),
                    'created_at'           => date('Y-m-d H:i:s'),
                    'updated_at'           => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('ensureSupplierCommsTemplates: ' . $e->getMessage());
        }
    }
}
