# SLU Services: sources and references

This file covers where the starter catalogue in `app/seed.php` came from, and which other governments' apps shaped the design of SLU Services. It also lists what to do as the Government of Saint Lucia gets involved.

## Where the service data came from

The brief was to comb through [govt.lc](https://www.govt.lc) and its [departments](https://www.govt.lc/departments), following links to each ministry's own site. The build environment's network blocked direct access to govt.lc, so the catalogue was gathered through web search of those same pages. Every record links to the page it came from:

| Source | Records | What it gave |
| --- | --- | --- |
| www.govt.lc (services and departments pages) | 50 | Most service pages: passports, civil registry certificates, driving and vehicles, business registration, tax, land |
| Ministry sites on govt.lc (pmd, publicservice, health, finance, physicaldevelopment, agriculture, homeaffairs, legalaffairs, socialtransformation, sustainabledevelopment, tourism, infrastructure, fire, npc, ifarm, moaslu) | 41 | Agency addresses, phone numbers and hours, ministry portfolios, planning, health, agriculture and fisheries services |
| digigov.govt.lc | 2 | Which services can be paid or applied for online. Those pages link straight to digiGov. |
| Attorney General's Chambers (laws), archive.stlucia.gov.lc and stlucia.gov.lc | 10 | Fees set in statutory instruments (for example the vehicle fees in S.I. No. 78 of 2015) and older official pages |
| US Social Security Administration country reports | 3 | National Insurance (NIC) contributions and benefits, where the NIC's own pages weren't reachable |
| ACE Electoral Knowledge Network | 1 | The Electoral Department's role in issuing the national ID card |
| News (The Voice, Biometric Update) | 4 | Recent changes: health card standards, Public Assistance card renewal, the National Authentication Framework launch (March 2026) |

What this means in practice:
- **Seeded data is unconfirmed.** It's marked "awaiting agency confirmation" until an agency approves its own record through intake. Fees that differed between sources say so on the page. The government's own channel replaces all of this: an approved intake draft sets "Confirmed by the agency" and the date.
- **Ministry names change between administrations.** Agencies are seeded under neutral portfolio names (for example "Ministry of Finance"). An admin can rename them in **Console** › **Agencies** without breaking links.
- **More to add.** The user will add more sources later. A CSV or an API call per agency is the quickest way to load a lot at once (see SETUP.md).

## Government apps this design draws on

| Service | What it does well | What SLU Services took from it |
| --- | --- | --- |
| **GOV.UK** (United Kingdom) | One site for all of government, organised by what people need rather than by department. Plain-language service pages with a consistent shape. Step-by-step guides. "Tell Us Once" for reporting a death to many departments at once. | Every service page has the same shape: who can apply, what you need, fees, how long it takes, how to apply, where and when. Steps are numbered, the writing is plain, and there's a tell-us-once change of address. |
| **LifeSG and Singpass** (Singapore) | Services grouped around life events (having a baby, starting a family) instead of agencies. One trusted digital identity for signing in to government services. | 12 life events alongside the 14 topics, and identity linked once to the account so ID-only services open up after one check. |
| **eesti.ee** (Estonia) | A single state portal, with transparency over who in government looked at a person's data. | Every view of a resident's ID photos by a verifier is logged with who and when. Residents can download everything held about them. |
| **Service NSW** (New South Wales, Australia) | One account across agencies, applications tracked with status, and digital credentials shown in the app. | Requests with reference numbers, a progress bar and a timeline of messages, a "Action needed" flow when the agency needs something, and the verified-resident card on **My ID**. |
| **Trident ID** (Barbados) | A Caribbean government's national ID programme that pairs a new ID card with digital identity for online services. | The rule that identity checks are the Government's to authorise. ID linking stays closed until clearance is recorded, verifiers are appointed by the Government, and photos are deleted after the decision. |
| **digiGov and NAF** (Saint Lucia) | digiGov already takes online applications and payments for several services. The National Authentication Framework (launched 2026) gives residents a government sign-in. | SLU Services doesn't duplicate digiGov: those services send residents straight to it. When NAF can be used by partner apps, it should replace the photo-based ID check (see below). |

## Working with the Government

The brief says this must be undertaken with the Government, which must give clearance. The app is built so nothing official happens without it:
- **Clearance** (Console › Clearance) records the status, approving authority, reference and date. Until it says "Authorised", residents see a preview or pilot notice and ID linking stays closed (pilot: testers only).
- **No government branding.** The app is "SLU Services", built by Digital SoLucians. It doesn't use the Government's coat of arms or claim to be an official service until clearance is granted. Agree the name and branding with the Government as part of clearance.
- **Agency confirmation.** Information becomes "Confirmed by the agency" only when an agency officer approves it.

Suggested next steps with the Government:
1. **Agree clearance and data protection.** Settle the terms in line with Saint Lucia's Data Protection Act. That includes who appoints identity verifiers, how long ID photos are kept, and where data is hosted.
2. **Connect NAF.** Ask the Department of Public Sector Modernization whether NAF can serve as the sign-in and identity check for partner apps. If it can, residents would sign in with NAF and "link your ID" becomes automatic.
3. **Appoint an officer per agency.** Each agency checks its seeded pages through intake: upload the CSV template or correct pages in the console.
4. **Set up the intake mailbox.** Use a govt.lc address (or forward one to the app's mailbox) so agencies can email updates.
5. **Payments.** Fees are still paid at the counter or on digiGov. A link from request approval to digiGov payment would be the next integration.
