=== Comment Reply Email ===
Contributors: treeflips
Donate link: https://www.paypal.me/wpjohnny
Tags: comment, reply, email, notification
Requires at least: 4.0
Tested up to: 5.4.2
Stable tag: 1.0.2
License: GPL-2.0+

Commenters can receive email notifications of replies to their comments.

== Description ==

This simple plugin automatically sends a notification email to commenters when someone replies to their comment. This feature can be enabled automatically by the site admin, or through an opt-in/opt-out checkbox below comment section on frontend.

It's best to use it with an email-sending plugin like WP Mail SMTP, and with SMTP or transactional email service like SendGrid or Mailgun. Sending from your server via PHPmailer can cause deliverability issues (email notfications caught in spam).

I loved the original plugin [Comment Reply Notification](https://wordpress.org/plugins/comment-reply-notification/) (by @denishua) for its simplicity but it was abandoned and stopped working years ago. So I forked and revived it to work with the latest PHP and WordPress. I also improved some wording, removed unnecessary author links in the email notifications, and also keep it more updated. Credits to Denis who first hacked it 5 years ago, and later Walter for fixing string escapes.

= Features: =
* Feature modes - disabled, author/admin replies only, automatically, checkbox opt-in.
* Edit email notification - subject and message.
* Can delete plugin options - after deactivation.

== Installation ==
<ol>
	<li>Upload the folder comment-reply-email to the `/wp-content/plugins/` directory</li>
	<li>Activate the plugin through the 'Plugins' menu in WordPress</li>
	<li>Navigate to Manage > Setting > Comment Reply Email to configure the plugin.</li>
</ol>

== Frequently Asked Questions ==

= Does this plugin work with newest WP version and also older versions? =
Yes, this plugin works perfect with the latest version of WordPress! It also works with older versions as well but you should always run the latest WordPress and PHP version for best security and performance. This plugin is used in my critical sites so you can be assured it works perfect.

= Will this plugin slow down my site? =
No. It's coded extremely lightweight, no CSS or JS script. It does only the essential function and nothing more. Very lightweight. No heavy PHP processing or database queries. I'm an absolute speed fanatic.

= Do you plan to add more features? =
Not unless someone wanted to pay for my development time and costs. This was intended as a free community plugin. I do have to update the default .PO file soon (after I finalize the wording) so translations can be submitted.

= How do I style the text and checkbox? =
With CSS of course! It shouldn't take any developer more than 15 minutes of time.

== Screenshots ==
1. Checkbox opt-in on frontend.
2. Settings screen 1 of 2.
3. Setting screen 2 of 2.

== Changelog ==

= 1.0.2 =
- Feature: Add option to change the checkbox text
- Fix PHP Deprecated method: Methods with the same name as their class will not be constructors in a future version of PHP.
- Fix headers already sent error & deprecated capability functions
- Fix undefined variable error

= 1.0.1 =

Added more line-breaks to the default notfication email template.

= 1.0.0 =

Initial Commit. Updated to work with PHP 7.x (fixed mysqli escape strings), improved wording, admin settings styling. Unfortunately had to delete old translator files.

Was forked from version 1.4 of Denishua's original [Comment Reply Notfication](https://wordpress.org/plugins/comment-reply-notification/).

1.0.0 Initial release
