---
extends: _layouts.post
section: content
title: How to use your OctoberCMS site as a SAML Identity Provider
date: 2020-01-28
description: Using your existing OctoberCMS site as an IdP server.
cover_image: /assets/img/authentication.svg
published: true
featured: true
---

No one likes having to sign-up to multiple services. For that reason you should try to avoid making your customers do exactly that for the services you provide.

Recently a customer asked if I could configure SAML authentication between their OctoberCMS site and their Zoho support software. They wanted their users to transition from their site to the support site with a Single Sign-On (SSO) solution.

## Engineering a solution

SAML is an authentication protocol commonly used to provide an SSO solution for companies with similar problem to my customer - there are [plenty of good articles](https://auth0.com/blog/how-saml-authentication-works/) around the web [about SAML](https://developers.onelogin.com/saml), for that reason I won't be going in to how SAML works here, instead I'll just be focusing on how to use your OctoberCMS site as a SAML Identity Provider.

OctoberCMS provides a very good [User plugin](https://octobercms.com/plugin/rainlab-user), but at the time of writing there is no SAML Server or SAML Identity Provider plugin. The SAML solution this article provides will require you have the User plugin installed on your site.

I knew that someone else in the past would have been in a similar situation to me, so I did as every good developer does and started searching for "Laravel SAML server". Unfortunately the top result is a Service Provider package, rather than an Identity Provider (IdP) package. A little further down the results I found this [Laravel SAML Github repository](https://github.com/kingstarter/laravel-saml) by kingstarter. This repository in-turn references a [SAML implementation guide by Dustin Parham](https://imbringingsyntaxback.com/implementing-a-saml-idp-with-laravel/), which has a good write-up about how this repository works, I'd recommend you read that first. You will find that this package is an implementation of that guide.

### Implementing kingstarter/laravel-saml package with OctoberCMS

First I created a new octoberCMS plugin

```
php artisan create:plugin JD.SAML
```

Following [the instructions](https://github.com/kingstarter/laravel-saml#installation) of the package I used composer to install it to the `JD.SAML` plugin. When I got to the part about generating metadata and certs for the package

```
mkdir -p storage/saml/idp
touch storage/saml/idp/{metadata.xml,cert.pem,key.pem}
```

They included a link to a metadata generator but didn't go as far as to include information on how to generate the certs. You can generate the certs using the same site as the metadata generator by using their [X.509 Self-Signed generator](https://www.samltool.com/self_signed_certs.php). Put the Private Key contents in to the `key.pem` file and the X.509 cert contents in to the `cert.pem` file.

> I'd highly recommend that you make a new pair of pem files for each of your environments. For example, a pair for your dev environment and another for your production.

> To ensure you do not upload these 3 files to your production environment add a .gitignore file to the above idp folder with the below contents. This does mean that you will have to generate the 3 files described above individually for each of your environments.

In `storage/saml/idp/.gitignore` add

```
*
.gitignore
```

Continue following the instructions of the package until you get to the section "[Using the SAML package](https://github.com/kingstarter/laravel-saml#using-the-saml-package)". The instructions at this point now need to deviate from what you would do using a vanilla Laravel application compared to your OctoberCMS site. Follow along here to find out what to do next.

Before going any further I'll describe briefly how this package is to be used. If you have already taken a look at the examples you will see that the SAML response shouldn't be given until the user has authenticated themselves (successfully) and your application has been sent a `SAMLRequest` query string from the Service Provider (SP). As well as the `SAMLRequest` parameter the SP will send a `RelayState` parameter. Both of these parameters need to be (eventually) returned to the SP to complete the authentication flow. In other words, your site needs to capture these parameters, then send them back once the user has been successfully authenticated.

To begin implementing this flow create a component for the plugin created earlier:

```
php artisan create:component JD.SAML SSO
```

Now you have a boiler plate component within your plugin, go the the `components/SSO.php` file to add the following `onRun` method.

```php
public function onRun()
{
	$query = http_build_query([
		'SAMLRequest' => post('SAMLRequest'),
		'RelayState' => post('RelayState'),
	]);

	$this->page['ssoSAMLRequest'] = post('SAMLRequest');
	$this->page['ssoSAMLRequestRedirect'] = $this->controller->currentPageUrl() . '?' . $query;
}
```

Enable the component within the `registerComponent` method of your `Plugin.php` file.

```php
/**
 * Registers any front-end components implemented in this plugin.
 *
 * @return array
 */
public function registerComponents()
{
	return [
		'JD\SAML\Components\SSO' => 'sso',
	];
}
```

Now find the page within your theme that hosts the `[account]` component, this should be the page that is used to login your users. Add the `[sso]` component to the same page.

```
title = "Account"
url = "/account/:code?"
layout = "default"

[account]
redirect = "account"
paramCode = "code"

[sso]
==

...
```

This component will capture the query parameters `SAMLRequest` and `RelayState` sent by the SP. The `SAMLRequest` parameter will be added to a hidden field, while the `RelayState` parameter will be combined with the current page url as well as the `SAMLRequest` to form a redirect URL, which in turn will be added to a hidden field. Both hidden fields should be applied to your login form.

```
{{ form_ajax('onSignin') }}

    <div class="form-group">
        <label for="userSigninLogin">{{ loginAttributeLabel }}</label>
        <input
            name="login"
            type="text"
            class="form-control"
            id="userSigninLogin"
            placeholder="Enter your {{ loginAttributeLabel|lower }}" />
    </div>

    <div class="form-group">
        <label for="userSigninPassword">Password</label>
        <input
            name="password"
            type="password"
            class="form-control"
            id="userSigninPassword"
            placeholder="Enter your password" />
    </div>

	{% if ssoSAMLRequest %}
		<input type="hidden" id="SAMLRequest" name="SAMLRequest" value="{{ ssoSAMLRequest }}">
	{% endif %}
	{% if ssoSAMLRequestRedirect %}
		<input type="hidden" id="SAMLRequestRedirect" name="redirect" value="{{ ssoSAMLRequestRedirect }}">
	{% endif %}	

    <button type="submit" class="btn btn-default">Sign in</button>

{{ form_close() }}
```

>If you haven't already, you will need to [override the component partial](https://octobercms.com/docs/cms/components#overriding-partials) (`signin.htm`) for RainLab.User Account to add these hidden fields.

The hidden redirect field will force a successful user authentication back to the same page the user was on, by doing so it will ensure the page has the the required query parameters (`SAMLRequest` and `RelayState`).

If the user was authenticated successfully the component will consume those redirect parameters to build a SAML response that will be sent back to the SP. The SP will use these parameters and SAML response to authenticate the user on their application.

Create a new component:

```
php artisan create:component JD.SAML SAMLRequest
```

Add the following method to the SAMLRequest.php component file:

```php
public function init()
{
	if (Auth::check() && Input::has('SAMLRequest')) {
		return $this->handleSamlLoginRequest(request());
	}
}
```

Just as before, enable the new component in your Plugin.php file:

```php
/**
 * Registers any front-end components implemented in this plugin.
 *
 * @return array
 */
public function registerComponents()
{
	return [
		'JD\SAML\Components\SSO' => 'sso',
		'JD\SAML\Components\SAMLRequest' => 'samlRequest',
	];
}
```

Add the newly created `[samlRequest]` component to the account page that `[sso]` was added to previously.

```
title = "Account"
url = "/account/:code?"
layout = "default"

[account]
redirect = "account"
paramCode = "code"

[sso]

[samlRequest]
==

...
```

The overridden redirect will be captured by the `[samlRequest]` component if the user was authenticated successfully. The rest is handled by the [kingstarter/laravel-saml package](https://github.com/kingstarter/laravel-saml). If you want to know exactly what happens next, and if you haven't already, go back and read the original guide to [implementing SAML with Laravel](https://imbringingsyntaxback.com/implementing-a-saml-idp-with-laravel/) by Dustin Parham.

#### Thanks

Thanks for reading...

Special thanks to [Dustin](https://imbringingsyntaxback.com/) and [Kingstarter](https://kingstarter.de/) for the work they have done.

If you spotted a mistake, or would like to help improve this document in another way please [file a bug on Github](https://github.com/jonathandey/dey-dev).

---

### Things to note

__Testing the implementation__

When I tried testing this locally I had an SP running on a separate application to the one I was implementing the IdP. Both applications ran on my localhost address (127.0.0.1), which led to the error: `"unserialize(): Error at offset 0 of 40 bytes"`. The solution was to run each application (the SP and IdP) on their own local domain (see cookies, same domain and different application keys).

__Missing NameID__

In some cases when testing this I got an error indicating that the NameID parameter was missing. To resolve this I added the key value to the SP array in `config/saml.php`

```
...

'sp' => [
	'base64encodedapplication' => [
		...

		'nameID' => 'email',
	]
];

```

__Debugging communication between the IdP and SP__

Move the value of `debug_saml_request` from `config/saml.php` to an environment variable in your .env file.

```
'debug_saml_request' => env('SAML_DEBUG', false)
```

Add to your .env file

```
SAML_DEBUG=true
```

>Ensure debugging is turned off in production.

This will help you to ensure that you have used the correct address for the base64 encoded configuration string in the SP array. The information will be logged to your `system.log` file. 