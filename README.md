# omnilab/ncbi

NCBI Gene and NCBI Taxonomy for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a
gene by its GeneID - its symbol, name, type, organism, chromosome, cytogenetic and genomic
locations, summary, and its UniProt and Ensembl ids for the Collector to follow - and a taxon by
its id or a name - its scientific name, rank, common name, basionym, lineage by rank, and its GBIF
key; Entrez's search of either. Through NCBI Datasets v2 for the records and E-utilities for the
search, no key (an API key for ten calls a second).

```php
$ncbi = (new NcbiSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$hbb = $ncbi->entity('3043');                    // or 'gene:3043', Identifier::of(Scheme::NCBI_GENE, '3043')
$hbb->label;                                     // HBB
$hbb->value('gene_name');                        // hemoglobin subunit beta
$hbb->value('map_location');                     // 11p15.4
$hbb->identifier(Scheme::UNIPROT);               // P68871
$lion = $ncbi->entity('txid9689');               // or 'taxonomy:9689', 'Panthera leo', 'lion'
$lion->value('lineage');                         // Eukaryota > Metazoa > Chordata > Mammalia > Carnivora > Felidae > Panthera
$lion->identifier(Scheme::GBIF);                 // 5219404
$ncbi->search(new Query('HBB AND human[orgn]', limit: 10));
$ncbi->search(new Query('Panthera[subtree]', Domain::TAXONOMY));
```

```yaml
omnilab:
    sources:
        ncbi:
            factory: ncbi
            options:
                mailto: 'support@glitchr.io'   # E-utilities' "email" and the User-Agent: whom NCBI writes to before it blocks a caller
                api_key: '%env(NCBI_API_KEY)%' # optional: ten calls a second instead of three
                links: true                    # read a taxon's external links (one call more): its GBIF key
```

With UniProt and Wikidata configured too, the `Collector` follows a protein to its gene, and
NCBI's gene gives the UniProt accession back; with GBIF, a species reaches NCBI Taxonomy by the
NCBI id GBIF keeps for it.

## Terms of use

NCBI asks every client of E-utilities to send `tool` and `email` (the source sends `omnilab` and
the `mailto` option) and allows three calls a second without a key, ten with one; NCBI Datasets
allows five, ten with a key (its answers say so in `X-RateLimit-Limit`). The source leaves 0.34 s
between two calls without a key, 0.1 s with one (`throttle: auto`). An address that calls faster
is refused, and an address NCBI blocks is served again once its `tool` and `email` are registered
with NCBI. NCBI places no restriction on the use of its data, though submitters may hold rights on
theirs: see [NCBI's policies](https://www.ncbi.nlm.nih.gov/home/about/policies/).

See [docs/](docs/index.md) for what is read, how it maps, and what NCBI does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NCBI. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
