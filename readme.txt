=== Signalfire Content Compliance ===
Contributors: signalfire
Tags: content, compliance, review, approval, maintenance
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Ensures content compliance with legal requirements by managing maintainer reviews and approvals on a scheduled basis.

== Description ==

Signalfire Content Compliance is a comprehensive WordPress plugin designed to help website owners maintain legal compliance for their content through scheduled review processes and approval workflows.

= Key Features =

* **Scheduled Content Reviews**: Automatically schedule content reviews based on configurable frequencies (monthly, quarterly, annually)
* **Approval Workflows**: Streamlined approval process for content maintainers
* **Email Notifications**: Automatic email notifications to content maintainers when reviews are required
* **Bulk Operations**: Efficiently manage multiple content items at once
* **Compliance Tracking**: Track compliance status across all your content
* **Custom Post Type Support**: Works with posts, pages, and custom post types
* **User Role Integration**: Respects WordPress user capabilities and permissions

= How It Works =

1. **Setup**: Configure review frequency and email templates in the plugin settings
2. **Automatic Scheduling**: The plugin automatically identifies content that needs review based on your schedule
3. **Notifications**: Content maintainers receive email notifications when reviews are required
4. **Review Process**: Maintainers can review and approve content through a simple interface
5. **Compliance Tracking**: Monitor compliance status through the WordPress admin dashboard

= Use Cases =

* Legal compliance for regulated industries
* Regular content audits for accuracy
* Maintenance of time-sensitive information
* Team-based content approval workflows
* Corporate content governance

= Security Features =

* Nonce verification for all form submissions
* User capability checks throughout the plugin
* Proper input sanitization and output escaping
* Secure database operations using WordPress APIs

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/signalfire-content-compliance` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Settings > Content Compliance to configure the plugin.
4. Set your preferred review frequency and email templates.
5. The plugin will automatically begin scheduling reviews based on your configuration.

== Frequently Asked Questions ==

= What post types are supported? =

By default, the plugin supports posts and pages. You can configure additional post types in the plugin settings.

= How often can content be reviewed? =

You can set review frequencies of monthly, quarterly, or annually based on your compliance needs.

= Can I customize the email notifications? =

Yes, the plugin includes customizable email templates for both reviewer notifications and manager updates.

= What happens if a reviewer doesn't respond? =

You can configure the plugin to take various actions for non-responses, including sending reminder emails or escalating to administrators.

= Is this plugin compatible with multisite? =

The plugin is designed for single-site installations. Multisite compatibility may be added in future versions.

== Screenshots ==

1. Plugin settings page with configuration options
2. Content compliance dashboard showing review status
3. Email notification template customization
4. Bulk operations interface for managing multiple items

== Changelog ==

= 1.0.0 =
* Initial release
* Scheduled content review functionality
* Email notification system
* Bulk operations support
* Compliance tracking dashboard
* User role and capability integration

== Upgrade Notice ==

= 1.0.0 =
Initial release of Signalfire Content Compliance. No upgrade notices apply.

== Technical Requirements ==

* WordPress 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher (or equivalent MariaDB version)

== Support ==

For support questions and feature requests, please contact support@signalfire.com or visit our website at https://signalfire.com.

== Privacy ==

This plugin stores compliance review data in your WordPress database. Email addresses are used solely for sending review notifications. No data is transmitted to external servers beyond standard WordPress email functionality.