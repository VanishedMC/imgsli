<?php

namespace App\Services;

use finfo;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\StreamInterface;

/**
 * Fetches a remote image for album uploads, with SSRF protection:
 * http(s) only, public hosts only (checked after DNS resolution and on
 * every redirect hop), the connection pinned to the validated IP (no
 * re-resolution between check and connect), and a hard size cap enforced
 * while streaming.
 */
class RemoteImageFetcher
{
    private const CHUNK_SIZE = 65536;

    /**
     * Caps (bytes / hops / seconds). When left null — production, and the
     * feature tests that use the defaults — each value is read from
     * config('uploads.*') at use time; pass explicit values to exercise a
     * specific configuration.
     */
    public function __construct(
        private ?int $maxBytes = null,
        private ?int $maxRedirects = null,
        private ?int $hopTimeout = null,
    ) {}

    public function maxBytes(): int
    {
        return $this->maxBytes ?? (int) config('uploads.max_image_bytes', 10 * 1024 * 1024);
    }

    public function maxRedirects(): int
    {
        return $this->maxRedirects ?? (int) config('uploads.max_redirects', 3);
    }

    public function hopTimeout(): int
    {
        return $this->hopTimeout ?? (int) config('uploads.hop_timeout', 30);
    }

    /**
     * The per-image size cap as a human-readable string (KB/MB/GB), for
     * error messages. Canonical for both the file and URL caps (same config).
     */
    public function humanBytes(): string
    {
        return self::humanBytesFor($this->maxBytes());
    }

    /**
     * Human-readable byte count (KB/MB/GB) for error messages.
     */
    public static function humanBytesFor(int $bytes): string
    {
        foreach ([['GB', 1073741824], ['MB', 1048576], ['KB', 1024]] as [$unit, $size]) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 1), '0'), '.').' '.$unit;
            }
        }

        return $bytes.' B';
    }

    /**
     * Test seam: replaces DNS resolution with a callable
     * (string $host) => list<string>. Null = real dns_get_record.
     *
     * @var (callable(string): list<string>)|null
     */
    public $resolver = null;

    /**
     * The CURLOPT_RESOLVE entry (host:port:ip) used for the last connection,
     * i.e. the exact address the transport was pinned to.
     */
    public ?string $lastResolve = null;

    /**
     * @param  float|null  $deadline  Unix time (microtime) by which this fetch
     *                                must have finished; null = no deadline.
     * @param  int|null  $maxBytesOverride  Per-call cap in bytes (e.g. an
     *                                      admin bypass); null = this->maxBytes().
     * @return array{0: string, 1: string} [bytes, extension]
     *
     * @throws ValidationException when the URL or its content is rejected
     */
    public function fetch(string $url, ?float $deadline = null, ?int $maxBytesOverride = null): array
    {
        // The SSRF protection pins the validated address with CURLOPT_RESOLVE.
        // Guzzle's stream handler ignores that option, which would silently
        // reopen the DNS-rebinding window; refuse to fetch without curl.
        if (! extension_loaded('curl')) {
            throw new \RuntimeException('Fetching image URLs requires the PHP curl extension.');
        }

        $maxBytes = $maxBytesOverride ?? $this->maxBytes();
        $location = $url;

        for ($hop = 0; $hop <= $this->maxRedirects(); $hop++) {
            $parsed = parse_url($location);
            $scheme = strtolower($parsed['scheme'] ?? '');
            if ($scheme !== 'http' && $scheme !== 'https' || ($parsed['host'] ?? '') === '') {
                throw $this->error('Only http(s) image URLs are supported.');
            }

            $host = $parsed['host'];
            $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

            $remaining = $deadline === null
                ? $this->hopTimeout()
                : min($this->hopTimeout(), $deadline - microtime(true));
            if ($remaining <= 0) {
                throw $this->error('Could not fetch the image.');
            }

            // Resolve once, validate, then pin the connection to that exact
            // address (CURLOPT_RESOLVE) so the transport cannot re-resolve
            // the name to a different (private) IP — no DNS-rebinding TOCTOU.
            $resolve = $this->publicResolve($host, $port);

            try {
                $response = Http::timeout($remaining)
                    ->withOptions([
                        'allow_redirects' => false,
                        'stream' => true,
                        'curl' => [\CURLOPT_RESOLVE => [$resolve]],
                    ])
                    ->get($location);
            } catch (ConnectionException) {
                throw $this->error('Could not fetch the image.');
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $next = $response->header('Location');
                if ($next === null || $next === '') {
                    throw $this->error('Could not fetch the image.');
                }
                if (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $next)) {
                    // Relative reference (path, ../, query-only, //host): resolve
                    // it against the URL just requested. The next hop is
                    // re-validated at the top of the loop either way.
                    try {
                        $next = (string) UriResolver::resolve(new Uri($location), new Uri($next));
                    } catch (MalformedUriException) {
                        throw $this->error('Could not fetch the image.');
                    }
                }
                $location = $next;

                continue;
            }

            if (! $response->ok()) {
                throw $this->error("Could not fetch the image (HTTP {$response->status()}).");
            }

            $contentLength = $response->header('Content-Length');
            if ($contentLength !== null && (int) $contentLength > $maxBytes) {
                throw $this->error('The image is too large (max '.self::humanBytesFor($maxBytes).').');
            }

            $bytes = $this->readWithCap($response->body(), $maxBytes);

            $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $extension = match ($type) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => null,
            };

            if ($extension === null) {
                throw $this->error('The URL does not point to a PNG, JPEG, GIF or WebP image.');
            }

            return [$bytes, $extension];
        }

        throw $this->error('The image URL redirected too many times.');
    }

    /**
     * Resolves the host, rejects it unless every address is public, and
     * returns the pinned "host:port:ip" entry for CURLOPT_RESOLVE.
     */
    private function publicResolve(string $host, int $port): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            if (is_callable($this->resolver)) {
                $ips = ($this->resolver)($host);
            } else {
                $records = dns_get_record($host, \DNS_A | \DNS_AAAA);
                $ips = collect($records)
                    ->map(fn (array $record) => $record['ip'] ?? ($record['ipv6'] ?? null))
                    ->filter()
                    ->all();
            }
        }

        if ($ips === []) {
            throw $this->error('Could not resolve the image URL host.');
        }

        $this->assertPublicIps($ips);

        // Pin to one validated address (prefer IPv4 for the connection).
        $pin = collect($ips)
            ->first(fn (string $ip) => filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false)
            ?? $ips[0];

        $this->lastResolve = sprintf('%s:%d:%s', $host, $port, str_contains($pin, ':') ? "[{$pin}]" : $pin);

        return $this->lastResolve;
    }

    /**
     * Reads the response body up to $maxBytes, aborting the transfer the
     * moment the cap is exceeded (a lying/absent Content-Length cannot
     * stream more than $maxBytes + one chunk into memory).
     */
    private function readWithCap(string|StreamInterface $body, int $maxBytes): string
    {
        if (is_string($body)) {
            if (strlen($body) > $maxBytes) {
                throw $this->error('The image is too large (max '.self::humanBytesFor($maxBytes).').');
            }

            return $body;
        }

        $body->rewind();
        $bytes = '';
        while (! $body->eof()) {
            $bytes .= $body->read(self::CHUNK_SIZE);
            if (strlen($bytes) > $maxBytes) {
                $body->close();
                throw $this->error('The image is too large (max '.self::humanBytesFor($maxBytes).').');
            }
        }

        return $bytes;
    }

    /**
     * @param  list<string>  $ips
     */
    private function assertPublicIps(array $ips): void
    {
        foreach ($ips as $ip) {
            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
                throw $this->error('The image URL must point to a public host.');
            }
        }
    }

    private function error(string $message): ValidationException
    {
        return ValidationException::withMessages(['images' => $message]);
    }
}
