<?php

namespace app\domain\geo;

class HttpClient
{
    public function __construct(
        private int $timeoutSeconds,
        private string $userAgent,
        private string $contact
    ) {
    }

    /**
     * Returns the decoded JSON body, or null on network failure, non-2xx
     * status or invalid JSON. Never throws: callers decide what a failure means.
     *
     * @param int|null $timeoutSeconds overrides the default for this call
     */
    public function getJson(string $url, array $query = [], ?int $timeoutSeconds = null): ?array
    {
        $fullUrl = empty($query) ? $url : $url . '?' . http_build_query($query);

        [$status, $body] = $this->get($fullUrl, $timeoutSeconds ?? $this->timeoutSeconds);

        if ($status === 0) {
            return null;
        }

        if ($status < 200 || $status >= 300) {
            error_log("[HTTP CLIENT ERROR] GET {$fullUrl} - status {$status}");
            return null;
        }

        $decoded = json_decode((string) $body, true);

        if (!is_array($decoded)) {
            error_log("[HTTP CLIENT ERROR] GET {$fullUrl} - response is not a JSON array/object");
            return null;
        }

        return $decoded;
    }

    /**
     * Raw body of a 2xx answer (e.g. an HTML directory listing), or null.
     */
    public function getText(string $url, ?int $timeoutSeconds = null): ?string
    {
        [$status, $body] = $this->get($url, $timeoutSeconds ?? $this->timeoutSeconds);

        if ($status < 200 || $status >= 300) {
            if ($status !== 0) {
                error_log("[HTTP CLIENT ERROR] GET {$url} - status {$status}");
            }
            return null;
        }

        return $body;
    }

    /**
     * Streams a 2xx answer into a file without holding it in memory.
     * Returns false (and removes the partial file) on any failure.
     */
    public function download(string $url, string $path, int $timeoutSeconds): bool
    {
        $file = fopen($path, 'wb');
        if ($file === false) {
            return false;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $file,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => $this->buildUserAgent(),
        ]);

        $ok = curl_exec($curl) !== false;
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        fclose($file);

        if (!$ok || $status < 200 || $status >= 300) {
            error_log("[HTTP CLIENT ERROR] download {$url} - status {$status} {$error}");
            @unlink($path);
            return false;
        }

        return true;
    }

    /**
     * True when the server answers at all, whatever the status: some services
     * (e.g. OSRM) have no health endpoint and reply 4xx to an empty request.
     */
    public function isReachable(string $url, int $timeoutSeconds): bool
    {
        [$status] = $this->get($url, $timeoutSeconds);

        return $status > 0;
    }

    /**
     * @return array{0: int, 1: string|null} HTTP status (0 on network failure) and body
     */
    private function get(string $url, int $timeoutSeconds): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => $this->buildUserAgent(),
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false || $error !== '') {
            error_log("[HTTP CLIENT ERROR] GET {$url} - {$error}");
            return [0, null];
        }

        return [$status, (string) $body];
    }

    /**
     * OpenStreetMap services require identifying the application and a
     * contact; requests without one may be blocked.
     */
    private function buildUserAgent(): string
    {
        $contact = trim($this->contact);

        return $contact === '' ? $this->userAgent : "{$this->userAgent} ({$contact})";
    }
}
