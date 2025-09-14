# Wedding_RSVP_Plugin
A Wordpress plugin to be able to manage a guest list and enable a guest lookup to relate to an rsvp entry for editing and confirmation


Install: copy folder to wp-content/plugins/, then activate plugin in WP admin. Shortcode: [wedding_rsvp_form]. Elementor widget available. Configure SMTP under Settings->Wedding RSVP.

# Version 1 (basic prototype)
- A simple shortcode to render an RSVP form.
- Stored entries in a custom database table.
- Very limited admin interface (just viewing entries).
- No partner linking, no email integration.

# Version 2 (Elementor support + Email confirmation)
- Added an Elementor widget so the form could be dropped into pages with drag-and-drop.
- Added basic email confirmation (plain email sent after RSVP).
- Still very simple guest handling.

# Version 3–4 (SMTP + expanded settings)
- Introduced SMTP configuration options inside plugin settings.
- Allowed sending email confirmations through Gmail/Google or any SMTP server.
- Admin got some basic settings fields for From Name/Email.
- Guests still mostly read-only in backend.