# `world_airports.csv` — every airport Orbit will price

**Source** <https://davidmegginson.github.io/ourairports-data/airports.csv> and
`countries.csv` from the same directory (the canonical mirror of
<https://ourairports.com/data/>).
**Retrieved** 2026-08-15.
**Licence** Public domain. OurAirports' own wording: *"All data is released to
the Public Domain, and comes with no guarantee of accuracy or fitness for use."*
Credit is requested and not required; this file is the credit.

## Why a snapshot is committed rather than downloaded

Seeding runs on every deploy (`.claude/commands/deploy.md`), and a seeder that
fetches 12 MB over the public internet is a deploy that fails when GitHub Pages
is slow, a container has no egress, or the upstream file changes shape on a
morning nobody was expecting it to. The filtered snapshot is 228 KB, it is
reviewable in a diff, and it makes `db:seed` hermetic — which is the same
argument `european_destinations.php` makes for its own contents.

## What was filtered out, and why

The upstream file is 85,901 rows, most of which are heliports, farm strips and
airports that closed decades ago. Three conditions cut it to 3,270:

| condition | why |
| --- | --- |
| `scheduled_service == "yes"` | Orbit prices scheduled flights. A field with a windsock is not somewhere the owner can fly to. |
| `iata_code` is not empty | The IATA code is Orbit's key for an airport (`airports.iata`) and what every price provider speaks. A row without one cannot be looked up or priced. |
| `type` in `large_airport`, `medium_airport` | `small_airport` with scheduled service is a nine-seater to an island; the fare APIs have no data for them, so they would be codes the typeahead offers and the price screen apologises for. |

The result is 1,154 large and 2,116 medium airports in 240-odd countries — no
duplicate IATA codes, every `iso_country` two characters, every code three.

## Columns

`iata,name,city,country,country_code,lat,lng`, sorted by IATA so a refreshed
snapshot produces a diff that can be read.

Three of them are derived rather than copied:

- **city** is OurAirports' `municipality`, falling back to the airport's own
  name for the 50 rows that have no municipality (mostly island strips whose
  airport *is* the settlement).
- **country** is `countries.csv`'s name for the row's `iso_country`, which is
  the country-code → country-name lookup the `airports` table needs and the
  airports file does not carry.
- **lat/lng** are rounded to six decimals (~10 cm), which is the precision
  `create_airports_table`'s note says an airport needs.

## Refreshing it

Re-download both files, re-apply the filter above, replace this CSV, update the
retrieval date, and run the suite: `tests/Feature/SeedersTest` asserts the shape
of every row rather than a checksum, so a snapshot that grew a column or lost a
country fails there rather than in production.

`Database\Seeders\WorldAirportSeeder` will then upsert the changes — and will
still not touch the curated rows, which are `european_destinations.php`'s and
`world_destinations.php`'s to own. See that seeder for why.
