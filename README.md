# wp-kosmos-notifications
A mini WordPress plugin to provide a REST endpoint for KosmOS notifications.

## Installation
- Put php script in ``wp-content/plugins/kosmos-notifications/kosmos-notifications.php``
- Create a Category with slug ``notifications``
- Using **ACF** create a Field Group attached to the ``notifications``category
  - **notify_users** (true/false),
  - **start_date** (date),
  - **end_date** (date),
  - **priority** (select low/normal/high)
  - **notification_text** (text)
  - **notification_link** (URL, optional)
- Ensure all fields have _Show in REST API_ set.
