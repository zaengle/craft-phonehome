
import schema from '../../src/schemas/PhonehomeApi.schema.json';
import pkg from '../../package.json';

export default {
  title: 'Phonehome for Craft CMS',
  description: 'Monitor the status of your #CraftCMS projects remotely',
  themeConfig: {
    logo: '/zaengle.svg',
    nav: [
      { text: 'Guide', link: '/' },
      { text: 'GitHub', link: 'https://github.com/zaengle/craft-phonehome' },
      { text: 'Open an issue', link: 'https://github.com/zaengle/craft-phonehome/issues' },
    ],
    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Home', link: '/' },
          { text: 'Installation', link: '/01-installation' },
        ]
      },{
        text: 'Usage',
        items: [
          { text: 'Configuration', link: '/02-config' },
          { text: 'API', link: '/03-api' },
        ]
      },
      {
        text: 'Made with ❤️ by Zaengle',
        items: [
          { text: 'Be Nice, Do Good', link: 'https://zaengle.com/'},
          { text: 'MIT Licensed', link: 'https://mit-license.org/'},
        ],
      }
    ]
  },
  vite: {
    define: {
      __API_VERSION__: JSON.stringify(schema.version),
      __PLUGIN_VERSION__: JSON.stringify(pkg.version),
    }
  }
};
