<?php
/**
 * Database schema definition.
 *
 * One neutral definition produces both the MySQL 8 production schema and an SQLite
 * schema used for the local preview / automated tests. Money columns are DECIMAL so
 * no floating point arithmetic is ever used for financial values.
 *
 * Column shorthand syntax:  "type[:args]|flag|flag:value"
 *   pk                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   fk:table[:cascade|restrict|setnull]  BIGINT UNSIGNED + foreign key
 *   string:191   text   longtext   int   bigint   tinyint
 *   money                   DECIMAL(14,2)
 *   decimal:10,4
 *   bool                    TINYINT(1) NOT NULL DEFAULT 0
 *   date   datetime   json   enum:a,b,c
 * Flags: notnull, null, unique, index, default:VALUE
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Schema
{
    /** Tables in creation order (parents before children). */
    public static function tables(): array
    {
        return [
            // ------------------------------------------------------------ core
            'settings' => [
                'columns' => [
                    'id' => 'pk',
                    'setting_key' => 'string:120|notnull|unique',
                    'setting_value' => 'longtext|null',
                    'setting_type' => "string:20|notnull|default:'string'",
                    'setting_group' => "string:60|notnull|default:'general'|index",
                    'updated_at' => 'datetime|null',
                ],
                'timestamps' => ['created_at'],
            ],
            'sequences' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:60|notnull|unique',
                    'value' => 'bigint|notnull|default:0',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'login_attempts' => [
                'columns' => [
                    'id' => 'pk',
                    'scope' => 'string:40|notnull',
                    'identifier' => 'string:191|notnull',
                    'attempts' => 'int|notnull|default:0',
                    'last_attempt_at' => 'datetime|null',
                    'locked_until' => 'datetime|null',
                ],
                'timestamps' => ['created_at'],
                'indexes' => [['scope', 'identifier']],
            ],
            'idempotency_keys' => [
                'columns' => [
                    'id' => 'pk',
                    'idempotency_key' => 'string:191|notnull|unique',
                    'scope' => 'string:60|notnull|index',
                    'reference_id' => 'bigint|null',
                    'created_at' => 'datetime|notnull',
                ],
            ],

            // ------------------------------------------------------------- CMS
            'website_pages' => [
                'columns' => [
                    'id' => 'pk',
                    'title' => 'string:191|notnull',
                    'slug' => 'string:191|notnull|unique',
                    'meta_title' => 'string:191|null',
                    'meta_description' => 'text|null',
                    'meta_keywords' => 'text|null',
                    'featured_image' => 'string:255|null',
                    'banner_image' => 'string:255|null',
                    'og_image' => 'string:255|null',
                    'canonical_url' => 'string:255|null',
                    'content' => 'longtext|null',
                    'template' => "string:40|notnull|default:'default'",
                    'status' => "enum:draft,published|notnull|default:'published'|index",
                    'visibility' => "enum:public,private,members|notnull|default:'public'",
                    'is_system' => 'bool',
                    'show_in_nav' => 'bool',
                    'show_in_footer' => 'bool',
                    'custom_css' => 'text|null',
                    'robots' => "string:60|notnull|default:'index,follow'",
                    'sort_order' => 'int|notnull|default:0|index',
                    'published_at' => 'datetime|null',
                    'author_id' => 'fk:admin_users:setnull|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'page_sections' => [
                'columns' => [
                    'id' => 'pk',
                    'page_id' => 'fk:website_pages:cascade|notnull|index',
                    'section_key' => 'string:80|notnull',
                    'title' => 'string:191|null',
                    'subtitle' => 'string:255|null',
                    'content' => 'longtext|null',
                    'image' => 'string:255|null',
                    'settings' => 'json|null',
                    'sort_order' => 'int|notnull|default:0',
                    'status' => "enum:active,inactive|notnull|default:'active'",
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'navigation_menus' => [
                'columns' => [
                    'id' => 'pk',
                    'parent_id' => 'fk:navigation_menus:cascade|null|index',
                    'label' => 'string:120|notnull',
                    'url' => 'string:255|notnull',
                    'icon' => 'string:80|null',
                    'target' => "string:10|notnull|default:'_self'",
                    'position' => "string:20|notnull|default:'header'|index",
                    'sort_order' => 'int|notnull|default:0',
                    'status' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'homepage_sliders' => [
                'columns' => [
                    'id' => 'pk',
                    'title' => 'string:191|null',
                    'subtitle' => 'string:255|null',
                    'description' => 'text|null',
                    'image_desktop' => 'string:255|null',
                    'image_mobile' => 'string:255|null',
                    'button_text' => 'string:80|null',
                    'button_url' => 'string:255|null',
                    'button2_text' => 'string:80|null',
                    'button2_url' => 'string:255|null',
                    'text_align' => "enum:left,center,right|notnull|default:'left'",
                    'show_heading' => 'bool|default:1',
                    'show_description' => 'bool|default:1',
                    'show_buttons' => 'bool|default:1',
                    'overlay_color' => "string:20|notnull|default:'#14532D'",
                    'overlay_opacity' => 'decimal:4,2|notnull|default:0.45',
                    'transition' => "enum:fade,slide,zoom,kenburns,parallax,soft|notnull|default:'fade'",
                    'text_animation' => "string:30|notnull|default:'fade-up'",
                    'image_animation' => "string:30|notnull|default:'none'",
                    'duration' => 'int|notnull|default:6',
                    'sort_order' => 'int|notnull|default:0|index',
                    'status' => 'bool|default:1|index',
                    'publish_from' => 'datetime|null',
                    'publish_to' => 'datetime|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'banners' => [
                'columns' => [
                    'id' => 'pk',
                    'title' => 'string:191|null',
                    'image' => 'string:255|null',
                    'link' => 'string:255|null',
                    'position' => "string:40|notnull|default:'home-middle'|index",
                    'sort_order' => 'int|notnull|default:0',
                    'status' => 'bool|default:1',
                    'starts_at' => 'datetime|null',
                    'ends_at' => 'datetime|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'social_media' => [
                'columns' => [
                    'id' => 'pk',
                    'platform' => 'string:60|notnull',
                    'display_name' => 'string:80|null',
                    'url' => 'string:255|notnull',
                    'icon' => 'string:80|null',
                    'icon_image' => 'string:255|null',
                    'sort_order' => 'int|notnull|default:0',
                    'open_new_tab' => 'bool|default:1',
                    'status' => 'bool|default:1|index',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'media_library' => [
                'columns' => [
                    'id' => 'pk',
                    'file_name' => 'string:191|notnull',
                    'file_path' => 'string:255|notnull',
                    'file_type' => "string:40|notnull|default:'image'|index",
                    'mime_type' => 'string:120|null',
                    'file_size' => 'bigint|notnull|default:0',
                    'width' => 'int|null',
                    'height' => 'int|null',
                    'alt_text' => 'string:191|null',
                    'folder' => "string:60|notnull|default:'media'",
                    'uploaded_by' => 'fk:admin_users:setnull|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'blog_categories' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:120|notnull',
                    'slug' => 'string:120|notnull|unique',
                    'description' => 'text|null',
                    'image' => 'string:255|null',
                    'sort_order' => 'int|notnull|default:0',
                    'status' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'blogs' => [
                'columns' => [
                    'id' => 'pk',
                    'category_id' => 'fk:blog_categories:setnull|null|index',
                    'title' => 'string:191|notnull',
                    'slug' => 'string:191|notnull|unique',
                    'excerpt' => 'text|null',
                    'content' => 'longtext|null',
                    'featured_image' => 'string:255|null',
                    'author_name' => 'string:120|null',
                    'tags' => 'string:255|null',
                    'meta_title' => 'string:191|null',
                    'meta_description' => 'text|null',
                    'views' => 'int|notnull|default:0',
                    'status' => "enum:draft,published|notnull|default:'published'|index",
                    'published_at' => 'datetime|null|index',
                    'author_id' => 'fk:admin_users:setnull|null',
                    'is_featured' => 'bool',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'gallery_items' => [
                'columns' => [
                    'id' => 'pk',
                    'title' => 'string:191|null',
                    'image' => 'string:255|notnull',
                    'category' => "string:60|notnull|default:'General'|index",
                    'description' => 'text|null',
                    'sort_order' => 'int|notnull|default:0',
                    'status' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'videos' => [
                'columns' => [
                    'id' => 'pk',
                    'title' => 'string:191|notnull',
                    'description' => 'text|null',
                    'source_type' => "enum:youtube,local,vimeo|notnull|default:'youtube'",
                    'video_url' => 'string:255|notnull',
                    'video_file' => 'string:255|null',
                    'thumbnail' => 'string:255|null',
                    'category' => "string:60|notnull|default:'General'",
                    'sort_order' => 'int|notnull|default:0',
                    'status' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'testimonials' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:120|notnull',
                    'designation' => 'string:120|null',
                    'location' => 'string:120|null',
                    'message' => 'text|notnull',
                    'photo' => 'string:255|null',
                    'rating' => 'tinyint|notnull|default:5',
                    'sort_order' => 'int|notnull|default:0',
                    'status' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'faqs' => [
                'columns' => [
                    'id' => 'pk',
                    'category' => "string:80|notnull|default:'General'|index",
                    'question' => 'string:255|notnull',
                    'answer' => 'text|notnull',
                    'sort_order' => 'int|notnull|default:0',
                    'status' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'contact_enquiries' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:120|notnull',
                    'email' => 'string:191|null',
                    'phone' => 'string:20|null',
                    'subject' => 'string:191|null',
                    'message' => 'text|notnull',
                    'source_page' => 'string:120|null',
                    'status' => "enum:new,read,replied,spam|notnull|default:'new'|index",
                    'admin_reply' => 'text|null',
                    'replied_at' => 'datetime|null',
                    'ip_address' => 'string:45|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'newsletter_subscribers' => [
                'columns' => [
                    'id' => 'pk',
                    'email' => 'string:191|notnull|unique',
                    'name' => 'string:120|null',
                    'status' => 'bool|default:1',
                    'token' => 'string:64|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],

            // ---------------------------------------------------- e-commerce
            'product_categories' => [
                'columns' => [
                    'id' => 'pk',
                    'parent_id' => 'fk:product_categories:cascade|null|index',
                    'name' => 'string:150|notnull',
                    'slug' => 'string:150|notnull|unique',
                    'short_description' => 'text|null',
                    'description' => 'longtext|null',
                    'image' => 'string:255|null',
                    'banner_image' => 'string:255|null',
                    'icon' => 'string:80|null',
                    'meta_title' => 'string:191|null',
                    'meta_description' => 'text|null',
                    'sort_order' => 'int|notnull|default:0|index',
                    'status' => 'bool|default:1|index',
                    'show_on_home' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'products' => [
                'columns' => [
                    'id' => 'pk',
                    'category_id' => 'fk:product_categories:setnull|null|index',
                    'name' => 'string:191|notnull',
                    'slug' => 'string:191|notnull|unique',
                    'sku' => 'string:80|null|index',
                    'short_description' => 'text|null',
                    'description' => 'longtext|null',
                    'technical_specifications' => 'longtext|null',
                    'benefits' => 'longtext|null',
                    'application_instructions' => 'longtext|null',
                    'dosage' => 'text|null',
                    'suitable_crops' => 'string:255|null',
                    'target_pests' => 'string:255|null',
                    'precautions' => 'text|null',
                    'packaging_sizes' => 'string:255|null',
                    'mrp' => 'money|notnull|default:0',
                    'price' => 'money|notnull|default:0',
                    'tax_percent' => 'decimal:5,2|notnull|default:0',
                    'stock_quantity' => 'int|notnull|default:0',
                    'min_order_qty' => 'int|notnull|default:1',
                    'unit' => "string:30|notnull|default:'unit'",
                    'featured_image' => 'string:255|null',
                    'is_featured' => 'bool|index',
                    'status' => "enum:active,inactive|notnull|default:'active'|index",
                    'meta_title' => 'string:191|null',
                    'meta_description' => 'text|null',
                    'meta_keywords' => 'string:255|null',
                    'views' => 'int|notnull|default:0',
                    'sort_order' => 'int|notnull|default:0',
                    // Product level MLM configuration (single source used by the engine,
                    // versioned copies are written to mlm_commission_rules on save).
                    'mlm_enabled' => 'bool',
                    'direct_commission_type' => "enum:fixed,percent|notnull|default:'fixed'",
                    'direct_commission_value' => 'money|notnull|default:0',
                    'grand_direct_commission_type' => "enum:fixed,percent|notnull|default:'fixed'",
                    'grand_direct_commission_value' => 'money|notnull|default:0',
                    'commission_basis' => "enum:selling_price,mrp,net_amount|notnull|default:'selling_price'",
                    'royalty_eligible' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'product_images' => [
                'columns' => [
                    'id' => 'pk',
                    'product_id' => 'fk:products:cascade|notnull|index',
                    'image_path' => 'string:255|notnull',
                    'alt_text' => 'string:191|null',
                    'sort_order' => 'int|notnull|default:0',
                    'is_primary' => 'bool',
                ],
                'timestamps' => ['created_at'],
            ],
            'product_videos' => [
                'columns' => [
                    'id' => 'pk',
                    'product_id' => 'fk:products:cascade|notnull|index',
                    'title' => 'string:191|null',
                    'source_type' => "enum:youtube,local,vimeo|notnull|default:'youtube'",
                    'video_url' => 'string:255|null',
                    'video_file' => 'string:255|null',
                    'thumbnail' => 'string:255|null',
                    'sort_order' => 'int|notnull|default:0',
                ],
                'timestamps' => ['created_at'],
            ],
            'product_variants' => [
                'columns' => [
                    'id' => 'pk',
                    'product_id' => 'fk:products:cascade|notnull|index',
                    'name' => 'string:120|notnull',
                    'sku' => 'string:80|null',
                    'pack_size' => 'string:60|null',
                    'mrp' => 'money|notnull|default:0',
                    'price' => 'money|notnull|default:0',
                    'stock_quantity' => 'int|notnull|default:0',
                    'is_default' => 'bool',
                    'status' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'customers' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:150|notnull',
                    'email' => 'string:191|null|unique',
                    'mobile' => 'string:15|notnull|unique',
                    'password_hash' => 'string:255|notnull',
                    'member_id' => 'fk:members:setnull|null|index',
                    'referred_by_member_id' => 'fk:members:setnull|null|index',
                    'status' => "enum:active,inactive|notnull|default:'active'|index",
                    'email_verified_at' => 'datetime|null',
                    'remember_token' => 'string:100|null',
                    'reset_token' => 'string:100|null',
                    'reset_expires_at' => 'datetime|null',
                    'last_login_at' => 'datetime|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'customer_addresses' => [
                'columns' => [
                    'id' => 'pk',
                    'customer_id' => 'fk:customers:cascade|notnull|index',
                    'address_type' => "enum:shipping,billing|notnull|default:'shipping'",
                    'full_name' => 'string:150|notnull',
                    'phone' => 'string:15|notnull',
                    'address_line1' => 'string:255|notnull',
                    'address_line2' => 'string:255|null',
                    'city' => 'string:100|notnull',
                    'district' => 'string:100|null',
                    'state' => 'string:100|notnull',
                    'pincode' => 'string:10|notnull',
                    'landmark' => 'string:150|null',
                    'is_default' => 'bool',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'carts' => [
                'columns' => [
                    'id' => 'pk',
                    'customer_id' => 'fk:customers:cascade|null|index',
                    'session_token' => 'string:64|null|index',
                    'status' => "enum:open,converted,abandoned|notnull|default:'open'|index",
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'cart_items' => [
                'columns' => [
                    'id' => 'pk',
                    'cart_id' => 'fk:carts:cascade|notnull|index',
                    'product_id' => 'fk:products:cascade|notnull|index',
                    'variant_id' => 'fk:product_variants:setnull|null',
                    'quantity' => 'int|notnull|default:1',
                    'unit_price' => 'money|notnull|default:0',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'wishlists' => [
                'columns' => [
                    'id' => 'pk',
                    'customer_id' => 'fk:customers:cascade|null|index',
                    'session_token' => 'string:64|null|index',
                    'product_id' => 'fk:products:cascade|notnull|index',
                ],
                'timestamps' => ['created_at'],
                'indexes' => [['customer_id', 'product_id']],
            ],
            'orders' => [
                'columns' => [
                    'id' => 'pk',
                    'order_number' => 'string:40|notnull|unique',
                    'customer_id' => 'fk:customers:setnull|null|index',
                    'member_id' => 'fk:members:setnull|null|index',
                    'referral_member_id' => 'fk:members:setnull|null|index',
                    'customer_name' => 'string:150|notnull',
                    'customer_email' => 'string:191|null',
                    'customer_mobile' => 'string:15|notnull',
                    'shipping_address' => 'json|notnull',
                    'billing_address' => 'json|null',
                    'subtotal' => 'money|notnull|default:0',
                    'discount_total' => 'money|notnull|default:0',
                    'shipping_total' => 'money|notnull|default:0',
                    'tax_total' => 'money|notnull|default:0',
                    'grand_total' => 'money|notnull|default:0',
                    'payment_method' => "string:30|notnull|default:'cod'|index",
                    'payment_status' => "enum:unpaid,pending,paid,failed,refunded|notnull|default:'unpaid'|index",
                    'status' => "enum:pending,payment_pending,paid,processing,shipped,delivered,cancelled,refunded|notnull|default:'pending'|index",
                    'notes' => 'text|null',
                    'admin_notes' => 'text|null',
                    'coupon_code' => 'string:40|null',
                    'tracking_number' => 'string:80|null',
                    'carrier' => 'string:80|null',
                    'invoice_number' => 'string:40|null|index',
                    'invoice_date' => 'date|null',
                    'commission_processed_at' => 'datetime|null',
                    'commission_status' => "enum:pending,credited,reversed,none|notnull|default:'none'|index",
                    'paid_at' => 'datetime|null',
                    'shipped_at' => 'datetime|null',
                    'delivered_at' => 'datetime|null',
                    'cancelled_at' => 'datetime|null',
                    'refunded_at' => 'datetime|null',
                    'cancel_reason' => 'text|null',
                    'ip_address' => 'string:45|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'order_items' => [
                'columns' => [
                    'id' => 'pk',
                    'order_id' => 'fk:orders:cascade|notnull|index',
                    'product_id' => 'fk:products:setnull|null|index',
                    'variant_id' => 'fk:product_variants:setnull|null',
                    'product_name' => 'string:191|notnull',
                    'product_sku' => 'string:80|null',
                    'pack_size' => 'string:60|null',
                    'unit_price' => 'money|notnull|default:0',
                    'quantity' => 'int|notnull|default:1',
                    'line_total' => 'money|notnull|default:0',
                    'tax_amount' => 'money|notnull|default:0',
                    'commissionable_amount' => 'money|notnull|default:0',
                ],
                'timestamps' => ['created_at'],
            ],
            'order_payments' => [
                'columns' => [
                    'id' => 'pk',
                    'order_id' => 'fk:orders:cascade|notnull|index',
                    'gateway' => "string:30|notnull|default:'cod'|index",
                    'gateway_order_id' => 'string:120|null|index',
                    'gateway_payment_id' => 'string:120|null|unique',
                    'gateway_signature' => 'string:255|null',
                    'amount' => 'money|notnull|default:0',
                    'status' => "enum:pending,success,failed,refunded|notnull|default:'pending'|index",
                    'idempotency_key' => 'string:191|null|unique',
                    'payload' => 'json|null',
                    'failure_reason' => 'text|null',
                    'paid_at' => 'datetime|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'order_status_history' => [
                'columns' => [
                    'id' => 'pk',
                    'order_id' => 'fk:orders:cascade|notnull|index',
                    'status' => 'string:30|notnull',
                    'note' => 'text|null',
                    'changed_by_type' => "string:20|notnull|default:'system'",
                    'changed_by_id' => 'bigint|null',
                ],
                'timestamps' => ['created_at'],
            ],
            'gateway_webhook_events' => [
                'columns' => [
                    'id' => 'pk',
                    'gateway' => 'string:30|notnull|index',
                    'event_id' => 'string:191|notnull|unique',
                    'event_type' => 'string:80|null',
                    'payload' => 'longtext|null',
                    'processed' => 'bool',
                    'processed_at' => 'datetime|null',
                    'error' => 'text|null',
                ],
                'timestamps' => ['created_at'],
            ],

            // ----------------------------------------------------------- MLM
            'members' => [
                'columns' => [
                    'id' => 'pk',
                    'member_code' => 'string:20|notnull|unique|index',
                    'sponsor_id' => 'fk:members:restrict|null|index',
                    'original_sponsor_id' => 'bigint|null',
                    'name' => 'string:150|notnull|index',
                    'email' => 'string:191|null|unique',
                    'mobile' => 'string:15|notnull|unique|index',
                    'password_hash' => 'string:255|notnull',
                    'role' => "enum:farmer,distributor,retailer|notnull|default:'farmer'|index",
                    'status' => "enum:pending,active,inactive,suspended|notnull|default:'active'|index",
                    'is_company' => 'bool',
                    'referral_code' => 'string:24|notnull|unique',
                    'kyc_status' => "enum:not_submitted,submitted,under_verification,approved,rejected,resubmission_required|notnull|default:'not_submitted'|index",
                    'is_payout_eligible' => 'bool',
                    'joined_at' => 'datetime|notnull|index',
                    'activated_at' => 'datetime|null',
                    'last_login_at' => 'datetime|null',
                    'remember_token' => 'string:100|null',
                    'reset_token' => 'string:100|null',
                    'reset_expires_at' => 'datetime|null',
                    'terms_accepted_at' => 'datetime|null',
                    'direct_referral_count' => 'int|notnull|default:0',
                    'downline_count' => 'int|notnull|default:0',
                    'active_downline_count' => 'int|notnull|default:0',
                    'total_purchase_amount' => 'money|notnull|default:0',
                    'total_earned' => 'money|notnull|default:0',
                    'admin_note' => 'text|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'member_profiles' => [
                'columns' => [
                    'id' => 'pk',
                    'member_id' => 'fk:members:cascade|notnull|unique',
                    'date_of_birth' => 'date|null',
                    'gender' => "enum:male,female,other|null",
                    'address' => 'string:255|null',
                    'state' => 'string:100|null|index',
                    'district' => 'string:100|null|index',
                    'taluka' => 'string:100|null',
                    'village' => 'string:100|null',
                    'pincode' => 'string:10|null',
                    'photo' => 'string:255|null',
                    'occupation' => 'string:100|null',
                    'land_area' => 'string:60|null',
                    'primary_crops' => 'string:255|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'member_sponsors' => [
                'columns' => [
                    'id' => 'pk',
                    'member_id' => 'fk:members:cascade|notnull|unique',
                    'sponsor_id' => 'fk:members:restrict|null|index',
                    'original_sponsor_id' => 'bigint|null',
                    'relationship' => "string:20|notnull|default:'direct'",
                    'assigned_at' => 'datetime|cascade',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'member_sponsor_history' => [
                'columns' => [
                    'id' => 'pk',
                    'member_id' => 'fk:members:cascade|notnull|index',
                    'old_sponsor_id' => 'bigint|null',
                    'new_sponsor_id' => 'bigint|null',
                    'changed_by_admin_id' => 'fk:admin_users:setnull|null',
                    'reason' => 'text|notnull',
                ],
                'timestamps' => ['created_at'],
            ],
            'member_referrals' => [
                'columns' => [
                    'id' => 'pk',
                    'sponsor_id' => 'fk:members:cascade|notnull|index',
                    'referred_member_id' => 'fk:members:cascade|notnull|unique',
                    'referral_code' => 'string:24|null',
                    'source' => "string:30|notnull|default:'link'",
                    'ip_address' => 'string:45|null',
                ],
                'timestamps' => ['created_at'],
            ],
            'member_referral_clicks' => [
                'columns' => [
                    'id' => 'pk',
                    'member_id' => 'fk:members:cascade|notnull|index',
                    'referral_code' => 'string:24|notnull|index',
                    'ip_address' => 'string:45|null',
                    'user_agent' => 'string:255|null',
                ],
                'timestamps' => ['created_at'],
            ],
            'member_kyc' => [
                'columns' => [
                    'id' => 'pk',
                    'member_id' => 'fk:members:cascade|notnull|unique',
                    'pan_number' => 'string:255|null',
                    'pan_name' => 'string:150|null',
                    'aadhaar_number' => 'string:255|null',
                    'date_of_birth' => 'date|null',
                    'address' => 'string:255|null',
                    'pan_document' => 'string:255|null',
                    'identity_document' => 'string:255|null',
                    'address_document' => 'string:255|null',
                    'photo' => 'string:255|null',
                    'status' => "enum:not_submitted,submitted,under_verification,approved,rejected,resubmission_required|notnull|default:'not_submitted'|index",
                    'rejection_reason' => 'text|null',
                    'admin_note' => 'text|null',
                    'submitted_at' => 'datetime|null',
                    'verified_at' => 'datetime|null',
                    'verified_by' => 'fk:admin_users:setnull|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'member_bank_accounts' => [
                'columns' => [
                    'id' => 'pk',
                    'member_id' => 'fk:members:cascade|notnull|index',
                    'account_holder_name' => 'string:150|notnull',
                    'bank_name' => 'string:150|notnull',
                    'account_number' => 'string:255|notnull',
                    'ifsc_code' => 'string:20|notnull',
                    'branch_name' => 'string:150|null',
                    'account_type' => "string:20|notnull|default:'savings'",
                    'cheque_image' => 'string:255|null',
                    'status' => "enum:pending,verified,rejected|notnull|default:'pending'|index",
                    'is_primary' => 'bool|default:1',
                    'verified_at' => 'datetime|null',
                    'verified_by' => 'fk:admin_users:setnull|null',
                    'rejection_reason' => 'text|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'mlm_commission_rules' => [
                'columns' => [
                    'id' => 'pk',
                    'product_id' => 'fk:products:cascade|notnull|index',
                    'direct_type' => "enum:fixed,percent|notnull|default:'fixed'",
                    'direct_value' => 'money|notnull|default:0',
                    'grand_direct_type' => "enum:fixed,percent|notnull|default:'fixed'",
                    'grand_direct_value' => 'money|notnull|default:0',
                    'commission_basis' => "enum:selling_price,mrp,net_amount|notnull|default:'selling_price'",
                    'royalty_eligible' => 'bool|default:1',
                    'is_active' => 'bool|default:1|index',
                    'effective_from' => 'datetime|notnull',
                    'effective_to' => 'datetime|null',
                    'created_by' => 'fk:admin_users:setnull|null',
                    'notes' => 'text|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'commission_transactions' => [
                'columns' => [
                    'id' => 'pk',
                    'transaction_code' => 'string:30|notnull|unique',
                    'member_id' => 'fk:members:cascade|notnull|index',
                    'beneficiary_type' => "enum:direct,grand_direct|notnull",
                    'commission_type' => "enum:direct,grand_direct,royalty,incentive|notnull|index",
                    'order_id' => 'fk:orders:setnull|null|index',
                    'order_item_id' => 'fk:order_items:setnull|null|index',
                    'product_id' => 'fk:products:setnull|null|index',
                    'product_name' => 'string:191|null',
                    'buyer_member_id' => 'fk:members:setnull|null|index',
                    'buyer_name' => 'string:150|null',
                    'level' => 'int|notnull|default:1',
                    'basis_amount' => 'money|notnull|default:0',
                    'commission_type_rate' => "enum:fixed,percent|notnull|default:'fixed'",
                    'rate_value' => 'decimal:14,4|notnull|default:0',
                    'amount' => 'money|notnull|default:0',
                    'status' => "enum:pending,approved,available,paid,rejected,reversed|notnull|default:'pending'|index",
                    'reversed_amount' => 'money|notnull|default:0',
                    'idempotency_key' => 'string:191|notnull|unique',
                    'payment_reference' => 'string:80|null',
                    'remittance_reference' => 'string:80|null',
                    'remarks' => 'text|null',
                    'available_at' => 'datetime|null',
                    'credited_at' => 'datetime|null',
                    'paid_at' => 'datetime|null',
                    'reversed_at' => 'datetime|null',
                    'reversal_reason' => 'text|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
                'indexes' => [['member_id', 'status'], ['order_id', 'commission_type']],
            ],
            'royalty_rules' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:150|notnull',
                    'level_no' => 'int|notnull|default:1|index',
                    'required_direct_referrals' => 'int|notnull|default:0',
                    'required_downline' => 'int|notnull|default:0',
                    'required_active_members' => 'int|notnull|default:0',
                    'required_purchase_count' => 'int|notnull|default:0',
                    'min_qualifying_sales' => 'money|notnull|default:0',
                    'qualification_period_days' => 'int|notnull|default:0',
                    'reward_type' => "enum:fixed,percent|notnull|default:'fixed'",
                    'reward_amount' => 'money|notnull|default:0',
                    'reward_percent' => 'decimal:5,2|notnull|default:0',
                    'percent_basis' => "enum:downline_sales,team_sales,payout_pool|notnull|default:'downline_sales'",
                    'frequency' => "enum:one_time,recurring|notnull|default:'one_time'",
                    'recurring_months' => 'int|notnull|default:1',
                    'requires_admin_approval' => 'bool|default:1',
                    'start_date' => 'date|null',
                    'end_date' => 'date|null',
                    'description' => 'text|null',
                    'status' => 'bool|default:1|index',
                    'sort_order' => 'int|notnull|default:0',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'royalty_achievements' => [
                'columns' => [
                    'id' => 'pk',
                    'member_id' => 'fk:members:cascade|notnull|index',
                    'royalty_rule_id' => 'fk:royalty_rules:cascade|notnull|index',
                    'cycle_key' => 'string:30|notnull|default:\'once\'',
                    'direct_referrals_snapshot' => 'int|notnull|default:0',
                    'downline_snapshot' => 'int|notnull|default:0',
                    'active_snapshot' => 'int|notnull|default:0',
                    'qualifying_sales_snapshot' => 'money|notnull|default:0',
                    'reward_amount' => 'money|notnull|default:0',
                    'status' => "enum:pending,approved,paid,rejected|notnull|default:'pending'|index",
                    'commission_transaction_id' => 'fk:commission_transactions:setnull|null',
                    'achieved_at' => 'datetime|notnull|index',
                    'approved_by' => 'fk:admin_users:setnull|null',
                    'approved_at' => 'datetime|null',
                    'remarks' => 'text|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
                'uniques' => [['member_id', 'royalty_rule_id', 'cycle_key']],
            ],
            'wallet_accounts' => [
                'columns' => [
                    'id' => 'pk',
                    'member_id' => 'fk:members:cascade|notnull|unique',
                    'direct_balance' => 'money|notnull|default:0',
                    'grand_direct_balance' => 'money|notnull|default:0',
                    'royalty_balance' => 'money|notnull|default:0',
                    'incentive_balance' => 'money|notnull|default:0',
                    'pending_balance' => 'money|notnull|default:0',
                    'approved_balance' => 'money|notnull|default:0',
                    'available_balance' => 'money|notnull|default:0',
                    'total_earned' => 'money|notnull|default:0',
                    'total_withdrawn' => 'money|notnull|default:0',
                    'total_reversed' => 'money|notnull|default:0',
                    'locked_balance' => 'money|notnull|default:0',
                    'last_activity_at' => 'datetime|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'wallet_transactions' => [
                'columns' => [
                    'id' => 'pk',
                    'transaction_code' => 'string:30|notnull|unique',
                    'member_id' => 'fk:members:cascade|notnull|index',
                    'wallet_type' => "enum:direct,grand_direct,royalty,incentive|notnull|index",
                    'direction' => "enum:credit,debit|notnull|index",
                    'amount' => 'money|notnull|default:0',
                    'balance_after' => 'money|notnull|default:0',
                    'reference_type' => "string:40|notnull|default:'manual'|index",
                    'reference_id' => 'bigint|null|index',
                    'status' => "enum:pending,available,paid,reversed|notnull|default:'available'|index",
                    'description' => 'string:255|null',
                    'created_by_admin_id' => 'fk:admin_users:setnull|null',
                ],
                'timestamps' => ['created_at'],
            ],
            'payout_requests' => [
                'columns' => [
                    'id' => 'pk',
                    'request_code' => 'string:30|notnull|unique',
                    'member_id' => 'fk:members:restrict|notnull|index',
                    'amount' => 'money|notnull|default:0',
                    'deduction_amount' => 'money|notnull|default:0',
                    'net_amount' => 'money|notnull|default:0',
                    'tds_amount' => 'money|notnull|default:0',
                    'admin_charges' => 'money|notnull|default:0',
                    'status' => "enum:pending,approved,rejected,paid,cancelled|notnull|default:'pending'|index",
                    'bank_account_id' => 'fk:member_bank_accounts:setnull|null',
                    'bank_snapshot' => 'json|null',
                    'kyc_snapshot' => 'json|null',
                    'member_note' => 'text|null',
                    'admin_note' => 'text|null',
                    'rejection_reason' => 'text|null',
                    'utr_number' => 'string:80|null',
                    'payment_proof' => 'string:255|null',
                    'payment_mode' => "string:30|notnull|default:'bank_transfer'",
                    'requested_at' => 'datetime|notnull|index',
                    'approved_by' => 'fk:admin_users:setnull|null',
                    'approved_at' => 'datetime|null',
                    'rejected_by' => 'fk:admin_users:setnull|null',
                    'rejected_at' => 'datetime|null',
                    'paid_by' => 'fk:admin_users:setnull|null',
                    'paid_at' => 'datetime|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'payout_transactions' => [
                'columns' => [
                    'id' => 'pk',
                    'payout_request_id' => 'fk:payout_requests:cascade|notnull|index',
                    'member_id' => 'fk:members:cascade|notnull|index',
                    'amount' => 'money|notnull|default:0',
                    'mode' => "string:30|notnull|default:'bank_transfer'",
                    'reference_number' => 'string:80|null|index',
                    'proof_file' => 'string:255|null',
                    'status' => "enum:pending,success,failed|notnull|default:'success'|index",
                    'remarks' => 'text|null',
                    'processed_by' => 'fk:admin_users:setnull|null',
                    'processed_at' => 'datetime|null',
                ],
                'timestamps' => ['created_at'],
            ],
            'payout_settings' => [
                'columns' => [
                    'id' => 'pk',
                    'min_withdrawal_amount' => 'money|notnull|default:500',
                    'max_withdrawal_amount' => 'money|notnull|default:100000',
                    'withdrawal_fee_type' => "enum:fixed,percent|notnull|default:'fixed'",
                    'withdrawal_fee_value' => 'money|notnull|default:0',
                    'tds_percent' => 'decimal:5,2|notnull|default:0',
                    'processing_days' => 'int|notnull|default:3',
                    'require_kyc' => 'bool|default:1',
                    'require_verified_bank' => 'bool|default:1',
                    'auto_approve' => 'bool|default:0',
                    'payouts_enabled' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'mlm_settings' => [
                'columns' => [
                    'id' => 'pk',
                    'company_sponsor_code' => 'string:20|notnull|default:\'SAEE00001\'',
                    'plan_enabled' => 'bool|default:0',
                    'commission_hold_days' => 'int|notnull|default:0',
                    'direct_levels' => 'int|notnull|default:1',
                    'grand_direct_levels' => 'int|notnull|default:1',
                    'commission_on_paid_only' => 'bool|default:1',
                    'commission_on_cod' => 'bool|default:0',
                    'auto_credit_status' => "enum:pending,available|notnull|default:'available'",
                    'royalty_auto_process' => 'bool|default:1',
                    'royalty_requires_approval' => 'bool|default:1',
                    'member_id_prefix' => 'string:10|notnull|default:\'SLH\'',
                    'member_id_padding' => 'int|notnull|default:5',
                    'registration_enabled' => 'bool|default:1',
                    'max_direct_referrals' => 'int|notnull|default:0',
                    'compliance_notice' => 'text|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],

            // -------------------------------------------------- administration
            'admin_roles' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:80|notnull',
                    'slug' => 'string:80|notnull|unique',
                    'description' => 'text|null',
                    'is_super' => 'bool',
                    'status' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'admin_permissions' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:120|notnull',
                    'slug' => 'string:120|notnull|unique',
                    'group_name' => "string:60|notnull|default:'general'|index",
                    'description' => 'text|null',
                ],
                'timestamps' => ['created_at'],
            ],
            'admin_role_permissions' => [
                'columns' => [
                    'id' => 'pk',
                    'role_id' => 'fk:admin_roles:cascade|notnull|index',
                    'permission_id' => 'fk:admin_permissions:cascade|notnull|index',
                ],
                'timestamps' => ['created_at'],
                'uniques' => [['role_id', 'permission_id']],
            ],
            'admin_users' => [
                'columns' => [
                    'id' => 'pk',
                    'name' => 'string:120|notnull',
                    'email' => 'string:191|notnull|unique',
                    'username' => 'string:60|notnull|unique',
                    'password_hash' => 'string:255|notnull',
                    'role_id' => 'fk:admin_roles:setnull|null|index',
                    'mobile' => 'string:15|null',
                    'avatar' => 'string:255|null',
                    'status' => "enum:active,inactive|notnull|default:'active'|index",
                    'must_change_password' => 'bool',
                    'last_login_at' => 'datetime|null',
                    'last_login_ip' => 'string:45|null',
                    'reset_token' => 'string:100|null',
                    'reset_expires_at' => 'datetime|null',
                    'remember_token' => 'string:100|null',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'admin_activity_logs' => [
                'columns' => [
                    'id' => 'pk',
                    'admin_id' => 'fk:admin_users:setnull|null|index',
                    'admin_name' => 'string:120|null',
                    'action' => 'string:80|notnull|index',
                    'module' => 'string:60|notnull|default:\'general\'|index',
                    'entity_type' => 'string:60|null',
                    'entity_id' => 'bigint|null',
                    'description' => 'text|null',
                    'changes' => 'json|null',
                    'ip_address' => 'string:45|null',
                    'user_agent' => 'string:255|null',
                ],
                'timestamps' => ['created_at'],
                'indexes' => [['entity_type', 'entity_id']],
            ],
            'notifications' => [
                'columns' => [
                    'id' => 'pk',
                    'recipient_type' => "enum:member,customer,admin|notnull|default:'member'|index",
                    'recipient_id' => 'bigint|notnull|index',
                    'template_code' => 'string:80|null',
                    'title' => 'string:191|notnull',
                    'message' => 'text|notnull',
                    'type' => "string:30|notnull|default:'info'",
                    'link' => 'string:255|null',
                    'icon' => 'string:60|null',
                    'channel' => "string:20|notnull|default:'in_system'",
                    'is_read' => 'bool|index',
                    'read_at' => 'datetime|null',
                    'sent_at' => 'datetime|null',
                ],
                'timestamps' => ['created_at'],
                'indexes' => [['recipient_type', 'recipient_id', 'is_read']],
            ],
            'notification_templates' => [
                'columns' => [
                    'id' => 'pk',
                    'code' => 'string:80|notnull|unique',
                    'name' => 'string:150|notnull',
                    'channel' => "string:20|notnull|default:'in_system'",
                    'subject' => 'string:191|null',
                    'body' => 'text|notnull',
                    'variables' => 'string:255|null',
                    'status' => 'bool|default:1',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'system_settings' => [
                'columns' => [
                    'id' => 'pk',
                    'setting_key' => 'string:120|notnull|unique',
                    'setting_value' => 'text|null',
                    'description' => 'string:255|null',
                    'is_locked' => 'bool',
                ],
                'timestamps' => ['created_at', 'updated_at'],
            ],
            'database_backups' => [
                'columns' => [
                    'id' => 'pk',
                    'filename' => 'string:191|notnull',
                    'file_size' => 'bigint|notnull|default:0',
                    'checksum' => 'string:64|null',
                    'status' => "string:20|notnull|default:'completed'",
                    'notes' => 'string:255|null',
                    'created_by' => 'fk:admin_users:setnull|null',
                ],
                'timestamps' => ['created_at'],
            ],
            'page_views' => [
                'columns' => [
                    'id' => 'pk',
                    'page_url' => 'string:255|notnull|index',
                    'visitor_hash' => 'string:64|null|index',
                    'ip_address' => 'string:45|null',
                    'user_agent' => 'string:255|null',
                    'referrer' => 'string:255|null',
                ],
                'timestamps' => ['created_at'],
                'indexes' => [['page_url', 'created_at']],
            ],
        ];
    }

    /** Tables that must be dropped first (children last created are dropped first). */
    public static function install(Database $db): void
    {
        // Tables are created in registry order and children frequently reference parents that
        // come later (e.g. website_pages -> admin_users). SQLite allows forward references,
        // MySQL does not: without this it fails with errno 1824 "Failed to open the
        // referenced table". Foreign key checks are restored immediately afterwards.
        $mysql = $db->driver() === 'mysql';
        if ($mysql) {
            $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        }
        try {
            foreach (self::tables() as $table => $definition) {
                $db->statement(self::createTableSql($db->driver(), $table, $definition));
            }
        } finally {
            if ($mysql) {
                $db->statement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }

    public static function dropAll(Database $db): void
    {
        $tables = array_reverse(array_keys(self::tables()));
        if ($db->driver() === 'sqlite') {
            $db->statement('PRAGMA foreign_keys = OFF');
            foreach ($tables as $table) {
                $db->statement('DROP TABLE IF EXISTS ' . $db->quoteIdentifier($table));
            }
            $db->statement('PRAGMA foreign_keys = ON');
            return;
        }
        $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $db->statement('DROP TABLE IF EXISTS ' . $db->quoteIdentifier($table));
        }
        $db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Full schema as a MySQL script (database/schema.sql). */
    public static function mysqlScript(): string
    {
        $out = "-- Saee Life Herbal - MySQL 8 schema\n-- Generated " . date('Y-m-d H:i:s') . "\n\n";
        $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
        foreach (self::tables() as $table => $definition) {
            $out .= "DROP TABLE IF EXISTS `$table`;\n";
            $out .= self::createTableSql('mysql', $table, $definition) . ";\n\n";
        }
        $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return $out;
    }

    public static function sqliteScript(): string
    {
        $out = "-- Saee Life Herbal - SQLite schema (preview/testing)\n\n";
        foreach (self::tables() as $table => $definition) {
            $out .= self::createTableSql('sqlite', $table, $definition) . ";\n\n";
        }
        return $out;
    }

    public static function createTableSql(string $driver, string $table, array $definition): string
    {
        $columns = [];
        $columnDefs = [];
        $constraints = [];
        $indexes = [];

        foreach ($definition['columns'] as $name => $spec) {
            [$sql, $flags, $fk] = self::columnSql($driver, $name, $spec);
            $columnDefs[] = $sql;
            if (in_array('unique', $flags, true)) {
                $indexes[] = ['unique' => true, 'name' => "uq_{$table}_{$name}", 'columns' => [$name]];
            }
            if (in_array('index', $flags, true)) {
                $indexes[] = ['unique' => false, 'name' => "idx_{$table}_{$name}", 'columns' => [$name]];
            }
            if ($fk !== null) {
                $constraints[] = sprintf(
                    'CONSTRAINT fk_%s_%s FOREIGN KEY (%s) REFERENCES %s(id) ON DELETE %s ON UPDATE CASCADE',
                    $table,
                    $name,
                    self::ident($driver, $name),
                    self::ident($driver, $fk['table']),
                    strtoupper($fk['on_delete'])
                );
            }
        }

        foreach ($definition['timestamps'] ?? [] as $ts) {
            $columnDefs[] = self::ident($driver, $ts) . ($driver === 'mysql' ? ' DATETIME NULL DEFAULT NULL' : ' DATETIME');
        }

        foreach ($definition['uniques'] ?? [] as $cols) {
            $colList = implode('_', $cols);
            $indexes[] = ['unique' => true, 'name' => "uq_{$table}_" . implode('_', $cols), 'columns' => $cols, 'inline' => true];
        }
        foreach ($definition['indexes'] ?? [] as $cols) {
            $indexes[] = [
                'unique' => false,
                'name' => "idx_{$table}_" . implode('_', $cols),
                'columns' => $cols,
            ];
        }

        // MySQL: composite unique keys can live inline, everything else as separate statements.
        $inlineIndexes = [];
        if ($driver === 'mysql') {
            foreach ($indexes as $idx) {
                if (!empty($idx['inline'])) {
                    $inlineIndexes[] = sprintf(
                        'UNIQUE KEY %s (%s)',
                        self::ident($driver, $idx['name']),
                        implode(', ', array_map(fn ($c) => self::ident($driver, $c), $idx['columns']))
                    );
                } elseif ($idx['unique']) {
                    $inlineIndexes[] = sprintf(
                        'UNIQUE KEY %s (%s)',
                        self::ident($driver, $idx['name']),
                        implode(', ', array_map(fn ($c) => self::ident($driver, $c), $idx['columns']))
                    );
                } else {
                    $inlineIndexes[] = sprintf(
                        'KEY %s (%s)',
                        self::ident($driver, $idx['name']),
                        implode(', ', array_map(fn ($c) => self::ident($driver, $c), $idx['columns']))
                    );
                }
            }
        }

        $parts = array_merge($columnDefs, $constraints, $inlineIndexes);
        if ($driver === 'mysql') {
            $sql = sprintf(
                "CREATE TABLE IF NOT EXISTS %s (\n  %s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                self::ident($driver, $table),
                implode(",\n  ", $parts)
            );
            return $sql;
        }

        $sql = sprintf(
            "CREATE TABLE IF NOT EXISTS %s (\n  %s\n)",
            self::ident($driver, $table),
            implode(",\n  ", $parts)
        );
        // SQLite cannot create indexes inline; emit them in the script only.
        return $sql;
    }

    /** Extra index statements needed after table creation (SQLite only). */
    public static function postCreateIndexes(string $driver): array
    {
        $statements = [];
        foreach (self::tables() as $table => $definition) {
            $indexes = [];
            foreach ($definition['columns'] as $name => $spec) {
                $flags = explode('|', $spec);
                if (in_array('unique', $flags, true)) {
                    $indexes[] = ['unique' => true, 'name' => "uq_{$table}_{$name}", 'columns' => [$name]];
                }
                if (in_array('index', $flags, true)) {
                    $indexes[] = ['unique' => false, 'name' => "idx_{$table}_{$name}", 'columns' => [$name]];
                }
            }
            foreach ($definition['uniques'] ?? [] as $cols) {
                $indexes[] = ['unique' => true, 'name' => "uq_{$table}_" . implode('_', $cols), 'columns' => $cols];
            }
            foreach ($definition['indexes'] ?? [] as $cols) {
                $indexes[] = ['unique' => false, 'name' => "idx_{$table}_" . implode('_', $cols), 'columns' => $cols];
            }
            foreach ($indexes as $idx) {
                $statements[] = sprintf(
                    'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
                    $idx['unique'] ? 'UNIQUE ' : '',
                    self::ident($driver, $idx['name']),
                    self::ident($driver, $table),
                    implode(', ', array_map(fn ($c) => self::ident($driver, $c), $idx['columns']))
                );
            }
        }
        return $statements;
    }

    private static function ident(string $driver, string $name): string
    {
        return $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
    }

    /**
     * @return array{0:string,1:array,2:?array} [sql, flags, foreignKey]
     */
    private static function columnSql(string $driver, string $name, string $spec): array
    {
        $tokens = explode('|', $spec);
        $type = array_shift($tokens);
        $flags = $tokens;
        $fk = null;
        $parts = [self::ident($driver, $name)];
        $default = null;
        $notNull = false;
        $unique = in_array('unique', $flags, true);

        foreach ($flags as $flag) {
            if ($flag === 'notnull' || $flag === 'unique') {
                $notNull = $notNull || $flag === 'notnull';
            } elseif (str_starts_with($flag, 'default:')) {
                $default = substr($flag, 8);
            }
        }

        $typeSql = '';
        switch (true) {
            case $type === 'pk':
                $parts[] = $driver === 'mysql' ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT' : 'INTEGER';
                if ($driver === 'mysql') {
                    $parts[] = 'PRIMARY KEY';
                } else {
                    $parts[] = 'PRIMARY KEY AUTOINCREMENT';
                }
                return [implode(' ', $parts), $flags, null];
            case str_starts_with($type, 'fk:'):
                $bits = explode(':', $type);
                $fkTable = $bits[1];
                $onDelete = strtoupper($bits[2] ?? 'cascade');
                if ($onDelete === 'SETNULL') {
                    $onDelete = 'SET NULL';
                }
                $fk = ['table' => $fkTable, 'on_delete' => $onDelete];
                $typeSql = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';
                break;
            case str_starts_with($type, 'string:'):
                $len = (int) substr($type, 7);
                $typeSql = $driver === 'mysql' ? "VARCHAR($len)" : 'TEXT';
                break;
            case $type === 'text':
                $typeSql = $driver === 'mysql' ? 'TEXT' : 'TEXT';
                break;
            case $type === 'longtext':
                $typeSql = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
                break;
            case $type === 'json':
                $typeSql = $driver === 'mysql' ? 'JSON' : 'TEXT';
                break;
            case $type === 'bool':
                $typeSql = $driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER';
                break;
            case $type === 'int':
                $typeSql = $driver === 'mysql' ? 'INT' : 'INTEGER';
                break;
            case $type === 'tinyint':
                $typeSql = $driver === 'mysql' ? 'TINYINT' : 'INTEGER';
                break;
            case $type === 'bigint':
                $typeSql = $driver === 'mysql' ? 'BIGINT' : 'INTEGER';
                break;
            case $type === 'money':
                $typeSql = $driver === 'mysql' ? 'DECIMAL(14,2)' : 'NUMERIC';
                break;
            case str_starts_with($type, 'decimal:'):
                $args = substr($type, 8);
                $typeSql = $driver === 'mysql' ? "DECIMAL($args)" : 'NUMERIC';
                break;
            case $type === 'date':
                $typeSql = $driver === 'mysql' ? 'DATE' : 'DATE';
                break;
            case $type === 'datetime':
                $typeSql = $driver === 'mysql' ? 'DATETIME' : 'DATETIME';
                break;
            case str_starts_with($type, 'enum:'):
                $values = explode(',', substr($type, 5));
                $quoted = implode(',', array_map(fn ($v) => "'" . str_replace("'", "''", $v) . "'", $values));
                $typeSql = $driver === 'mysql' ? "ENUM($quoted)" : 'TEXT';
                break;
            default:
                $typeSql = $driver === 'mysql' ? 'VARCHAR(191)' : 'TEXT';
        }

        $parts[] = $typeSql;

        if ($notNull && !str_contains(strtolower($spec), 'null|') && !str_ends_with($spec, '|null') && !str_contains($spec, '|null|')) {
            $parts[] = 'NOT NULL';
        } elseif ($notNull) {
            $parts[] = 'NOT NULL';
        } else {
            $parts[] = 'NULL';
        }

        if ($default !== null) {
            $parts[] = 'DEFAULT ' . $default;
        } elseif ($type === 'bool') {
            $parts[] = 'DEFAULT 0';
        }

        return [implode(' ', $parts), $flags, $fk];
    }
}
