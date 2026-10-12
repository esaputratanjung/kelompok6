<?php
/**
 * Mock Resend + Gemini API, KHUSUS untuk pengujian lokal.
 *  POST /emails                                  -> simpan email ke tests/.mockdb/emails.jsonl
 *  POST /v1beta/models/{model}:generateContent   -> jawaban AI palsu
 *
 * Pesan chat "TEST:{...json...}" -> AI mengembalikan JSON itu apa adanya.
 * "TEST:INVALID" -> teks bukan JSON, "TEST:FAIL" -> HTTP 500, "TEST:FLAKY" -> 503 sekali lalu berhasil.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];
header('Content-Type: application/json');

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
        $flag = __DIR__ . '/.mockdb/flaky.flag';
        if ($m[1] === 'FLAKY' && !is_file($flag)) { // gagal sekali (503), lalu berhasil
            touch($flag);
            http_response_code(503);
            exit('{"error":{"message":"overloaded"}}');
        }
        if ($m[1] === 'FLAKY') {
            $m[1] = '{"intent":"other","room_id":"ANY","date":"","start":"","end":"","reply":"Berhasil setelah retry"}';
        }
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
