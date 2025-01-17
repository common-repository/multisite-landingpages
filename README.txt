=== Multisite Landingpages ===
Contributors: ruigehond
Tags: landing page, domain, mapping, multisite, landingpages
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=hallo@ruigehond.nl&lc=US&item_name=Multisite+landingpages+plugin&no_note=0&cn=&currency_code=EUR&bn=PP-DonationsBF:btn_donateCC_LG.gif:NonHosted
Requires at least: 5.0
Tested up to: 6.2
Requires PHP: 5.6
Stable tag: 1.2.9
License: GPLv3

Allow your subsite administrators to add specific landingpages to domains they own in a multisite environment.

== Description ==
This plugin has been developed for and tested with a Wordpress Multisite hosting company in the U.S. They have agreed to release this plugin for free.
You may need some technical knowledge to set this up. It may also be that you need some specific compatibility or functionality, please use your local programmer to adjust this plugin or contact me.
This is the multisite version of my Each-domain-a-page plugin, for non-multisite environments Each-domain-a-page is recommended.

= Easy =
For owners of subsites it is now easy to add landingpages to their sites for different domain names. They simply type in any domain name they own, and then the slug they would like to serve for that domain.
‘Multisite landingpages’ enforces a dns txt record proving ownership, this can be switched off (for the entire multisite).

= Compatibility =
The plugin is specifically compatible with:
- WPMU Domain Mapping plugin (now deprecated).
- WP Rocket caching.
- Cartflows (step) post type.
- Yoast SEO plugin.

== Installation ==
Put the plugin in your plugins folder and follow the below instructions. If you need help or customization contact me.

= Documented working (admin) =
The network administrator does not have any settings, nor do they need any.
Of course, they can ‘network activate’ or deactivate and uninstall this plugin.

The plugin uses three config settings that you can put in your wp-config file.
`define('RUIGEHOND011_TXT_RECORD_MANDATORY', true);`
default: true; When true domains can only be added if they contain a mandatory txt record, proving ownership.
`define('RUIGEHOND011_DOMAIN_MAPPING_IS_PRESENT', false);`
default: false; when true Multisite Landingpages takes into account the relevant settings in the Domain Mapping plugin (now deprecated by WPMU).
`define('RUIGEHOND011_WP_ROCKET_CACHE_DIR', '/path/to/dir/wp-content/cache/wp-rocket');`
default: not present. When present, **the dir must be valid and writable**. Multisite Landingpages will invalidate cache per domain when it can, and warn when it can’t.
When not present you must invalidate any cache yourself when you’re done changing your settings.

This plugin can only work using the ‘sunrise’ drop in structure, so a site administrator must do the following:
Copy the sunrise.php file of this plugin to the wp-content directory, or add its code to an existing sunrise.php, ensuring it does not conflict.
NOTE: currently the sunrise of domain-mapping (WPMU) is taken and this plugin is added to ensure compatibility.
Set the sunrise constant in wp-config.php, somewhere below the multisite constants would be appropriate:
`define('SUNRISE', true);`

Multisite-landingpages creates a small table holding the domain names put in by subsite admins. The domain column is the primary key so queries should run fast even with many domains.

The following is only true if the global config ruigehond011_txt_record_mandatory = true:
Subsite administrators must put a TXT record in their DNS for any domain they want to add to prove they own it. This TXT record is unique for each subsite and installation (it uses the uuid4 functionality) and displayed to the administrator on the settings page.
If the TXT record is not present the domain will not be added.
When the TXT record is no longer found, a warning will be displayed on the settings page next to the entry. The landing page will keep working however as long as the domain is correctly pointed at the installation.
When someone else adds the domain (while proving ownership), the domain is assigned to that subsite, and not visible anymore to the old subsite.

When ruigehond011_txt_record_mandatory = false admins cannot prove ownership, therefore the transfer of a domain as described in the above paragraph is NOT possible. Domains that are in the table cannot be added by another subsite.

For custom fonts to work the following code must be added to .htaccess:

```
<IfModule mod_headers.c>
<FilesMatch "\.(eot|ttf|otf|woff)$">
Header set Access-Control-Allow-Origin "*"
</FilesMatch>
</IfModule>
```
The plugin will attempt to do this and warn when failed. The lines will be clearly marked by #ruigehond011 so you can find them in your .htaccess.

= Documented working (subsite) =
Subsite administrators get a ‘settings’ page called ‘Landingpages’ once the plugin is active.
At the top is displayed the TXT record containing the guid they must add to the DNS records for the domains they want to add. (Unless this is set to false in wp-config.)
A domain will be added when the record is found, after that they can assign a slug, which must be of a page or a regular post type (custom post types not supported out of the box).
The plugin will match a domain name to a slug and show the page or post of that slug then. If no match occurs, the plugin has no influence.
If the ‘canonicals’ option is checked however the plugin will always actively rewrite links to any of the landingpage domains of the current subsite.

= Note about international domainnames =
International domains, containing utf-8 characters, will be stored in punycode (ascii notation). Either automatically (when available) or they must be put in as such by the user. Upon failure a warning will be shown.

= Note about deactivation =
If a subsite administrator deactivates the plugin, its entries in the landingpages / domains table are removed.
On a network deactivation the table is left in the database for the admin to prune, to conserve resources. It will be dropped on uninstall.
On a network deactivation the options are removed for each subsite, as long as wp_is_large_network() returns false. For large networks, the admin should cleanup the relevant options. They are prefixed by ‘ruigehond011’.

== Screenshots ==

1. Settings screen for subsite administrators (1.2.9)

== Changelog ==

1.2.9 Public release