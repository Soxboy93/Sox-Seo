# Sox SEO

AI-powered SEO for WordPress and WooCommerce, driven by Claude through your own Anthropic API key.

It covers the feature set people install Rank Math, Yoast SEO, All in One SEO and image/link SEO add-ons for, written from scratch, with Claude doing the writing that those plugins leave to you.

## What it does

| Area | What you get |
| --- | --- |
| **AI titles & descriptions** | Claude reads each page, post, product or category and writes the SEO title, meta description and focus keyword. One click in the editor, automatic on publish, or in bulk. |
| **AI image alt text** | Claude looks at each image (vision) and writes the alt text. Automatic on upload, in bulk for the whole media library, or per image from the Media list. |
| **AI internal links** | Claude suggests where a page should link to other pages on the site; tick the ones you want and they are inserted and saved as a revision. |
| **Autopilot** | Hourly background job that works through everything still missing SEO data, capped by a daily limit so API spend stays predictable. |
| **On-page check** | A 0–100 score with a checklist per page (lengths, keyword placement, density, alt text, links). Runs locally, no API cost. Shown in the editor and as a column in post lists. |
| **Meta tags** | Title templates, meta description, canonical URL, robots (noindex per page, search results, cart/checkout/account), Open Graph and X/Twitter cards, product price tags. |
| **Schema** | JSON-LD graph: Organization, WebSite with search action, WebPage, BreadcrumbList, Article. WooCommerce product schema is enriched with brand, GTIN/MPN and all gallery images. |
| **Sitemap & robots.txt** | Cleans WordPress's built-in `/wp-sitemap.xml` (drops noindexed pages and author archives) and keeps crawlers out of WooCommerce sort/filter URLs. |
| **Images** | Images with no alt text fall back to the page or product title instead of being empty. |
| **Links** | Optional `target="_blank"` / `rel="nofollow"` on external links. |
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

## Models and cost

The default model is Claude Opus 5.5. You can switch to Sonnet 5.5 or Haiku 5.5 in settings to cut cost on large catalogues, or Fable 5.2 for the highest quality. API usage is billed by Anthropic to your key; the settings page shows requests and tokens per month. Each page or image is one request.

If Claude declines a request, the plugin opts into Anthropic's server-side fallback so the request is retried on another model instead of failing.

## What it cannot do

No plugin can guarantee a number 1 ranking. Rankings depend on how good and useful your pages are compared with competitors, on who links to you, on site speed, and on Google's own judgement. This plugin removes the technical and on-page reasons a page fails to rank, and keeps doing so as you add content. It does not build backlinks, speed up hosting, or write the pages themselves.

Claude only describes what is on the page. It is told not to invent prices, specifications or claims, and automation never overwrites text you wrote unless you switch that on.

## Privacy

Page text, product details and images are sent to the Anthropic API when an AI feature runs. Nothing is sent anywhere else.

## Filters

- `sox_seo_post_types` — post types the plugin manages.
- `sox_seo_schema_graph` — the JSON-LD graph before output.
