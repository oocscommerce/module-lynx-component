# Lynx Component

`oocscommerce/module-lynx-component`

The component system the Lynx storefront is made of; it does not depend on the page builder.

## Features

- Components declared in `etc/components/*.graphqls`: settings, slots and the data a PHP provider returns
- Resolves a layout of components into page props, filtered to the declared keys
- Generates the TypeScript types of the props for the theme build
- Other modules extend or replace a component's declaration and add blocks and observers to it

## Installation

Part of [Lynx](https://docs.oocscommerce.com/lynx/), installed with the metapackage:

```bash
composer require oocscommerce/magento2-lynx-community
```

See [Installation](https://docs.oocscommerce.com/lynx/developer/installation) for the full setup.

## Documentation

https://docs.oocscommerce.com/lynx/

Building on Lynx with Claude Code: the [Lynx skills](https://github.com/oocscommerce/claude-skills) teach it to write components, providers, checkout methods and more.

## Community

Questions, ideas and help: join [Lynx on Slack](https://join.slack.com/t/lynxmagento/shared_invite/zt-4dd3wdz3k-DILaGAowU_p3nevNLJ1gbw).

## License

[OSL-3.0](LICENSE.txt) or [AFL-3.0](LICENSE_AFL.txt).
