# omnilab/ncbi

NCBI Gene and NCBI Taxonomy, through NCBI Datasets v2 (`https://api.ncbi.nlm.nih.gov/datasets/v2/`)
and E-utilities (`https://eutils.ncbi.nlm.nih.gov/entrez/eutils/`), for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): genes by GeneID, taxa by id or
name - location, summary, lineage - with their UniProt, Ensembl and GBIF ids as cross-references,
as entities; a search in Entrez's syntax. No key needed.

## Installation

```sh
composer require omnilab/ncbi
```

```php
use Omnilab\Ncbi\NcbiSourceFactory;

$ncbi = (new NcbiSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, asked by NCBI
    'api_key' => $key,                 // optional
]);
```

With the Symfony bundle, the factory is registered as `ncbi` (see the core's docs).

## Credentials

None needed: E-utilities answer three calls a second without a key, Datasets five. An NCBI API
key (an NCBI account's settings, ncbi.nlm.nih.gov) raises both to ten: give it as `api_key` (sent
as `api_key` to E-utilities and `api-key` to Datasets). NCBI asks every E-utilities client for the
tool's name and an email: `tool` and `mailto` are sent.

## Options

The factory's options, with the defaults it applies (`NcbiSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `api_key` | `?string` | null | no | an NCBI API key: ten calls a second instead of three (five for Datasets) |
| `tool` | `string` | `omnilab` | no | E-utilities' `tool`: the name NCBI knows the application by |
| `links` | `bool` | `true` | no | `entity()` reads a taxon's external links (one call more): its GBIF key |
| `synonyms` | `int` | `20` | no | how many of a gene's other names to keep; 0: none |
| `eutils_uri` | `string` | `https://eutils.ncbi.nlm.nih.gov/entrez/eutils/` | no | E-utilities, for the search (`esearch.fcgi`) |
| `mailto` | `?string` | null | no | a contact address: E-utilities' `email`, and in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float\|string` | `auto` | no | seconds left between two calls; `auto`: 0.34 without a key, 0.1 with one |
| `base_uri` | `string` | `https://api.ncbi.nlm.nih.gov/datasets/v2/` | no | NCBI Datasets v2 |

## Capabilities

`capabilities()` answers `entity`, `search`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a GeneID (a bare number, `gene:`, `ncbigene:`, its page), a taxon id (`txid9689`, `taxonomy:`, an `Identifier`) or a taxon's scientific or common name; another scheme refused before any call |
| `search` | yes | Entrez's text search in NCBI Gene, or NCBI Taxonomy for `Domain::TAXONOMY`, its syntax and order, 100 a page at most, with a `total`; then one Datasets call for the page; another domain answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | yes | a gene's UniProt (Swiss-Prot) accessions and Ensembl ids; a taxon's GBIF key (`links`) |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API

Two of NCBI's, each for what it does best:

- **NCBI Datasets v2** (`https://api.ncbi.nlm.nih.gov/datasets/v2/`, OpenAPI 3) for the records.
  Its gene report carries what E-utilities' `esummary` does not: the gene's UniProt (Swiss-Prot)
  accessions and Ensembl ids, its genomic ranges on each annotated assembly; its taxonomy report
  gives the lineage by rank and the basionym; `taxonomy/taxon/{id}/links` the taxon's page at GBIF.
  Several records come in one call (`gene/id/7157,1080,5728/dataset_report`). The endpoints used are
  the `dataset_report` ones: `gene/id/{ids}` and `taxonomy/taxon/{ids}` alone are marked deprecated
  in the OpenAPI schema.
- **E-utilities' `esearch`** (`https://eutils.ncbi.nlm.nih.gov/entrez/eutils/`) for the search:
  Entrez's text search, its syntax and its order (`HBB AND human[orgn]`, `Panthera[subtree]`),
  which Datasets has not. Its ids are then read from Datasets, the page in one call.

`efetch` is not used: its gene and taxonomy records are XML, and what they hold more (the full
lineage with every rank, the gene's references) is not read here.

## Calls

| Method | Reads | NCBI |
|---|---|---|
| `entity()` | a GeneID (`3043`, `gene:3043`, `ncbigene:3043`, its page) | Datasets `gene/id/3043/dataset_report` |
| | a taxon id (`txid9689`, `taxonomy:9689`, `ncbitaxon:9689`, its page) | Datasets `taxonomy/taxon/9689/dataset_report`, then `taxonomy/taxon/9689/links` |
| | a name, scientific or common (`Panthera leo`, `lion`) | Datasets `taxonomy/taxon/{name}/dataset_report`, then its links |
| `search()` | `Query::$text` in Entrez's syntax; NCBI Gene, or NCBI Taxonomy for `Domain::TAXONOMY`; another domain answers nothing | `esearch.fcgi?db=gene` (or `taxonomy`)`&term=&retmax=&retstart=`, then Datasets for the page |

A bare number is a GeneID: a taxon needs its scheme (`txid9689`, an `Identifier`). An identifier of
another scheme (UniProt, Ensembl, GBIF...) is refused before any call; unknown is `null` (Datasets
answers `{}` for a GeneID it does not know, an `errors` report for a taxon). A name is looked up
as a taxon; a gene symbol is not a name here (`HBB` names a gene in hundreds of organisms): search
for it.

## What an entity holds

**A gene**: `id` (`ncbi.gene`), `label` (its symbol), `domain` biology, `kind` `gene`, `description`
(its summary, RefSeq's, with its attribution), `synonyms` (its full name, NCBI's other symbols and
names, 20 by default), `url` (its page), and:

| Key | Datasets | Value |
|---|---|---|
| `gene` | symbol | text |
| `gene_name` | description | text |
| `gene_type` | type, as `protein-coding`, `ncrna`... | text |
| `organism`, `organism_common_name` | taxname, common_name | text |
| `chromosome` | chromosomes | text, one each |
| `map_location` | map_locations (cytogenetic) | text |
| `genomic_location` | annotations: one per assembly, the reference first: `11:5225464-5227071 (minus strand), NC_000011.10, GRCh38.p14` | text |

`identifiers`: its UniProt (Swiss-Prot) accessions and its Ensembl genes. `related`: `organism`
(its NCBI taxon).

**A taxon**: `id` (`ncbi.taxon`), `label` (its scientific name), `domain` taxonomy, `kind` (its rank,
lower case: `species`, `genus`, `subspecies`), `synonyms` (its common names and its basionym),
`url` (its page in the Taxonomy Browser), and:

| Key | Datasets | Value |
|---|---|---|
| `taxon_name` | current_scientific_name.name (Wikidata's key for P225) | text |
| `taxon_authority` | current_scientific_name.authority, when given | text |
| `taxon_rank` | rank | text |
| `common_name` | curator_common_name (else genbank_common_name) | text |
| `group` | group_name, NCBI's BLAST name (`carnivores`) | text |
| `domain`, `realm`, `kingdom`, `phylum`, `class`, `order`, `family`, `genus`, `species` | classification: the taxon's ancestors at those ranks, its own rank left out | text |
| `lineage` | the same, from the root down: `Eukaryota > Metazoa > ... > Panthera` | text |
| `basionym` | the basionym and its authority | text |

`identifiers`: the GBIF key of its GBIF page, among Datasets' links (a legacy GBIF Backbone number:
`5219404` for the lion; GBIF sends its page to the new taxon). `related`: `parent` (the taxon above
it). A search result holds the same, without the links.

## What NCBI does not give here

No Wikidata item nor iNaturalist taxon: Datasets' links name GBIF, the Encyclopedia of Life and
Wikipedia only (Wikidata finds the taxon by its NCBI id, P685). Not read: a gene's GO annotations,
transcripts and proteins, orthologs, expression, references, and its HGNC and OMIM ids (schemes
the core has, which this source does not read: omnilab/hgnc gives them) and RefSeq's; a taxon's children, its genome and gene counts, its genetic
codes, its full lineage with the intermediate ranks (only their ids come, in `parents`). Not used:
`efetch`, `elink`, `esummary`, PubMed and the sequence databases.

## The Collector

Hemoglobin beta from UniProt reaches NCBI Gene by its GeneID (3043); *Panthera leo* from GBIF
reaches NCBI Taxonomy by the NCBI id GBIF keeps in its ENA checklist (9689). Both answered on
2026-10-15: see the core's `docs/merging.md`.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Ncbi\NcbiSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$ncbi = (new NcbiSourceFactory(HttpClient::create()))->create([
    'mailto' => 'you@example.org',                 // E-utilities' "email"
    'api_key' => getenv('NCBI_API_KEY') ?: null,   // optional: ten calls a second instead of three
]);

$hbb = $ncbi->entity('3043');   // a bare number is a GeneID
echo $hbb->label, ' (', $hbb->id, '): ', $hbb->value('gene_name'), ', ', $hbb->value('map_location'), ', UniProt ', $hbb->identifier(Scheme::UNIPROT), ', Ensembl ', $hbb->identifier(Scheme::ENSEMBL), "\n";

$lion = $ncbi->entity(Identifier::of(Scheme::NCBI_TAXON, '9689'));
echo $lion->label, ' (', $lion->id, '): ', $lion->value('taxon_rank'), ', ', $lion->value('common_name'), ', GBIF ', $lion->identifier(Scheme::GBIF), ', basionym ', $lion->value('basionym'), "\n";

$page = $ncbi->search(new Query('Panthera[subtree]', Domain::TAXONOMY, limit: 3));
printf("%d taxa in Panthera[subtree]; the first %d: %s\n", $page->total, count($page), implode(', ', array_map(static fn ($t) => $t->label, $page->items)));
```

```
HBB (ncbi.gene:3043): hemoglobin subunit beta, 11p15.4, UniProt P68871, Ensembl ENSG00000244734
Panthera leo (ncbi.taxon:9689): species, lion, GBIF 5219404, basionym Felis leo Linnaeus, 1758
33 taxa in Panthera[subtree]; the first 3: Panthera pardus nimr, Panthera leo melanochaita, Panthera pardus adersi
```

(as answered on 2026-10-27, without a key: five calls, 0.34 s apart; the GBIF key NCBI links is
the legacy Backbone number)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        ncbi: { factory: ncbi, options: { mailto: '%env(OMNILAB_MAILTO)%', api_key: '%env(default::NCBI_API_KEY)%' } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

NCBI's own: an unknown GeneID is Datasets' `{}`, an unknown taxon an `errors` report: null.
E-utilities' `ERROR` (a search it cannot run) is a `ProviderException` with it. Before any call: an
identifier of another scheme (UniProt, Ensembl, GBIF...) (`NotSupportedException`).

## Verified, and not

Against the real services on 2026-10-15, without a key: HBB by its GeneID (its summary, symbol,
name, chromosome 11, 11p15.4, its ranges on GRCh38.p14 and T2T-CHM13v2.0, P68871 and
ENSG00000244734); an unknown GeneID (`{}`); *Panthera leo* by its id, `txid9689`, its scientific and
its common name, its lineage, basionym (*Felis leo* Linnaeus, 1758) and its GBIF page
(`gbif.org/species/5219404`); an unknown taxon (an `errors` report) and an unknown name; the search
of NCBI Gene for `HBB AND human[orgn]` (225 genes, Entrez's order: TP53, CFTR, PTEN first, then the
second page from 3) and of NCBI Taxonomy for `Panthera[subtree]` (33 taxa); the `X-RateLimit-Limit`
of each service (3 for E-utilities, 5 for Datasets). The fixtures in `Tests/Fixtures/` are their
answers, the search page's GO annotations cut.

Again on 2026-10-27, without a key: HBB by its GeneID, the lion by its taxon id, the search of
NCBI Taxonomy for `Panthera[subtree]` (33 taxa; the example above).

Not verified: an API key (none at hand: the `api_key` parameter and the `api-key` header are sent
as NCBI documents them, checked against recorded calls only); NCBI's answer past the rate (no 429
met: the source keeps under it); Datasets' `links` for an unknown taxon answer 500 - never asked,
the links being read only for a taxon found.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the NCBI or the U.S. National Library of Medicine. NCBI asks for `tool` and `email`
and for its rates, places no restriction on the use of its data though submitters may hold rights
on theirs, as the README's [Terms of use](https://github.com/glitchr-omnisense/omnilab-ncbi#terms-of-use)
and [NCBI's policies](https://www.ncbi.nlm.nih.gov/home/about/policies/) say. Whoever runs the
package is responsible for complying with them and for any use made of what it returns: see the
README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-ncbi#responsibility) section.
