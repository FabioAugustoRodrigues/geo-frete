<?php

namespace app\domain\geo;

use RuntimeException;
use ZipArchive;

/**
 * Finds a municipality's IBGE code and fetches its CNEFE CSV from IBGE's FTP
 * (served over HTTPS), keeping the zip as a local cache.
 */
class CnefeDownloader
{
    private const DOWNLOAD_TIMEOUT_SECONDS = 600;

    public function __construct(
        private HttpClient $httpClient,
        private AddressNormalizer $normalizer,
        private string $localitiesApiUrl,
        private string $cnefeBaseUrl,
        private string $dataDir
    ) {
    }

    /**
     * @return array{code: int, name: string} IBGE code and official name (with accents)
     */
    public function findMunicipality(string $state, string $city): array
    {
        $state = strtoupper($state);
        $municipalities = $this->httpClient->getJson("{$this->localitiesApiUrl}/estados/{$state}/municipios");

        if ($municipalities === null) {
            throw new RuntimeException("Could not list the municipalities of {$state} from IBGE.");
        }

        $wanted = $this->normalizer->normalizeText($city);
        foreach ($municipalities as $municipality) {
            if ($this->normalizer->normalizeText($municipality['nome'] ?? '') === $wanted) {
                return ['code' => (int) $municipality['id'], 'name' => $municipality['nome']];
            }
        }

        throw new RuntimeException("Municipality '{$city}' not found in {$state}.");
    }

    /**
     * @return string path of the extracted CSV
     */
    public function fetchCsv(string $state, int $code): string
    {
        $dir = "{$this->dataDir}/{$code}";
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        $zipPath = "{$dir}/cnefe.zip";
        if (!is_file($zipPath)) {
            $url = $this->findZipUrl($state, $code);
            if (!$this->httpClient->download($url, $zipPath, self::DOWNLOAD_TIMEOUT_SECONDS)) {
                throw new RuntimeException("Download failed: {$url}");
            }
        }

        return $this->extractCsv($zipPath, $dir);
    }

    /**
     * Files are named "<code>_<NAME>.zip" inside "<state code>_<UF>/"; the
     * name part is not reliably derivable (spaces, apostrophes), so the
     * directory listing is searched for the code instead.
     */
    private function findZipUrl(string $state, int $code): string
    {
        $folder = sprintf('%s/%s_%s/', $this->cnefeBaseUrl, substr((string) $code, 0, 2), strtoupper($state));
        $listing = $this->httpClient->getText($folder);

        if ($listing === null) {
            throw new RuntimeException("Could not read {$folder} (see the error log for the network error).");
        }

        if (preg_match('/href="(' . $code . '_[^"]+\.zip)"/', $listing, $matches) !== 1) {
            throw new RuntimeException("CNEFE file for municipality {$code} not found in {$folder}");
        }

        return $folder . $matches[1];
    }

    private function extractCsv(string $zipPath, string $dir): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to import CNEFE files.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Invalid zip file: {$zipPath} (delete it to download again).");
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($name), '.csv')) {
                    // basename(): never trust paths inside an archive.
                    $target = $dir . '/' . basename($name);
                    if (!is_file($target)) {
                        copy("zip://{$zipPath}#{$name}", $target);
                    }

                    return $target;
                }
            }
        } finally {
            $zip->close();
        }

        throw new RuntimeException("No CSV inside {$zipPath}.");
    }
}
