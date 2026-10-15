<?php

namespace Omnilab\Ncbi\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Ncbi\NcbiSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded on 2026-10-15 from NCBI Datasets v2
 * (api.ncbi.nlm.nih.gov/datasets/v2) - gene-3043.json (HBB, human),
 * gene-unknown.json (a GeneID NCBI does not know: {}), taxon-9689.json and
 * taxon-lion.json (Panthera leo, by its id and by its common name),
 * links-9689.json (its external links), taxon-unknown.json (an "errors"
 * report), genes-page.json and taxa-page.json (a search page's records; the
 * genes' GO annotations cut) - and from E-utilities' esearch
 * (eutils.ncbi.nlm.nih.gov): esearch-gene.json and esearch-gene-2.json
 * ("HBB AND human[orgn]", two pages of three), esearch-taxonomy.json
 * ("Panthera[subtree]").
 */
final class NcbiSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    /** @var list<array<string, list<string>>> */
    private array $headers = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $request) use ($response): MockResponse {
            $host = (string) parse_url($url, \PHP_URL_HOST);
            $path = rawurldecode((string) parse_url($url, \PHP_URL_PATH));
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = 'eutils.ncbi.nlm.nih.gov' === $host ? 'esearch '.$query['db'].' '.$query['term'].(isset($query['retstart']) ? ' from '.$query['retstart'] : '') : substr($path, \strlen('/datasets/v2/'));
            $this->headers[] = $request['normalized_headers'] + ['query' => [http_build_query($query)]];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                'eutils.ncbi.nlm.nih.gov' === $host => match ($query['db'].' '.($query['retstart'] ?? 0)) {
                    'gene 0' => 'esearch-gene.json',
                    'gene 3' => 'esearch-gene-2.json',
                    default => 'esearch-taxonomy.json',
                },
                str_ends_with($path, '/gene/id/3043/dataset_report') => 'gene-3043.json',
                str_ends_with($path, '/gene/id/7157,1080,5728/dataset_report') => 'genes-page.json',
                str_ends_with($path, '/taxonomy/taxon/9689/dataset_report') => 'taxon-9689.json',
                str_ends_with($path, '/taxonomy/taxon/lion/dataset_report') => 'taxon-lion.json',
                str_ends_with($path, '/taxonomy/taxon/9689/links') => 'links-9689.json',
                str_ends_with($path, '/taxonomy/taxon/3135897,3113013,2991501/dataset_report') => 'taxa-page.json',
                str_contains($path, '/taxonomy/') => 'taxon-unknown.json',
                default => 'gene-unknown.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });

        return (new NcbiSourceFactory($http))->create($options + ['mailto' => 'support@glitchr.io', 'throttle' => 0]);
    }

    public function testAGeneByItsGeneIdInOneCall(): void
    {
        $hbb = $this->source()->entity(Identifier::of(Scheme::NCBI_GENE, '3043'));

        self::assertSame('ncbi.gene:3043', $hbb->id->key());
        self::assertSame('HBB', $hbb->label);
        self::assertSame(Domain::BIOLOGY, $hbb->domain);
        self::assertSame('gene', $hbb->kind);
        self::assertStringStartsWith('The alpha (HBA) and beta (HBB) loci determine the structure of the 2 types of polypeptide chains in adult hemoglobin', $hbb->description);
        self::assertSame('hemoglobin subunit beta', $hbb->value('gene_name'));
        self::assertSame('protein-coding', $hbb->value('gene_type'));
        self::assertSame('Homo sapiens', $hbb->value('organism'));
        self::assertSame('11', $hbb->value('chromosome'));
        self::assertSame('11p15.4', $hbb->value('map_location'));
        self::assertSame(['11:5225464-5227071 (minus strand), NC_000011.10, GRCh38.p14', '11:5284832-5286439 (minus strand), NC_060935.1, T2T-CHM13v2.0'], array_map('strval', $hbb->properties('genomic_location')), 'the reference assembly first');
        self::assertSame(['hemoglobin subunit beta', 'ECYT6', 'CD113t-C', 'beta-globin', 'Hb Monza protein', 'beta globin chain', 'hemoglobin beta subunit', 'hemoglobin, beta'], $hbb->synonyms);
        self::assertSame(['ncbi.gene:3043', 'uniprot:P68871', 'ensembl:ENSG00000244734'], $hbb->identifiers()->keys());
        self::assertSame(['ncbi.taxon:9606'], $hbb->related('organism')->keys(), 'its organism is related, not one of its identifiers');
        self::assertSame('https://www.ncbi.nlm.nih.gov/gene/3043', $hbb->url);
        self::assertSame('ncbi', $hbb->property('gene')->source);
        self::assertSame(['gene/id/3043/dataset_report'], $this->calls);
    }

    public function testAGeneIdSpelledAnyWay(): void
    {
        foreach (['3043', 'gene:3043', 'ncbigene:3043', 'https://www.ncbi.nlm.nih.gov/gene/3043'] as $id) {
            self::assertSame('ncbi.gene:3043', $this->source()->entity($id)?->id->key(), $id);
        }
        self::assertNull($this->source()->entity('999999999'), 'Datasets answers {} for a GeneID it does not know');
    }

    public function testATaxonItsLineageAndItsGbifKey(): void
    {
        $lion = $this->source()->entity(Identifier::of(Scheme::NCBI_TAXON, '9689'));

        self::assertSame('ncbi.taxon:9689', $lion->id->key());
        self::assertSame('Panthera leo', $lion->label);
        self::assertSame(Domain::TAXONOMY, $lion->domain);
        self::assertSame('species', $lion->kind);
        self::assertSame(['lion', 'Felis leo'], $lion->synonyms);
        self::assertSame('Panthera leo', $lion->value('taxon_name'));
        self::assertSame('lion', $lion->value('common_name'));
        self::assertSame('carnivores', $lion->value('group'));
        self::assertSame(['Metazoa', 'Chordata', 'Mammalia', 'Carnivora', 'Felidae', 'Panthera'], [$lion->value('kingdom'), $lion->value('phylum'), $lion->value('class'), $lion->value('order'), $lion->value('family'), $lion->value('genus')]);
        self::assertSame('Eukaryota > Metazoa > Chordata > Mammalia > Carnivora > Felidae > Panthera', $lion->value('lineage'));
        self::assertNull($lion->value('species'), 'its own rank is not its lineage');
        self::assertSame('Felis leo Linnaeus, 1758', $lion->value('basionym'));
        self::assertSame(['ncbi.taxon:9689', 'gbif:5219404'], $lion->identifiers()->keys(), 'GBIF\'s page among its links: a legacy key');
        self::assertSame(['ncbi.taxon:9688'], $lion->related('parent')->keys());
        self::assertSame(['taxonomy/taxon/9689/dataset_report', 'taxonomy/taxon/9689/links'], $this->calls);

        $this->calls = [];
        self::assertSame(['ncbi.taxon:9689'], $this->source(null, ['links' => false])->entity('txid9689')?->identifiers()->keys());
        self::assertSame(['taxonomy/taxon/9689/dataset_report'], $this->calls, 'no links asked');
    }

    public function testATaxonByAName(): void
    {
        self::assertSame('ncbi.taxon:9689', $this->source()->entity('lion')?->id->key());
        self::assertNull($this->source()->entity('nosuchtaxonxyz'), 'Datasets\' errors report');
        self::assertNull($this->source()->entity('taxonomy:999999999'));
    }

    public function testWhatItDoesNotReadIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::of(Scheme::UNIPROT, 'P68871'), Identifier::of(Scheme::GBIF, '5219404'), 'CHEBI:27732', 'Q140', 'ENSG00000244734'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchGenesThenTaxaEntrezThenDatasets(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('HBB AND human[orgn]', limit: 3));

        self::assertSame(['TP53', 'CFTR', 'PTEN'], array_map(static fn ($e) => $e->label, $page->items), 'Entrez\'s order');
        self::assertSame(225, $page->total);
        self::assertSame('3', $page->next);
        self::assertSame(['esearch gene HBB AND human[orgn]', 'gene/id/7157,1080,5728/dataset_report'], $this->calls);
        $source->search(new Query('HBB AND human[orgn]', limit: 3, cursor: $page->next));
        self::assertSame('esearch gene HBB AND human[orgn] from 3', $this->calls[2]);

        $this->calls = [];
        $taxa = $source->search(new Query('Panthera[subtree]', Domain::TAXONOMY, limit: 3));
        self::assertSame(['Panthera pardus nimr', 'Panthera leo melanochaita', 'Panthera pardus adersi'], array_map(static fn ($e) => $e->label, $taxa->items));
        self::assertSame([33, '3'], [$taxa->total, $taxa->next]);
        self::assertSame(['esearch taxonomy Panthera[subtree]', 'taxonomy/taxon/3135897,3113013,2991501/dataset_report'], $this->calls, 'no links for a search page');

        self::assertSame([], $source->search(new Query('HBB', Domain::CHEMISTRY))->items, 'nothing of another domain');
        self::assertCount(2, $this->calls, 'and no call for it');
    }

    public function testItSaysWhoCallsAndTakesAKey(): void
    {
        $source = $this->source(null, ['api_key' => 'abc123']);
        $source->search(new Query('HBB AND human[orgn]', limit: 3));

        self::assertStringContainsString('tool=omnilab&email=support%40glitchr.io&api_key=abc123', $this->headers[0]['query'][0], 'E-utilities\' parameters');
        self::assertSame(['api-key: abc123'], $this->headers[1]['api-key'], 'Datasets\' header');
        self::assertStringContainsString('mailto:support@glitchr.io', $this->headers[1]['user-agent'][0]);
    }

    public function testItLeavesNcbisIntervalBetweenTwoCalls(): void
    {
        $source = (new NcbiSourceFactory(new MockHttpClient(static fn () => new MockResponse('{}'))))->create();
        $start = microtime(true);
        $source->entity('3043');
        $source->entity('3043');
        self::assertGreaterThanOrEqual(0.33, microtime(true) - $start, 'three calls a second without a key');

        $keyed = (new NcbiSourceFactory(new MockHttpClient(static fn () => new MockResponse('{}'))))->create(['api_key' => 'abc123']);
        $start = microtime(true);
        $keyed->entity('3043');
        $keyed->entity('3043');
        self::assertLessThan(0.3, microtime(true) - $start, 'ten with one');
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse('{"error":{"status":500}}', ['http_code' => 500]))->entity('3043');
            self::fail('a 500 is an UnavailableException');
        } catch (UnavailableException $e) {
            self::assertSame(500, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('{"error":"API rate limit exceeded"}', ['http_code' => 429]))->entity('3043');
    }

    public function testWhatItCanDo(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }
}
