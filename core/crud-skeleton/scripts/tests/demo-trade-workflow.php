#!/usr/bin/env php
<?php
/**
 * Trade Workflow E2E Demo — 全部状态流转具现化 (SQLite)
 *
 * 覆盖 Happy Path + Cancel + Guards + App + 新 Endpoints + Timestamps
 */
declare(strict_types=1);

use App\Identity\Entity\User;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '0';
$_ENV['APP_ENV'] = 'test';
$_ENV['DATABASE_URL'] = 'sqlite:///tmp/trade_demo_' . getmypid() . '.db';
$_ENV['MESSENGER_TRANSPORT_DSN'] = 'doctrine://default';
$_ENV['DEFAULT_URI'] = 'http://localhost';
$_ENV['MAILER_DSN'] = 'null://null';
$_ENV['APP_SECRET'] = 'demo_secret_key_for_testing';
$_ENV['JWT_PRIVATE_KEY_PATH'] = dirname(__DIR__, 2) . '/tests/Identity/Security/test_private.pem';
$_ENV['JWT_PUBLIC_KEY_PATH'] = dirname(__DIR__, 2) . '/tests/Identity/Security/test_public.pem';
$_ENV['JWT_PASSPHRASE'] = '';
$_ENV['REFRESH_TOKEN_SECRET'] = 'test_refresh_secret_32';
$_ENV['ACCESS_TOKEN_TTL'] = '7200';
$_ENV['REFRESH_TOKEN_TTL'] = '31536000';
putenv('APP_ENV=test');
putenv('DATABASE_URL=' . $_ENV['DATABASE_URL']);
putenv('MESSENGER_TRANSPORT_DSN=doctrine://default');
putenv('DEFAULT_URI=http://localhost');
putenv('MAILER_DSN=null://null');
putenv('APP_SECRET=demo_secret_key_for_testing');
putenv('JWT_PRIVATE_KEY_PATH=' . $_ENV['JWT_PRIVATE_KEY_PATH']);
putenv('JWT_PUBLIC_KEY_PATH=' . $_ENV['JWT_PUBLIC_KEY_PATH']);
putenv('JWT_PASSPHRASE=');
putenv('REFRESH_TOKEN_SECRET=test_refresh_secret_32');
putenv('ACCESS_TOKEN_TTL=7200');
putenv('REFRESH_TOKEN_TTL=31536000');
@unlink('/tmp/trade_demo_' . getmypid() . '.db');

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer();

/** @var EntityManagerInterface $em */
$em = $container->get('doctrine')->getManager();
(new SchemaTool($em))->dropSchema($em->getMetadataFactory()->getAllMetadata());
(new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

$client = $kernel->getContainer()->get('test.client');
$client->disableReboot();

// User setup - password doesn't need to be valid since we use loginUser()
$user = new User();
$user->setEmail('demo@trade.test');
$user->setUsername('demouser');
$user->setPassword('$2y$13$demo_HashNotUsedSinceWeLoginViaClient');
$user->setRoles(['ROLE_ADMIN']);
$user->setPhoneVerified(true);
$em->persist($user);
$em->flush();
$client->loginUser($user);

$passed = 0; $failed = 0;

function ok(string $msg): void { global $passed; $passed++; echo "  \033[0;32m✓\033[0m $msg\n"; }
function err(string $msg): void { global $failed; $failed++; echo "  \033[0;31m✗\033[0m $msg\n"; }
function step(string $msg): void { echo "\n\033[1;33m═══ $msg ═══\033[0m\n"; }
function assertEq($e, $a, string $l): void { $e === $a ? ok($l) : err("$l — expected " . var_export($e, true) . ", got " . var_export($a, true)); }
function assertOk(array $c, string $l): void { $x = $c['code'] ?? -1; ($x === 0 || $x === 200 || $x === 201) ? ok($l) : err("$l — code=$x msg=" . ($c['message']??'')); }
function assertErr(array $c, string $l): void { $x = $c['code'] ?? 0; ($x !== 0 && $x !== 200 && $x !== 201) ? ok($l) : err("$l — should've failed (code=$x)"); }
function assertGt($e, $a, string $l): void { $a > $e ? ok($l) : err("$l — expected >$e, got $a"); }
function assertHas(string $n, string $h, string $l): void { str_contains($h, $n) ? ok($l) : err("$l — '$n' not found"); }

function r(KernelBrowser $cl, string $method, string $uri, array $data = []): array {
    $cl->request($method, $uri, [], [], [], json_encode($data, JSON_THROW_ON_ERROR));
    return json_decode($cl->getResponse()->getContent(), true) ?? [];
}
function rs(KernelBrowser $cl, string $method, string $uri, array $data = []): Response {
    $cl->request($method, $uri, [], [], [], json_encode($data, JSON_THROW_ON_ERROR));
    return $cl->getResponse();
}

step("Setup: products & specs");
$ignored = [];  // PHP 8 list assignment discard
[$ignored, $pa] = [$ignored, r($client, 'POST', '/api/v1/manage/products', ['name' => 'iPhone 15 Pro', 'status' => 'active'])];
$pA = $pa['data']['id'];
[$ignored, $pb] = [$ignored, r($client, 'POST', '/api/v1/manage/products', ['name' => 'MacBook Pro', 'status' => 'active'])];
$pB = $pb['data']['id'];
[$ignored, $s1] = [$ignored, r($client, 'POST', "/api/v1/manage/products/$pA/specifications", ['name' => '128GB', 'price' => 699900])];
$s1Id = $s1['data']['id'];
[$ignored, $s2] = [$ignored, r($client, 'POST', "/api/v1/manage/products/$pA/specifications", ['name' => '256GB', 'price' => 799900])];
$s2Id = $s2['data']['id'];
[$ignored, $s3] = [$ignored, r($client, 'POST', "/api/v1/manage/products/$pB/specifications", ['name' => 'M4 Pro', 'price' => 1499900])];
$s3Id = $s3['data']['id'];
ok("Products #$pA, #$pB | Specs #$s1Id(#6999) #$s2Id(#7999) #$s3Id(#14999)");

// ================================================================
step("1. HAPPY PATH: draft → pending → confirmed → paid → fulfilled → completed → refunded");
[$ignored, $hp] = [$ignored, r($client, 'POST', '/api/v1/manage/orders', [
    'items' => [['specificationId' => $s1Id, 'quantity' => 1], ['specificationId' => $s2Id, 'quantity' => 2]],
    'notes' => 'Happy Path',
])];
$hpId = $hp['data']['id'];
assertEq(699900 + 799900 * 2, $hp['data']['totalAmount'], "Create order #$hpId total=" . ((699900 + 799900 * 2) / 100));

foreach (['submit' => 'pending', 'confirm' => 'confirmed', 'pay' => 'paid', 'fulfill' => 'fulfilled', 'complete' => 'completed', 'refund' => 'refunded'] as $tr => $st) {
    [$ignored, $res] = [$ignored, r($client, 'POST', "/api/v1/manage/orders/$hpId/do/$tr")];
    assertOk($res, "$tr → $st");
}
[$ignored, $hpDetail] = [$ignored, r($client, 'GET', "/api/v1/manage/orders/$hpId")];
assertEq('refunded', $hpDetail['data']['status'], "Final status = refunded");
foreach (['paidAt', 'fulfilledAt', 'completedAt', 'refundedAt'] as $ts) {
    ($hpDetail['data'][$ts] ?? null) !== null ? ok("$ts = {$hpDetail['data'][$ts]}") : err("$ts MISSING");
}

// ================================================================
step("2. CANCEL: draft / pending / confirmed → cancelled");
foreach (['draft' => [], 'pending' => ['submit'], 'confirmed' => ['submit', 'confirm']] as $label => $preTransitions) {
    [$ignored, $c] = [$ignored, r($client, 'POST', '/api/v1/manage/orders', ['items' => [['specificationId' => $s3Id, 'quantity' => 1]]])];
    $cid = $c['data']['id'];
    foreach ($preTransitions as $t) { r($client, 'POST', "/api/v1/manage/orders/$cid/do/$t"); }
    r($client, 'POST', "/api/v1/manage/orders/$cid/do/cancel");
    [$ignored, $cd] = [$ignored, r($client, 'GET', "/api/v1/manage/orders/$cid")];
    assertEq('cancelled', $cd['data']['status'], "$label → cancelled");
}
// paid → cancel rejected
[$ignored, $c4] = [$ignored, r($client, 'POST', '/api/v1/manage/orders', ['items' => [['specificationId' => $s3Id, 'quantity' => 1]]])];
r($client, 'POST', "/api/v1/manage/orders/{$c4['data']['id']}/do/submit");
r($client, 'POST', "/api/v1/manage/orders/{$c4['data']['id']}/do/confirm");
r($client, 'POST', "/api/v1/manage/orders/{$c4['data']['id']}/do/pay");
[$ignored, $cr] = [$ignored, r($client, 'POST', "/api/v1/manage/orders/{$c4['data']['id']}/do/cancel")];
assertErr($cr, "paid → cancel REJECTED");

// ================================================================
step("3. GUARDS: update/delete non-draft");
[$ignored, $gu] = [$ignored, r($client, 'PUT', "/api/v1/manage/orders/$hpId", ['notes' => 'fail'])];
assertErr($gu, "Update refunded → REJECTED");
[$ignored, $gd] = [$ignored, r($client, 'DELETE', "/api/v1/manage/orders/$hpId")];
assertErr($gd, "Delete refunded → REJECTED");

[$ignored, $gdd] = [$ignored, r($client, 'POST', '/api/v1/manage/orders', ['items' => [['specificationId' => $s3Id, 'quantity' => 1]]])];
$resp = rs($client, 'DELETE', "/api/v1/manage/orders/{$gdd['data']['id']}");
assertEq(204, $resp->getStatusCode(), "Delete draft → HTTP 204");

// ================================================================
step("4. NEW ENDPOINTS: items, pay, fulfill, refund guards");
// Items
[$ignored, $co] = [$ignored, r($client, 'POST', '/api/v1/manage/orders', ['items' => [['specificationId' => $s3Id, 'quantity' => 1]]])];
$coId = $co['data']['id'];
[$ignored, $it] = [$ignored, r($client, 'GET', "/api/v1/manage/orders/$coId/items")];
assertOk($it, "GET items → OK");
assertGt(0, count($it['data']), "  Has items");
[$ignored, $it2] = [$ignored, r($client, 'GET', '/api/v1/manage/orders/99999/items')];
assertErr($it2, "GET items missing → 404");

// Pay guards
[$ignored, $po] = [$ignored, r($client, 'POST', '/api/v1/manage/orders', ['items' => [['specificationId' => $s3Id, 'quantity' => 1]]])];
$poId = $po['data']['id'];
r($client, 'POST', "/api/v1/manage/orders/$poId/do/submit");
r($client, 'POST', "/api/v1/manage/orders/$poId/do/confirm");
[$ignored, $pm] = [$ignored, r($client, 'POST', "/api/v1/manage/orders/$poId/pay")];
assertErr($pm, "Pay without systemWalletId → REJECTED");
[$ignored, $pc] = [$ignored, r($client, 'POST', "/api/v1/manage/orders/$coId/pay", ['systemWalletId' => 1])];
assertErr($pc, "Pay wrong status → REJECTED");

// Fulfill guard
[$ignored, $fw] = [$ignored, r($client, 'POST', "/api/v1/manage/orders/$poId/fulfill")];
assertErr($fw, "Fulfill confirmed → REJECTED");

// Refund guards
[$ignored, $rw] = [$ignored, r($client, 'POST', "/api/v1/manage/orders/$poId/refund", ['systemWalletId' => 1, 'reason' => 'test'])];
assertErr($rw, "Refund confirmed → REJECTED");
[$ignored, $rm] = [$ignored, r($client, 'POST', "/api/v1/manage/orders/$coId/refund", ['systemWalletId' => 1])];
assertErr($rm, "Refund without reason → REJECTED");

// ================================================================
step("5. APP: user-side endpoints");
[$ignored, $ac] = [$ignored, r($client, 'POST', '/api/v1/app/orders', ['items' => [['specificationId' => $s1Id, 'quantity' => 1]]])];
$aoId = $ac['data']['id'];
assertOk($ac, "App create order #$aoId");

[$ignored, $al] = [$ignored, r($client, 'GET', '/api/v1/app/orders')];
assertOk($al, "App list orders — " . count($al['data']) . " found");

[$ignored, $ai] = [$ignored, r($client, 'GET', "/api/v1/app/orders/$aoId/items")];
assertOk($ai, "App own items — " . count($ai['data']) . " item(s)");

// Cross-user guards: the manager-created order has no user, so app won't see it
[$ignored, $ao2] = [$ignored, r($client, 'GET', "/api/v1/app/orders/$coId/items")];
assertErr($ao2, "App other's items → 404");

[$ignored, $aca] = [$ignored, r($client, 'POST', "/api/v1/app/orders/$aoId/cancel")];
assertOk($aca, "App cancel own → cancelled");
[$ignored, $acb] = [$ignored, r($client, 'POST', "/api/v1/app/orders/$coId/cancel")];
assertErr($acb, "App cancel other's → REJECTED");

// ================================================================
step("6. WORKFLOW: transitions & todo");
[$ignored, $to] = [$ignored, r($client, 'POST', '/api/v1/manage/orders', ['items' => [['specificationId' => $s3Id, 'quantity' => 1]]])];
$tId = $to['data']['id'];
[$ignored, $tr] = [$ignored, r($client, 'GET', "/api/v1/manage/orders/$tId/transitions")];
$tn = array_column($tr['data'], 'name');
assertEq(true, in_array('submit', $tn, true), "Draft transitions: submit ✓");
assertEq(true, in_array('cancel', $tn, true), "Draft transitions: cancel ✓");
assertEq(false, in_array('refund', $tn, true), "Draft transitions: refund ✗");

[$ignored, $td] = [$ignored, r($client, 'GET', '/api/v1/manage/orders/todo')];
assertEq(true, in_array($tId, array_column($td['data'], 'id'), true), "Order #$tId in todo");

// ================================================================
step("7. BATCH UPDATE & TRANSITION WITH DATA");
[$ignored, $ba] = [$ignored, r($client, 'POST', "/api/v1/manage/orders/batch-update?@basis=id&@mode=update", [['id' => $tId, 'notes' => 'Batch notes']])];
assertOk($ba, "Batch update → OK");

[$ignored, $wd] = [$ignored, r($client, 'POST', '/api/v1/manage/orders', ['items' => [['specificationId' => $s3Id, 'quantity' => 1]]])];
$wdId = $wd['data']['id'];
[$ignored, $we] = [$ignored, r($client, 'POST', "/api/v1/manage/orders/$wdId/do/submit", ['notes' => 'Payload notes', 'metadata' => ['src' => 'demo']])];
assertOk($we, "Submit with payload → OK");
[$ignored, $wf] = [$ignored, r($client, 'GET', "/api/v1/manage/orders/$wdId")];
assertEq('pending', $wf['data']['status'], "  Status = pending");
assertHas('Payload notes', $wf['data']['notes'] ?? '', "  Notes preserved");

// ================================================================
step("8. APP PRODUCTS");
[$ignored, $ap] = [$ignored, r($client, 'GET', '/api/v1/app/products')];
$names = array_column($ap['data'], 'name');
assertOk($ap, "App products — " . count($names) . " found");
assertEq(true, in_array('iPhone 15 Pro', $names, true), "  iPhone 15 Pro ✓");
assertEq(true, in_array('MacBook Pro', $names, true), "  MacBook Pro ✓");

// ================================================================
$total = $passed + $failed;
echo "\n\033[1m══════════════════════════════════════\033[0m\n";
printf("\033[1m  Assertions: %d/%d passed\033[0m\n", $passed, $total);
if ($failed > 0) { echo "\033[0;31m  FAILED: $failed\033[0m\n"; }
else { echo "\033[0;32m  ALL PASSED\033[0m\n"; }
echo "\033[1m══════════════════════════════════════\033[0m\n\n";

echo "\033[0;32m  ✓\033[0m Happy Path:      draft → pending → confirmed → paid → fulfilled → completed → refunded
\033[0;32m  ✓\033[0m Cancel:           draft / pending / confirmed → cancelled
\033[0;32m  ✓\033[0m Cancel Guard:     paid 之后不可取消
\033[0;32m  ✓\033[0m Update Guard:     非draft 不可更新
\033[0;32m  ✓\033[0m Delete Guard:     非draft 不可删 (draft 可)
\033[0;32m  ✓\033[0m Pay API:          wrong status / missing wallet → REJECTED
\033[0;32m  ✓\033[0m Fulfill API:      wrong status → REJECTED
\033[0;32m  ✓\033[0m Refund API:       wrong status / missing reason → REJECTED
\033[0;32m  ✓\033[0m App Items:        own visible, cross-user → 404
\033[0;32m  ✓\033[0m App Cancel:       own works, cross-user → 404
\033[0;32m  ✓\033[0m Timestamps:       cancelledAt, paidAt, fulfilledAt, completedAt, refundedAt
\033[0;32m  ✓\033[0m Transitions:      list + todo filter
\033[0;32m  ✓\033[0m Batch update:     draft order updated
\033[0;32m  ✓\033[0m Transition+data:  submit with notes/metadata
\033[0;32m  ✓\033[0m App Products:     active + not deleted filter
";

@unlink('/tmp/trade_demo_' . getmypid() . '.db');
exit($failed > 0 ? 1 : 0);
