=== Follow Vendors for HivePress ===
Contributors: chrisb
Tags: hivepress, vendors, follow, following, email
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets users follow Vendors, lists them in the account menu, emails followers about new Listings, and adds a Follow item to Action Bar.

== Description ==

HivePress lets users keep a list of favourite Listings, through its Favorites extension. Vendors have no equivalent: there is no way for a user to say "keep me posted about this seller". This plugin adds that. A Follow button appears on Vendor cards and Vendor pages, in the same place and style as the Favorites heart on Listings; a user's followed Vendors are listed on their own account page; and when a followed Vendor publishes new Listings, each follower receives one email listing them.

If Action Bar for HivePress is active, the plugin also adds a Follow item to the bar, on Vendor pages and on Listing pages for the Listing's Vendor. It shows the visitor's current state and switches without reloading the page.

Features:

* A Follow button on Vendor cards and Vendor pages, styled by all six official HivePress themes exactly as the Send Message control beside it. Choose cards, pages or both.
* Follow and Following labels you can change, and a configurable name for the account page.
* A Followed Vendors page in the account menu, listing the Vendors a user follows as Vendor cards, with pagination.
* Signed-out visitors who click Follow get the HivePress sign-in pop-up, exactly as with the Favorites heart.
* An optional follower count under the Vendor's name, shown only once someone follows the Vendor.
* An email to followers when a followed Vendor publishes new Listings. Listings published inside a quiet period (10 minutes by default) go out as one email, so a Vendor adding five Listings sends one message, not five. Renewing, hiding or unhiding a Listing never sends another. The email can be reworded under HivePress, Emails, or switched off.
* Sending happens in the background through HivePress's own scheduler, in batches, so a Vendor with many followers never slows the site down when publishing.
* An Action Bar for HivePress item: Follow, switchable on and off. Nothing changes when Action Bar is not active.
* Everything is translatable, no assets load from outside your site, and nothing is sent anywhere.
* Your settings and everyone's follows are kept if you delete the plugin, unless you tick the box that asks for them to be removed.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/follow-vendors-for-hivepress` directory, or install the plugin zip through the WordPress admin.
2. Activate the plugin through the Plugins screen. HivePress must be installed and active.
3. Go to HivePress, Settings, Follow Vendors, to change the labels, choose where the button shows, and set the email quiet period.
4. Make sure "Enable vendor pages" is ticked under HivePress, Settings, Vendors. Vendor cards and pages only exist while it is on, and the Follow button lives on them.

Once installed, the plugin checks for new versions automatically and updates through the normal WordPress Plugins screen, just like a plugin from the WordPress.org directory.

== Frequently Asked Questions ==

= Where does the Follow button appear? =

On Vendor cards (the boxes in Vendor lists and search results) it sits beside the Send Message envelope as an icon. On a Vendor's own page it sits in the actions box as a button under Send Message. Under HivePress, Settings, Follow Vendors, you can limit it to cards or pages. A Vendor never sees a Follow button on their own profile.

= Where do users see the Vendors they follow? =

Under Followed Vendors in their account menu, at /account/followed-vendors/. The item appears once they follow at least one Vendor. The name of the page can be changed on the settings tab.

= When is the email sent? =

When a followed Vendor publishes a new Listing, the plugin waits for the quiet period (10 minutes by default) and then sends each follower one email listing every Listing that Vendor published in that time. A Listing is announced once in its life: renewing it, hiding and unhiding it, or a moderation round trip sends nothing more. The email is sent through HivePress's scheduler, so it needs WordPress cron to be running, which it is on almost every site.

= Can I change the wording of the email? =

Yes. It appears under HivePress, Emails, as "New Listings from a Followed Vendor", where you can edit the subject and body like any other HivePress email. The tokens available are %user_name%, %vendor_name%, %vendor_url%, %listings% (the list of new Listings, each as a link), %listing_count% and %followed_vendors_url%.

= What does the Action Bar integration need? =

Action Bar for HivePress must be active for the item to appear, and it can be switched off on the settings tab. It is added after the ones you set up on the Action Bar tab, so a bar that already has five items becomes six on those pages; the Listing page and Vendor page bars in Action Bar are the tidy way to make room. The Save and Alert me buttons are part of Action Bar for HivePress itself (version 1.8.0 and later), under Quick Actions on its own tab.

= What happens if Vendor pages are switched off? =

Nothing from this plugin is shown: HivePress removes Vendor cards and pages entirely while "Enable vendor pages" is unticked under HivePress, Settings, Vendors. The settings tab says so when that is the case.

= Does deleting the plugin remove the follows? =

Not unless you ask it to. Your settings and everyone's follows are kept by default, even though the WordPress delete screen warns that data will be removed, so a reinstall picks up where you left off. Tick "Delete all data when this plugin is deleted" on the settings tab first if you want everything gone. Deactivating the plugin never removes anything; while it is deactivated the follow records appear on the Comments screen as "hp_hpfv_follow" entries, which is also how the Favorites extension stores favourites.

== Changelog ==

= 1.0.1 =
* Adds a Follow button to Vendor cards and Vendor pages, with editable Follow and Following labels. Signed-out visitors get the HivePress sign-in pop-up.
* Adds a Followed Vendors page to the account menu, listing the Vendors a user follows as Vendor cards.
* Shows an optional follower count under the Vendor's name.
* Emails followers when a followed Vendor publishes new Listings, one email per quiet period (10 minutes by default), editable under HivePress, Emails.
* Adds a Follow item to Action Bar for HivePress when that plugin is active.
* Settings are under HivePress, Settings, Follow Vendors. "Enable vendor pages" must be ticked under HivePress, Settings, Vendors.
* Settings and follows are kept when the plugin is deleted unless "Delete All Data" is ticked first.
