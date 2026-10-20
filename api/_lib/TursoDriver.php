<?php
declare(strict_types=1);

/**
 * Driver Turso (libSQL) lewat HTTP API "Hrana over HTTP" (/v2/pipeline).
 * Tidak butuh library tambahan — cukup ekstensi curl (atau allow_url_fopen).
 * Docs: https://docs.turso.tech/sdk/http/reference
 */
final class TursoDriver implements DbDriver
{
    private string $endpoint;

    public function __construct(string $url, private readonly string $token)
    {
        $url = trim($url);
        $url = preg_replace('~^libsql://~i', 'https://', $url);
        $url = preg_replace('~^wss?://~i', 'https://', $url);
        $this->endpoint = rtrim($url, '/') . '/v2/pipeline';
    }

    public function name(): string
    {
        return 'turso';
    }

    public function queryMany(array $statements): array
    {
        $requests = [];
        foreach ($statements as $stmt) {
            $requests[] = ['type' => 'execute', 'stmt' => $this->stmt($stmt[0], $stmt[1] ?? [])];
        }
        $results = $this->pipeline($requests);

        $out = [];
        foreach ($statements as $i => $_) {
            $res = $results[$i] ?? null;
            if (($res['type'] ?? '') !== 'ok') {
                throw new RuntimeException('Turso: ' . ($res['error']['message'] ?? 'query gagal'));
            }
            $out[] = $this->rows($res['response']['result'] ?? []);
        }
        return $out;
    }

    public function transaction(array $statements): void
    {
        // Pola batch atomik Hrana: BEGIN → statement (masing-masing hanya jalan jika langkah
        // sebelumnya ok) → COMMIT → ROLLBACK jika COMMIT tidak tercapai.
        $steps = [['stmt' => ['sql' => 'BEGIN IMMEDIATE']]];
        foreach ($statements as $stmt) {
            $steps[] = [
                'stmt'      => $this->stmt($stmt[0], $stmt[1] ?? []),
                'condition' => ['type' => 'ok', 'step' => count($steps) - 1],
            ];
        }
        $commitStep = count($steps);
        $steps[] = ['stmt' => ['sql' => 'COMMIT'], 'condition' => ['type' => 'ok', 'step' => $commitStep - 1]];
        $steps[] = [
            'stmt'      => ['sql' => 'ROLLBACK'],
            'condition' => ['type' => 'not', 'cond' => ['type' => 'ok', 'step' => $commitStep]],
        ];

        $results = $this->pipeline([['type' => 'batch', 'batch' => ['steps' => $steps]]]);
        $res = $results[0] ?? null;
        if (($res['type'] ?? '') !== 'ok') {
            throw new RuntimeException('Turso: ' . ($res['error']['message'] ?? 'batch gagal'));
        }
        $batch = $res['response']['result'] ?? [];
        foreach (($batch['step_errors'] ?? []) as $i => $err) {
            if ($err !== null && $i <= $commitStep) {
                throw new RuntimeException('Turso: ' . ($err['message'] ?? 'statement gagal'));
            }
        }
        if (($batch['step_results'][$commitStep] ?? null) === null) {
            throw new RuntimeException('Turso: transaksi tidak ter-commit');
        }
    }

    private function stmt(string $sql, array $params): array
    {
        return ['sql' => $sql, 'args' => array_map([$this, 'arg'], array_values($params))];
    }

    private function arg(mixed $value): array
    {
        return match (true) {
            $value === null => ['type' => 'null'],
            is_bool($value) => ['type' => 'integer', 'value' => $value ? '1' : '0'],
            is_int($value)  => ['type' => 'integer', 'value' => (string) $value],
            is_float($value) => is_finite($value) ? ['type' => 'float', 'value' => $value] : ['type' => 'null'],
            default         => ['type' => 'text', 'value' => (string) $value],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(array $result): array
    {
        $cols = array_map(fn ($c) => $c['name'] ?? '', $result['cols'] ?? []);
        $rows = [];
        foreach (($result['rows'] ?? []) as $row) {
            $assoc = [];
            foreach ($row as $i => $cell) {
                $assoc[$cols[$i] ?? (string) $i] = match ($cell['type'] ?? 'null') {
                    'integer' => (int) $cell['value'],
                    'float'   => (float) $cell['value'],
                    'text'    => (string) $cell['value'],
                    'blob'    => base64_decode((string) ($cell['base64'] ?? '')),
                    default   => null,
                };
            }
            $rows[] = $assoc;
        }
        return $rows;
    }

    private function pipeline(array $requests): array
    {
        $requests[] = ['type' => 'close'];
        $payload = json_encode(['requests' => $requests], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = ['Content-Type: application/json'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($this->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            if ($raw === false) {
                throw new RuntimeException('Turso: koneksi gagal — ' . $error);
            }
        } else {
            $raw = @file_get_contents($this->endpoint, false, stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $payload,
                'timeout'       => 30,
                'ignore_errors' => true,
            ]]));
            if ($raw === false) {
                throw new RuntimeException('Turso: koneksi gagal (curl tidak tersedia)');
            }
            $status = 200;
            // $http_response_header deprecated di PHP 8.5 → pakai fungsi penggantinya bila ada
            $respHeaders = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : ($http_response_header ?? []);
            foreach ($respHeaders as $h) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
                    $status = (int) $m[1];
                }
            }
        }

        $json = json_decode((string) $raw, true);
        if ($status >= 400 || !is_array($json)) {
            $msg = is_array($json) ? ($json['error'] ?? $json['message'] ?? '') : substr((string) $raw, 0, 200);
            throw new RuntimeException("Turso: HTTP $status " . (is_string($msg) ? $msg : json_encode($msg)));
        }
        return $json['results'] ?? [];
    }
}
