<?php

namespace YouTube;

use Exception;
use Throwable;

/**
 * YouTube Downloader class
 * Handles downloading and streaming of YouTube videos
 */
class YouTubeDownloader
{
    protected $client;

    /** @var string */
    protected $error;

    public function __construct()
    {
        $this->client = new Browser();
    }

    /**
     * Get the browser instance used for HTTP requests
     *
     * @return Browser
     */
    public function getBrowser(): Browser
    {
        return $this->client;
    }

    /**
     * Get the last error that occurred
     *
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->error;
    }

    /**
     * Extract player URL from video HTML
     * 
     * @param string $video_html HTML content of the YouTube video page
     * @return string|null The player URL or null if not found
     */
    public function getPlayerUrl(string $video_html): ?string
    {
        $player_url = null;

        // check what player version that video is using
        if (preg_match('@<script\s*src="([^"]+player[^"]+js)@', $video_html, $matches)) {
            $player_url = $matches[1];

            // relative protocol?
            if (strpos($player_url, '//') === 0) {
                $player_url = 'https://' . substr($player_url, 2);
            } elseif (strpos($player_url, '/') === 0) {
                // relative path?
                $player_url = 'https://www.youtube.com' . $player_url;
            }
        }

        return $player_url;
    }

    /**
     * Get player JavaScript code from URL
     *
     * @param string $player_url URL of the player JavaScript file
     * @return string|null The player code or null if not retrieved
     */
    public function getPlayerCode(string $player_url): ?string
    {
        $contents = $this->client->getCached($player_url);
        return $contents;
    }

    /**
     * Extract YouTube video ID from any piece of text
     *
     * @param string $str Input string that may contain a YouTube video ID
     * @return string|false The extracted video ID or false if not found
     */
    public function extractVideoId(string $str)
    {
        if (preg_match('/[a-z0-9_-]{11}/i', $str, $matches)) {
            return $matches[0];
        }

        return false;
    }

    /**
     * Select specific video formats based on selector criteria
     *
     * @param array $links Array of video links with format information
     * @param string $selector Format selector (e.g., mp4, 360, etc.)
     * @return array Selected video links matching the criteria
     */
    private function selectFirst(array $links, string $selector): array
    {
        $result = array();
        $formats = preg_split('/\s*,\s*/', $selector);

        // has to be in this order
        foreach ($formats as $f) {

            foreach ($links as $l) {

                if (stripos($l['format'], $f) !== false || $f == 'any') {
                    $result[] = $l;
                }
            }
        }

        return $result;
    }

    /**
     * Get video info from YouTube (currently not implemented)
     *
     * @param string $url Video URL
     * @return void
     */
    public function getVideoInfo(string $url): void
    {
        // $this->client->get("https://www.youtube.com/get_video_info?el=embedded&eurl=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3D" . urlencode($video_id) . "&video_id={$video_id}");
    }

    /**
     * Get HTML content of the YouTube video page
     *
     * @param string $url Video URL
     * @return string HTML content of the page
     */
    public function getPageHtml(string $url): string
    {
        $video_id = $this->extractVideoId($url);
        return $this->client->get("https://www.youtube.com/watch?v={$video_id}");
    }

    /**
     * Extract player response from page HTML
     *
     * @param string $page_html HTML content of the YouTube video page
     * @return array|null The player response data or null if not found
     */
    public function getPlayerResponse(string $page_html): ?array
    {
        if (preg_match('/player_response":"(.*?)","/', $page_html, $matches)) {
            $match = stripslashes($matches[1]);

            $ret = json_decode($match, true);
            return $ret;
        }

        return null;
    }

    // redirector.googlevideo.com
    //$url = preg_replace('@(\/\/)[^\.]+(\.googlevideo\.com)@', '$1redirector$2', $url);
    /**
     * Parse player response to extract download links
     *
     * @param array $player_response Player response data from YouTube
     * @param string $js_code JavaScript code for signature deciphering
     * @return array|null Array of download links or null if parsing failed
     */
    public function parsePlayerResponse(array $player_response, string $js_code): ?array
    {
        $parser = new Parser();

        try {
            $formats = $player_response['streamingData']['formats'];
            $adaptiveFormats = $player_response['streamingData']['adaptiveFormats'];

            if (!is_array($formats)) {
                $formats = array();
            }

            if (!is_array($adaptiveFormats)) {
                $adaptiveFormats = array();
            }

            $formats_combined = array_merge($formats, $adaptiveFormats);

            // final response
            $return = array();

            foreach ($formats_combined as $item) {
                $cipher = isset($item['cipher']) ? $item['cipher'] : '';
                $itag = $item['itag'];

                // some videos do not need to be decrypted!
                if (isset($item['url'])) {

                    $return[] = array(
                        'url' => $item['url'],
                        'itag' => $itag,
                        'format' => $parser->parseItagInfo($itag)
                    );

                    continue;
                }

                parse_str($cipher, $result);

                $url = $result['url'];
                $sp = $result['sp']; // typically 'sig'
                $signature = $result['s'];

                $decoded_signature = (new SignatureDecoder())->decode($signature, $js_code);

                // redirector.googlevideo.com
                //$url = preg_replace('@(\/\/)[^\.]+(\.googlevideo\.com)@', '$1redirector$2', $url);
                $return[] = array(
                    'url' => $url . '&' . $sp . '=' . $decoded_signature,
                    'itag' => $itag,
                    'format' => $parser->parseItagInfo($itag)
                );
            }

            return $return;

        } catch (\Exception $exception) {
            // do nothing
        } catch (\Throwable $throwable) {
            // do nothing
        }

        return null;
    }

    /**
     * Get download links for a YouTube video
     *
     * @param string $video_id YouTube video ID
     * @param string|bool $selector Format selector (e.g., mp4, 360, etc.) or false for all formats
     * @return array Array of download links
     */
    public function getDownloadLinks(string $video_id, $selector = false): array
    {
        $this->error = null;

        $page_html = $this->getPageHtml($video_id);

        if (strpos($page_html, 'We have been receiving a large volume of requests') !== false ||
            strpos($page_html, 'systems have detected unusual traffic') !== false) {

            $this->error = 'HTTP 429: Too many requests.';

            return array();
        }

        // get JSON encoded parameters that appear on video pages
        $json = $this->getPlayerResponse($page_html);

        // get player.js location that holds signature function
        $url = $this->getPlayerUrl($page_html);
        $js = $this->getPlayerCode($url);

        $result = $this->parsePlayerResponse($json, $js);

        // if error happens
        if (!is_array($result)) {
            return array();
        }

        // do we want all links or just select few?
        if ($selector) {
            return $this->selectFirst($result, $selector);
        }

        return $result;
    }
}
