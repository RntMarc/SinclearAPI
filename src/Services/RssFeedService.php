<?php

namespace Sinclear\Api\Services;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

final readonly class RssFeedService
{
    public function __construct(
        private ClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    /** @param array<array{name: string, url: string, itemsPerPage: int}> $sources */
    public function fetchAll(array $sources, int $maxAgeDays = 7): array
    {
        $articles = [];
        $cutoff = new \DateTimeImmutable("-{$maxAgeDays} days");

        foreach ($sources as $source) {
            try {
                $feedArticles = $this->fetchSource($source, $cutoff);
                $articles = [...$articles, ...$feedArticles];
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to fetch RSS source', [
                    'source' => $source['name'] ?? 'unknown',
                    'url' => $source['url'] ?? '',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $articles;
    }

    /** @param array{name: string, url: string, itemsPerPage: int} $source */
    private function fetchSource(array $source, \DateTimeImmutable $cutoff): array
    {
        $response = $this->httpClient->request('GET', $source['url'], [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml'],
        ]);

        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        $xml = new SimpleXMLElement($body);

        if ($xml->getName() === 'rss') {
            return $this->parseRss($xml, $source, $cutoff);
        }
        if ($xml->getName() === 'feed') {
            return $this->parseAtom($xml, $source, $cutoff);
        }

        $this->logger->warning('Unknown feed format', ['source' => $source['name']]);
        return [];
    }

    /** @param array{name: string, itemsPerPage: int} $source */
    private function parseRss(SimpleXMLElement $xml, array $source, \DateTimeImmutable $cutoff): array
    {
        $items = $xml->channel->item ?? [];
        $articles = [];
        $maxItems = (int) ($source['itemsPerPage'] ?? 10);

        foreach ($items as $item) {
            if (count($articles) >= $maxItems) {
                break;
            }

            $pubDate = $this->parseDate((string) $item->pubDate);
            if ($pubDate !== null && $pubDate < $cutoff) {
                continue;
            }

            $link = (string) $item->link;
            if ($link === '') {
                continue;
            }

            $articles[] = [
                'title' => trim((string) $item->title) ?: '(kein Titel)',
                'url' => $link,
                'sourceName' => $source['name'],
                'sourceIcon' => null,
                'publishedAt' => $pubDate?->format('Y-m-d\TH:i:s.v\Z'),
            ];
        }

        return $articles;
    }

    /** @param array{name: string, itemsPerPage: int} $source */
    private function parseAtom(SimpleXMLElement $xml, array $source, \DateTimeImmutable $cutoff): array
    {
        $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
        $entries = $xml->xpath('//atom:entry') ?: [];
        $articles = [];
        $maxItems = (int) ($source['itemsPerPage'] ?? 10);

        foreach ($entries as $entry) {
            if (count($articles) >= $maxItems) {
                break;
            }

            $published = $this->parseDate((string) $entry->published);
            if ($published !== null && $published < $cutoff) {
                continue;
            }

            $link = '';
            foreach ($entry->link as $l) {
                $attrs = $l->attributes();
                if ((string) $attrs['rel'] === 'alternate' || (string) $attrs['rel'] === '') {
                    $link = (string) $attrs['href'];
                    if ($link !== '') {
                        break;
                    }
                }
            }
            if ($link === '') {
                continue;
            }

            $articles[] = [
                'title' => trim((string) $entry->title) ?: '(kein Titel)',
                'url' => $link,
                'sourceName' => $source['name'],
                'sourceIcon' => null,
                'publishedAt' => $published?->format('Y-m-d\TH:i:s.v\Z'),
            ];
        }

        return $articles;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $formats = [
            \DateTimeInterface::RFC2822,
            \DateTimeInterface::RFC3339,
            'Y-m-d\TH:i:s.v\Z',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s.vP',
            'Y-m-d\TH:i:s',
            'D, d M Y H:i:s O',
            'Y-m-d',
        ];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt !== false) {
                return $dt;
            }
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
