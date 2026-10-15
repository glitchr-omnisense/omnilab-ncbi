# omnilab/ncbi

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
transcripts and proteins, orthologs, expression, references and the identifiers of schemes the core
does not know (HGNC, OMIM, RefSeq); a taxon's children, its genome and gene counts, its genetic
codes, its full lineage with the intermediate ranks (only their ids come, in `parents`). Not used:
`efetch`, `elink`, `esummary`, PubMed and the sequence databases.

## The Collector

Hemoglobin beta from UniProt reaches NCBI Gene by its GeneID (3043); *Panthera leo* from GBIF
reaches NCBI Taxonomy by the NCBI id GBIF keeps in its ENA checklist (9689). Both answered on
2026-10-15: see the core's `docs/merging.md`.

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

Not verified: an API key (none at hand: the `api_key` parameter and the `api-key` header are sent
as NCBI documents them, checked against recorded calls only); NCBI's answer past the rate (no 429
met: the source keeps under it); Datasets' `links` for an unknown taxon answer 500 - never asked,
the links being read only for a taxon found.
