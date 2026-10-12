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
     */
    public function getJson(string $url, array $query = []): ?array
    {
        $fullUrl = empty($query) ? $url : $url . '?' . http_build_query($query);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->buildUserAgent(),
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false || $error !== '') {
            error_log("[HTTP CLIENT ERROR] GET {$fullUrl} - {$error}");
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
     * OpenStreetMap services require identifying the application and a
     * contact; requests without one may be blocked.
     */
    private function buildUserAgent(): string
    {
        $contact = trim($this->contact);

        return $contact === '' ? $this->userAgent : "{$this->userAgent} ({$contact})";
    }
}
