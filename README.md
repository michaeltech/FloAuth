# FloAuth WordPress plugin

FloAuth is a WordPress plugin for bridging FloMembers membership management and a WordPress website.

## Features

* Allows login through FloMembers with no need for WordPress login credentials
* Checks and changes user role on each login (if user's FloMembers role has changed)
* Extranet (pages restricted to logged-in users only, **optional**)
  * Restricts access to extranet page and its children
  * Removes extranet pages from search results
* Hides WordPress toolbar from Subscribers

## Installation

1. Download the plugin
2. Upload directory to `wp-content/plugins`
3. Activate the plugin

## Filters

### Add meta data fields to user on login

For example save person address fields for WooCommerce

```php
function custom_floauth_add_user_meta_data( $meta_data, $parameters ) {
	if ( isset( $parameters['address'] ) ) {
		$meta_data['billing_address_1'] = $parameters['address'];
	}
	return $meta_data;
}
add_filter( 'floauth_add_user_meta_data', 'custom_floauth_add_user_meta_data', 10, 2 );
```

For example add a custom meta field according to FloMembers groups this person belongs to

```php
function custom_floauth_add_user_meta_data( $meta_data, $parameters ) {
	$group_to_test = '1';
	if ( isset( $parameters['groups'] ) ) {
		$user_group_ids = explode( ',', $parameters['groups'] );
		if ( in_array( $group_to_test, $user_group_ids ) ) {
			// Add some meta field
			$meta_data['custom_field'] = 'some value';
		}
	}
	return $meta_data;
}
add_filter( 'floauth_add_user_meta_data', 'custom_floauth_add_user_meta_data', 10, 2 );
```

### Change user role according to request parameters

For example if user is not a website admin and does not have a member role in FloMembers, you can assign another role

```php
function custom_floauth_assign_role_for_user( $role, $parameters ) {
	if ( isset( $parameters['ismember'] ) && intval( $parameters['ismember'] ) !== 1 && $parameters['role'] !== 'admin'  ) {
		$role = 'subscriber';
	}
	return $role;
}
add_filter( 'floauth_assign_role_for_user', 'custom_floauth_assign_role_for_user', 10, 2 );
```

### Filter roles which are checked and removed upon login

For example if you have BBPress installed and don't want FloAuth to remove those roles from persons

```php
function custom_floauth_filter_roles_to_check_on_login( $roles ) {
	if ( function_exists( 'bbp_get_dynamic_roles' ) ) {
		$bbpress_roles = array_keys( bbp_get_dynamic_roles() );
		$roles = array_diff( $roles, $bbpress_roles );
	}
	return $roles;
}
add_filter( 'floauth_filter_roles_to_check_on_login', 'custom_floauth_filter_roles_to_check_on_login' );
```

### Modify path to which user is redirected

For example redirect user back to where they came from, if `forum` parameter is present in the request (`forum` is supported by FloMembers)

```php
function custom_floauth_modify_redirect_path( $redirect_path, $parameters ) {
	if ( isset( $parameters['forum'] ) ) {
		// In order to work, "forum" parameter should include a path to a page, e.g. ?forum=another/page
		$redirect_path = $parameters['forum'];
	}
	return $redirect_path;
}
add_filter( 'floauth_modify_redirect_path', 'custom_floauth_modify_redirect_path', 10, 2 );
```

### Modify redirect path for user with no access

```php
function custom_floauth_restrict_extranet_block_redirect( $url ) {
	// Do some magic
	return $url;
}
add_filter( 'floauth_restrict_extranet_block_redirect', 'custom_floauth_restrict_extranet_block_redirect' );
```

### Change capability for hiding extranet pages

For example hide extranet pages from Subscriber also (default capability is `read`)

```php
function custom_restrict_extranet_pages_capability( $capability ) {
	return 'edit_posts';
}
add_filter( 'floauth_restrict_extranet_pages_capability', 'custom_restrict_extranet_pages_capability' );
```
More info on WordPress [Roles and Capabilities](https://wordpress.org/support/article/roles-and-capabilities/)

### Keep WordPress toolbar always visible

```php
add_filter( 'floauth_hide_admin_bar_from_users', '__return_false' );
```

### Change capability for hiding toolbar

For example hide toolbar from Contributor also (default capability is `edit_posts`)

```php
function custom_hide_admin_bar_capability( $capability ) {
	return 'edit_published_posts';
}
add_filter( 'floauth_hide_admin_bar_capability', 'custom_hide_admin_bar_capability' );
```

### Use FloMembers ID as user_login

FloMembers ID can be used as the matching attribute when a user logs in. This can be useful if you need to ensure the corresponding WordPress user stays intact even if a member changes their e-mail address.

**Note!** Existing users need to be removed from WordPress before adding this filter. Otherwise the new user with ID as `user_login` cannot be created as their email already exists.

```php
function custom_floauth_parameter_for_matching_user( $parameter ) {
	// Possible values: 'id' | 'email' (default)
	return 'id';
}
add_filter( 'floauth_parameter_for_matching_user', 'custom_floauth_parameter_for_matching_user' );
```

## Test logins

Login can be tested without access to a FloMembers installation.

**Admin login:**
```
https://example.org?floauth&id=123&username=john.doe%40email.com&firstname=John&lastname=Doe&role=admin&ismember=1&hash=examplehash
```

**Regular user login:**
```
https://example.org?floauth=pages&id=123&username=john.doe%40email.com&firstname=John&lastname=Doe&role=member&ismember=1&hash=examplehash
```

### Parameters

- `address`, `extraaddress`, `postoffice`, `postcode`, `mobile`, `phone`: Contact details
- `firstname`: Person's first name
- `floauth`: Can be used as a flag (for admin login) or with a value `pages` for regular users.
- `forum`: Forum parameter (optional)
- `groups`: Comma-separated group IDs
- `hash`: Security hash (MD5 of secret key + email)
- `id`: Person's ID in FloMembers
- `ismember`: `1` if member or admin, otherwise `0`
- `lastname`: Person's last name
- `role`: Person's role (`admin` or `member`)
- `roles`: Comma-separated role IDs
- `username`: Person's email
