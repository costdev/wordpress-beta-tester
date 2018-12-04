# WordPress Beta Tester
* Tags: beta, advanced, testing
* Contributors: [westi](https://github.com/westi), [mlteal](https://github.com/mlteal), [afragen](https://github.com/afragen)
* Tested up to: 5.0
* Requires at least: 3.1
* Stable Tag: master
* License: GPLv2
* License URI: https://www.opensource.org/licenses/GPL-2.0
* Requires PHP: 5.2.4

Allows you to easily upgrade to Beta releases.

## Description
This plugin provides an easy way to get involved with Beta testing WordPress.

Once installed it will enable you to upgrade your website to the latest Beta or Release candidate at the click of a button using the built in upgrader.

By default once enabled it switches your website onto the point release development track.

For the more adventurous there is the option to switch to the bleeding edge (trunk) of development.

Don't forget to backup before you start!

In the **Extra Settings** tab are choices for testing features in trunk that may require constants to be set. A checked feature will add a constant to the user's `wp-config.php` file in the format as follows:

`define( 'WP_BETA_TESTER_{$feature}', true );`

Unchecking the feature will remove the constant.

If no settings are present there is no testing to be done that requires this feature.

PRs are welcome on [GitHub](https://github.com/afragen/wordpress-beta-tester).
