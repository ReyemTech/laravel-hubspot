import { defineConfig } from "astro/config";
import starlight from "@astrojs/starlight";

// Deployed by Phase 9 (SHIP-04) to the `docs-pages` branch and served by GitHub Pages as a
// project page (no CNAME/custom domain configured — see docs/repo/docs-site-deploy.md). `site`
// and `base` must both be set correctly now: getting either wrong produces a build that looks
// green but ships broken canonical URLs and internal links, a failure that only surfaces after
// deploy.
export default defineConfig({
  site: "https://reyemtech.github.io",
  base: "/laravel-hubspot",
  integrations: [
    starlight({
      title: "laravel-hubspot",
      description:
        "Laravel package for HubSpot CRM covering every object type, with directional associations and inbound webhooks.",
      social: [
        {
          icon: "github",
          label: "GitHub",
          href: "https://github.com/reyemtech/laravel-hubspot",
        },
      ],
      editLink: {
        baseUrl: "https://github.com/reyemtech/laravel-hubspot/edit/main/site/",
      },
      sidebar: [{ label: "Welcome", slug: "" }],
    }),
  ],
});
