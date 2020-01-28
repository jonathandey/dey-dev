---
extends: _layouts.post
section: content
title: How to use your OctoberCMS site as a SAML Identity Provider
date: 2020-01-28
description: Using your existing OctoberCMS website as an IdP server.
cover_image: /assets/img/authentication.svg
published: true
featured: true
---

No one likes having to sign-up to multiple services. For that reason you should try to avoid making your customers do exactly that for the services you provide.

Recently a customer asked if I could configure SAML authentication between their OctoberCMS website and their Zoho support software. They wanted their users to transition from their website to the support site with a Single Sign-On (SSO) solution.

## Engineering a solution

SAML is an authentication protocol commonly used to provide an SSO solution for companies with similar problem to my customer - there are [plenty of good articles](https://auth0.com/blog/how-saml-authentication-works/) around the web [about SAML](https://developers.onelogin.com/saml), for that reason we won't be going in to how SAML works here, instead we'll just be focusing on how to use your OctoberCMS website as a SAML Identity Provider.

OctoberCMS provides a very good [User plugin](https://octobercms.com/plugin/rainlab-user), but at the time of writing there is no SAML Server or SAML Identity Provider plugin. The SAML solution this article provides will require you have the User plugin installed on your website.

I knew that someone else in the past would have been in a similar situation to me, so I did as every good developer does and started searching for "Laravel SAML server". Unfortunately the top result is a Service Provider package, rather than an Identity Provider (IdP) package. A little further down the results I found this [Laravel SAML Github repository](https://github.com/kingstarter/laravel-saml) by kingstarter. This repository in-turn references a [SAML implementation guide by Dustin Parham](https://imbringingsyntaxback.com/implementing-a-saml-idp-with-laravel/), which has a good write-up about how this repository works, I'd recommend you read that first. You will find that this package is an implementation of that guide.

### Implementing kingstarter/laravel-saml package with OctoberCMS

First I created a new octoberCMS plugin

```
php artisan create:plugin JD.SAML
```

Following [the instructions](https://github.com/kingstarter/laravel-saml#installation) I installed the package within the newly created plugin. I got to the part about generating metadata and certs for the package

```
mkdir -p storage/saml/idp
touch storage/saml/idp/{metadata.xml,cert.pem,key.pem}
```

The instructions include a link to a metadata generator but don't go as far as to include information on how to generate the certs. You can generate the certs using the same website as the metadata generator by using their [X.509 Self-Signed generator](https://www.samltool.com/self_signed_certs.php). Put the Private Key contents in to the `key.pem` file and the X.509 cert contents in to the `cert.pem` file.

> I'd highly recommend you make a new pair of pem files for each of your environments. For example, a pair for your dev environment and another for your production.

> To ensure you do not upload your dev certs to your production environment add a .gitignore file to the above idp folder with the below contents. This does mean that you will have to generate the 3 files described above individually for each of your environments.

In `storage/saml/idp/.gitignore` add

```
*
.gitignore
```

Continue following the instructions of the package until you get to the section "[Using the SAML package](https://github.com/kingstarter/laravel-saml#using-the-saml-package)". The instructions at that point now need to deviate from what you would do using a vanilla Laravel application compared to your OctoberCMS website. Follow along here to find out what to do next.

Before going any further I'll describe briefly how this package is to be used. If you have already taken a look at the examples you will see that the SAML response shouldn't be given until the user has authenticated themselves and your application has been sent a `SAMLRequest` query string from the Service Provider (SP). As well as the `SAMLRequest` parameter the SP will send a `RelayState` parameter. Both of these parameters need to be (eventually) returned to the SP to complete the authentication flow. In other words, we need to capture these parameters, then send them back once the user has authenticated themselves.

To begin implementing this flow we will create a component for the plugin we created earlier:

```
php artisan create:component JD.SAML SSO
```

Now you have a boiler plate component within your plugin, go the the `components/SSO.php` file, then add the `onRun` method.

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

Enable the component via the `registerComponent` method in your `Plugin.php` file.

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

Now find the page within your theme that hosts the `[account]` component, this should be the page that is used to authenticate your users. Add the `[sso]` component to the same page.

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

This component will capture the query parameters `SAMLRequest` and `RelayState`. The `SAMLRequest` parameter will be added to a hidden field, while the `RelayState` parameter will be combined with the current page url as well as the `SAMLRequest` to form a redirect URL, which in turn will be added to a hidden field. Both hidden fields should be applied to your login form.

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

>If you haven't already, you will need to [override the component partial](https://octobercms.com/docs/cms/components#overriding-partials) (`signin.htm`) for RainLab.User Account to add these fields.

The hidden redirect field will force a successful user authentication back to the same page the user was on, by doing so it will ensure the page has the the required query parameters `SAMLRequest` and `RelayState`.

Next, if the user was authenticated successfully, we will consume those parameters to build a SAML response that will be sent back to SP to log the user in.

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

Enable the new component in your Plugin.php file:

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

Add the `[samlRequest]` component to the account page we added `[sso]` to previously.

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

Now the overridden redirect will be captured because (hopefully) the user was authenticated successfully and your request contains the query string `SAMLRequest`. The rest is handled by the [kingstarter/larave;-saml package](https://github.com/kingstarter/laravel-saml). If you wanted to know exactly what happens next, and if you haven't already go back and read the original guide to [implementing SAML with Laravel](https://imbringingsyntaxback.com/implementing-a-saml-idp-with-laravel/) by Dustin Parham.

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