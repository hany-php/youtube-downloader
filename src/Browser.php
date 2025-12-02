<?php

namespace YouTube;

/**
 * HTTP client for making requests to YouTube
 */
class Browser
{
    protected $storage_dir;
    protected $cookie_file;

    protected $user_agent = 'Mozilla/5.0 (Windows NT 6.3; WOW64; rv:49.0) Gecko/20100101 Firefox/49.0';

    public function __construct()
    {
        $filename = 'youtube_downloader_cookies.txt';

        $this->storage_dir = sys_get_temp_dir();
        $this->cookie_file = join(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), $filename]);
    }

    /**
     * Get the cookie file path
     *
     * @return string Path to the cookie file
     */
    public function getCookieFile(): string
    {
        return $this->cookie_file;
    }

    /**
     * Make a GET request to the specified URL
     *
     * @param string $url URL to request
     * @return string Response content
     */
    public function get(string $url): string
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);

        //curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    /**
     * Get cached content or fetch from URL if not cached
     *
     * @param string $url URL to fetch
     * @return string|null Cached or fetched content
     */
    public function getCached(string $url): ?string
    {
        $cache_path = sprintf('%s/%s', $this->storage_dir, $this->getCacheKey($url));

        if (file_exists($cache_path)) {

            // unserialize could fail on empty file
            $str = file_get_contents($cache_path);
            return unserialize($str);
        }

        $response = $this->get($url);

        // must not fail
        if ($response) {
            file_put_contents($cache_path, serialize($response));
            return $response;
        }

        return null;
    }

    /**
     * Make a HEAD request to the specified URL
     *
     * @param string $url URL to request
     * @return array Response headers
     */
    public function head(string $url): array
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
        curl_setopt($ch, CURLOPT_NOBODY, 1);
        $result = curl_exec($ch);
        curl_close($ch);

        return http_parse_headers($result);
    }

    // useful for checking for: 429 Too Many Requests
    public function getStatus($url)
    {

    }

    /**
     * Get cache key for a URL
     *
     * @param string $url URL to generate cache key for
     * @return string MD5 hash of the URL
     */
    protected function getCacheKey(string $url): string
    {
        return md5($url);
    }
}