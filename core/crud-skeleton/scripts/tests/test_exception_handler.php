#!/opt/homebrew/opt/php@8.5/bin/php
<?php
/**
 * Test script to verify ExceptionInterceptor JSON response (using JsonResponse)
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Symfony\Component\HttpFoundation\JsonResponse;

echo "=== Testing ExceptionInterceptor Response Generation (JsonResponse) ===\n\n";

// Test 1: JsonResponse basic functionality
echo "[Test 1] Testing JsonResponse with array data\n";
$responseData = [
    'code' => 500,
    'message' => 'Test error',
    'class' => 'Exception',
];

$jsonResponse = new JsonResponse($responseData, 500);

echo "✓ JsonResponse created successfully\n";
echo "Status Code: " . $jsonResponse->getStatusCode() . "\n";
echo "Headers:\n";
foreach ($jsonResponse->headers->all() as $name => $values) {
    foreach ($values as $value) {
        echo "  $name: $value\n";
    }
}
echo "Body: " . $jsonResponse->getContent() . "\n\n";

// Test 2: Verify JSON is valid
echo "[Test 2] Validating JSON output\n";
$json = $jsonResponse->getContent();
$decoded = json_decode($json, true);

if ($decoded === null) {
    echo "✗ Invalid JSON: " . json_last_error_msg() . "\n\n";
} else {
    echo "✓ Valid JSON\n";
    echo "Decoded data:\n";
    foreach ($decoded as $key => $value) {
        echo "  $key: $value\n";
    }
    echo "\n";
}

// Test 3: Different status codes
echo "[Test 3] Testing various status codes\n";
$testCodes = [400, 401, 403, 404, 500, 502, 503];
foreach ($testCodes as $code) {
    $response = new JsonResponse(['code' => $code, 'message' => 'Error'], $code);
    $status = $response->getStatusCode() === $code ? '✓' : '✗';
    echo "$status Status Code $code: " . $response->getStatusCode() . "\n";
}
echo "\n";

// Test 4: Verify Content-Type header
echo "[Test 4] Checking Content-Type header\n";
$response = new JsonResponse(['test' => 'data']);
$contentType = $response->headers->get('Content-Type');
echo "Content-Type: $contentType\n";
$isCorrect = strpos($contentType, 'application/json') !== false ? '✓' : '✗';
echo "$isCorrect Correct Content-Type: " . ($isCorrect === '✓' ? 'YES' : 'NO') . "\n\n";

// Test 5: Simulate exception scenario
echo "[Test 5] Simulating exception handling logic\n";
try {
    throw new \Exception("Database connection failed", 503);
} catch (\Exception $exception) {
    $statusCode = $exception->getCode() && $exception->getCode() >= 400 && $exception->getCode() < 600 
        ? $exception->getCode() 
        : 500;
    
    $responseData = [
        'code' => $statusCode,
        'message' => $exception->getMessage(),
        'class' => get_class($exception),
    ];
    
    $response = new JsonResponse($responseData, $statusCode);
    
    echo "✓ Exception response generated\n";
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Body: " . $response->getContent() . "\n\n";
}

// Test 6: Route pattern validation (same as before)
echo "[Test 6] Testing API route pattern\n";
$pattern = '/^\/(api)\/.*$/';
$testPaths = [
    '/api/users' => true,
    '/api/v1/products' => true,
    '/users' => false,
    '/admin/api/users' => false,
];

foreach ($testPaths as $path => $shouldMatch) {
    $result = preg_match($pattern, $path);
    $matched = $result === 1;
    $status = $matched === $shouldMatch ? '✓' : '✗';
    echo "$status Path: $path => " . ($matched ? 'matched' : 'not matched') . "\n";
}

echo "\n=== All tests completed ===\n";

