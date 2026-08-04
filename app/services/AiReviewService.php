<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

final class AiReviewService
{
    /** Target unused pre-generated reviews per client (cron + proactive refill). */
    public const BUFFER_TARGET = 5;

    // Gemini API માટેના ઓફિશિયલ એન્ડપોઇન્ટ્સ
    private const AI_CHAT_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const LOG_FILE = __DIR__ . '/../../storage/logs/ai_refill.log';

    public function __construct(private PDO $pdo)
    {
    }

    public function fillBufferToTarget(int $clientId, int $targetCount = self::BUFFER_TARGET): int
    {
        $startUnused = $this->getUnusedCount($clientId);
        if ($startUnused >= $targetCount) {
            $this->log("fillBufferToTarget client={$clientId} skipped: startUnused={$startUnused} target={$targetCount}");
            return 0;
        }

        $ctx = $this->getClientContext($clientId);
        if (!$ctx) {
            $this->log("fillBufferToTarget client={$clientId} failed: missing/disabled client context");
            return 0;
        }

        $targetCount = max(1, $targetCount);
        $inserted = 0;
        $attempts = 0;
        $maxAttempts = max(15, min(50, $targetCount * 5));
        $deadline = microtime(true) + 12.0;

        $duplicateCount = 0;
        $emptyCount = 0;
        while ($this->getUnusedCount($clientId) < $targetCount && $attempts < $maxAttempts) {
            if (microtime(true) > $deadline) {
                break;
            }
            $attempts++;
            $text = $this->generateReviewText($ctx, $attempts);
            if ($text === '') {
                $emptyCount++;
                continue;
            }

            $hash = hash('sha256', $this->normalizeText($text));
            if ($this->isDuplicateHash($clientId, $hash)) {
                $duplicateCount++;
                if ($this->insertReviewForced($clientId, $text)) {
                    $inserted++;
                }
                continue;
            }

            if ($this->insertReview($clientId, $text, $hash)) {
                $inserted++;
            }
        }

        $endUnused = $this->getUnusedCount($clientId);
        $this->log("fillBufferToTarget client={$clientId} startUnused={$startUnused} endUnused={$endUnused} target={$targetCount} inserted={$inserted} attempts={$attempts} duplicates={$duplicateCount} empty={$emptyCount}");

        return $inserted;
    }

    public function flushUnusedBufferAndRefill(int $clientId): array
    {
        $del = $this->pdo->prepare("DELETE FROM pre_generated_reviews WHERE client_id = :id AND status = 'unused'");
        $del->execute([':id' => $clientId]);
        $deleted = $del->rowCount();
        $inserted = $this->fillBufferToTarget($clientId, self::BUFFER_TARGET);

        return ['deleted' => $deleted, 'inserted' => $inserted];
    }

    public function generateRealtimeReviewForClient(int $clientId): ?string
    {
        $ctx = $this->getClientContext($clientId);
        if (!$ctx) {
            return null;
        }

        $text = $this->generateReviewText($ctx, random_int(1000, 99999));
        return $text !== '' ? $text : null;
    }

    public function generateAndStoreOneUnusedReview(int $clientId): ?array
    {
        $ctx = $this->getClientContext($clientId);
        if (!$ctx) {
            return null;
        }

        $maxAttempts = 30;
        for ($i = 1; $i <= $maxAttempts; $i++) {
            $text = $this->generateReviewText($ctx, random_int(1000, 99999) + $i);
            if ($text === '') {
                continue;
            }
            $hash = hash('sha256', $this->normalizeText($text));
            if ($this->isDuplicateHash($clientId, $hash)) {
                if ($this->insertReviewForced($clientId, $text)) {
                    $idStmt = $this->pdo->prepare("
                        SELECT id, review_text
                        FROM pre_generated_reviews
                        WHERE client_id = :client_id
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $idStmt->execute([':client_id' => $clientId]);
                    $row = $idStmt->fetch();
                    if ($row) {
                        return ['id' => (int)$row['id'], 'review_text' => (string)$row['review_text']];
                    }
                }
                continue;
            }
            if ($this->insertReview($clientId, $text, $hash)) {
                $idStmt = $this->pdo->prepare("
                    SELECT id, review_text
                    FROM pre_generated_reviews
                    WHERE client_id = :client_id AND review_hash = :review_hash
                    LIMIT 1
                ");
                $idStmt->execute([
                    ':client_id' => $clientId,
                    ':review_hash' => $hash,
                ]);
                $row = $idStmt->fetch();
                if ($row) {
                    return ['id' => (int)$row['id'], 'review_text' => (string)$row['review_text']];
                }
            }
        }

        return null;
    }

    private function getUnusedCount(int $clientId): int
    {
        $s = $this->pdo->prepare("SELECT COUNT(*) c FROM pre_generated_reviews WHERE client_id = :id AND status = 'unused'");
        $s->execute([':id' => $clientId]);
        return (int)($s->fetch()['c'] ?? 0);
    }

    private function getClientContext(int $clientId): ?array
    {
        $s = $this->pdo->prepare("
            SELECT c.id, c.business_name, c.owner_name, c.address, c.category_id, bc.category_name, c.review_model_version, c.review_logic_type, c.review_tone, c.seo_keywords
            FROM clients c
            INNER JOIN business_categories bc ON bc.id = c.category_id
            WHERE c.id = :id AND c.is_active = 1
            LIMIT 1
        ");
        $s->execute([':id' => $clientId]);
        $client = $s->fetch();
        if (!$client) {
            return null;
        }

        $f = $this->pdo->prepare("
            SELECT facility_name
            FROM client_facilities cf
            INNER JOIN facilities f ON f.id = cf.facility_id
            WHERE cf.client_id = :id
            ORDER BY facility_name ASC
        ");
        $f->execute([':id' => $clientId]);
        $client['facilities'] = $f->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (empty($client['facilities'])) {
            if ($this->hasFacilitiesCategoryColumn()) {
                $fallback = $this->pdo->prepare("
                    SELECT facility_name
                    FROM facilities
                    WHERE is_active = 1
                      AND (category_id = :category_id OR category_id IS NULL)
                    ORDER BY facility_name ASC
                    LIMIT 5
                ");
                $fallback->execute([':category_id' => (int)$client['category_id']]);
            } else {
                $fallback = $this->pdo->prepare("
                    SELECT facility_name
                    FROM facilities
                    WHERE is_active = 1
                    ORDER BY facility_name ASC
                    LIMIT 5
                ");
                $fallback->execute();
            }
            $client['facilities'] = $fallback->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        return $client;
    }

    private function hasFacilitiesCategoryColumn(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM facilities LIKE 'category_id'");
            return (bool)$stmt->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    private function generateReviewText(array $ctx, int $variant): string
    {
        $clientId = (int)($ctx['id'] ?? 0);
        $apiKey = $this->resolveApiKey($clientId);
        $model = $this->resolveModelVersion($ctx, $apiKey);
        $prompt = $this->buildPrompt($ctx, $variant);

        if ($apiKey === '') {
            $this->log("generateReviewText client={$clientId}: API key is empty");
            return '';
        }

        $fromApi = $this->callExternalAiApi($apiKey, $model, $prompt);
        if ($fromApi !== null && trim($fromApi) !== '') {
            return $this->ensureWordRange($fromApi, 40, 90);
        }

        $this->log("generateReviewText client={$clientId}: API failed/empty response");
        return '';
    }

    private function resolveApiKey(int $clientId): string
    {
        try {
            $stmt = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'master_api_key' LIMIT 1");
            $row = $stmt->fetch();
            if ($row && !empty($row['setting_value'])) {
                return trim((string)$row['setting_value']);
            }
        } catch (Throwable) {
            // Fallback
        }

        $envKey = getenv('GAPI_API_KEY');
        if (is_string($envKey) && $envKey !== '') {
            return trim($envKey);
        }

        if (!empty($_ENV['GAPI_API_KEY'])) {
            return trim((string)$_ENV['GAPI_API_KEY']);
        }

        return '';
    }

    private function buildPrompt(array $ctx, int $variant): string
    {
        $business = $ctx['business_name'];
        $ownerName = trim((string)($ctx['owner_name'] ?? ''));
        $address = trim((string)($ctx['address'] ?? ''));
        $city = $this->extractCityFromAddress($address);
        $category = $ctx['category_name'];
        $logicType = (string)($ctx['review_logic_type'] ?? 'balanced');
        $tone = (string)($ctx['review_tone'] ?? 'Professional');
        $seoKeywords = $this->extractSeoKeywords((string)($ctx['seo_keywords'] ?? ''));
        $allFacilities = empty($ctx['facilities']) ? [] : array_values(array_filter(array_map('trim', $ctx['facilities'])));
        $focusFacilities = $this->pickFacilitiesForVariant($allFacilities, (int)$ctx['id'], $variant);
        $focusLine = $focusFacilities === [] ? 'good service' : implode(' and ', $focusFacilities);

        return "Write one unique, natural Google review in plain English. 40-90 words. "
            . "Business: {$business}. Owner: {$ownerName}. Category: {$category}. City: {$city}. "
            . "Focus only on: {$focusLine}. Tone: {$tone}. Style: {$logicType}. "
            . (empty($seoKeywords) ? '' : "Keywords: " . implode(', ', $seoKeywords) . ". ")
            . "Return ONLY the pure review text without quotes or hashtags.";
    }

    private function callExternalAiApi(string $apiKey, string $model, string $prompt): ?string
    {
        $result = $this->callExternalAiApiDetailed($apiKey, $model, $prompt);
        return $result['ok'] === true ? (string)$result['text'] : null;
    }

    private function callExternalAiApiDetailed(string $apiKey, string $model, string $prompt): array
    {
        $payload = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.92
            ]
        ]);

        $url = self::AI_CHAT_ENDPOINT . $model . ':generateContent';

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'text' => '', 'http_status' => 0, 'error' => 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($response) || $httpCode < 200 || $httpCode >= 300) {
            $snippet = is_string($response) ? substr($response, 0, 180) : 'no-body';
            $this->log("callExternalAiApi failed http={$httpCode} curl_error={$curlError} body={$snippet}");
            return [
                'ok' => false,
                'text' => '',
                'http_status' => $httpCode,
                'error' => 'HTTP error from Gemini API',
                'curl_error' => $curlError,
                'raw_response' => is_string($response) ? $response : '',
            ];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'text' => '',
                'http_status' => $httpCode,
                'error' => 'Invalid JSON from Gemini API',
                'curl_error' => $curlError,
                'raw_response' => $response,
            ];
        }

        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (trim($text) === '') {
            return [
                'ok' => false,
                'text' => '',
                'http_status' => $httpCode,
                'error' => 'Gemini response missing text',
                'curl_error' => $curlError,
                'raw_response' => $response,
            ];
        }

        return [
            'ok' => true,
            'text' => trim($text),
            'http_status' => $httpCode,
            'error' => '',
            'curl_error' => $curlError,
            'raw_response' => $response,
        ];
    }

    private function resolveModelVersion(array $ctx, string $apiKey): string
    {
        $preferred = trim((string)($ctx['review_model_version'] ?? ''));
        // જો જૂનું મોડેલ સેટ કરેલું હોય, તો તેને લેટેસ્ટ gemini-2.5-flash સાથે રિપ્લેસ કરો
        if ($preferred === '' || $preferred === 'gemini-2.0-flash') {
            return 'gemini-2.5-flash';
        }
        return $preferred;
    }

    private function extractCityFromAddress(string $address): string
    {
        if ($address === '') {
            return 'Dwarka';
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2];
        }
        return $parts[0] ?? 'Dwarka';
    }

    private function extractSeoKeywords(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
        return array_slice(array_values(array_filter($parts)), 0, 3);
    }

    private function pickFacilitiesForVariant(array $facilities, int $clientId, int $variant): array
    {
        $facilities = array_values(array_unique(array_filter(array_map('trim', $facilities))));
        $n = count($facilities);
        if ($n === 0) return [];
        if ($n === 1) return [$facilities[0]];
        $want = (($variant ^ ($variant >> 4) ^ $clientId) & 1) === 0 ? 1 : 2;
        $want = min($want, $n);

        $indices = [];
        $hash = hash('sha256', (string)$clientId . '|' . (string)$variant);
        $pos = 0;
        $guard = 0;
        while (count($indices) < $want && $guard < 64) {
            $guard++;
            if ($pos + 8 > strlen($hash)) {
                $hash = hash('sha256', $hash . '|' . (string)count($indices));
                $pos = 0;
            }
            $idx = hexdec(substr($hash, $pos, 8)) % $n;
            $pos += 8;
            if (!in_array($idx, $indices, true)) $indices[] = $idx;
        }
        sort($indices);
        $out = [];
        foreach ($indices as $i) $out[] = $facilities[$i];
        return $out;
    }

    private function ensureWordRange(string $text, int $minWords, int $maxWords): string
    {
        $clean = trim((string)preg_replace('/\s+/', ' ', $text));
        if ($clean === '') return '';
        $words = preg_split('/\s+/', $clean) ?: [];
        $count = count($words);

        if ($count > $maxWords) {
            $words = array_slice($words, 0, $maxWords);
            $clean = rtrim(implode(' ', $words), " ,;:-") . '.';
        } elseif ($count < $minWords) {
            $clean .= ' The staff remained attentive and the complete experience felt dependable.';
        }
        return $clean;
    }

    private function normalizeText(string $text): string
    {
        return (string)preg_replace('/\s+/', ' ', strtolower(trim($text)));
    }

    private function isDuplicateHash(int $clientId, string $hash): bool
    {
        $s = $this->pdo->prepare("SELECT id FROM pre_generated_reviews WHERE client_id = :id AND review_hash = :h LIMIT 1");
        $s->execute([':id' => $clientId, ':h' => $hash]);
        return (bool)$s->fetch();
    }

    private function insertReview(int $clientId, string $text, string $hash): bool
    {
        try {
            $s = $this->pdo->prepare("
                INSERT INTO pre_generated_reviews (client_id, review_text, review_hash, status, generated_by, generated_at)
                VALUES (:id, :t, :h, 'unused', 'ai', NOW())
            ");
            return $s->execute([':id' => $clientId, ':t' => $text, ':h' => $hash]);
        } catch (PDOException) {
            return $this->insertReviewForced($clientId, $text);
        }
    }

    private function insertReviewForced(int $clientId, string $text): bool
    {
        $forcedText = $this->ensureWordRange($text . ' Ref-' . uniqid('', true) . '.', 40, 90);
        $forcedHash = hash('sha256', $this->normalizeText($forcedText) . '|' . uniqid('', true));
        try {
            $s2 = $this->pdo->prepare("
                INSERT INTO pre_generated_reviews (client_id, review_text, review_hash, status, generated_by, generated_at)
                VALUES (:id, :t, :h, 'unused', 'ai', NOW())
            ");
            return $s2->execute([':id' => $clientId, ':t' => $forcedText, ':h' => $forcedHash]);
        } catch (PDOException) {
            return false;
        }
    }

    private function log(string $message): void
    {
        $dir = dirname(self::LOG_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents(self::LOG_FILE, $line, FILE_APPEND);
    }
}
