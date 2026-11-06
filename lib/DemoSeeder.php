<?php
/**
 * Development / staging seed data with realistic Saee Life Herbal content.
 * Run with: php tools/console.php demo
 * Never run this on a production database with live data.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class DemoSeeder
{
    public function __construct(private Database $db)
    {
    }

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        MlmSettings::flush();
        $this->db->update('mlm_settings', ['plan_enabled' => 1, 'updated_at' => $now], 'id = 1');

        $companyId = $this->ensureCompanyMember($now);
        $this->seedCategories($now);
        $this->seedProducts($now);
        $this->seedSliders($now);
        $this->seedBlogs($now);
        $this->seedGalleryAndVideos($now);
        $this->seedTestimonials($now);
        $this->seedFounderTeam($now);
        $this->seedTeam($companyId, $now);
        $this->seedOrders($now);
        $this->seedActivity($now);
        (new TeamService($this->db))->recalculateAll();
    }

    private function ensureCompanyMember(string $now): int
    {
        $existing = $this->db->value('SELECT id FROM members WHERE is_company = 1');
        if ($existing) {
            return (int) $existing;
        }
        $wallet = new WalletService($this->db);
        $id = $this->db->insert('members', [
            'member_code' => MlmSettings::companySponsorCode(),
            'sponsor_id' => null,
            'name' => 'Saee Life Herbal (Company)',
            'email' => 'company@saeelifeherbal.com',
            'mobile' => '9000000000',
            'password_hash' => Security::hashPassword(Security::randomToken(16)),
            'role' => 'distributor',
            'status' => 'active',
            'is_company' => 1,
            'referral_code' => 'SAEECOMPANY',
            'kyc_status' => 'approved',
            'is_payout_eligible' => 0,
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('member_profiles', [
            'member_id' => $id, 'address' => 'Agri Business Park, Nagpur', 'state' => 'Maharashtra',
            'district' => 'Nagpur', 'pincode' => '440001', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $wallet->account($id);
        return $id;
    }

    private function seedCategories(string $now): array
    {
        $existing = $this->db->select('SELECT id, slug FROM product_categories');
        if ($existing !== []) {
            $ids = [];
            foreach ($existing as $row) {
                $ids[$row['slug']] = (int) $row['id'];
            }
            return $ids;
        }
        $categories = [
            ['Fertilizers', 'fertilizers', 'Complete plant nutrition range - granular, liquid and bio-fertilizers for every growth stage.', 1],
            ['Crop Protection', 'crop-protection', 'Preventive and curative solutions that protect yield from pest and disease pressure.', 2],
            ['Pesticides', 'pesticides', 'Effective, label-compliant crop protection chemistry for Indian farming conditions.', 3],
            ['Insecticides', 'insecticides', 'Targeted control of sucking and chewing pests with low residue profiles.', 4],
            ['Bio Stimulants', 'bio-stimulants', 'Plant growth promoters, seaweed extract and amino acid formulations.', 5],
        ];
        $ids = [];
        foreach ($categories as [$name, $slug, $desc, $order]) {
            $ids[$slug] = $this->db->insert('product_categories', [
                'name' => $name, 'slug' => $slug, 'short_description' => $desc,
                'description' => '<p>' . $desc . '</p>', 'icon' => 'fa-seedling',
                'image' => 'assets/images/demo/' . ['hero-2.jpg', 'hero-1.jpg', 'blog-1.jpg', 'product-3.jpg'][($order - 1) % 4],
                'meta_title' => $name . ' for Indian Farmers',
                'meta_description' => $desc,
                'sort_order' => $order, 'status' => 1, 'show_on_home' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        // subcategory example
        $this->db->insert('product_categories', [
            'parent_id' => $ids['insecticides'], 'name' => 'Neem Based', 'slug' => 'neem-based',
            'short_description' => 'Azadirachtin based botanical insecticides.', 'description' => '<p>Neem based botanical protection.</p>',
            'sort_order' => 1, 'status' => 1, 'show_on_home' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $ids;
    }

    private function seedProducts(string $now): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM products') > 0) {
            return;
        }
        $cats = [];
        foreach ($this->db->select('SELECT id, slug FROM product_categories') as $row) {
            $cats[$row['slug']] = (int) $row['id'];
        }
        $products = [
            ['Saee Grow NPK 19:19:19', 'saee-grow-npk-19-19-19', 'fertilizers', 1200, 940, 0, 6, 'Water soluble NPK for vegetative stage', 'SLH-NPK-1919', 80, 'fixed', 45, 'fixed', 18],
            ['Saee Root Power Bio Fertilizer', 'saee-root-power-bio-fertilizer', 'fertilizers', 900, 690, 0, 4, 'Mycorrhiza + Azotobacter root development booster', 'SLH-BIO-001', 50, 'fixed', 22, 'fixed', 10],
            ['Saee Herbal Neem Shield', 'saee-herbal-neem-shield', 'insecticides', 780, 620, 0, 5, 'Azadirachtin 1500 ppm botanical insecticide', 'SLH-NEEM-1500', 40, 'percent', 5, 'fixed', 15],
            ['Saee Sugarcane Guard', 'saee-sugarcane-guard', 'pesticides', 1650, 1390, 3, 2, 'Systemic control for sugarcane early shoot borer', 'SLH-PEST-220', 90, 'fixed', 35, 'percent', 2],
            ['Saee Cotton Boll Protector', 'saee-cotton-boll-protector', 'pesticides', 1450, 1180, 3, 1, 'Bollworm management for cotton', 'SLH-PEST-311', 75, 'fixed', 30, 'fixed', 12],
            ['Saee Bio Blast Fungicide', 'saee-bio-blast-fungicide', 'crop-protection', 1050, 840, 0, 8, 'Bio fungicide for blast and blight', 'SLH-FUNG-108', 55, 'percent', 6, 'fixed', 20],
            ['Saee Amino Boost', 'saee-amino-boost', 'bio-stimulants', 1350, 1080, 0, 3, 'Amino acid + seaweed growth promoter', 'SLH-BIO-210', 65, 'fixed', 28, 'fixed', 14],
            ['Saee Seaweed Magic', 'saee-seaweed-magic', 'bio-stimulants', 950, 760, 0, 10, 'Cold processed seaweed extract', 'SLH-BIO-310', 45, 'percent', 5, 'fixed', 9],
        ];
        $i = 0;
        foreach ($products as [$name, $slug, $cat, $mrp, $price, $tax, $stock, $short, $sku, $directFixed, $directType, $directValue, $grandType, $grandValue]) {
            $id = $this->db->insert('products', [
                'category_id' => $cats[$cat] ?? null,
                'name' => $name,
                'slug' => $slug,
                'sku' => $sku,
                'short_description' => $short,
                'description' => '<h2>' . $name . '</h2><p>' . $short . ' - a field proven Saee Life Herbal formulation designed for Indian agro-climatic conditions. Every batch is quality tested before dispatch.</p><h3>Key benefits</h3><ul><li>Improves nutrient use efficiency and crop vigour.</li><li>Compatible with common crop protection programmes.</li><li>Low residue profile when used as directed.</li></ul><h3>Application</h3><p>Apply as foliar spray during the recommended crop stage. Use the dose printed on the label leaflet.</p>',
                'technical_specifications' => '<table class="table table-bordered"><tbody><tr><td>Formulation</td><td>Water dispersible / soluble</td></tr><tr><td>Shelf life</td><td>24 months from manufacturing</td></tr><tr><td>Storage</td><td>Cool dry place, away from direct sunlight</td></tr></tbody></table>',
                'benefits' => 'Improved vigour, uniform growth and better yield quality.',
                'application_instructions' => 'Foliar spray at 20-30 day intervals or as per the crop schedule.',
                'dosage' => '1.5 - 2 ml per litre of water',
                'suitable_crops' => 'Cotton, Soybean, Sugarcane, Paddy, Vegetables',
                'target_pests' => $cat === 'insecticides' || $cat === 'pesticides' ? 'Sucking pests, bollworm, borer complex' : '-',
                'precautions' => 'Wear protective clothing during application. Do not spray against the wind. Keep away from children.',
                'packaging_sizes' => '250 ml, 500 ml, 1 L',
                'mrp' => $mrp,
                'price' => $price,
                'tax_percent' => $tax,
                'stock_quantity' => $stock * 25,
                'featured_image' => 'assets/images/demo/product-' . (($i % 4) + 1) . '.jpg',
                'is_featured' => $i < 4 ? 1 : 0,
                'status' => 'active',
                'meta_title' => $name . ' | Saee Life Herbal',
                'meta_description' => $short,
                'mlm_enabled' => 1,
                'direct_commission_type' => $directType,
                'direct_commission_value' => $directValue,
                'grand_direct_commission_type' => $grandType,
                'grand_direct_commission_value' => $grandValue,
                'commission_basis' => 'selling_price',
                'royalty_eligible' => 1,
                'sort_order' => $i++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $product = $this->db->first('SELECT * FROM products WHERE id = :id', ['id' => $id]);
            $this->db->insert('product_images', [
                'product_id' => $id,
                'image_path' => 'assets/images/demo/product-' . (($i % 4) + 1) . '.jpg',
                'alt_text' => $name,
                'sort_order' => 0,
                'is_primary' => 1,
                'created_at' => $now,
            ]);
            (new MlmCommissionEngine($this->db))->versionRuleForProduct($product);
        }
    }

    private function seedSliders(string $now): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM homepage_sliders') > 0) {
            return;
        }
        $slides = [
            ['Herbal Protection. Healthier Harvests.', 'Trusted crop care for every Indian farm', 'Saee Life Herbal brings you scientifically formulated, herbal-first crop protection and nutrition products that protect yield and soil health.', '#247A3C', 0.5, 'fade', 'fade-up'],
            ['Grow More With Every Acre', 'Fertilizers that work with nature', 'Balanced nutrition, bio-fertilizers and growth promoters engineered for Indian soils and cropping patterns.', '#14532D', 0.55, 'kenburns', 'slide-left'],
            ['Partner With Saee Life Herbal', 'A transparent, product-sale based program', 'Share genuine product value with farmers around you and earn eligible product-wise commissions. No joining fee. No recruitment-only income.', '#1B5E20', 0.6, 'zoom', 'fade-up'],
        ];
        foreach ($slides as $i => [$title, $subtitle, $desc, $color, $opacity, $transition, $textAnim]) {
            $this->db->insert('homepage_sliders', [
                'title' => $title, 'subtitle' => $subtitle, 'description' => $desc,
                'image_desktop' => 'assets/images/demo/hero-' . ($i + 1) . '.jpg',
                'image_mobile' => 'assets/images/demo/hero-' . ($i + 1) . '.jpg',
                'button_text' => 'Explore Products', 'button_url' => 'our-products',
                'button2_text' => 'Join as Partner', 'button2_url' => 'member/register',
                'text_align' => 'left', 'show_heading' => 1, 'show_description' => 1, 'show_buttons' => 1,
                'overlay_color' => $color, 'overlay_opacity' => $opacity,
                'transition' => $transition, 'text_animation' => $textAnim, 'image_animation' => $i === 1 ? 'kenburns' : 'none',
                'duration' => 7, 'sort_order' => $i, 'status' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedBlogs(string $now): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM blogs') > 0) {
            return;
        }
        $catIds = [];
        foreach ([['Crop Advisory', 'crop-advisory'], ['Organic Farming', 'organic-farming'], ['Business Growth', 'business-growth']] as $i => [$name, $slug]) {
            $catIds[$slug] = $this->db->insert('blog_categories', [
                'name' => $name, 'slug' => $slug, 'description' => $name . ' articles for farmers.',
                'sort_order' => $i, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $blogs = [
            ['How to control sucking pests in cotton organically', 'how-to-control-sucking-pests-in-cotton-organically', 'crop-advisory', 'Sucking pests like jassids, thrips and whitefly can reduce cotton yield by up to 30%. Here is a practical integrated plan.', 'Integrated pest management'],
            ['Choosing the right NPK grade for your growth stage', 'choosing-the-right-npk-grade-for-your-growth-stage', 'crop-advisory', 'Vegetative, flowering and fruiting stages need different nutrient ratios. Learn how to select the right grade.', 'Soil nutrition'],
            ['5 soil health practices every farmer should follow', '5-soil-health-practices-every-farmer-should-follow', 'organic-farming', 'Healthy soil is the base of every profitable harvest. These five low cost practices improve organic carbon and yields.', 'Soil health'],
            ['Building trust to grow your agricultural distribution network', 'building-trust-to-grow-your-agricultural-distribution-network', 'business-growth', 'Practical advice on serving farmers well, keeping records and presenting product value honestly.', 'Distribution'],
        ];
        foreach ($blogs as $i => [$title, $slug, $cat, $excerpt, $tag]) {
            $this->db->insert('blogs', [
                'category_id' => $catIds[$cat],
                'title' => $title, 'slug' => $slug, 'excerpt' => $excerpt,
                'content' => '<p>' . $excerpt . '</p><h3>Why it matters</h3><p>Field observations across Maharashtra and Central India show that timely intervention at the right crop stage delivers the biggest return on input cost.</p><h3>Recommended practice</h3><ol><li>Scout the field twice a week during the vulnerable stage.</li><li>Use economic threshold levels before spraying.</li><li>Rotate modes of action to avoid resistance.</li><li>Keep spray records for traceability.</li></ol><p>For personalised recommendations, contact our agronomy desk with crop age, pest population and last spray details.</p>',
                'featured_image' => 'assets/images/demo/blog-' . (($i % 3) + 1) . '.jpg',
                'author_name' => 'Saee Agronomy Desk', 'tags' => $tag,
                'meta_title' => $title,
                'meta_description' => $excerpt,
                'status' => 'published', 'is_featured' => $i === 0 ? 1 : 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-' . ($i * 6 + 2) . ' days')),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedGalleryAndVideos(string $now): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM gallery_items') > 0) {
            return;
        }
        foreach (['Field Demonstrations', 'Farmer Meets', 'Training Programmes'] as $i => $cat) {
            for ($j = 0; $j < 2; $j++) {
                $this->db->insert('gallery_items', [
                    'title' => $cat . ' ' . ($j + 1),
                    'image' => 'assets/images/demo/' . ['hero-1.jpg', 'hero-3.jpg', 'blog-2.jpg', 'blog-3.jpg', 'product-2.jpg', 'product-4.jpg'][($i * 2 + $j) % 6],
                    'category' => $cat,
                    'description' => 'Saee Life Herbal ' . strtolower($cat) . ' with farmers.',
                    'sort_order' => $i * 2 + $j, 'status' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        foreach (['Saee Life Herbal brand film', 'Neem Shield field demonstration', 'Farmer success story - Wardha'] as $i => $title) {
            $this->db->insert('videos', [
                'title' => $title, 'description' => $title . ' by Saee Life Herbal.',
                'source_type' => 'youtube', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'thumbnail' => 'assets/images/demo/' . ['hero-3.jpg', 'blog-1.jpg', 'blog-3.jpg'][$i % 3],
                'category' => 'Company', 'sort_order' => $i, 'status' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedTestimonials(string $now): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM testimonials') > 0) {
            return;
        }
        $rows = [
            ['Ramesh Patil', 'Cotton Farmer', 'Yavatmal, Maharashtra', 'The neem based spray reduced my pest pressure without harming beneficial insects. Yield improved by nearly 15% last season.', 5],
            ['Sunita Deshmukh', 'Farmer Partner', 'Amravati, Maharashtra', 'I started sharing products with farmers in my village. The commission statement is transparent and I can see exactly which sale paid what.', 5],
            ['Imran Shaikh', 'Agri Distributor', 'Akola, Maharashtra', 'Delivery is reliable and the agronomy desk actually answers calls. That matters during pest outbreaks.', 4],
        ];
        foreach ($rows as $i => [$name, $role, $loc, $msg, $rating]) {
            $this->db->insert('testimonials', [
                'name' => $name, 'designation' => $role, 'location' => $loc,
                'message' => $msg, 'rating' => $rating, 'sort_order' => $i, 'status' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** Founder and public team profiles (Admin → Founder & Team). */
    private function seedFounderTeam(string $now): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM team_members') > 0) {
            return;
        }
        $rows = [
            [
                'name' => 'Dr. Sunil Deshmukh', 'designation' => 'Founder & Managing Director', 'role_type' => 'founder',
                'bio' => 'An agronomist with two decades of field experience across the Vidarbha cotton and soybean belts, Sunil started Saee Life Herbal to make safe, effective crop care affordable for smallholders.',
                'quote' => 'We started Saee Life Herbal with one belief: farmers deserve inputs that are safe for their soil and reliable in the field.',
                'highlight' => '20+ years in agri-inputs', 'show_on_home' => 1, 'sort_order' => 1,
            ],
            [
                'name' => 'Anjali Wankhede', 'designation' => 'Director – Operations', 'role_type' => 'team',
                'bio' => 'Leads procurement, warehousing and pan-India dispatch so every order reaches the farm gate on time.',
                'highlight' => 'Pan-India logistics', 'show_on_home' => 1, 'sort_order' => 2,
            ],
            [
                'name' => 'Rahul Kale', 'designation' => 'Head of Agronomy', 'role_type' => 'team',
                'bio' => 'Runs farmer field demonstrations, soil testing camps and crop advisory across Maharashtra.',
                'highlight' => '400+ field demos', 'show_on_home' => 1, 'sort_order' => 3,
            ],
            [
                'name' => 'Pooja Bhosale', 'designation' => 'Quality & Compliance', 'role_type' => 'team',
                'bio' => 'Oversees product registration, batch testing and label compliance for every formulation we sell.',
                'highlight' => 'ISO-aligned QA', 'show_on_home' => 1, 'sort_order' => 4,
            ],
            [
                'name' => 'Vasantrao Patil', 'designation' => 'Agronomy Advisor', 'role_type' => 'advisor',
                'bio' => 'Retired agriculture officer advising on soil health and integrated pest management.',
                'highlight' => '30 years advisory', 'show_on_home' => 0, 'sort_order' => 5,
            ],
        ];
        foreach ($rows as $row) {
            $this->db->insert('team_members', $row + ['status' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** Build a 4 level unilevel team with realistic names. */
    private function seedTeam(int $companyId, string $now): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM members WHERE is_company = 0') > 6) {
            return;
        }
        $service = new MemberService($this->db);
        $people = [
            ['Anil Deshmukh', 'farmers'],
            ['Priya Kulkarni', 'distributor'],
            ['Sunita Deshmukh', 'farmer'],
            ['Ramesh Patil', 'farmer'],
            ['Imran Shaikh', 'distributor'],
            ['Vaishali Jadhav', 'farmer'],
            ['Ganesh More', 'farmer'],
            ['Kavita Pawar', 'retailer'],
            ['Sanjay Bhosale', 'farmer'],
            ['Meena Gaikwad', 'farmer'],
            ['Pravin Wankhede', 'distributor'],
            ['Asha Kadam', 'farmer'],
        ];
        $villages = [
            ['Nagpur', 'Hingna', 'Wanadongri', '441110'],
            ['Yavatmal', 'Kalamb', 'Kalamb', '445401'],
            ['Amravati', 'Achalpur', 'Paratwada', '444805'],
            ['Akola', 'Balapur', 'Balapur', '444302'],
            ['Wardha', 'Hinganghat', 'Hinganghat', '442301'],
        ];
        $created = [];
        foreach ($people as $index => [$name, $role]) {
            if ($role === 'farmers') {
                $role = 'farmer';
            }
            // Every third member joins under an earlier member to create depth.
            $sponsorCode = '__COMPANY__';
            if ($index > 0 && $index % 2 === 0) {
                $sponsorCode = $created[$index - 1]['member_code'];
            } elseif ($index === 3) {
                $sponsorCode = $created[0]['member_code'];
            }
            $village = $villages[$index % count($villages)];
            $mobile = '98765' . str_pad((string) (43210 + $index), 5, '0', STR_PAD_LEFT);
            $result = $service->register([
                'name' => $name,
                'mobile' => $mobile,
                'email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
                'password' => 'Member@12345',
                'role' => $role,
                'sponsor_code' => $sponsorCode === '__COMPANY__' ? MlmSettings::companySponsorCode() : $sponsorCode,
                'address' => 'Main Road, ' . $village[2],
                'state' => 'Maharashtra',
                'district' => $village[0],
                'taluka' => $village[1],
                'village' => $village[2],
                'pincode' => $village[3],
                'terms' => '1',
            ], ['source' => 'demo']);
            if (!empty($result['member'])) {
                $member = $result['member'];
                $created[] = $member;
                // KYC + bank for the first four members so payouts can be demonstrated.
                if ($index < 4) {
                    $this->db->update('members', ['kyc_status' => 'approved'], 'id = :id', ['id' => $member['id']]);
                    $this->db->update('member_kyc', [
                        'pan_number' => Security::encrypt('ABCDE' . (1234 + $index) . 'F'),
                        'pan_name' => $name,
                        'aadhaar_number' => Security::encrypt('4567123456' . (70 + $index)),
                        'date_of_birth' => '198' . ($index + 2) . '-05-12',
                        'address' => 'Main Road, ' . $village[2],
                        'status' => 'approved',
                        'submitted_at' => $now,
                        'verified_at' => $now,
                        'updated_at' => $now,
                    ], 'member_id = :m', ['m' => $member['id']]);
                    $this->db->insert('member_bank_accounts', [
                        'member_id' => $member['id'],
                        'account_holder_name' => $name,
                        'bank_name' => 'State Bank of India',
                        'account_number' => Security::encrypt('3012' . str_pad((string) (100000 + $index * 137), 8, '0', STR_PAD_LEFT)),
                        'ifsc_code' => 'SBIN000' . (1234 + $index),
                        'branch_name' => $village[0] . ' Main',
                        'status' => 'verified',
                        'is_primary' => 1,
                        'verified_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $this->db->update('members', ['is_payout_eligible' => 1], 'id = :id', ['id' => $member['id']]);
                }
            }
        }
    }

    private function seedOrders(string $now): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM orders') > 0) {
            return;
        }
        $members = $this->db->select('SELECT * FROM members WHERE is_company = 0 ORDER BY id ASC LIMIT 8');
        $products = $this->db->select("SELECT * FROM products WHERE status = 'active' ORDER BY id ASC");
        if ($members === [] || $products === []) {
            return;
        }
        $orderService = new OrderService($this->db);
        $statuses = ['paid', 'delivered', 'delivered', 'processing', 'shipped', 'delivered', 'cancelled', 'paid'];
        foreach ($members as $index => $member) {
            $product = $products[$index % count($products)];
            $qty = 1 + ($index % 3);
            $lineTotal = round((float) $product['price'] * $qty, 2);
            $status = $statuses[$index % count($statuses)];
            $createdAt = date('Y-m-d H:i:s', strtotime('-' . ($index * 4 + 1) . ' days'));
            $orderNumber = Sequence::orderNumber();
            $this->db->transaction(function (Database $db) use ($member, $product, $qty, $lineTotal, $status, $createdAt, $orderNumber) {
                $orderId = $db->insert('orders', [
                    'order_number' => $orderNumber,
                    'customer_id' => $db->value('SELECT id FROM customers WHERE member_id = :m', ['m' => $member['id']]) ?: null,
                    'member_id' => $member['id'],
                    'customer_name' => $member['name'],
                    'customer_email' => $member['email'],
                    'customer_mobile' => $member['mobile'],
                    'shipping_address' => json_encode(['full_name' => $member['name'], 'phone' => $member['mobile'], 'address_line1' => 'Main Road', 'city' => 'Nagpur', 'state' => 'Maharashtra', 'pincode' => '440001']),
                    'subtotal' => $lineTotal,
                    'shipping_total' => 60,
                    'tax_total' => 0,
                    'grand_total' => $lineTotal + 60,
                    'payment_method' => 'razorpay',
                    'payment_status' => in_array($status, ['cancelled'], true) ? 'refunded' : 'paid',
                    'status' => $status,
                    'invoice_number' => Sequence::invoiceNumber(),
                    'invoice_date' => substr($createdAt, 0, 10),
                    'commission_status' => 'none',
                    'paid_at' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
                $db->insert('order_items', [
                    'order_id' => $orderId, 'product_id' => $product['id'], 'product_name' => $product['name'],
                    'product_sku' => $product['sku'], 'unit_price' => $product['price'], 'quantity' => $qty,
                    'line_total' => $lineTotal, 'tax_amount' => 0, 'commissionable_amount' => $lineTotal,
                    'created_at' => $createdAt,
                ]);
                $db->insert('order_payments', [
                    'order_id' => $orderId, 'gateway' => 'razorpay', 'gateway_payment_id' => 'pay_demo' . $orderId . bin2hex(random_bytes(3)),
                    'amount' => $lineTotal + 60, 'status' => 'success', 'paid_at' => $createdAt,
                    'created_at' => $createdAt, 'updated_at' => $createdAt,
                ]);
                $db->insert('order_status_history', [
                    'order_id' => $orderId, 'status' => $status, 'note' => 'Demo order', 'changed_by_type' => 'system', 'created_at' => $createdAt,
                ]);
                return $orderId;
            });
            $orderId = (int) $this->db->value('SELECT id FROM orders WHERE order_number = :o', ['o' => $orderNumber]);
            if (in_array($status, ['paid', 'processing', 'shipped', 'delivered'], true)) {
                (new MlmCommissionEngine($this->db))->processOrder($orderId);
            } elseif ($status === 'cancelled') {
                (new MlmCommissionEngine($this->db))->processOrder($orderId);
                (new MlmCommissionEngine($this->db))->reverseOrder($orderId, 'Order cancelled by customer');
            }
        }
        (new RoyaltyEngine($this->db))->processAll();

        // Lower the demo withdrawal floor and add a small achievable royalty slab so the
        // payout and royalty screens can be demonstrated with this dataset.
        $this->db->update('payout_settings', ['min_withdrawal_amount' => 10], 'id = 1');
        $this->db->insertIgnore('royalty_rules', [
            'name' => 'Team Starter (Demo)', 'level_no' => 0, 'required_direct_referrals' => 1,
            'required_downline' => 2, 'required_active_members' => 0, 'required_purchase_count' => 0,
            'min_qualifying_sales' => 0, 'qualification_period_days' => 0, 'reward_type' => 'fixed',
            'reward_amount' => 200, 'reward_percent' => 0, 'percent_basis' => 'downline_sales',
            'frequency' => 'one_time', 'recurring_months' => 1, 'requires_admin_approval' => 1,
            'description' => 'Demo slab: 2 team members.', 'status' => 1, 'sort_order' => 99,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        (new RoyaltyEngine($this->db))->processAll();

        // One pending payout request for demonstration.
        $first = $members[0];
        $wallet = (new WalletService($this->db))->account((int) $first['id']);
        if ((float) $wallet['available_balance'] >= 10) {
            (new PayoutService($this->db))->request((int) $first['id'], min(25.0, (float) $wallet['available_balance']), 'Demo withdrawal request');
        }
    }

    private function seedActivity(string $now): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM page_views') > 0) {
            return;
        }
        // Website visitor analytics for the dashboard chart.
        $pages = ['/', '/our-products', '/about-us', '/contact-us', '/blogs', '/mlm-business-opportunity'];
        for ($day = 29; $day >= 0; $day--) {
            $count = 20 + (int) (sin($day / 3) * 12) + random_int(0, 18);
            for ($i = 0; $i < $count; $i++) {
                $this->db->insert('page_views', [
                    'page_url' => $pages[array_rand($pages)],
                    'visitor_hash' => hash('sha256', 'v' . $day . '-' . $i . random_int(1, 5000)),
                    'ip_address' => '203.0.113.' . random_int(1, 254),
                    'user_agent' => 'Mozilla/5.0 (Demo analytics)',
                    'referrer' => random_int(0, 1) ? 'https://google.com' : null,
                    'created_at' => date('Y-m-d H:i:s', strtotime("-$day days +$i minutes")),
                ]);
            }
        }
        // Enquiries
        foreach ([['Rahul Kale', 'rahul@example.com', '9876500011', 'Bulk fertilizer requirement', 'I need 50 bags of NPK for 20 acres. Please share dealer pricing.'],
                  ['Sneha Joshi', 'sneha@example.com', '9876500022', 'Neem Shield dosage', 'What is the recommended dose for chilli crop?']] as $i => [$name, $email, $phone, $subject, $msg]) {
            $this->db->insert('contact_enquiries', [
                'name' => $name, 'email' => $email, 'phone' => $phone, 'subject' => $subject,
                'message' => $msg, 'source_page' => 'contact-us', 'status' => $i === 0 ? 'new' : 'read',
                'ip_address' => '203.0.113.10', 'created_at' => date('Y-m-d H:i:s', strtotime('-' . ($i + 1) . ' days')),
                'updated_at' => $now,
            ]);
        }
    }
}
