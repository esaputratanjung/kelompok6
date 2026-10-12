<?php
/**
 * Mock Resend + Gemini API, KHUSUS untuk pengujian lokal.
 *  POST /token                                   -> OAuth token palsu (service account)
 *  POST /emails                                  -> simpan email ke tests/.mockdb/emails.jsonl
 *  POST /v1beta/models/{model}:generateContent   -> jawaban AI palsu
 *
 * Pesan chat "TEST:{...json...}" -> AI mengembalikan JSON itu apa adanya.
 * "TEST:INVALID" -> teks bukan JSON, "TEST:FAIL" -> HTTP 500.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];
header('Content-Type: application/json');

if ($path === '/token') { // OAuth token palsu untuk service account
    exit('{"access_token":"mock-token","expires_in":3600,"token_type":"Bearer"}');
}

if ($path === '/emails') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-resend-key') {
        http_response_code(401);
        exit('{"message":"bad key"}');
    }
    file_put_contents(__DIR__ . '/.mockdb/emails.jsonl', json_encode($body) . "\n", FILE_APPEND | LOCK_EX);
    exit('{"id":"mock-email"}');
}

if (str_contains($path, ':generateContent')) {
    $prompt = $body['contents'][0]['parts'][0]['text'] ?? '';
    $text = "- Ruang A paling sering dipakai.\n- Jam tersibuk 10:00.";
    if (preg_match('/<<<TEST:(.*)>>>/s', $prompt, $m)) {
        if ($m[1] === 'FAIL') {
            http_response_code(500);
            exit('{"error":{"message":"quota"}}');
        }
        $text = $m[1] === 'INVALID' ? 'ini bukan json' : $m[1];
    }
    echo json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
    exit;
}

http_response_code(404);
echo '{}';
