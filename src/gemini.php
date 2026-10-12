<?php
/**
 * Klien sederhana Google Gemini API (dipanggil dari backend dengan Guzzle).
 * API key hanya ada di server (environment variable), tidak pernah dikirim ke browser.
 */

declare(strict_types=1);

function gemini_enabled(): bool
{
    return (bool) env('GEMINI_API_KEY');
}

/**
 * Kirim prompt ke Gemini.
 * Jika $schema diberikan, Gemini diminta menjawab JSON sesuai skema (structured output)
 * dan hasilnya dikembalikan sebagai array. Jika tidak, dikembalikan teks biasa.
 */
function gemini_generate(string $prompt, ?array $schema = null): array|string
{
    $apiKey = env('GEMINI_API_KEY');
    if (!$apiKey) {
        throw new ApiError('Asisten AI belum dikonfigurasi. Silakan gunakan form booking manual.', 503);
    }
    $model = env('GEMINI_MODEL', 'gemini-flash-latest');

    $config = ['temperature' => 0.2];
    if ($schema !== null) {
        $config['responseMimeType'] = 'application/json';
        $config['responseSchema'] = $schema;
    }

    try {
        $response = http_client()->post(
            env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta') . '/models/' . rawurlencode($model) . ':generateContent',
            [
                'headers' => ['x-goog-api-key' => $apiKey],
                'json' => [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => $config,
                ],
                'timeout' => 30,
            ]
        );
    } catch (Throwable $e) {
        error_log('[RoomSync] Gemini error: ' . $e->getMessage());
        throw new ApiError('Asisten AI sedang tidak tersedia (kuota habis atau gangguan). Silakan gunakan form booking manual.', 503);
    }

    $data = json_decode((string) $response->getBody(), true);
    $text = '';
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (isset($part['text']) && empty($part['thought'])) {
            $text .= $part['text'];
        }
    }
    $text = trim($text);
    if ($text === '') {
        throw new ApiError('Asisten AI tidak memberikan jawaban. Coba ulangi dengan kalimat lain.', 502);
    }

    if ($schema === null) {
        return $text;
    }
    $json = json_decode($text, true);
    if (!is_array($json)) {
        throw new ApiError('Jawaban AI tidak valid. Coba ulangi dengan kalimat lain.', 502);
    }
    return $json;
}
