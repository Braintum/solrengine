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

The only front-end component is a dropdown under a search input. A plugin that
shipped React to render a list of eight links would be adding a runtime to
every page of a site for something the platform already does well. If a later
phase needs Gutenberg blocks, those will use `@wordpress/*` packages, which
WordPress already loads.
