<?php

namespace Omnilab\Ncbi;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * NCBI Gene and NCBI Taxonomy: a gene by its GeneID - its symbol, name,
 * organism, place on the genome, summary, its UniProt and Ensembl ids - and
 * a taxon by its id or a name - its scientific name, rank, lineage, common
 * name and GBIF key; a search of either.
 *
 * The records come from NCBI Datasets v2 (api.ncbi.nlm.nih.gov/datasets/v2),
 * whose reports carry what E-utilities' summaries do not (a gene's UniProt
 * and Ensembl ids, a taxon's ranked lineage and its links to GBIF); the
 * search from E-utilities' esearch (eutils.ncbi.nlm.nih.gov), Entrez's text
 * search, which Datasets has not. An API key raises NCBI's limit from three
 * calls a second (E-utilities; five for Datasets) to ten: the source leaves
 * 0.34 s between two without one, 0.1 s with one.
 */
final class NcbiSource extends HttpSource
{
    public const BASE_URI = 'https://api.ncbi.nlm.nih.gov/datasets/v2/';
    public const EUTILS_URI = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';

    /** The most records one search page is asked for. */
    public const MAX_PER_PAGE = 100;

    /** The ranks of a Datasets classification, from the root down. */
    private const RANKS = ['domain', 'realm', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'];

    /**
     * @param string|null $apiKey an NCBI API key (account settings at ncbi.nlm.nih.gov): ten calls a second instead of three
     * @param string|null $email  sent to E-utilities with the tool's name, as NCBI asks: whom it writes to before it blocks a caller
     * @param bool        $links  whether a taxon's external links are read (one call more): its GBIF key
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly ?string $apiKey = null,
        private readonly ?string $email = null,
        private readonly string $tool = 'omnilab',
        private readonly bool $links = true,
        private readonly int $synonyms = 20,
        string $baseUri = self::BASE_URI,
        private readonly string $eutilsUri = self::EUTILS_URI,
        array $headers = [],
        ?float $throttle = null,
    ) {
        parent::__construct($http, $baseUri, $headers + array_filter(['api-key' => $apiKey]), $throttle ?? (null === $apiKey ? 0.34 : 0.1));
    }

    public function getName(): string
    {
        return 'ncbi';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    /**
     * A gene by its GeneID (an Identifier of ncbi.gene, "gene:3043",
     * "ncbigene:3043", its page, or a bare number), a taxon by its id (an
     * Identifier of ncbi.taxon, "txid9689", "taxonomy:9689", its page) or by
     * a name, scientific or common ("Panthera leo", "lion").
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $schemes = [Scheme::NCBI_GENE, Scheme::NCBI_TAXON];
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, $schemes, true) ? $id : throw NotSupportedException::identifier('ncbi', (string) $id);
        } else {
            $text = trim($id);
            // "txid9689", NCBI's own spelling of a taxon; a bare number is a gene.
            $identifier = Identifier::parse($text) ?? (preg_match('~^txid\s*\d+$~i', $text) ? Identifier::tryOf(Scheme::NCBI_TAXON, str_replace(' ', '', $text)) : Identifier::tryOf(Scheme::NCBI_GENE, $text));
            if (null !== $identifier && !\in_array($identifier->scheme, $schemes, true)) {
                throw NotSupportedException::identifier('ncbi', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : ($this->taxa([$text])[0] ?? null);
            }
        }

        return Scheme::NCBI_GENE === $identifier->scheme ? ($this->genes([$identifier->value])[0] ?? null) : ($this->taxa([$identifier->value])[0] ?? null);
    }

    /**
     * Entrez's search (esearch) of NCBI Gene, or of NCBI Taxonomy for the
     * taxonomy domain, the text in Entrez's syntax ("HBB AND human[orgn]",
     * "Panthera[subtree]"), in Entrez's order; then the page's records from
     * Datasets, in one call.
     */
    public function search(Query $query): Page
    {
        $database = match ($query->domain) {
            null, Domain::BIOLOGY => 'gene',
            Domain::TAXONOMY => 'taxonomy',
            default => null,
        };
        $text = trim($query->text);
        if (null === $database || '' === $text) {
            return new Page();
        }
        $limit = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $start = max(0, (int) $query->cursor);
        $data = $this->getJson($this->eutilsUri.'esearch.fcgi', [
            'db' => $database,
            'term' => $text,
            'retmode' => 'json',
            'retmax' => $limit,
            'retstart' => $start ?: null,
            ...$this->eutilsIdentity(),
        ]) ?? [];
        if (isset($data['esearchresult']['ERROR']) || isset($data['ERROR'])) {
            throw new ProviderException('ncbi', (string) ($data['esearchresult']['ERROR'] ?? $data['ERROR']));
        }
        $ids = array_values(array_map('strval', $data['esearchresult']['idlist'] ?? []));
        $total = (int) ($data['esearchresult']['count'] ?? 0);

        return new Page(
            $ids ? ('gene' === $database ? $this->genes($ids) : $this->taxa($ids, false)) : [],
            $start + \count($ids) < $total && $ids ? (string) ($start + \count($ids)) : null,
            $total,
        );
    }

    /**
     * Gene reports by GeneID, in the order asked, the unknown left out.
     *
     * @param list<string> $ids
     *
     * @return list<Entity>
     */
    private function genes(array $ids): array
    {
        $data = $this->getJson('gene/id/'.implode(',', array_map('rawurlencode', $ids)).'/dataset_report') ?? [];
        $byId = [];
        foreach ($data['reports'] ?? [] as $report) {
            if (isset($report['gene']['gene_id'])) {
                $byId[(string) $report['gene']['gene_id']] = $this->gene($report['gene']);
            }
        }

        return array_values(array_filter(array_map(static fn (string $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * Taxonomy reports by id or name, in the order asked, the unknown left
     * out (Datasets names them in an "errors" report); then, for each, its
     * external links when the source reads them (not for a search page: a
     * call each).
     *
     * @param list<string> $taxa
     *
     * @return list<Entity>
     */
    private function taxa(array $taxa, bool $links = true): array
    {
        $data = $this->getJson('taxonomy/taxon/'.implode(',', array_map('rawurlencode', $taxa)).'/dataset_report') ?? [];
        $byQuery = [];
        foreach ($data['reports'] ?? [] as $report) {
            if (isset($report['taxonomy']['tax_id'])) {
                foreach ($report['query'] ?? [] as $asked) {
                    $byQuery[mb_strtolower((string) $asked)] = $report['taxonomy'];
                }
            }
        }
        $entities = [];
        foreach ($taxa as $asked) {
            $taxonomy = $byQuery[mb_strtolower($asked)] ?? null;
            if (null !== $taxonomy) {
                $entities[] = $this->taxon($taxonomy, $this->links && $links ? $this->getJson('taxonomy/taxon/'.$taxonomy['tax_id'].'/links') : null);
            }
        }

        return $entities;
    }

    /** @param array<string, mixed> $gene a Datasets gene report */
    private function gene(array $gene): Entity
    {
        $id = (string) $gene['gene_id'];
        $symbol = self::text($gene['symbol'] ?? null) ?? $id;
        $name = self::text($gene['description'] ?? null);

        $properties = [];
        $add = static function (string $key, string $label, string|int|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'ncbi');
            }
        };
        $add('gene', 'Gene symbol', $symbol);
        $add('gene_name', 'Gene name', $name);
        $add('gene_type', 'Gene type', isset($gene['type']) ? strtolower(str_replace('_', '-', (string) $gene['type'])) : null);
        $add('organism', 'Organism', self::text($gene['taxname'] ?? null));
        $add('organism_common_name', 'Organism (common name)', self::text($gene['common_name'] ?? null));
        foreach ($gene['chromosomes'] ?? [] as $chromosome) {
            $add('chromosome', 'Chromosome', self::text((string) $chromosome));
        }
        foreach ($gene['map_locations'] ?? [] as $location) {
            $add('map_location', 'Cytogenetic location', self::text($location['map_value'] ?? null));
        }
        // The first annotation is the reference assembly's (GRCh38 for a human gene); each its own line.
        foreach ($gene['annotations'] ?? [] as $annotation) {
            foreach ($annotation['genomic_locations'] ?? [] as $location) {
                $range = $location['genomic_range'] ?? [];
                if (isset($range['begin'], $range['end'])) {
                    $add('genomic_location', 'Genomic location', \sprintf('%s:%s-%s (%s strand), %s, %s',
                        $location['sequence_name'] ?? '?', $range['begin'], $range['end'], strtolower((string) ($range['orientation'] ?? '?')),
                        $location['genomic_accession_version'] ?? '?', $annotation['assembly_name'] ?? $annotation['assembly_accession'] ?? '?'));
                }
            }
        }

        $synonyms = [];
        foreach ([$name, ...($gene['synonyms'] ?? []), ...($gene['alternate_names'] ?? [])] as $synonym) {
            if (null !== ($synonym = self::text($synonym)) && mb_strtolower($synonym) !== mb_strtolower($symbol)) {
                $synonyms[mb_strtolower($synonym)] ??= $synonym;
            }
        }
        $identifiers = [];
        foreach ($gene['swiss_prot_accessions'] ?? [] as $accession) {
            $identifiers[] = Identifier::tryOf(Scheme::UNIPROT, $accession);
        }
        foreach ($gene['ensembl_gene_ids'] ?? [] as $ensembl) {
            $identifiers[] = Identifier::tryOf(Scheme::ENSEMBL, $ensembl);
        }
        $taxon = Identifier::tryOf(Scheme::NCBI_TAXON, $gene['tax_id'] ?? null);

        return new Entity(
            id: Identifier::of(Scheme::NCBI_GENE, $id),
            label: $symbol,
            domain: Domain::BIOLOGY,
            kind: 'gene',
            synonyms: \array_slice(array_values($synonyms), 0, $this->synonyms),
            description: self::text($gene['summary'][0]['description'] ?? null),
            properties: $properties,
            identifiers: new Identifiers($identifiers),
            url: 'https://www.ncbi.nlm.nih.gov/gene/'.$id,
            source: 'ncbi',
            related: null === $taxon ? [] : ['organism' => Identifiers::of($taxon)],
        );
    }

    /**
     * @param array<string, mixed>      $taxonomy a Datasets taxonomy report
     * @param array<string, mixed>|null $links    its external links
     */
    private function taxon(array $taxonomy, ?array $links): Entity
    {
        $id = (string) $taxonomy['tax_id'];
        $current = $taxonomy['current_scientific_name'] ?? [];
        $name = self::text($current['name'] ?? null) ?? $id;
        $rank = isset($taxonomy['rank']) ? strtolower(str_replace('_', ' ', (string) $taxonomy['rank'])) : null;

        $properties = [];
        $add = static function (string $key, string $label, ?string $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'ncbi');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_authority', 'Authority', self::text($current['authority'] ?? null));
        $add('taxon_rank', 'Taxon rank', $rank);
        $add('common_name', 'Common name', self::text($taxonomy['curator_common_name'] ?? $taxonomy['genbank_common_name'] ?? null));
        $add('group', 'Group (NCBI\'s BLAST name)', self::text($taxonomy['group_name'] ?? null));
        $classification = $taxonomy['classification'] ?? [];
        $lineage = [];
        foreach (self::RANKS as $level) {
            $member = self::text($classification[$level]['name'] ?? null);
            if (null !== $member && (string) ($classification[$level]['id'] ?? '') !== $id) {
                $add($level, ucfirst($level), $member);
                $lineage[] = $member;
            }
        }
        $add('lineage', 'Lineage (main ranks)', $lineage ? implode(' > ', $lineage) : null);
        $basionym = $current['basionym'] ?? null;
        $add('basionym', 'Basionym', \is_array($basionym) ? self::text(trim(($basionym['name'] ?? '').' '.($basionym['authority'] ?? ''))) : null);

        $synonyms = [];
        foreach ([$taxonomy['curator_common_name'] ?? null, $taxonomy['genbank_common_name'] ?? null, \is_array($basionym) ? ($basionym['name'] ?? null) : null] as $synonym) {
            if (null !== ($synonym = self::text($synonym)) && mb_strtolower($synonym) !== mb_strtolower($name)) {
                $synonyms[mb_strtolower($synonym)] ??= $synonym;
            }
        }
        $identifiers = [];
        foreach ($links ?? [] as $key => $link) {
            // The links Datasets keeps: GBIF's page (a legacy key: GBIF sends it to the new one), EOL, Wikipedia.
            if ('tax_id' !== $key && \is_string($link) && null !== ($identifier = Identifier::parse($link))) {
                $identifiers[] = $identifier;
            }
        }
        $parents = $taxonomy['parents'] ?? [];
        $parent = $parents ? Identifier::tryOf(Scheme::NCBI_TAXON, end($parents)) : null;

        return new Entity(
            id: Identifier::of(Scheme::NCBI_TAXON, $id),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: $rank,
            synonyms: array_values($synonyms),
            properties: $properties,
            identifiers: new Identifiers($identifiers),
            url: 'https://www.ncbi.nlm.nih.gov/Taxonomy/Browser/wwwtax.cgi?id='.$id,
            source: 'ncbi',
            related: null === $parent ? [] : ['parent' => Identifiers::of($parent)],
        );
    }

    /** @return array<string, string> the tool, the contact address and the key E-utilities take as parameters */
    private function eutilsIdentity(): array
    {
        return array_filter(['tool' => $this->tool, 'email' => $this->email, 'api_key' => $this->apiKey]);
    }
}
