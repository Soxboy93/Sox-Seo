# Sox SEO

AI-powered SEO for WordPress and WooCommerce, driven by Claude through your own Anthropic API key.

It covers the feature set people install Rank Math, Yoast SEO, All in One SEO and image/link SEO add-ons for, written from scratch, with Claude doing the writing that those plugins leave to you.

## What it does

| Area | What you get |
| --- | --- |
| **Dashboard** | Average on-page score, what still needs attention, the pages to improve first, search clicks and Claude usage, on one screen. |
| **AI titles & descriptions** | Claude reads each page, post, product or category and writes the SEO title, meta description and focus keyword. One click in the editor, automatic on publish, or in bulk. |
| **AI image alt text** | Claude looks at each image (vision) and writes the alt text. Automatic on upload, in bulk for the whole media library, or per image from the Media list. |
| **AI internal links** | Claude suggests where a page should link to other pages on the site; tick the ones you want and they are inserted and saved as a revision. |
| **AI content audit** | Claude reviews a page against its focus keyword and lists specific fixes (missing subtopics, thin sections, slow intro), ranked by priority. Nothing is rewritten for you. |
| **AI product descriptions** | Claude writes a product's short and full description from its attributes. Preview and edit before saving; the previous text is kept. Bulk mode fills in missing short descriptions only. |
| **Google Search Console** | Connect once and Claude is given the real searches each page appears for when it writes titles and audits content. A Search Performance screen lists pages that are close to ranking higher. |
| **Second-language SEO** | On TranslatePress sites, every page and category gets an extra SEO title and description per language, written by Claude as a native speaker would search, plus a sitemap of the translated URLs. |
| **Category introductions** | Claude writes a short buying guide for each category from the items in it. Preview on the category screen, or fill all empty categories in bulk. |
| **Focus keyword review** | Claude replaces over-long or missing focus keywords with the short phrase people search for, and adjusts titles and descriptions only where they do not carry it. Previous values are kept. |
| **Smart 404 fixes** | Claude matches logged 404 addresses to the live page that truly replaces each one and proposes the redirects; junk and bot probes are left alone. |
| **Autopilot** | Hourly background job that works through everything still missing SEO data, capped by a daily limit so API spend stays predictable. |
| **On-page check** | A 0–100 score with a checklist per page (lengths, keyword placement, density, alt text, links). Runs locally, no API cost. Shown in the editor and as a column in post lists. |
| **Meta tags** | Title templates, meta description, canonical URL, robots (noindex per page, search results, cart/checkout/account), Open Graph and X/Twitter cards, product price tags. |
| **Schema** | JSON-LD graph: Organization, WebSite with search action, WebPage, BreadcrumbList, Article. WooCommerce product schema is enriched with brand, GTIN/MPN and all gallery images. |
| **Local business schema** | Address, phone, map coordinates, opening hours and business type, for Google Maps and "near me" results. |
| **FAQ schema** | Claude finds questions and answers already written on a page and marks them up. Questions that are not on the page are discarded. |
| **Social sharing** | Per-page title, description and image for Facebook, WhatsApp and X. |
| **Breadcrumbs** | `[sox_seo_breadcrumbs]` shortcode for a visible trail (Home › Category › Page). |
| **Sitemap & robots.txt** | Cleans WordPress's built-in `/wp-sitemap.xml` (drops noindexed pages and author archives) and keeps crawlers out of WooCommerce sort/filter URLs. |
| **Images** | Images with no alt text fall back to the page or product title. New JPEG uploads can be converted to WebP, compression quality is adjustable, and camera-style file names (`IMG_4471.jpg`) are renamed after the page they are uploaded to. |
| **Links** | Optional `target="_blank"` / `rel="nofollow"` on external links. Internal links can be inserted into Elementor Text Editor widgets as well as normal content. |
| **Link audit** | Scans every page for broken links and for pages nothing links to, on demand or weekly in the background. Claude then favours those unlinked pages when suggesting internal links. |
| **IndexNow** | Tells Bing, Yandex and other IndexNow engines the moment a page changes. |
| **AI assistants** | Publishes `/llms.txt`, and can ask AI companies not to train on the site via robots.txt. |
| **Shipping & returns schema** | Delivery cost, delivery time and returns policy on every product offer, as Google asks for in product results. |
| **Barcode import** | Upload a CSV of SKU and GTIN; each barcode's check digit is verified before saving. |
| **Bulk editor** | One table to edit every title, description and focus keyword, including the second-language ones. |
| **Speed report** | Google PageSpeed test of one page per template, with the biggest savings listed. |
| **Ranking history** | A weekly Search Console snapshot, showing clicks, impressions and position week by week and the pages that moved most. |
| **Leftover cleanup** | Removes tables, fields and settings left by SEO plugins you no longer use. Refuses to touch a plugin that is active. |
| **Product feed check** | Lists products missing what Google Merchant Center asks for: image, price, description, brand, GTIN. |
| **Import** | Copies titles, descriptions, keywords and canonicals from Rank Math, Yoast SEO or SEOPress. |
| **Weekly report** | E-mail summary of what Claude wrote, what is still missing, new 404s, broken links and search clicks. |
| **Redirects & 404s** | Redirect manager (301/302/307) with hit counts, and a 404 log with one-click "redirect this". |

## Works with your existing SEO plugin

If **Rank Math**, **Yoast SEO** or **SEOPress** is active, Sox SEO switches to AI-layer mode: Claude's titles, descriptions and focus keywords are saved into that plugin's own fields, and Sox SEO prints no meta tags, schema or sitemap of its own. Two plugins printing the same tags would hurt rankings, so this is automatic. Alt text, internal links, redirects and the bulk tools work in both modes.

With All in One SEO active, AI titles/descriptions are switched off (it uses private database tables); everything else still works.

## Install

1. Download this repository as a zip and upload it under **Plugins → Add New → Upload Plugin**, or copy the folder to `wp-content/plugins/sox-seo`.
2. Activate **Sox SEO**.
3. Create an API key at [console.anthropic.com](https://console.anthropic.com/) and add it under **Sox SEO → Settings**, or, safer, in `wp-config.php`:

   ```php
   define( 'SOX_SEO_ANTHROPIC_API_KEY', 'sk-ant-…' );
   ```

4. Press **Test connection**.
5. Open **Sox SEO → Bulk AI Optimizer** and press **Start** to fill in everything that is missing.

Requires WordPress 6.4+ and PHP 7.4+.

## Connecting Google Search Console

**If the site runs Site Kit by Google**, nothing needs setting up: Sox SEO uses the Google sign-in Site Kit already holds, and the settings page shows "Connected through Site Kit". The steps below are only for sites without Site Kit.

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project and enable the **Google Search Console API**.
2. Create a **service account** in that project and download its **JSON key**.
3. Paste the whole JSON file under **Sox SEO → Settings → Google Search Console**.
4. In [Search Console](https://search.google.com/search-console) → Settings → Users and permissions, add the service account's e-mail address as a user.

If your property is a domain property, enter it as `sc-domain:example.com` in the Property field.

## Multilingual sites

**TranslatePress** serves every language from the same page, so Sox SEO adds a title and description field per extra language to each page and category, a per-language site name for titles, and a sitemap section for the translated URLs. Claude writes these fields from the editor or in bulk.

**Polylang and WPML** keep each language as its own page, so the normal fields already cover them; Claude writes each page's SEO text in that page's own language.

The `hreflang` tags that tie language versions together are printed by all three translation plugins themselves, so this plugin does not print a second set.

## Switching from another SEO plugin

1. With the old plugin still active, install Sox SEO and run **Tools → Import** for it (and for any older plugin whose data is still in the database).
2. Deactivate the old plugin. Sox SEO takes over the meta tags, schema and sitemap.
3. Submit `/wp-sitemap.xml` in Search Console.

Code written against Rank Math's filters (`rank_math/frontend/title` and the description, canonical, Open Graph and product-schema equivalents) keeps running: Sox SEO passes its values through those filters when anything is hooked to them. New code should use the `sox_seo_*` filters.

## Models and cost

The default model is Claude Opus 5.5. You can switch to Sonnet 5.5 or Haiku 5.5 in settings to cut cost on large catalogues, or Fable 5.2 for the highest quality. API usage is billed by Anthropic to your key; the settings page shows requests and tokens per month. Each page or image is one request.

If Claude declines a request, the plugin opts into Anthropic's server-side fallback so the request is retried on another model instead of failing.

## What it cannot do

Google stopped showing HowTo rich results in 2023 and now shows FAQ rich results for only a small set of sites, so HowTo markup is not included and FAQ markup is unlikely to change how your listing looks. FAQ markup still helps search engines and AI assistants read the page.

No plugin can guarantee a number 1 ranking. Rankings depend on how good and useful your pages are compared with competitors, on who links to you, on site speed, and on Google's own judgement. This plugin removes the technical and on-page reasons a page fails to rank, and keeps doing so as you add content. It does not build backlinks, speed up hosting, or write the pages themselves.

Claude only describes what is on the page. It is told not to invent prices, specifications or claims, and automation never overwrites text you wrote unless you switch that on.

## Privacy

Page text, product details and images are sent to the Anthropic API when an AI feature runs. Nothing is sent anywhere else.

## Filters

- `sox_seo_post_types` — post types the plugin manages.
- `sox_seo_schema_graph` — the JSON-LD graph before output.
- `sox_seo_title`, `sox_seo_description`, `sox_seo_canonical` — head values.
- `sox_seo_og_title`, `sox_seo_og_description`, `sox_seo_og_locale` — social values.
- `sox_seo_product_schema` — WooCommerce Product JSON-LD.
