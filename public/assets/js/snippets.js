// Install snippets for the frameworks people ask about. Pure functions with no imports, so the same file runs in the dashboard and under Node in the tests.

/** The picker, grouped the way people think of their setup: [group, [[key, label], …]]. */
export const FRAMEWORK_GROUPS = [
  ['Websites', [['html', 'HTML'], ['wordpress', 'WordPress'], ['shopify', 'Shopify']]],
  ['JavaScript frameworks', [['next', 'Next.js'], ['nuxt', 'Nuxt'], ['astro', 'Astro'], ['sveltekit', 'SvelteKit']]],
  ['Backend frameworks', [['laravel', 'Laravel']]],
];

export const FRAMEWORKS = FRAMEWORK_GROUPS.flatMap(([, items]) => items);

export const WORDPRESS_PLUGIN_URL = 'https://wordpress.org/plugins/tidemark-analytics/';

const plainTag = (domain, src) => `<script defer data-domain="${domain}" src="${src}"></script>`;

/**
 * What to paste for one framework. `domain` is the site's domain and `src` the full address of its script file. Returns `{ where, text }`:
 * the file to open and the code to put in it. WordPress is the exception: it has a plugin instead of code, so `text` is the address to
 * enter in the plugin and `link` points at the plugin's page. The script finds its settings from its own address and data-domain, so
 * every other variant loads the same file; what changes is how each framework wants an external script declared. Route changes are
 * tracked without extra code.
 */
export function snippetFor(framework, { domain, src }) {
  switch (framework) {
    case 'next':
      return {
        where: 'App Router: app/layout.tsx. With the Pages Router, put the same <Script> in pages/_app.tsx.',
        text: `import Script from 'next/script';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        {children}
        <Script defer data-domain="${domain}" src="${src}" strategy="afterInteractive" />
      </body>
    </html>
  );
}`,
      };
    case 'nuxt':
      return {
        where: 'nuxt.config.ts',
        text: `export default defineNuxtConfig({
  app: {
    head: {
      script: [{ src: '${src}', defer: true, 'data-domain': '${domain}' }],
    },
  },
});`,
      };
    case 'astro':
      return {
        where: 'Your base layout, for example src/layouts/Layout.astro, inside <head>. is:inline keeps Astro from bundling the tag.',
        text: `<script is:inline defer data-domain="${domain}" src="${src}"></script>`,
      };
    case 'sveltekit':
      return {
        where: 'src/app.html, inside <head>.',
        text: plainTag(domain, src),
      };
    case 'laravel':
      return {
        where: 'Your main layout, for example resources/views/layouts/app.blade.php, inside <head>.',
        text: `@production
  ${plainTag(domain, src)}
@endproduction`,
      };
    case 'wordpress':
      return {
        where: `Install and activate the Tidemark Analytics plugin, then open Settings > Tidemark Analytics and enter this address. Set the site name to ${domain}; it can stay empty when your WordPress site is on that domain.`,
        text: src.replace(/\/js\/[^/]*$/, ''),
        link: { href: WORDPRESS_PLUGIN_URL, label: 'Get the plugin on WordPress.org' },
      };
    case 'shopify':
      return {
        where: 'Online Store > Themes > Edit code > layout/theme.liquid, just before </head>.',
        text: plainTag(domain, src),
      };
    default:
      return {
        where: 'Paste it before the closing </head> tag of every page.',
        text: plainTag(domain, src),
      };
  }
}
