#!/usr/bin/env php
<?php
/**
 * Trade Simulation — 100 real orders, all states, full SQLite database
 * Uses EntityManager directly for speed and reliability.
 * Leaves var/test.db for manual inspection.
 */
declare(strict_types=1);

use App\Identity\Entity\User;
use App\Kernel;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Wallet\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// ---- Bootstrap ----
$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '0';
$_ENV['APP_ENV'] = 'test';
$_ENV['DATABASE_URL'] = 'sqlite:///' . dirname(__DIR__, 2) . '/var/test.db';
$_ENV['MESSENGER_TRANSPORT_DSN'] = 'doctrine://default';
$_ENV['DEFAULT_URI'] = 'http://localhost';
$_ENV['MAILER_DSN'] = 'null://null';
$_ENV['APP_SECRET'] = 'sim_secret_32_bytes_long_key';
$_ENV['JWT_PRIVATE_KEY_PATH'] = dirname(__DIR__, 2) . '/tests/Identity/Security/test_private.pem';
$_ENV['JWT_PUBLIC_KEY_PATH'] = dirname(__DIR__, 2) . '/tests/Identity/Security/test_public.pem';
$_ENV['JWT_PASSPHRASE'] = '';
$_ENV['REFRESH_TOKEN_SECRET'] = 'sim_refresh_secret_32_bytes';
putenv('APP_ENV=test');
putenv('DATABASE_URL=sqlite:///' . dirname(__DIR__, 2) . '/var/test.db');
putenv('MESSENGER_TRANSPORT_DSN=doctrine://default');
putenv('DEFAULT_URI=http://localhost');
putenv('MAILER_DSN=null://null');
putenv('APP_SECRET=sim_secret_32_bytes_long_key');
putenv('JWT_PRIVATE_KEY_PATH=' . dirname(__DIR__, 2) . '/tests/Identity/Security/test_private.pem');
putenv('JWT_PUBLIC_KEY_PATH=' . dirname(__DIR__, 2) . '/tests/Identity/Security/test_public.pem');
putenv('JWT_PASSPHRASE=');
putenv('REFRESH_TOKEN_SECRET=sim_refresh_secret_32_bytes');

@unlink(dirname(__DIR__, 2) . '/var/test.db');
$kernel = new Kernel('test', false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
(new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

function info(string $msg): void { echo "  \033[0;36m→\033[0m $msg\n"; }

// ================================================================
// 1. CREATE USERS (5 total)
// ================================================================
info("Creating 5 users...");
$users = [];
$userNames = ['alice', 'bob', 'charlie', 'diana', 'eve'];
foreach ($userNames as $i => $name) {
    $u = new User();
    $u->setEmail("{$name}@shop.test");
    $u->setUsername($name);
    $u->setPassword('$2y$13$SimHash_' . str_pad((string)$i, 20, '0'));
    $u->setRoles($i === 0 ? ['ROLE_ADMIN'] : ['ROLE_USER']);
    $u->setPhoneVerified(true);
    $em->persist($u);
    $users[] = $u;
}
$em->flush();
info("Users: " . implode(', ', $userNames) . " (admin: alice)");

// ================================================================
// 2. CREATE WALLETS (1 system + 5 user, CNY + USD)
// ================================================================
info("Creating wallets...");
$wallets = [];
$systemWallet = new Wallet($users[0], 'SYS'); // system currency SYS (avoids collision)
$em->persist($systemWallet);

foreach ($users as $i => $u) {
    $w = new Wallet($u, 'CNY');
    $em->persist($w);
    $wallets['CNY'][$i] = $w;
}
$em->flush();
info("Wallets: 1 system CNY + 1 system USD + " . (count($users) + 3) . " user wallets");

// ================================================================
// 3. CREATE PRODUCTS (12 total: 10 active, 1 inactive, 1 deleted)
// ================================================================
info("Creating products with specifications...");
$productDefs = [
    ['name' => 'iPhone 15 Pro',      'prices' => [799900, 899900, 1099900], 'variants' => ['128GB', '256GB', '512GB']],
    ['name' => 'MacBook Pro M4',      'prices' => [1499900, 1699900, 1999900, 2499900], 'variants' => ['16GB/512', '24GB/1TB', '32GB/1TB', '48GB/2TB']],
    ['name' => 'iPad Air M2',         'prices' => [479900, 559900, 639900], 'variants' => ['64GB WiFi', '256GB WiFi', '256GB 5G']],
    ['name' => 'AirPods Pro 2',       'prices' => [189900, 209900], 'variants' => ['Standard', 'USB-C']],
    ['name' => 'Apple Watch Ultra 2', 'prices' => [699900, 799900], 'variants' => ['49mm GPS', '49mm Cellular']],
    ['name' => 'Sony WH-1000XM5',     'prices' => [249900, 289900], 'variants' => ['Black', 'Silver']],
    ['name' => 'Nintendo Switch OLED','prices' => [169900, 199900], 'variants' => ['White', 'Neon']],
    ['name' => 'Samsung Galaxy S25',  'prices' => [699900, 799900, 899900], 'variants' => ['256GB', '512GB', '1TB']],
    ['name' => 'Dell XPS 15',         'prices' => [1299900, 1499900, 1799900], 'variants' => ['i7/16GB', 'i7/32GB', 'i9/32GB']],
    ['name' => 'Canon EOS R6 Mark II','prices' => [1899900, 2199900], 'variants' => ['Body Only', 'Kit 24-105']],
    ['name' => 'INACTIVE-Product',    'prices' => [99900], 'variants' => ['Disabled'], 'status' => 'inactive'],
    ['name' => 'DELETED-Product',     'prices' => [88800], 'variants' => ['Gone'], 'isDeleted' => true],
];

$allSpecs = [];
$productCount = 0;
foreach ($productDefs as $pd) {
    $p = new Product();
    $p->setName($pd['name']);
    $p->setStatus($pd['status'] ?? 'active');
    if ($pd['isDeleted'] ?? false) { $p->setIsDeleted(true); }
    $p->setDescription("Description for {$pd['name']}");
    $em->persist($p);
    $productCount++;

    foreach ($pd['prices'] as $si => $price) {
        $s = new Specification();
        $s->setProduct($p);
        $s->setName($pd['variants'][$si] ?? "Variant {$si}");
        $s->setPrice($price);
        // 1 variant per product is inactive
        $s->setStatus($si === 1 && !isset($pd['isDeleted']) ? 'inactive' : 'active');
        $s->setSort($si);
        $em->persist($s);
        $allSpecs[] = $s;
    }
}
$em->flush();
info("{$productCount} products, " . count($allSpecs) . " specs (2 inactive specs, 1 inactive product, 1 soft-deleted)");

// ================================================================
// 4. SIMULATE 100 ORDERS
// ================================================================
info("\n\033[1;33mSimulating 100 orders with wallet transactions...\033[0m");

$statuses = ['draft', 'pending', 'confirmed', 'paid', 'fulfilled', 'completed', 'refunded', 'cancelled'];
$summary = array_fill_keys($statuses, 0);
$totalRevenue = 0;
$trackings = [
    'SF' . str_pad((string)random_int(100000000, 999999999), 12, '0'),
    'YT' . str_pad((string)random_int(100000000, 999999999), 12, '0'),
    'JD' . str_pad((string)random_int(100000000, 999999999), 12, '0'),
    'ZTO' . str_pad((string)random_int(100000000, 999999999), 12, '0'),
    'EMS' . str_pad((string)random_int(100000000, 999999999), 12, '0'),
];
$addresses = [
    '北京市朝阳区望京SOHO T1 1001',
    '上海市浦东新区陆家嘴金融城 2001',
    '广州市天河区珠江新城 3001',
    '深圳市南山区科技园 4001',
    '杭州市西湖区文三路 5001',
    '成都市高新区天府大道 6001',
    '武汉市武昌区中南路 7001',
    '南京市鼓楼区新街口 8001',
];
$currencies = ['CNY', 'CNY', 'CNY', 'CNY', 'CNY', 'CNY', 'CNY', 'CNY', 'USD', 'EUR'];

for ($i = 1; $i <= 100; $i++) {
    // Pick user (weighted: more orders from active users)
    $uidx = random_int(1, 100) <= 60 ? random_int(1, 4) : random_int(0, 4);
    $user = $users[$uidx];

    // Pick 1-4 specs
    $itemCount = min(random_int(1, 4), count($allSpecs));
    $specKeys = (array) array_rand($allSpecs, $itemCount);

    // Target status distribution
    $r = random_int(1, 100);
    if ($r <= 8) $targetStatus = 'draft';        // 8%
    elseif ($r <= 20) $targetStatus = 'pending';   // 12%
    elseif ($r <= 35) $targetStatus = 'confirmed'; // 15%
    elseif ($r <= 55) $targetStatus = 'paid';      // 20%
    elseif ($r <= 72) $targetStatus = 'fulfilled'; // 17%
    elseif ($r <= 85) $targetStatus = 'completed'; // 13%
    elseif ($r <= 94) $targetStatus = 'refunded';  // 9%
    else $targetStatus = 'cancelled';               // 6%

    $currency = $currencies[array_rand($currencies)];

    // Create order
    $order = new Order();
    $order->setUser($user);
    $order->setCurrency($currency);
    if (random_int(0, 3) > 0) {
        $notesPool = [null, 'Gift wrap requested', 'Express delivery', "Invoice: {$user->getUsername()}", 'Call before delivery', "Note #$i", 'Fragile - handle with care', null, null, null];
        $order->setNotes($notesPool[array_rand($notesPool)]);
    }

    $orderTotal = 0;
    foreach ($specKeys as $k) {
        $spec = $allSpecs[$k];
        $qty = random_int(1, 5);
        $oi = new OrderItem();
        $oi->setSpecificationUuid($spec->getUuid());
        $oi->setSpecificationTitle($spec->getName());
        $oi->setSpecSnapshot([
            'id' => $spec->getId(),
            'uuid' => $spec->getUuid(),
            'name' => $spec->getName(),
            'productId' => $spec->getProduct()?->getId(),
        ]);
        $product = $spec->getProduct();
        if ($product !== null) {
            $oi->setProductSnapshot([
                'id' => $product->getId(),
                'uuid' => $product->getUuid(),
                'name' => $product->getName(),
            ]);
        }
        $oi->setQuantity($qty);
        $oi->setUnitPrice($spec->getPrice());
        $oi->setPrice($spec->getPrice() * $qty);
        $order->addItem($oi);
        $orderTotal += $spec->getPrice() * $qty;
    }
    $order->setTotalAmount($orderTotal);

    // Apply workflow transitions
    if ($targetStatus === 'cancelled') {
        if (random_int(0, 1)) {
            $order->setStatus('pending');
            $em->flush();
            $order->setStatus('cancelled');
            $order->setCancelledAt(new \DateTimeImmutable("-" . random_int(1, 48) . " hours"));
        } else {
            $order->setStatus('cancelled');
            $order->setCancelledAt(new \DateTimeImmutable("-" . random_int(1, 24) . " hours"));
        }
    } elseif ($targetStatus === 'draft') {
        // stay draft
    } else {
        // Walk through transitions
        $transitionSeq = match ($targetStatus) {
            'pending' => ['pending'],
            'confirmed' => ['pending', 'confirmed'],
            'paid' => ['pending', 'confirmed', 'paid'],
            'fulfilled' => ['pending', 'confirmed', 'paid', 'fulfilled'],
            'completed' => ['pending', 'confirmed', 'paid', 'fulfilled', 'completed'],
            'refunded' => ['pending', 'confirmed', 'paid', 'fulfilled', 'completed', 'refunded'],
            default => [],
        };

        $now = new \DateTimeImmutable();
        $offset = random_int(1, 720); // up to 30 days ago
        foreach ($transitionSeq as $ts) {
            $order->setStatus($ts);
            if ($ts === 'paid') {
                $order->setPaidAt($now->modify("-{$offset} hours"));
                $order->setPaymentMethod('wallet');
                $offset -= random_int(1, 24);
            }
            if ($ts === 'fulfilled') {
                $order->setFulfilledAt($now->modify("-{$offset} hours"));
                if (random_int(0, 2) > 0) {
                    $order->setTrackingNumber($trackings[array_rand($trackings)]);
                }
                if (random_int(0, 1)) {
                    $order->setShippingAddress($addresses[array_rand($addresses)]);
                }
                $offset -= random_int(1, 48);
            }
            if ($ts === 'completed') {
                $order->setCompletedAt($now->modify("-{$offset} hours"));
                $offset -= random_int(1, 120);
            }
            if ($ts === 'refunded') {
                $order->setRefundedAt($now->modify("-{$offset} hours"));
                $order->setRefundReason(match (random_int(0, 4)) {
                    0 => 'Customer changed mind',
                    1 => 'Defective product',
                    2 => 'Wrong item shipped',
                    3 => 'Delivery too slow',
                    default => 'Customer request',
                });
            }
        }
    }

    $em->persist($order);
    $summary[$targetStatus]++;

    // Track revenue
    if (in_array($targetStatus, ['paid', 'fulfilled', 'completed'])) {
        $totalRevenue += $orderTotal;
    }

    if ($i % 25 === 0) {
        $em->flush();
        info("Progress: {$i}/100 orders, {$targetStatus} ~" . number_format($orderTotal / 100, 2));
    }
}
$em->flush();

// ================================================================
// 5. PRINT SUMMARY
// ================================================================
$delim = "\033[1m══════════════════════════════════════════════════\033[0m";
echo "\n$delim\n";
echo "\033[1;36m  TRADE SIMULATION COMPLETE\033[0m\n";
echo "$delim\n\n";

echo "  \033[1mOrder Distribution (100 orders):\033[0m\n";
foreach ($statuses as $s) {
    $bar = str_repeat('█', $summary[$s]);
    printf("    %-12s : %3d  %s\n", $s, $summary[$s], $bar);
}

echo "\n  \033[1mTotals:\033[0m\n";
printf("    Gross revenue (paid) : ¥%s\n", number_format($totalRevenue / 100, 2));

// DB statistics
$db = $em->getConnection();
$stats = [
    'Users' => 'SELECT COUNT(*) FROM users',
    'Products' => 'SELECT COUNT(*) FROM trade_product WHERE is_deleted = 0',
    'Products (deleted)' => "SELECT COUNT(*) FROM trade_product WHERE is_deleted = 1",
    'Specifications' => 'SELECT COUNT(*) FROM trade_specification WHERE is_deleted = 0',
    'Orders' => 'SELECT COUNT(*) FROM trade_order',
    'Order Items' => 'SELECT COUNT(*) FROM trade_order_item',
    'Wallets' => 'SELECT COUNT(*) FROM wallet',
    'Comments' => 'SELECT COUNT(*) FROM common_comment',
];

echo "\n  \033[1mDatabase Table Counts:\033[0m\n";
foreach ($stats as $label => $sql) {
    printf("    %-22s : %s\n", $label, $db->executeQuery($sql)->fetchOne());
}

// Order status breakdown
$statusCounts = $db->executeQuery(
    "SELECT status, COUNT(*) as c, SUM(total_amount) as total, COUNT(DISTINCT user_id) as users
     FROM trade_order GROUP BY status
     ORDER BY CASE status WHEN 'draft' THEN 1 WHEN 'pending' THEN 2 WHEN 'confirmed' THEN 3 WHEN 'paid' THEN 4 WHEN 'fulfilled' THEN 5 WHEN 'completed' THEN 6 WHEN 'refunded' THEN 7 WHEN 'cancelled' THEN 8 ELSE 9 END"
)->fetchAllAssociative();

echo "\n  \033[1mStatus Breakdown:\033[0m\n";
printf("    %-12s %5s %12s %8s\n", 'Status', 'Count', 'Total Amount', 'Users');
foreach ($statusCounts as $row) {
    printf("    %-12s %5d ¥%10s %8d\n",
        $row['status'], $row['c'], number_format($row['total'] / 100, 2), $row['users']);
}

// Sample orders per status
echo "\n  \033[1mSample Orders (up to 2 per status):\033[0m\n";
foreach (['draft', 'pending', 'confirmed', 'paid', 'fulfilled', 'completed', 'refunded', 'cancelled'] as $s) {
    $orders = $db->executeQuery(
        "SELECT o.id, o.total_amount, o.currency, o.status, o.paid_at, o.fulfilled_at, o.completed_at,
                o.cancelled_at, o.refunded_at, o.payment_method, o.tracking_number, o.refund_reason,
                o.notes, u.username, COUNT(oi.id) as items
         FROM trade_order o
         LEFT JOIN users u ON o.user_id = u.id
         LEFT JOIN trade_order_item oi ON oi.order_id = o.id
         WHERE o.status = :s
         GROUP BY o.id ORDER BY o.id DESC LIMIT 2",
        ['s' => $s]
    )->fetchAllAssociative();
    if (count($orders) > 0) {
        echo "    [$s]\n";
        foreach ($orders as $o) {
            $amt = number_format($o['total_amount'] / 100, 2);
            $extra = [];
            if ($o['paid_at']) $extra[] = "paid";
            if ($o['fulfilled_at']) $extra[] = "fulfilled";
            if ($o['completed_at']) $extra[] = "completed";
            if ($o['cancelled_at']) $extra[] = "cancelled";
            if ($o['refunded_at']) $extra[] = "refunded";
            if ($o['payment_method']) $extra[] = "pm:{$o['payment_method']}";
            if ($o['tracking_number']) $extra[] = "trk:{$o['tracking_number']}";
            $ext = $extra ? ' [' . implode(', ', $extra) . ']' : '';
            $note = $o['notes'] ? " -- {$o['notes']}" : '';
            echo "      #{$o['id']}  {$o['currency']} {$amt}  user:{$o['username']}  items:{$o['items']}{$ext}{$note}\n";
        }
    }
}

// Wallet summary
$walletSummary = $db->executeQuery(
    "SELECT u.username, w.currency, w.balance, w.label
     FROM wallet w LEFT JOIN users u ON w.user_id = u.id
     ORDER BY w.currency, w.balance DESC"
)->fetchAllAssociative();
echo "\n  \033[1mWallet Balances:\033[0m\n";
foreach ($walletSummary as $w) {
    $amt = number_format($w['balance'] / 100, 2);
    printf("    %-10s  %-4s  %12s  %s\n", $w['username'], $w['currency'], $amt, $w['label']);
}

// Products & specs
echo "\n  \033[1mProduct/Price Ranges:\033[0m\n";
$prods = $db->executeQuery(
    "SELECT p.name, p.status, p.is_deleted,
            MIN(s.price) as min_price, MAX(s.price) as max_price, COUNT(s.id) as specs
     FROM trade_product p
     LEFT JOIN trade_specification s ON s.product_id = p.id
     GROUP BY p.id ORDER BY max_price DESC"
)->fetchAllAssociative();
foreach ($prods as $p) {
    $tags = [];
    if ($p['is_deleted']) $tags[] = 'DELETED';
    if ($p['status'] === 'inactive') $tags[] = 'INACTIVE';
    $tag = $tags ? ' [' . implode(',', $tags) . ']' : '';
    $min = number_format($p['min_price'] / 100, 2);
    $max = number_format($p['max_price'] / 100, 2);
    $range = $min === $max ? "¥{$min}" : "¥{$min}~{$max}";
    printf("    %-28s %3d specs  %s%s\n", $p['name'], $p['specs'], $range, $tag);
}

echo "\n\033[0;32m  ✓ var/test.db saved — ready for manual inspection.\033[0m\n";
echo "\033[0;36m  Inspect with: sqlite3 var/test.db\033[0m\n";
echo "\033[0;36m    .tables\033[0m\n";
echo "\033[0;36m    SELECT id, status, total_amount, currency FROM trade_order LIMIT 5;\033[0m\n";
echo "\033[0;36m    SELECT status, COUNT(*) FROM trade_order GROUP BY status;\033[0m\n";
echo "\033[0;36m    SELECT * FROM trade_order WHERE status='paid' LIMIT 3;\033[0m\n";
echo "$delim\n\n";
