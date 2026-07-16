CC Listing Engine v0.1.0
Standalone listing API for Central Commercial Realty. Runs on EasyPanel.
The WordPress site (any host) calls this API instead of holding the data itself.
Deploy on EasyPanel
MySQL service (skip if reusing an existing MySQL):
EasyPanel → your project → + Service → MySQL
Name: `listings-db` · database: `listings` · user: `listings` · note the password
Engine app:

Service → App → "Build from source" (upload this folder or point at a git repo containing `Dockerfile` + `engine.php`)
Port: 8080
Environment variables:
```
     DB_HOST=listings-db        (EasyPanel internal hostname of the MySQL service)
     DB_NAME=listings
     DB_USER=listings
     DB_PASS=<the password>
     IDX_TOKEN=<your AMPRE IDX bearer token>
     API_KEY=<generate: long random string — the WP plugin will use this>
     SYNC_KEY=<generate: another long random string — cron uses this>
     ```
Deploy. EasyPanel gives it a URL like `https://ai-services-listing-engine.xxxx.easypanel.host`
First sync (from any terminal):
```
   curl -X POST "https://<engine-url>/v1/sync?key=<SYNC_KEY>"
   ```
Repeat until `"more": false` (first full pull is big). Then schedule it:
EasyPanel → the app → Advanced → Cron, or n8n Schedule node:
hit `POST /v1/sync?key=…` every 6 hours.
Try the API:
```
   curl "https://<engine-url>/health"
   curl -H "X-Api-Key: <API_KEY>" "https://<engine-url>/v1/listings?cat=business&txn=sale&pp=3"
   curl -H "X-Api-Key: <API_KEY>" "https://<engine-url>/v1/listing/N13525860"
   curl -H "X-Api-Key: <API_KEY>" "https://<engine-url>/v1/cities"
   ```
Endpoints
Endpoint	What
`GET /health`	status, listing count, last sync (no auth)
`GET /v1/listings`	filters: cat (business/property/residential), txn (sale/lease), city (repeatable), q, ind, sqmin, sqmax, sort (newest/price_asc/price_desc), page, pp
`GET /v1/listing/{key}`	full record incl. all feed fields + photos
`GET /v1/cities`	distinct cities with counts
`POST /v1/sync?key=…`	incremental AMPRE sync (cron)
Roadmap (next versions)
v0.2: geocoding worker + lat/lng backfill (port of the plugin's cache system)
v0.3: VOW feed + statuses (after probe v2 findings)
v0.4: WP thin-client plugin consuming this API
v0.5: client matching + alerts server-side
Compliance
PropTx/AMPRE data is licensed to Central Commercial Realty for its own authorized
website(s). The API key restricts access accordingly. Data must not be provided to
any AI system (agreement clause 1.e) or to third parties.
