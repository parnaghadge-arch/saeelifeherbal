<?php
/**
 * Database installer: creates the schema, indexes and the mandatory baseline records
 * (settings, roles, permissions, admin user, menus, legal pages, MLM/payout settings,
 * notification templates).
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Installer
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array $admin ['name','email','username','password']
     * @param bool  $fresh Drop existing tables first.
     */
    public function install(array $admin, bool $fresh = true): void
    {
        if ($fresh) {
            Schema::dropAll($this->db);
        }
        Schema::install($this->db);
        Schema::ensureTables($this->db); // idempotent: adds tables introduced after the first install
        if ($this->db->driver() === 'sqlite') {
            foreach (Schema::postCreateIndexes('sqlite') as $sql) {
                $this->db->statement($sql);
            }
        }
        $this->seedCore($admin);
    }

    public function seedCore(array $admin): void
    {
        $now = date('Y-m-d H:i:s');
        $this->seedSettings($now);
        $this->seedRolesAndPermissions($now);
        $this->seedAdminUser($admin, $now);
        $this->seedMlmAndPayoutSettings($now);
        $this->seedPages($now);
        $this->seedMenus($now);
        $this->seedNotificationTemplates($now);
        $this->seedSequences($now);
        $this->seedSocialLinks($now);
        $this->seedFaqs($now);
        $this->seedLegalContent($now);
        $this->seedCompanyStructure($now);
    }

    /**
     * The three company accounts at the top of the tree (SAEE00001 -> 00002 -> 00003).
     *
     * Seeded on every install so the sponsor fallback always resolves. They carry explicit
     * member codes and never consume the member_code sequence, so the first real partner is
     * still SLH000001.
     */
    private function seedCompanyStructure(string $now): void
    {
        (new CompanyStructure($this->db))->seed();
    }

    private function setting(string $key, string $value, string $type = 'string', string $group = 'general'): void
    {
        $this->db->insertIgnore('settings', [
            'setting_key' => $key,
            'setting_value' => $value,
            'setting_type' => $type,
            'setting_group' => $group,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function seedSettings(string $now): void
    {
        $defaults = [
            // general
            ['site_name', 'Saee Life Herbal', 'string', 'general'],
            ['site_tagline', 'Rooted in Nature. Growing Trust.', 'string', 'general'],
            ['site_description', 'Saee Life Herbal manufactures premium herbal agricultural inputs - pesticides, insecticides, fertilizers and crop protection solutions that help Indian farmers grow healthier, higher yielding crops.', 'text', 'general'],
            ['site_url', '', 'string', 'general'],
            ['contact_email', 'info@saeelifeherbal.com', 'string', 'general'],
            ['contact_phone', '+91 90000 00000', 'string', 'general'],
            ['contact_phone_alt', '', 'string', 'general'],
            ['whatsapp_number', '919000000000', 'string', 'general'],
            ['company_address', "Saee Life Herbal\nAgri Business Park\nNagpur, Maharashtra 440001\nIndia", 'text', 'general'],
            ['company_gst', '27AABCS0000A1Z5', 'string', 'general'],
            ['company_cin', '', 'string', 'general'],
            ['copyright_text', '© ' . date('Y') . ' Saee Life Herbal. All rights reserved.', 'string', 'general'],
            ['maintenance_mode', '0', 'bool', 'general'],
            ['maintenance_message', 'We are performing scheduled maintenance. Please check back shortly.', 'text', 'general'],
            // branding
            ['logo_header', 'assets/images/logo.png', 'string', 'branding'],
            ['logo_footer', 'assets/images/logo.png', 'string', 'branding'],
            ['logo_admin', 'assets/images/logo.png', 'string', 'branding'],
            ['logo_pwa', 'assets/images/logo-square.png', 'string', 'branding'],
            ['favicon', 'assets/images/logo-square.png', 'string', 'branding'],
            ['logo_text', 'Saee Life Herbal', 'string', 'branding'],
            ['logo_subtitle', 'Herbal Agri Solutions', 'string', 'branding'],
            ['theme_primary', '#247A3C', 'string', 'branding'],
            ['theme_dark', '#14532D', 'string', 'branding'],
            ['theme_secondary', '#4CAF50', 'string', 'branding'],
            ['theme_accent', '#A5C94A', 'string', 'branding'],
            // header / footer
            ['header_show_topbar', '1', 'bool', 'header'],
            ['header_sticky', '1', 'bool', 'header'],
            ['header_cta_text', 'Become a Distributor', 'string', 'header'],
            ['header_cta_url', 'mlm-business-opportunity', 'string', 'header'],
            ['footer_about', 'Saee Life Herbal is a farmer-first agricultural company delivering herbal crop protection and nutrition products, backed by a transparent, product-sale based partner program.', 'text', 'footer'],
            ['footer_show_newsletter', '1', 'bool', 'footer'],
            ['footer_note', 'Saee Life Herbal products are registered with the Central Insecticides Board & Registration Committee (CIB&RC) wherever applicable. Always read the label and leaflet before use.', 'text', 'footer'],
            // SEO / analytics
            ['meta_title_suffix', 'Saee Life Herbal', 'string', 'seo'],
            ['meta_description_default', 'Herbal pesticides, insecticides, fertilizers and crop protection products for Indian farmers. Join the Saee Life Herbal farmer network and grow with us.', 'text', 'seo'],
            ['meta_keywords_default', 'herbal pesticide, organic fertilizer, crop protection, insecticides, agriculture products India', 'text', 'seo'],
            ['og_default_image', 'assets/images/logo-square.png', 'string', 'seo'],
            ['google_analytics_id', '', 'string', 'seo'],
            ['google_site_verification', '', 'string', 'seo'],
            ['facebook_pixel_id', '', 'string', 'seo'],
            ['robots_txt', "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /member\nDisallow: /api\nDisallow: /install\n", 'text', 'seo'],
            // commerce
            ['store_enabled', '1', 'bool', 'commerce'],
            ['currency_symbol', "\u{20B9}", 'string', 'commerce'],
            ['ship_flat_rate', '60', 'string', 'commerce'],
            ['free_shipping_above', '1500', 'string', 'commerce'],
            ['gst_percent_default', '0', 'string', 'commerce'],
            ['invoice_prefix', 'SLH-INV-', 'string', 'commerce'],
            ['order_prefix', 'SLH-ORD-', 'string', 'commerce'],
            ['order_terms', 'By placing an order you agree to the Terms & Conditions and Refund Policy of Saee Life Herbal.', 'text', 'commerce'],
            // payments
            ['razorpay_enabled', '0', 'bool', 'payments'],
            ['razorpay_key_id', '', 'string', 'payments'],
            ['razorpay_key_secret', '', 'string', 'payments'],
            ['razorpay_webhook_secret', '', 'string', 'payments'],
            ['razorpay_mode', 'test', 'string', 'payments'],
            ['cod_enabled', '1', 'bool', 'payments'],
            ['upi_enabled', '0', 'bool', 'payments'],
            ['upi_id', '', 'string', 'payments'],
            ['upi_name', '', 'string', 'payments'],
            ['bank_transfer_enabled', '1', 'bool', 'payments'],
            ['bank_transfer_details', "Account Name: Saee Life Herbal\nBank: State Bank of India\nA/C No: 000000000000\nIFSC: SBIN0000000\nBranch: Nagpur Main", 'text', 'payments'],
            ['cod_max_amount', '20000', 'string', 'payments'],
            // notifications
            ['notify_email_enabled', '0', 'bool', 'notifications'],
            ['notify_sms_enabled', '0', 'bool', 'notifications'],
            ['notify_whatsapp_enabled', '0', 'bool', 'notifications'],
            ['notify_admin_email', 'info@saeelifeherbal.com', 'string', 'notifications'],
            // mlm compliance
            ['mlm_compliance_notice', 'Saee Life Herbal runs a product-purchase based unilevel partner program. Income depends entirely on genuine product sales to consumers. No commission is paid for recruitment alone, there is no joining fee and no income is guaranteed. Please read the Terms & Conditions and the Business Opportunity page before joining.', 'text', 'mlm'],
            ['mlm_legal_review_ack', '0', 'bool', 'mlm'],
            // social defaults handled in social_media table
            ['pwa_enabled', '1', 'bool', 'pwa'],
            ['pwa_short_name', 'Saee Life', 'string', 'pwa'],
            ['pwa_theme_color', '#247A3C', 'string', 'pwa'],
            ['pwa_background_color', '#F7FAF6', 'string', 'pwa'],
            ['pwa_version', '1', 'string', 'pwa'],
            ['recaptcha_enabled', '0', 'bool', 'security'],
            ['recaptcha_site_key', '', 'string', 'security'],
            ['recaptcha_secret_key', '', 'string', 'security'],
        ];
        foreach ($defaults as [$key, $value, $type, $group]) {
            $this->setting($key, $value, $type, $group);
        }
        // Keep the site name/url derived from environment when available.
        $configuredUrl = (string) Config::get('app.url', '');
        if ($configuredUrl !== '') {
            $this->db->update('settings', ['setting_value' => $configuredUrl], 'setting_key = :k', ['k' => 'site_url']);
        }
        unset($now);
    }

    private function seedRolesAndPermissions(string $now): void
    {
        $roles = [
            ['Super Administrator', 'super-admin', 'Unrestricted access to every module.', 1],
            ['Administrator', 'administrator', 'Full business operations access except system configuration.', 0],
            ['Finance Manager', 'finance-manager', 'Commissions, wallets, payouts, reports and reconciliation.', 0],
            ['KYC Verifier', 'kyc-verifier', 'Member KYC and bank verification only.', 0],
            ['Content Editor', 'content-editor', 'Website CMS, products, blogs, gallery and SEO.', 0],
            ['Support Executive', 'support-executive', 'Members, customers, orders and enquiries (read/respond).', 0],
        ];
        $roleIds = [];
        foreach ($roles as [$name, $slug, $desc, $isSuper]) {
            // Idempotent: re-running install (or install --no-fresh) must not collide with
            // the UNIQUE slug, so existing rows are reused instead of inserted again.
            $this->db->insertIgnore('admin_roles', [
                'name' => $name, 'slug' => $slug, 'description' => $desc, 'is_super' => $isSuper,
                'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $roleIds[$slug] = (int) $this->db->value('SELECT id FROM admin_roles WHERE slug = :s', ['s' => $slug]);
        }

        $permissions = [
            ['Dashboard', 'dashboard.view', 'dashboard'],
            ['View Members', 'members.view', 'members'],
            ['Manage Members', 'members.manage', 'members'],
            ['Export Members', 'members.export', 'members'],
            ['View KYC', 'kyc.view', 'members'],
            ['Verify KYC', 'kyc.verify', 'members'],
            ['View Bank Details', 'bank.view', 'members'],
            ['Verify Bank Details', 'bank.verify', 'members'],
            ['View MLM Tree', 'mlm.tree', 'mlm'],
            ['Process Commissions', 'mlm.process', 'mlm'],
            ['Reverse Commissions', 'mlm.reverse', 'mlm'],
            ['Manage Royalty Rules', 'royalty.manage', 'royalty'],
            ['Approve Royalty', 'royalty.approve', 'royalty'],
            ['View Wallets', 'wallets.view', 'wallets'],
            ['Adjust Wallets', 'wallets.adjust', 'wallets'],
            ['Request Payouts', 'payouts.request', 'payouts'],
            ['Approve Payouts', 'payouts.approve', 'payouts'],
            ['Mark Payouts Paid', 'payouts.pay', 'payouts'],
            ['View Payout Reports', 'payouts.reports', 'payouts'],
            ['Manage Products', 'products.manage', 'products'],
            ['Manage Categories', 'categories.manage', 'products'],
            ['View Orders', 'orders.view', 'ecommerce'],
            ['Update Orders', 'orders.update', 'ecommerce'],
            ['Refund Orders', 'orders.refund', 'ecommerce'],
            ['Manage CMS Pages', 'cms.pages', 'cms'],
            ['Manage Sliders', 'cms.sliders', 'cms'],
            ['Manage Blogs', 'cms.blogs', 'cms'],
            ['Manage Gallery', 'cms.gallery', 'cms'],
            ['Manage FAQs & Testimonials', 'cms.content', 'cms'],
            ['Manage Navigation', 'cms.navigation', 'cms'],
            ['Manage Media Library', 'cms.media', 'cms'],
            ['View Enquiries', 'enquiries.view', 'support'],
            ['Manage Enquiries', 'enquiries.manage', 'support'],
            ['View B2B Leads', 'b2b.view', 'support'],
            ['Manage B2B Leads', 'b2b.manage', 'support'],
            ['View Reports', 'reports.view', 'reports'],
            ['Manage Settings', 'settings.manage', 'settings'],
            ['Manage Payment Gateways', 'settings.payments', 'settings'],
            ['Manage Notification Templates', 'settings.notifications', 'settings'],
            ['Manage Admin Users', 'users.manage', 'users'],
            ['Manage Roles & Permissions', 'roles.manage', 'users'],
            ['View Audit Logs', 'audit.view', 'system'],
            ['Manage Backups', 'backups.manage', 'system'],
        ];
        $permIds = [];
        foreach ($permissions as [$name, $slug, $group]) {
            $this->db->insertIgnore('admin_permissions', [
                'name' => $name, 'slug' => $slug, 'group_name' => $group,
                'description' => null, 'created_at' => $now,
            ]);
            $permIds[$slug] = (int) $this->db->value('SELECT id FROM admin_permissions WHERE slug = :s', ['s' => $slug]);
        }

        // Super admin role implicitly has everything; still map for transparency.
        $map = [
            'super-admin' => array_keys($permIds),
            'administrator' => array_values(array_diff(array_keys($permIds), ['roles.manage', 'backups.manage'])),
            'finance-manager' => ['dashboard.view', 'members.view', 'mlm.tree', 'mlm.process', 'mlm.reverse', 'royalty.manage', 'royalty.approve', 'wallets.view', 'wallets.adjust', 'payouts.approve', 'payouts.pay', 'payouts.reports', 'reports.view', 'members.export'],
            'kyc-verifier' => ['dashboard.view', 'members.view', 'kyc.view', 'kyc.verify', 'bank.view', 'bank.verify'],
            'content-editor' => ['dashboard.view', 'products.manage', 'categories.manage', 'cms.pages', 'cms.sliders', 'cms.blogs', 'cms.gallery', 'cms.content', 'cms.navigation', 'cms.media', 'enquiries.view', 'b2b.view', 'settings.manage'],
            'support-executive' => ['dashboard.view', 'members.view', 'orders.view', 'orders.update', 'enquiries.view', 'enquiries.manage', 'b2b.view', 'b2b.manage', 'payouts.request'],
        ];
        foreach ($map as $roleSlug => $perms) {
            foreach ($perms as $permSlug) {
                $this->db->insertIgnore('admin_role_permissions', [
                    'role_id' => $roleIds[$roleSlug],
                    'permission_id' => $permIds[$permSlug],
                    'created_at' => $now,
                ]);
            }
        }
    }

    private function seedAdminUser(array $admin, string $now): void
    {
        $roleId = (int) $this->db->value('SELECT id FROM admin_roles WHERE slug = :s', ['s' => 'super-admin']);
        $payload = [
            'name' => $admin['name'] ?? 'Administrator',
            'email' => strtolower($admin['email'] ?? 'admin@saeelifeherbal.com'),
            'username' => $admin['username'] ?? 'admin',
            'password_hash' => Security::hashPassword($admin['password'] ?? 'Admin@12345'),
            'role_id' => $roleId,
            'status' => 'active',
            'must_change_password' => !empty($admin['must_change_password']) ? 1 : 0,
            'updated_at' => $now,
        ];

        // Re-running install (for example to reset the password) updates the existing
        // super admin instead of failing on the unique username/email.
        $existing = $this->db->first(
            'SELECT id FROM admin_users WHERE username = :u OR LOWER(email) = :e LIMIT 1',
            ['u' => $payload['username'], 'e' => $payload['email']]
        );
        if ($existing) {
            $this->db->update('admin_users', $payload, 'id = :id', ['id' => (int) $existing['id']]);
            return;
        }
        $payload['created_at'] = $now;
        $this->db->insert('admin_users', $payload);
    }

    private function seedMlmAndPayoutSettings(string $now): void
    {
        $this->db->insertIgnore('mlm_settings', [
            'id' => 1,
            'company_sponsor_code' => DEFAULT_COMPANY_SPONSOR_ID,
            'plan_enabled' => 0,
            'commission_hold_days' => 0,
            'direct_levels' => 1,
            'grand_direct_levels' => 1,
            'commission_on_paid_only' => 1,
            'commission_on_cod' => 0,
            'auto_credit_status' => 'available',
            'royalty_auto_process' => 1,
            'royalty_requires_approval' => 1,
            'member_id_prefix' => 'SLH',
            'member_id_padding' => 6,
            'registration_enabled' => 1,
            'max_direct_referrals' => 0,
            'compliance_notice' => 'Commissions are earned only on genuine product sales. No joining fee. No recruitment-only income.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insertIgnore('payout_settings', [
            'id' => 1,
            'min_withdrawal_amount' => 500,
            'max_withdrawal_amount' => 100000,
            'withdrawal_fee_type' => 'fixed',
            'withdrawal_fee_value' => 0,
            'tds_percent' => 0,
            'processing_days' => 3,
            'require_kyc' => 1,
            'require_verified_bank' => 1,
            'auto_approve' => 0,
            'auto_payout_enabled' => 1,
            'payouts_enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // Default royalty pyramid (fully editable by the admin).
        $slabs = [
            [1, 10, 500, 'Starter Grower'],
            [2, 25, 1500, 'Rising Partner'],
            [3, 50, 3000, 'Team Builder'],
            [4, 100, 7500, 'District Leader'],
            [5, 250, 15000, 'State Achiever'],
            [6, 500, 30000, 'Elite Mentor'],
        ];
        foreach ($slabs as $i => [$level, $members, $amount, $name]) {
            $this->db->insertIgnore('royalty_rules', [
                'name' => $name,
                'level_no' => $level,
                'required_direct_referrals' => 0,
                'required_downline' => $members,
                'required_active_members' => 0,
                'required_purchase_count' => 0,
                'min_qualifying_sales' => 0,
                'qualification_period_days' => 0,
                'reward_type' => 'fixed',
                'reward_amount' => $amount,
                'reward_percent' => 0,
                'percent_basis' => 'downline_sales',
                'frequency' => 'one_time',
                'recurring_months' => 1,
                'requires_admin_approval' => 1,
                'description' => "One-time royalty reward on achieving $members team members.",
                'status' => 1,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedPages(string $now): void
    {
        $pages = [
            ['Home', 'home', 'default', 1, 0, 1, 'Welcome to Saee Life Herbal'],
            ['About Us', 'about-us', 'default', 1, 1, 1, 'About Saee Life Herbal'],
            ['Our Products', 'our-products', 'default', 1, 1, 1, 'Our Products'],
            ['Agriculture Solutions', 'agriculture-solutions', 'default', 1, 1, 1, 'Agriculture Solutions'],
            ['Crop Protection', 'crop-protection', 'default', 1, 1, 0, 'Crop Protection'],
            ['Fertilizers', 'fertilizers', 'default', 1, 1, 0, 'Fertilizers'],
            ['Pesticides', 'pesticides', 'default', 1, 1, 0, 'Pesticides'],
            ['Insecticides', 'insecticides', 'default', 1, 1, 0, 'Insecticides'],
            ['Gallery', 'gallery', 'default', 1, 1, 1, 'Gallery'],
            ['Videos', 'videos', 'default', 1, 1, 1, 'Videos'],
            ['Blogs', 'blogs', 'default', 1, 1, 1, 'Agriculture Knowledge Hub'],
            ['Farmer Registration', 'farmer-registration', 'default', 1, 1, 0, 'Farmer Registration'],
            ['MLM Business Opportunity', 'mlm-business-opportunity', 'default', 1, 1, 1, 'Business Opportunity'],
            ['Contact Us', 'contact-us', 'default', 1, 1, 1, 'Contact Us'],
            ['FAQs', 'faqs', 'default', 1, 1, 1, 'Frequently Asked Questions'],
            ['Privacy Policy', 'privacy-policy', 'legal', 1, 0, 1, 'Privacy Policy'],
            ['Terms & Conditions', 'terms-conditions', 'legal', 1, 0, 1, 'Terms & Conditions'],
            ['Refund & Return Policy', 'refund-return-policy', 'legal', 1, 0, 1, 'Refund & Return Policy'],
            ['Shipping Policy', 'shipping-policy', 'legal', 1, 0, 1, 'Shipping Policy'],
            ['Disclaimer', 'disclaimer', 'legal', 1, 0, 1, 'Disclaimer'],
            ['Distributor Login', 'member/login', 'system', 1, 0, 0, 'Partner Login'],
        ];
        $order = 0;
        foreach ($pages as [$title, $slug, $template, $status, $nav, $footer, $metaTitle]) {
            $this->db->insertIgnore('website_pages', [
                'title' => $title,
                'slug' => $slug,
                'meta_title' => $metaTitle,
                'meta_description' => null,
                'content' => null,
                'template' => $template,
                'status' => $status ? 'published' : 'draft',
                'visibility' => 'public',
                'is_system' => $template === 'system' ? 1 : 0,
                'show_in_nav' => $nav,
                'show_in_footer' => $footer,
                'robots' => 'index,follow',
                'sort_order' => $order++,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedMenus(string $now): void
    {
        // Flat top-level header menu: no submenus at all. Products opens the full catalogue,
        // Solutions opens the agriculture solutions page, Gallery mixes photos and videos.
        $items = [
            ['Home', 'home', 1],
            ['About Us', 'about-us', 2],
            ['Products', 'our-products', 3],
            ['Solutions', 'agriculture-solutions', 4],
            ['Gallery', 'gallery', 5],
            ['Business Opportunity', 'mlm-business-opportunity', 6],
            ['Contact', 'contact-us', 7],
            ['B2B', 'b2b', 8],
        ];
        foreach ($items as [$label, $url, $order]) {
            $this->db->insert('navigation_menus', [
                'parent_id' => null, 'label' => $label, 'url' => $url, 'icon' => null,
                'target' => '_self', 'position' => 'header', 'sort_order' => $order,
                'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $footer = [
            ['About Us', 'about-us', 1], ['Our Products', 'our-products', 2], ['Blogs', 'blogs', 3],
            ['FAQs', 'faqs', 4], ['B2B', 'b2b', 5], ['Contact Us', 'contact-us', 6],
            ['Privacy Policy', 'privacy-policy', 7], ['Terms & Conditions', 'terms-conditions', 8],
            ['Refund & Return Policy', 'refund-return-policy', 9], ['Shipping Policy', 'shipping-policy', 10],
            ['Disclaimer', 'disclaimer', 11], ['Business Opportunity', 'mlm-business-opportunity', 12],
            ['Partner Login', 'member/login', 13],
        ];
        foreach ($footer as [$label, $url, $order]) {
            $this->db->insert('navigation_menus', [
                'parent_id' => null, 'label' => $label, 'url' => $url, 'icon' => null,
                'target' => '_self', 'position' => 'footer', 'sort_order' => $order,
                'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedNotificationTemplates(string $now): void
    {
        $templates = [
            ['registration_welcome', 'Welcome to Saee Life Herbal', 'Your partner account {{member_code}} is now active. Your sponsor is {{sponsor_name}}. Share your referral link to start building your team.', 'member_code,sponsor_name,referral_link'],
            ['sponsor_assigned', 'Sponsor Assigned', 'Your sponsor has been set to {{sponsor_name}} ({{sponsor_code}}).', 'sponsor_name,sponsor_code'],
            ['new_referral', 'New Team Member Joined', '{{member_name}} ({{member_code}}) joined your team.', 'member_name,member_code'],
            ['order_placed', 'Order Placed Successfully', 'Your order {{order_number}} for {{amount}} has been received.', 'order_number,amount'],
            ['order_status_updated', 'Order Status Updated', 'Your order {{order_number}} is now {{status}}.', 'order_number,status'],
            ['commission_credited', 'Commission Credited', '{{commission_type}} of {{amount}} has been credited to your wallet from order {{order_number}}.', 'commission_type,amount,order_number'],
            ['commission_reversed', 'Commission Reversed', '{{commission_type}} of {{amount}} from order {{order_number}} was reversed because the order was {{reason}}.', 'commission_type,amount,order_number,reason'],
            ['royalty_achieved', 'Royalty Milestone Achieved', 'Congratulations! You qualified for {{royalty_name}} and earned {{amount}}.', 'royalty_name,amount'],
            ['kyc_submitted', 'KYC Submitted', 'Your KYC documents were received and are pending verification.', ''],
            ['kyc_approved', 'KYC Approved', 'Your KYC has been approved. You can now request payouts.', ''],
            ['kyc_rejected', 'KYC Rejected', 'Your KYC was rejected. Reason: {{reason}}. Please resubmit.', 'reason'],
            ['bank_updated', 'Bank Details Updated', 'Your bank account details were updated and are pending verification.', ''],
            ['payout_requested', 'Payout Request Received', 'Your payout request {{request_code}} for {{amount}} has been received.', 'request_code,amount'],
            ['payout_approved', 'Payout Approved', 'Your payout request {{request_code}} has been approved.', 'request_code'],
            ['payout_in_progress', 'Payout In Progress', 'Your payout {{request_code}} of {{amount}} is now in progress. The accounts team has downloaded the payout report and payment is being processed to your bank account.', 'request_code,amount'],
            ['payout_rejected', 'Payout Rejected', 'Your payout request {{request_code}} was rejected. Reason: {{reason}}.', 'request_code,reason'],
            ['payout_completed', 'Payout Completed', 'Your payout {{request_code}} of {{amount}} was paid. UTR: {{utr}}.', 'request_code,amount,utr'],
            ['enquiry_received', 'We received your message', 'Thank you for contacting Saee Life Herbal. Our team will respond within one business day.', ''],
            ['b2b_lead_received', 'New B2B lead received', 'A new B2B enquiry from {{contact_name}} ({{business_type}}) is waiting in the B2B Leads pipeline.', 'contact_name,business_type'],
        ];
        foreach ($templates as [$code, $subject, $body, $vars]) {
            $this->db->insertIgnore('notification_templates', [
                'code' => $code, 'name' => $subject, 'channel' => 'in_system',
                'subject' => $subject, 'body' => $body, 'variables' => $vars, 'status' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedSequences(string $now): void
    {
        foreach (['member_code', 'invoice', 'order', 'commission', 'wallet', 'payout'] as $name) {
            $this->db->insertIgnore('sequences', [
                'name' => $name, 'value' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedSocialLinks(string $now): void
    {
        $links = [
            ['facebook', 'Facebook', 'https://facebook.com/', 'fab fa-facebook-f', 1],
            ['instagram', 'Instagram', 'https://instagram.com/', 'fab fa-instagram', 2],
            ['youtube', 'YouTube', 'https://youtube.com/', 'fab fa-youtube', 3],
            ['whatsapp', 'WhatsApp', 'https://wa.me/919000000000', 'fab fa-whatsapp', 4],
            ['linkedin', 'LinkedIn', 'https://linkedin.com/', 'fab fa-linkedin-in', 5],
        ];
        foreach ($links as [$platform, $name, $url, $icon, $order]) {
            $this->db->insertIgnore('social_media', [
                'platform' => $platform, 'display_name' => $name, 'url' => $url, 'icon' => $icon,
                'sort_order' => $order, 'open_new_tab' => 1, 'status' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedFaqs(string $now): void
    {
        $faqs = [
            ['General', 'What does Saee Life Herbal manufacture?', 'Saee Life Herbal manufactures herbal and bio-based agricultural inputs including pesticides, insecticides, fertilizers, plant growth promoters and crop protection products for Indian farmers.'],
            ['General', 'Are Saee Life Herbal products safe for crops?', 'Our products are formulated to be effective at recommended doses with minimal residue. Always follow the dosage and safety instructions printed on the label and leaflet.'],
            ['Products', 'How do I know which product suits my crop?', 'Use the product filters on the Our Products page, or contact our agronomy desk with details of your crop, soil and pest problem. Our field officers can recommend a suitable programme.'],
            ['Orders', 'How long does delivery take?', 'Metro deliveries typically arrive in 3-5 working days and rural pin codes within 5-8 working days from dispatch confirmation.'],
            ['MLM', 'Is there a joining fee to become a partner?', 'No. Registration as a Saee Life Herbal partner is completely free.'],
            ['MLM', 'How is commission earned?', 'Commission is earned only when a product is purchased by a customer or member through the platform and the order is paid and completed. Direct sponsor and grand direct sponsor commissions are configured product-wise by the company.'],
            ['MLM', 'Is income guaranteed?', 'No. Income depends entirely on genuine product sales and is not guaranteed. The partner program is not an investment or a deposit scheme.'],
            ['Payouts', 'When can I withdraw my earnings?', 'Withdrawals can be requested once your KYC is approved, your bank account is verified and your available wallet balance meets the minimum withdrawal limit.'],
        ];
        $i = 0;
        foreach ($faqs as [$category, $q, $a]) {
            $this->db->insertIgnore('faqs', [
                'category' => $category, 'question' => $q, 'answer' => $a,
                'sort_order' => $i++, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedLegalContent(string $now): void
    {
        $blocks = [
            'about-us' => '<h2>Rooted in nature, built for Indian farms</h2><p>Saee Life Herbal is an agricultural inputs company focused on herbal and bio-based crop protection and nutrition. We work directly with farmers, distributors and agri-entrepreneurs across India to deliver products that are effective, affordable and responsible.</p><p>Our portfolio covers pesticides, insecticides, fertilizers, plant growth promoters and complete crop protection programmes for cereals, pulses, oilseeds, cotton, sugarcane, horticulture and vegetables.</p><h3>What makes us different</h3><ul><li>Field-tested formulations with clear dosage guidance.</li><li>Agronomy support in local languages.</li><li>A transparent, product-sale based partner program with no joining fee.</li><li>Documented quality control for every batch.</li></ul>',
            'privacy-policy' => '<h2>Privacy Policy</h2><p>This policy explains how Saee Life Herbal collects, uses and protects personal data submitted through this website, the e-commerce portal and the partner portal.</p><h3>Information we collect</h3><ul><li>Contact details such as name, mobile number, email and address.</li><li>Order, payment and delivery information.</li><li>KYC and bank details submitted voluntarily by partners for payouts.</li><li>Technical data such as IP address, browser and device information.</li></ul><h3>How we use information</h3><p>To process orders, deliver products, operate the partner program, verify identity, comply with statutory requirements and improve our services. We do not sell personal data.</p><h3>Data security</h3><p>Passwords are stored using one-way hashing. Sensitive identity and banking fields are encrypted at rest and masked in interfaces according to user role. Access is restricted and audited.</p><h3>Your rights</h3><p>You may request access, correction or deletion of your data by writing to our grievance officer. Records required for statutory compliance may be retained for the mandated period.</p>',
            'terms-conditions' => '<h2>Terms &amp; Conditions</h2><p>By accessing this website or purchasing from Saee Life Herbal you agree to these terms.</p><h3>Products and information</h3><p>Product descriptions are provided for guidance. Agricultural outcomes depend on soil, weather, crop stage and application practice. Always read the label and leaflet before use.</p><h3>Partner program</h3><p>The Saee Life Herbal partner program is a product-purchase based unilevel program. Registration is free. No commission is payable for recruitment alone. Commission rates are set by the company, may be revised with notice, and are paid only on genuine, paid and completed product sales. Income is not guaranteed and must not be presented as assured.</p><h3>Accounts</h3><p>You are responsible for maintaining the confidentiality of your login credentials and for all activity under your account.</p><h3>Prohibited conduct</h3><p>Misrepresentation of earnings, pyramid style recruitment, spam, or use of our brand without written authorisation is prohibited and may result in suspension.</p><h3>Governing law</h3><p>These terms are governed by the laws of India. Disputes are subject to the jurisdiction of courts at Nagpur, Maharashtra.</p>',
            'refund-return-policy' => '<h2>Refund &amp; Return Policy</h2><h3>Eligibility</h3><p>Returns are accepted for damaged, leaked, expired or wrongly supplied products reported within 7 days of delivery with unboxing photographs and the original invoice.</p><h3>Non-returnable items</h3><p>Opened or partially used agrochemical packs cannot be returned for safety and regulatory reasons unless the product is defective.</p><h3>Refund processing</h3><p>Approved refunds are processed to the original payment method within 7-10 working days. For cash on delivery orders, refunds are made by bank transfer to the account provided by the customer.</p><h3>Commission impact</h3><p>If an order that generated partner commission is refunded or cancelled, the related commission entries are reversed automatically and the wallet balances are adjusted.</p>',
            'shipping-policy' => '<h2>Shipping Policy</h2><h3>Dispatch</h3><p>Orders are dispatched within 1-2 working days of payment confirmation. Consignments are shipped through registered logistics partners with tracking.</p><h3>Delivery timelines</h3><p>Metro cities: 3-5 working days. Other serviceable pin codes: 5-8 working days. Remote locations may take longer.</p><h3>Shipping charges</h3><p>A flat shipping fee applies to orders below the free shipping threshold; the applicable charge is displayed at checkout.</p><h3>Regulatory restrictions</h3><p>Certain agrochemical products can only be delivered to serviceable pin codes in accordance with state regulations. If a pin code is not serviceable, our team will contact you for an alternative.</p>',
            'disclaimer' => '<h2>Disclaimer</h2><p>The information on this website is provided for general agricultural guidance and does not constitute a guarantee of results, a recommendation for a specific crop programme, or financial advice.</p><p>Saee Life Herbal partner earnings depend entirely on genuine product sales to consumers. Earnings are not guaranteed. Nothing on this website should be read as a promise of income, returns or investment benefit. Participation in the partner program is entirely voluntary and free.</p><p>Product efficacy may vary based on climatic conditions, soil health, application method and pest pressure. Always consult a qualified agronomist and follow the label directions. Keep out of reach of children. Store in a cool, dry place away from food and feed.</p>',
            'mlm-business-opportunity' => '<h2>Grow with Saee Life Herbal</h2><p>Our partner program rewards partners for genuine product sales. Register free, share your referral link with farmers in your network, and earn eligible per-product commissions when your referred customers purchase.</p><h3>How the plan works</h3><ol><li><strong>Register free</strong> - no joining fee, no kit purchase requirement.</li><li><strong>Share</strong> - give your referral link or QR code to farmers and retailers.</li><li><strong>Earn</strong> - when a referred customer buys an eligible product and the order is paid and delivered, the product-wise direct sponsor and grand direct sponsor commissions are credited.</li><li><strong>Grow</strong> - build your team and unlock configurable royalty milestones based on team size and qualifying sales.</li></ol><h3>Important disclosures</h3><ul><li>Commission is generated only from paid, completed product orders.</li><li>No commission is paid for registering members.</li><li>Company may modify commission structures prospectively with notice.</li><li>Income is not guaranteed and depends on your own sales effort.</li></ul>',
            'farmer-registration' => '<h2>Register as a farmer partner</h2><p>Create your free partner account, get a unique member ID, and receive agronomy updates, product guidance and a referral link you can share with other farmers.</p>',
        ];
        foreach ($blocks as $slug => $content) {
            $this->db->update('website_pages', ['content' => $content, 'updated_at' => $now], 'slug = :slug', ['slug' => $slug]);
        }
    }
}
