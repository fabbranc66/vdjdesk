<?php
declare(strict_types=1);

final class SessionTrackUploadService
{
    public function __construct(private array $config) {}

    public function publish(string $localPath): array
    {
        $this->assertConfigured();
        if (!is_file($localPath)) throw new RuntimeException('JSON sessione locale non trovato.');
        if (!function_exists('curl_init')) throw new RuntimeException('Estensione cURL non disponibile.');

        $curl = curl_init((string)$this->config['endpoint']);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token(),
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => [
                'checksum_sha256' => hash_file('sha256', $localPath),
                'upload_token' => $this->token(),
                'file' => new CURLFile($localPath, 'application/json', basename($localPath)),
            ],
            CURLOPT_TIMEOUT => (int)($this->config['timeout'] ?? 45),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status < 200 || $status >= 300) {
            $message = $error !== '' ? $error : 'Upload JSON sessione fallito.';
            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);
                $remoteError = is_array($decoded) ? (string)($decoded['error'] ?? $decoded['message'] ?? '') : '';
                $message .= ' HTTP ' . $status . ($remoteError !== '' ? ': ' . $remoteError : ': ' . mb_substr(strip_tags($body), 0, 300));
            }
            throw new RuntimeException($message);
        }

        $payload = json_decode((string)$body, true);
        if (!is_array($payload)) throw new RuntimeException('Risposta hosting non valida.');
        return $payload;
    }

    public function receive(array $file, string $checksum, SessionTrackService $sessionTracks): array
    {
        $tmp = (string)($file['tmp_name'] ?? '');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('Upload JSON sessione non valido.');
        }
        if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > 52428800) {
            throw new RuntimeException('Dimensione JSON sessione non valida.');
        }
        if ($checksum !== '' && !hash_equals($checksum, hash_file('sha256', $tmp))) {
            throw new RuntimeException('Checksum JSON sessione non coerente.');
        }

        $payload = $sessionTracks->load($tmp);
        $target = APP_ROOT . '/storage/session/krdesk_session_tracks.json';
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Cartella session non creata su hosting.');
        }
        if (!move_uploaded_file($tmp, $target) && !copy($tmp, $target)) {
            throw new RuntimeException('Salvataggio JSON sessione su hosting fallito.');
        }

        return [
            'ok' => true,
            'path' => 'storage/session/krdesk_session_tracks.json',
            'tracks' => count($payload['tracks']),
            'checksum_sha256' => hash_file('sha256', $target),
        ];
    }

    public function assertToken(string $token): void
    {
        if ($this->token() === '' || !hash_equals($this->token(), $token)) {
            throw new RuntimeException('Token upload JSON sessione non valido.');
        }
    }

    private function assertConfigured(): void
    {
        if (trim((string)($this->config['endpoint'] ?? '')) === '' || $this->token() === '') {
            throw new RuntimeException('Upload JSON sessione non configurato.');
        }
    }

    private function token(): string
    {
        return trim((string)($this->config['token'] ?? ''));
    }
}
