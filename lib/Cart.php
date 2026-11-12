<?php
/**
 * Shopping cart: works for guests (session token) and logged-in customers.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Cart
{
    public function __construct(private Database $db)
    {
    }

    public function token(bool $create = true): ?string
    {
        $token = Session::get('cart_token');
        if (!$token && $create) {
            $token = Security::randomToken(16);
            Session::set('cart_token', $token);
        }
        return $token;
    }

    /** Adopt a still-open guest cart after the visitor becomes a customer. */
    private function adoptGuestCart(int $customerId): ?array
    {
        $token = $this->token(false);
        if ($token) {
            $guest = $this->db->first(
                "SELECT * FROM carts WHERE session_token = :t AND customer_id IS NULL AND status = 'open' ORDER BY id DESC LIMIT 1",
                ['t' => $token]
            );
            if ($guest) {
                $this->db->update('carts', [
                    'customer_id' => $customerId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => (int) $guest['id']]);
                return $this->db->first('SELECT * FROM carts WHERE id = :id', ['id' => (int) $guest['id']]);
            }
        }
        return $this->db->first(
            "SELECT * FROM carts WHERE customer_id = :c AND status = 'open' ORDER BY id DESC LIMIT 1",
            ['c' => $customerId]
        );
    }

    public function current(bool $create = true): ?array
    {
        $customerId = CustomerAuth::id();
        if ($customerId) {
            $adopted = $this->adoptGuestCart($customerId);
            if ($adopted) {
                return $adopted;
            }
        }
        if ($customerId) {
            $cart = $this->db->first(
                "SELECT * FROM carts WHERE customer_id = :c AND status = 'open' ORDER BY id DESC LIMIT 1",
                ['c' => $customerId]
            );
            if (!$cart && $create) {
                $now = date('Y-m-d H:i:s');
                $id = $this->db->insert('carts', [
                    'customer_id' => $customerId, 'session_token' => $this->token(), 'status' => 'open',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $cart = $this->db->first('SELECT * FROM carts WHERE id = :id', ['id' => $id]);
                $this->mergeGuestCart($cart);
            }
            return $cart;
        }
        $token = $this->token($create);
        if (!$token) {
            return null;
        }
        $cart = $this->db->first(
            "SELECT * FROM carts WHERE session_token = :t AND customer_id IS NULL AND status = 'open' ORDER BY id DESC LIMIT 1",
            ['t' => $token]
        );
        if (!$cart && $create) {
            $now = date('Y-m-d H:i:s');
            $id = $this->db->insert('carts', [
                'customer_id' => null, 'session_token' => $token, 'status' => 'open',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $cart = $this->db->first('SELECT * FROM carts WHERE id = :id', ['id' => $id]);
        }
        return $cart;
    }

    /** Move items saved under the guest session token into the customer cart after login. */
    private function mergeGuestCart(array $customerCart): void
    {
        $token = Session::get('cart_token');
        if (!$token) {
            return;
        }
        $guest = $this->db->first(
            "SELECT * FROM carts WHERE session_token = :t AND customer_id IS NULL AND status = 'open'",
            ['t' => $token]
        );
        if (!$guest || (int) $guest['id'] === (int) $customerCart['id']) {
            return;
        }
        $items = $this->db->select('SELECT * FROM cart_items WHERE cart_id = :c', ['c' => $guest['id']]);
        foreach ($items as $item) {
            $existing = $this->db->first(
                'SELECT * FROM cart_items WHERE cart_id = :c AND product_id = :p AND (variant_id IS NULL OR variant_id = :v)',
                ['c' => $customerCart['id'], 'p' => $item['product_id'], 'v' => $item['variant_id']]
            );
            if ($existing) {
                $this->db->update('cart_items', ['quantity' => (int) $existing['quantity'] + (int) $item['quantity']], 'id = :id', ['id' => $existing['id']]);
            } else {
                $this->db->update('cart_items', ['cart_id' => $customerCart['id']], 'id = :id', ['id' => $item['id']]);
            }
        }
        $this->db->update('carts', ['status' => 'converted'], 'id = :id', ['id' => $guest['id']]);
    }

    public function add(int $productId, int $quantity = 1, ?int $variantId = null): array
    {
        $product = $this->db->first("SELECT * FROM products WHERE id = :id AND status = 'active'", ['id' => $productId]);
        if (!$product) {
            return ['ok' => false, 'message' => 'This product is not available.'];
        }
        $quantity = max(1, $quantity);
        $min = max(1, (int) $product['min_order_qty']);
        if ($quantity < $min) {
            $quantity = $min;
        }
        $price = (float) $product['price'];
        if ($variantId) {
            $variant = $this->db->first('SELECT * FROM product_variants WHERE id = :id AND product_id = :p AND status = 1', ['id' => $variantId, 'p' => $productId]);
            if ($variant) {
                $price = (float) $variant['price'];
            } else {
                $variantId = null;
            }
        }
        $stock = (int) $product['stock_quantity'];
        if ($stock > 0 && $quantity > $stock) {
            return ['ok' => false, 'message' => 'Only ' . $stock . ' units available in stock.'];
        }
        $cart = $this->current();
        $existing = $this->db->first(
            'SELECT * FROM cart_items WHERE cart_id = :c AND product_id = :p AND ' . ($variantId ? 'variant_id = :v' : 'variant_id IS NULL'),
            $variantId ? ['c' => $cart['id'], 'p' => $productId, 'v' => $variantId] : ['c' => $cart['id'], 'p' => $productId]
        );
        $now = date('Y-m-d H:i:s');
        if ($existing) {
            $newQty = (int) $existing['quantity'] + $quantity;
            if ($stock > 0 && $newQty > $stock) {
                $newQty = $stock;
            }
            $this->db->update('cart_items', ['quantity' => $newQty, 'unit_price' => $price, 'updated_at' => $now], 'id = :id', ['id' => $existing['id']]);
        } else {
            $this->db->insert('cart_items', [
                'cart_id' => $cart['id'], 'product_id' => $productId, 'variant_id' => $variantId,
                'quantity' => $quantity, 'unit_price' => $price, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->db->update('carts', ['updated_at' => $now], 'id = :id', ['id' => $cart['id']]);
        return ['ok' => true, 'message' => $product['name'] . ' added to cart.', 'count' => $this->itemCount()];
    }

    public function updateQuantity(int $itemId, int $quantity): array
    {
        $cart = $this->current(false);
        if (!$cart) {
            return ['ok' => false, 'message' => 'Cart not found.'];
        }
        $item = $this->db->first('SELECT * FROM cart_items WHERE id = :id AND cart_id = :c', ['id' => $itemId, 'c' => $cart['id']]);
        if (!$item) {
            return ['ok' => false, 'message' => 'Item not in your cart.'];
        }
        if ($quantity <= 0) {
            $this->db->delete('cart_items', 'id = :id', ['id' => $itemId]);
            return ['ok' => true, 'message' => 'Item removed.'];
        }
        $product = $this->db->first('SELECT stock_quantity FROM products WHERE id = :id', ['id' => $item['product_id']]);
        $stock = (int) ($product['stock_quantity'] ?? 0);
        if ($stock > 0 && $quantity > $stock) {
            return ['ok' => false, 'message' => 'Only ' . $stock . ' units available.'];
        }
        $this->db->update('cart_items', ['quantity' => $quantity, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $itemId]);
        return ['ok' => true, 'message' => 'Cart updated.'];
    }

    public function remove(int $itemId): array
    {
        $cart = $this->current(false);
        if (!$cart) {
            return ['ok' => false, 'message' => 'Cart not found.'];
        }
        $this->db->delete('cart_items', 'id = :id AND cart_id = :c', ['id' => $itemId, 'c' => $cart['id']]);
        return ['ok' => true, 'message' => 'Item removed from cart.'];
    }

    public function items(): array
    {
        $cart = $this->current(false);
        if (!$cart) {
            return [];
        }
        return $this->db->select(
            'SELECT ci.*, p.name, p.slug, p.featured_image, p.mrp, p.tax_percent, p.stock_quantity, p.mlm_enabled,
                    v.name AS variant_name, v.pack_size
             FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             LEFT JOIN product_variants v ON v.id = ci.variant_id
             WHERE ci.cart_id = :c ORDER BY ci.id ASC',
            ['c' => $cart['id']]
        );
    }

    public function totals(): array
    {
        $items = $this->items();
        $subtotal = 0.0;
        $tax = 0.0;
        foreach ($items as $item) {
            $line = (float) $item['unit_price'] * (int) $item['quantity'];
            $subtotal += $line;
            $tax += MlmCommissionEngine::compute($line, 'percent', (float) $item['tax_percent']);
        }
        $freeAbove = (float) Settings::get('free_shipping_above', 0);
        $flat = (float) Settings::get('ship_flat_rate', 0);
        $shipping = ($subtotal <= 0 || ($freeAbove > 0 && $subtotal >= $freeAbove)) ? 0.0 : $flat;
        $grand = round($subtotal + $tax + $shipping, 2);
        return [
            'items' => $items,
            'item_count' => array_sum(array_map(static fn ($i) => (int) $i['quantity'], $items)),
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'shipping' => $shipping,
            'grand_total' => $grand,
        ];
    }

    public function itemCount(): int
    {
        $cart = $this->current(false);
        if (!$cart) {
            return 0;
        }
        return (int) $this->db->value('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE cart_id = :c', ['c' => $cart['id']]);
    }

    public function clear(): void
    {
        $cart = $this->current(false);
        if (!$cart) {
            return;
        }
        $this->db->delete('cart_items', 'cart_id = :c', ['c' => $cart['id']]);
    }

    public function close(): void
    {
        $cart = $this->current(false);
        if ($cart) {
            $this->db->update('carts', ['status' => 'converted', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $cart['id']]);
        }
    }

    // -------------------------------------------------------------- wishlist
    public function toggleWishlist(int $productId): array
    {
        $customerId = CustomerAuth::id();
        $token = $this->token();
        $where = $customerId ? 'customer_id = :c' : 'session_token = :c';
        $params = ['c' => $customerId ?: $token, 'p' => $productId];
        $existing = $this->db->first("SELECT id FROM wishlists WHERE $where AND product_id = :p", $params);
        if ($existing) {
            $this->db->delete('wishlists', 'id = :id', ['id' => $existing['id']]);
            return ['ok' => true, 'added' => false, 'message' => 'Removed from wishlist.'];
        }
        $this->db->insert('wishlists', [
            'customer_id' => $customerId, 'session_token' => $customerId ? null : $token,
            'product_id' => $productId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'added' => true, 'message' => 'Added to wishlist.'];
    }

    public function wishlistIds(): array
    {
        $customerId = CustomerAuth::id();
        $token = $this->token(false);
        if (!$customerId && !$token) {
            return [];
        }
        $rows = $customerId
            ? $this->db->column('SELECT product_id FROM wishlists WHERE customer_id = :c', ['c' => $customerId])
            : $this->db->column('SELECT product_id FROM wishlists WHERE session_token = :c', ['c' => $token]);
        return array_map('intval', $rows);
    }
}
