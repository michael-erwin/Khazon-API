<?php

$router->get('/', function() {return "";});
$router->group(['prefix'=>'v1'], function() use($router) {
    /**
     * FRONT END
     */

    # Account
    $router->get('/ping', function() {return response()->json(['message'=>'pong'], 200);});
    $router->get('/account','AccountController@index');
    $router->get('/account/recover','AccountController@recoverNewRequest');
    $router->get('/account/recover/{token:[a-zA-Z0-9_\-\+=]+\.[a-zA-Z0-9_\-\+=]+\.[a-zA-Z0-9_\-\+=]+}','AccountController@verifyResetToken');
    $router->post('/account/auth','AccountController@auth');
    $router->post('/account/auth_verify','AccountController@authVerify');
    $router->post('/account/email_verify','AccountController@emailVerify');
    $router->post('/account/register','AccountController@register');
    $router->post('/account/recover','AccountController@recover');
    $router->post('/account/recover/check_answer','AccountController@checkAnswer');
    $router->put('/account','AccountController@update');

    # Transactions
    $router->get('/transactions/my_account/items','TransactionsController@showListFromMyAccount');
    $router->get('/transactions/transfer/{id:[0-9]+}','TransactionsController@showTransferItem');
    $router->post('/transactions/my_account/transfer','TransactionsController@transferFromMyAccount');
    $router->post('/transactions/my_account/withdraw','TransactionsController@withdrawFromMyAccount');
    $router->delete('/transactions/my_account/items/{id:[0-9]+}','TransactionsController@deleteFromMyAccount');

    # Referrals
    $router->get('/referrals/user','ReferralsController@readMine');
    $router->get('/referrals/user/{id:[0-9]+}','SafesController@readId');

    # Safes
    $router->get('/safes/location/{location:\d+\.\d+\.\d+}','SafesController@readLocation');
    $router->get('/safes/user','SafesController@readMine');
    $router->get('/safes/user/{id:[0-9]+}','SafesController@readId');
    $router->post('/safes/unlock','SafesController@unlock');

    # Settings
    $router->get('/settings','SettingsController@index');
    $router->get('/settings/new_2fa','SettingsController@makePrivateKey');
    $router->post('/settings/auth','SettingsController@auth');
    $router->put('/settings/{field:[0-9a-z_]+}','SettingsController@updateField');

    # Stats
    $router->get('/stats/user','StatsController@readMine');
    $router->get('/stats/dashboard','StatsController@readDashboard');

    # Users
    $router->get('/users/{id:[0-9]+}','UsersController@readId');
    $router->get('/users/{address:0x[0-9a-fA-F]{40}}','UsersController@readAddress');

    /**
     * BACK END
     */

    # Users
    $router->get('/users','UsersController@index');
    $router->get('/users/generate','UsersController@generate');
    $router->post('/users','UsersController@create');
    $router->put('/users/{id}','UsersController@update');
    $router->delete('/users/{id}','UsersController@delete');

    # CUK
    $router->get('/cuks','CuksController@index');
    $router->post('/cuks/generate','CuksController@create');
    $router->put('/cuks/{id}','CuksController@update');

    # Transactions
    $router->get('/transactions','TransactionsController@index');
    $router->get('/transactions/{id:[0-9]+}','TransactionsController@show');
    $router->get('/transactions/user/{identifier}','TransactionsController@showByUser');
    $router->post('/transactions/pay/{id:[0-9]+}','TransactionsController@payUser');
    $router->put('/transactions/{id:[0-9]+}','TransactionsController@update');

});