<?php

declare(strict_types=1);

namespace App\Service\Extractor;

class SocialMediaExtractor implements ContactExtractorInterface
{
    private const SOCIAL_PATTERNS = [
        'facebook' => '/https?:\/\/(?:www\.)?facebook\.com\/(?!sharer|share|dialog|plugins)([a-zA-Z0-9._\-]+)\/?/i',
        'instagram' => '/https?:\/\/(?:www\.)?instagram\.com\/([a-zA-Z0-9._]+)\/?/i',
        'linkedin' => '/https?:\/\/(?:www\.)?linkedin\.com\/(company|in)\/([a-zA-Z0-9\-_]+)\/?/i',
        'twitter' => '/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/([a-zA-Z0-9_]+)\/?/i',
        'youtube' => '/https?:\/\/(?:www\.)?youtube\.com\/(channel|c|@|user)\/([a-zA-Z0-9\-_]+)\/?/i',
        'tiktok' => '/https?:\/\/(?:www\.)?tiktok\.com\/@([a-zA-Z0-9._]+)\/?/i',
        'pinterest' => '/https?:\/\/(?:www\.)?pinterest\.[a-z]+\/([a-zA-Z0-9_]+)\/?/i',
    ];

    // Profiles to ignore (share buttons, generic links)
    private const IGNORED_PROFILES = [
        'facebook' => ['sharer.php', 'share', 'dialog', 'plugins', 'home.php', 'pages', 'groups', 'events', 'watch', 'marketplace', 'gaming'],
        'twitter' => ['intent', 'share', 'home', 'search', 'explore', 'i', 'hashtag'],
        'instagram' => ['explore', 'p', 'reel', 'stories', 'tv', 'accounts'],
        'linkedin' => ['shareArticle', 'feed', 'jobs', 'messaging', 'notifications'],
        'youtube' => ['watch', 'results', 'feed', 'playlist', 'shorts'],
    ];

    /**
     * Extract social media links from HTML.
     *
     * @return array<string, string>
     */
    public function extract(string $html): array
    {
        $socialMedia = [];

        foreach (self::SOCIAL_PATTERNS as $platform => $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $url = $match[0];
                    $profile = $this->extractProfile($match, $platform);

                    if ($profile !== null && $this->isValidProfile($platform, $profile)) {
                        // Clean up the URL
                        $cleanUrl = $this->cleanUrl($url);
                        $socialMedia[$platform] = $cleanUrl;
                        break;  // Take first valid profile per platform
                    }
                }
            }
        }

        return $socialMedia;
    }

    /**
     * Extract profile identifier from regex match.
     *
     * @param array<int, string> $match
     */
    private function extractProfile(array $match, string $platform): ?string
    {
        return match ($platform) {
            'linkedin', 'youtube' => $match[2] ?? null,
            default => $match[1] ?? null,
        };
    }

    /**
     * Check if the profile is valid (not a share button or generic page).
     */
    private function isValidProfile(string $platform, string $profile): bool
    {
        $profile = strtolower($profile);

        // Check against ignored profiles
        $ignored = self::IGNORED_PROFILES[$platform] ?? [];
        foreach ($ignored as $ignoredProfile) {
            if ($profile === strtolower($ignoredProfile)) {
                return false;
            }
        }

        // Basic length validation
        if (strlen($profile) < 2) {
            return false;
        }

        return true;
    }

    /**
     * Clean up extracted URL.
     */
    private function cleanUrl(string $url): string
    {
        // Remove trailing slashes and query strings
        $url = preg_replace('/\?.*$/', '', $url);
        $url = rtrim($url, '/');

        // Ensure https
        if (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }

        return $url;
    }
}
