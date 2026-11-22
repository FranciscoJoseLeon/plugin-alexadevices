<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Unauthorized access', __FILE__));
    }

    ajax::init();

    $action = init('action');

    if ($action == 'getAuthStatus') {
        try {
            $health = alexadevices::callDaemon('/api/health', array(), 'GET');
            ajax::success($health);
        } catch (Exception $e) {
            ajax::error($e->getMessage());
        }
    }

    if ($action == 'loginAmazon') {
        $username = init('username');
        $password = init('password');
        $code = init('code');
        $domain = init('domain', 'es');

        if (empty($username) || empty($password) || empty($code)) {
            throw new Exception(__('You must provide username, password, and OTP code.', __FILE__));
        }

        $domainUrls = array(
            'es' => 'https://www.amazon.es',
            'us' => 'https://www.amazon.com',
            'fr' => 'https://www.amazon.fr',
            'de' => 'https://www.amazon.de',
            'it' => 'https://www.amazon.it',
            'gb' => 'https://www.amazon.co.uk',
            'mx' => 'https://www.amazon.com.mx',
            'ca' => 'https://www.amazon.ca',
            'jp' => 'https://www.amazon.co.jp',
            'br' => 'https://www.amazon.com.br',
            'au' => 'https://www.amazon.com.au',
        );

        $site = isset($domainUrls[$domain]) ? $domainUrls[$domain] : 'https://www.amazon.' . $domain;

        $loginPayload = array(
            'username' => $username,
            'password' => $password,
            'code' => $code,
            'site' => $site
        );

        try {
            $result = alexadevices::callDaemon('/api/login', $loginPayload, 'POST');
            config::save('amazon_username', $username, 'alexadevices');
            config::save('amazon_domain', $domain, 'alexadevices');
            ajax::success($result);
        } catch (Exception $e) {
            ajax::error($e->getMessage());
        }
    }

    if ($action == 'syncDevices') {
        try {
            $result = alexadevices::syncDevices();
            ajax::success($result);
        } catch (Exception $e) {
            ajax::error($e->getMessage());
        }
    }

    if ($action == 'submitManualCode') {
        $code = init('code');
        $domain = init('domain', 'es');
        if (empty($code)) {
            throw new Exception(__('Please provide an authorization code or return URL.', __FILE__));
        }

        try {
            $result = alexadevices::callDaemon('/api/manual_code', array('code' => $code, 'domain' => $domain), 'POST');
            ajax::success($result);
        } catch (Exception $e) {
            ajax::error($e->getMessage());
        }
    }

    if ($action == 'getProxyStatus') {
        try {
            $status = alexadevices::callDaemon('/api/proxy_status', array(), 'GET');
            ajax::success($status);
        } catch (Exception $e) {
            ajax::error($e->getMessage());
        }
    }

    if ($action == 'refreshRoutines') {
        try {
            $routines = alexadevices::syncRoutines(true);
            ajax::success(array(
                'routines' => $routines,
                'updated_at' => config::byKey('routines_updated_at', 'alexadevices', ''),
            ));
        } catch (Exception $e) {
            ajax::error($e->getMessage());
        }
    }

    if ($action == 'reloadAllCommands') {
        try {
            $count = alexadevices::reloadAllCommands();
            ajax::success(array('count' => $count));
        } catch (Exception $e) {
            ajax::error($e->getMessage());
        }
    }

    if ($action == 'deleteAllAndRescan') {
        try {
            $res = alexadevices::deleteAllAndRescan();
            ajax::success($res);
        } catch (Exception $e) {
            ajax::error($e->getMessage());
        }
    }

    if ($action == 'resetAmazonSession') {
        try {
            alexadevices::resetAmazonSession();
            ajax::success(true);
        } catch (Exception $e) {
            ajax::error($e->getMessage());
        }
    }

    throw new Exception(__('No valid action provided.', __FILE__));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
