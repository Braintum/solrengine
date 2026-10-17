# Front-end assets

`src/` is the source. `build/` is generated and committed, because
WordPress.org distributes a zip rather than running a build, and guideline 4
requires the source and the build tooling to be available alongside it.

```bash
npm install
npm run build      # once
npm start          # watch
npm run lint:js
```

## A note on the ESLint configuration

`.eslintrc.json` extends `plugin:@wordpress/eslint-plugin/esnext` rather than
the more usual `/recommended`. The recommended preset pulls in
`@typescript-eslint`, which this project has no use for and which fails to load
on current Node versions with the pinned toolchain. `esnext` is the same
WordPress rule set without the TypeScript layer.

## Why there is no framework here

The front-end components are a dropdown under a search input, a search popup
and a filter panel. A plugin that shipped React to render a list of eight links
would be adding a runtime to every page of a site for something the platform
already does well.

The search popup depends on `wp-i18n` and nothing else. It needs real plural
forms ("1 result", "2 results" are different sentences in most languages), and
`@wordpress/i18n` is the package core already ships for that, translated through
the same language packs as the PHP strings.

Its markup is not in the script. `templates/` holds PHP templates a theme can
override; they are rendered into `<template>` elements once per page and the
script fills copies. Change a `data-solrengine-*` attribute in one and the script
must change with it — those attributes are public API.

### Formatting

`npm run format` (Prettier) and `npm run lint:js` (ESLint) disagree about
trailing commas in multi-line calls, and CI runs only the second. Finish with
`npx wp-scripts lint-js --fix <file>`. If a later
phase needs Gutenberg blocks, those will use `@wordpress/*` packages, which
WordPress already loads.
