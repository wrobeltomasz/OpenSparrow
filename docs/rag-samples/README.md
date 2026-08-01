# RAG Sample Documents

Sample `.txt` knowledge-base files for the OpenSparrow RAG module, describing the
**CRM demo app** (Admin → Demo Data). Upload via **Admin → RAG Documents**.

| File | Tag | Contents |
|------|-----|----------|
| crm_overview.txt | `crm` | Tables, menu, relationships, subtables, seeded volumes, demo users |
| crm_companies_contacts.txt | `companies` | Company and contact fields, subtables, photo gallery |
| crm_deals.txt | `deals` | Stages, Kanban board, stakeholders M2M, automations, pipeline view |
| crm_activities.txt | `activities` | Activity types, calendar, dashboard widgets |
| crm_leads.txt | `leads` | Lead sources and statuses, lead automations, GDPR anonymization |
| crm_quotes_invoices.txt | `quotes` | Quote lifecycle, invoice statuses, the invoice automation |
| crm_workflows.txt | `workflows` | The three workflows, incl. stored-procedure validation |
| crm_dashboard_calendar.txt | `dashboard` | The seven widgets, period filter, calendar sources |
| crm_reports_print.txt | `reports` | Pipeline Summary view, the two printouts, hidden views |
| crm_collaboration.txt | `collaboration` | Demo users, comments, notes, ownership, files, notifications |

These describe the demo **as it is installed today**: only Companies, Contacts, Deals and
Activities are in the sidebar. Leads, quotes, invoices, products and assets are seeded but
hidden, reachable through subtables, workflows or a direct URL — the docs say so where it
matters, so the agent does not send users to menu entries that are not there.

## Tag selection in the Ask AI panel

Tags are listed in the "Ask AI" panel (avatar menu → Ask AI) and start **unchecked** —
documents are attached to a question only when the user ticks their tag. The panel does
show a context bar naming the current table, and offers a separate opt-in checkbox to send
the current grid's data along with the question, but neither attaches RAG documents on its
own.

## Usage

1. Go to **Admin → RAG Documents → Documents tab**
2. Upload each `.txt` file
3. Enter the tag from the table above (one tag per file)
4. Click **Upload**

Requires Ollama running locally. Configure URL and model in
**Admin → RAG Documents → Settings tab**.
