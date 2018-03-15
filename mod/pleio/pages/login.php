<?php
global $CONFIG;

require_once(dirname(__FILE__) . "/../vendor/autoload.php");

$auth = elgg_get_plugin_setting('auth', 'pleio');
$auth_url = elgg_get_plugin_setting('auth_url', 'pleio', $CONFIG->pleio->url);
$auth_client = elgg_get_plugin_setting('auth_client', 'pleio', $CONFIG->pleio->client);
$auth_secret = elgg_get_plugin_setting('auth_secret', 'pleio', $CONFIG->pleio->secret);

if (elgg_is_logged_in()) {
    forward("/");
}

$site = elgg_get_site_url();

$method = get_input("method");
$code = get_input("code");
$state = get_input("state");
$returnto = urldecode(get_input("returnto"));

$login_credentials = get_input("login_credentials");
$idp = elgg_get_plugin_setting("idp", "pleio");

if (!$auth_client || !$auth_secret || !$auth_url) {
    register_error(elgg_echo("pleio:not_configured"));
    forward(REFERER);
}

if ($auth == 'oidc') {
    $oidc = new Jumbojett\OpenIDConnectClient($auth_url, $auth_client, $auth_secret);
    $oidc->addScope(array('openid', 'profile', 'email'));
    $oidc->authenticate();

    $userid = $oidc->requestUserInfo('sub');
    $name = $oidc->requestUserInfo('name');
    $email = $oidc->requestUserInfo('email');
    $username = $oidc->requestUserInfo('nickname');

    $user = get_user_by_pleio_guid_or_email($userid, $email);
    $allow_registration = elgg_get_config("allow_registration");

    if (!$user && $allow_registration) {
        $guid = register_user(
            $username,
            generate_random_cleartext_password(),
            $name,
            $email
        );

        $db_prefix = elgg_get_config('dbprefix');
        
        if ($guid) {
            update_data("UPDATE {$db_prefix}users_entity SET pleio_guid = {$userid} WHERE guid = {$guid}");
        }
    } elseif (!$user && !$allow_registration) {
        throw new ShouldRegisterException;
    }

    if (!$user) {
        return false;
    }

    try {
        login($user);
        system_message(elgg_echo("loginok"));

        if ($user->name !== $name) {
            $user->name = $name;
        }

        if ($user->email !== $email) {
            $user->email = $email;
        }

        $user->save();

        $returnto ? "{$site}login?returnto={$returnto}" : "{$site}login";

        if ($returnto && pleio_is_valid_returnto($returnto)) {
            forward($returnto);
        } else {
            forward("/");
        }

        return true;
    } catch (\LoginException $e) {
        throw new CouldNotLoginException;
    }
} else {
    $provider = new ModPleio\Provider([
        "clientId" => $CONFIG->pleio->client,
        "clientSecret" => $CONFIG->pleio->secret,
        "url" => $CONFIG->pleio->url,
        "redirectUri" => $returnto ? "{$site}login?returnto={$returnto}" : "{$site}login"
    ]);

    if (!isset($code)) {
        $authorizationUrl = $provider->getAuthorizationUrl();
        $_SESSION["oauth2state"] = $provider->getState();

        $header = "Location: " . $authorizationUrl;

        if ($method) {
            $header .= "&method={$method}";
        }

        if ($idp && $login_credentials !== "true") {
            $header .= "&idp={$idp}";
        }

        header($header);
        exit;
    } elseif (empty($state) || $state !== $_SESSION["oauth2state"]) {
        // mitigate CSRF attack
        unset($_SESSION["oauth2state"]);
        forward("/");
    } else {
        try {
            $accessToken = $provider->getAccessToken("authorization_code", [
                "code" => $code
            ]);

            unset($_SESSION["oauth2state"]);

            $resourceOwner = $provider->getResourceOwner($accessToken);
            $loginHandler = new ModPleio\LoginHandler($resourceOwner);

            try {
                $user = $loginHandler->handleLogin();
                $user->setPrivateSetting("pleio_token", $accessToken->getToken());

                system_message(elgg_echo("loginok"));

                if ($returnto && pleio_is_valid_returnto($returnto)) {
                    forward($returnto);
                } else {
                    forward("/");
                }
            } catch (ModPleio\Exceptions\CouldNotLoginException $e) {
                register_error(elgg_echo("pleio:is_banned"));
                forward("/");
            } catch (ModPleio\Exceptions\ShouldRegisterException $e) {
                if (ModPleio\Helpers::emailInWhitelist($resourceOwner->getEmail())) {
                    $title = elgg_echo("pleio:validate_access");
                    $description = elgg_echo("pleio:validate_access:description");
                } else {
                    $title = elgg_echo("pleio:request_access");
                    $description = elgg_echo("pleio:request_access:description");
                }

                $_SESSION["pleio_resource_owner"] = $resourceOwner->toArray();

                $body = elgg_view_layout("walled_garden", [
                    "content" => elgg_view("pleio/request_access", [
                        "title" => $title,
                        "description" => $description,
                        "resourceOwner" => $resourceOwner->toArray()
                    ]),
                    "class" => "elgg-walledgarden-double",
                    "id" => "elgg-walledgarden-login"
                ]);

                echo elgg_view_page(elgg_echo("pleio:request_access"), $body, "walled_garden");
                return true;
            } catch (\RegistrationException $e) {
                register_error($e->getMessage());
                forward("/");
            }
        } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
            register_error($e->getMessage());
            forward("/");
        }
    }
}
