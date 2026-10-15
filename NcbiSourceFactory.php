<?php

namespace Omnilab\Ncbi;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * NCBI Gene and NCBI Taxonomy, through NCBI Datasets v2 and E-utilities -
 * no key needed, an API key for ten calls a second instead of three.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # E-utilities' "email" and the User-Agent: whom NCBI writes to before it blocks a caller
 *     api_key: '...'                   # an NCBI API key (ncbi.nlm.nih.gov, account settings)
 *     tool: 'omnilab'                  # E-utilities' "tool": the name NCBI knows the application by
 *     links: true                      # read a taxon's external links (one call more): its GBIF key
 *     synonyms: 20                     # how many of a gene's other names to keep (0: none)
 *     throttle: auto                   # seconds between two calls; auto: 0.34 without a key, 0.1 with one
 *     base_uri: 'https://api.ncbi.nlm.nih.gov/datasets/v2/'
 *     eutils_uri: 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/'
 */
final class NcbiSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'ncbi',
            'omnilab.required_options' => [],
            'api_key' => null,
            'tool' => 'omnilab',
            'links' => true,
            'synonyms' => 20,
            // "auto": NCBI's, with or without a key.
            'throttle' => 'auto',
            'base_uri' => NcbiSource::BASE_URI,
            'eutils_uri' => NcbiSource::EUTILS_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $key = $c->get('api_key');
        $throttle = $c->get('throttle');

        return new NcbiSource(
            $this->http ?? HttpClient::create(),
            null === $key || '' === $key ? null : (string) $key,
            $c->get('mailto') ?: null,
            (string) $c->get('tool'),
            (bool) $c->get('links'),
            (int) $c->get('synonyms'),
            (string) $c->get('base_uri', NcbiSource::BASE_URI),
            (string) $c->get('eutils_uri', NcbiSource::EUTILS_URI),
            ['User-Agent' => self::userAgent($c)],
            'auto' === $throttle ? null : (float) $throttle,
        );
    }
}
