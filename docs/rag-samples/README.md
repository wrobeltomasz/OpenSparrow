# RAG Sample Documents

Sample `.txt` knowledge-base files for the OpenSparrow RAG module.
Upload via **Admin → RAG Documents**.

| File | Tag | Contents |
|------|-----|----------|
| opensparrow_overview.txt | `opensparrow` | Full platform feature overview |
| crm_overview.txt | `crm` | CRM tables, relationships, workflows |
| crm_deals.txt | `deals` | Deal stages, pipeline, calendar |
| crm_leads.txt | `leads` | Lead sources, statuses, conversion workflow |
| crm_activities.txt | `activities` | Activity types, scheduling, calendar |
| crm_quotes_invoices.txt | `quotes` | Quote lifecycle, invoice statuses, revenue views |
| crm_assets.txt | `assets` | Asset registry, depreciation, inspection |
| crm_products.txt | `products` | Product catalog, SKU convention, M2M contacts |

## Auto-tag behaviour

The "Ask AI" panel (avatar menu → Ask AI) automatically pre-selects the tag
matching the current page's `?table=` URL parameter. For example, visiting
`?table=deals` pre-selects the `deals` tag so the agent searches deal-relevant
documents first.

## Usage

1. Go to **Admin → RAG Documents → Documents tab**
2. Upload each `.txt` file
3. Enter the tag from the table above (one tag per file)
4. Click **Upload**

Requires Ollama running locally. Configure URL and model in
**Admin → RAG Documents → Settings tab**.
