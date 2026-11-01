import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Laravel Localizer',
  description: 'Locale detection and localized URLs for Laravel - the recommended successor to mcamara/laravel-localization.',

  cleanUrls: true,
  lastUpdated: true,

  head: [
    ['link', { rel: 'icon', href: '/favicon.svg', type: 'image/svg+xml' }],
    ['meta', { name: 'theme-color', content: '#3eaf7c' }],
  ],

  themeConfig: {
    nav: [
      { text: 'Get Started', link: '/installation' },
      { text: 'Migration', link: '/migrating-from-laravel-localization' },
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Introduction', link: '/' },
          { text: 'Installation', link: '/installation' },
          { text: 'Configuration', link: '/configuration' },
        ]
      },
      {
        text: 'Defining Routes',
        items: [
          { text: 'Localized Routes', link: '/defining-routes' },
          { text: 'Translated URL Paths', link: '/translated-url-paths' },
        ]
      },
      {
        text: 'Rendering URLs',
        items: [
          { text: 'Template Helpers', link: '/template-helpers' },
          { text: 'Language Switcher', link: '/language-switcher' },
          { text: 'JavaScript Route Helpers', link: '/javascript-route-helpers' },
        ]
      },
      {
        text: 'Runtime Behavior',
        items: [
          { text: 'Detectors', link: '/detectors' },
          { text: 'Redirects', link: '/redirects' },
          { text: 'Jobs, Mailables & Notifications', link: '/jobs-mailables-notifications' },
        ]
      },
      {
        text: 'Advanced',
        items: [
          { text: 'Multitenancy', link: '/multitenancy' },
          { text: 'Caveats & Recipes', link: '/caveats-and-recipes' },
        ]
      },
      {
        text: 'Third-Party Packages',
        items: [
          { text: 'Livewire', link: '/livewire' },
        ]
      },
      {
        text: 'Migration',
        items: [
          { text: 'From mcamara/laravel-localization', link: '/migrating-from-laravel-localization' },
          { text: 'Troubleshooting', link: '/troubleshooting' },
        ]
      },
      {
        text: 'About',
        items: [
          { text: 'Comparison & Background', link: '/comparison' },
        ]
      }
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/niels-numbers/laravel-localizer' }
    ],

    search: {
      provider: 'local'
    },

    editLink: {
      pattern: 'https://github.com/niels-numbers/laravel-localizer/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © Adam Nielsen'
    }
  }
})
