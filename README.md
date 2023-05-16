decred woocommerce payment plugin
====

[![ISC License](https://img.shields.io/badge/license-ISC-blue.svg)](http://copyfree.org)


This is a plugin for accepting payments via Decred on-chain transactions via a WooCommerce-branded payment gateway.

## Dependencies

-   WooCommerce
-  [decred-php-api](https://github.com/decred/decred-php-api/) (dependency install via composer)

### Install dependencies

-   `composer install`

## Setup

Install the following plugins:

-   WooCommerce

### General Concepts/Setup

The best way to begin is to see the [CLI Installation guide](https://docs.decred.org/wallets/cli/cli-installation/)
guide and follow the "Command-Line Path" so you have dcrd/dcrwallet running locally on testnet and mainnet.
From there you will be able to create accounts and be able to test receiving payments:

Create a new Decred wallet to receive payments trough your WooCommerce webshop. In order to protect your funds
you will need your XPUB key and enter it in the settings section of this plugin in your Wordpress admin console.
This way your webshop will not need to be operating with a hot wallet and the webserver can not access your funds.

You can user the following cli command to create a `dcrpayments` account in your wallet and export your XPUB key:
```
$ dcrctl --wallet createnewaccount dcrpayments
$ dcrctl --wallet getmasterpubkey dcrpayments
```
You should see a long string starting with **tpub** for textnet or **xpub** for mainnet
 which represents the master public key for this account returned as output. 
This is used by the decred payments plugin for woocommerce to deterministically derive payment addresses from the
account.  This lets the software use i.e. address #1 for order #1, address #2
for order #2, and so on to prevent customers or competitors from seeing how many
orders have been placed and the amount(s) the wallet has received.  If the
master public key is ever obtained by a third party, they will **not** be able
to spend your funds, however they will be able to see the addresses/balance of
this account.  For this reason, it is best to have the "real" wallet which
contains private keys separated from the actual payment infrastructure.

One quirk is that the wallet under normal operation for personal activity will
use a [gap](https://github.com/bitcoin/bips/blob/master/bip-0044.mediawiki)
between addresses.  Gaps are not desired for e-commerce at this time so we can
simply tell the wallet to watch the first 10,000 addresses.

```bash no-highlight
dcrctl --wallet accountsyncaddressindex dcrpayments 0 10000
```

This step will need to be performed again if the wallet is ever restored from
[seed](https://docs.decred.org/faq/wallets-and-seeds/).  At this point, you
should be able to accept up to 10,000 orders and can simply extend the address
index if you surpass 10,000 orders.
