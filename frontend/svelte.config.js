import adapter from '@sveltejs/adapter-static';

const config = {
  kit: {
    adapter: adapter({
      fallback: 'index.html',
    }),
    paths: {
      base: '/studio',
    },
  },
};

export default config;
