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

    $url = env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta') . '/models/' . rawurlencode($model) . ':generateContent';
    $response = null;
    $status = 0;
    // Coba maksimal 3 kali: error 429 (batas per menit) / 5xx (server Gemini sibuk) biasanya hilang sendiri.
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            $response = http_client()->post($url, [
                'headers' => ['x-goog-api-key' => $apiKey],
                'json' => [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => $config,
                ],
                'timeout' => 30,
            ]);
            break;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? mb_substr((string) $e->getResponse()->getBody(), 0, 300) : $e->getMessage();
            error_log("[RoomSync] Gemini error (percobaan $attempt, HTTP $status): $body");
            $retryable = $status === 0 || $status === 429 || $status >= 500;
            if (!$retryable || $attempt === 3) {
                break;
            }
            usleep($attempt * 1_000_000); // tunggu 1 detik, lalu 2 detik
        } catch (Throwable $e) {
            error_log('[RoomSync] Gemini error: ' . $e->getMessage());
            break;
        }
    }

    if ($response === null) {
        $reason = match (true) {
            $status === 429 => 'batas pemakaian gratis per menit tercapai, coba lagi sebentar lagi',
            $status === 400 || $status === 403 => 'API key atau model tidak valid',
            $status === 404 => 'model AI tidak ditemukan (cek GEMINI_MODEL)',
            default => 'server AI sedang sibuk atau gangguan',
        };
        throw new ApiError("Asisten AI sedang tidak tersedia ($reason). Silakan gunakan form booking manual.", 503);
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
