<?php
$router->group(['prefix'=>'utils'], function() use($router) {
    $router->get('/phpinfo', 'UtilsController@phpInfo');
    $router->get('/temp', 'UtilsController@temp');
	$router->get('/check-node-balance', 'UtilsController@checkNodeBalance');
    $router->get('/check-rate-limit', 'UtilsController@checkRateLimit');
    $router->get('/chamber-parent/{location:\d+\.\d+\.\d+}', 'UtilsController@chamberParent');
    $router->get('/safe-coords/{location:\d+\.\d+\.\d+}', 'UtilsController@safeCoords');
    $router->get('/safe-map/{location:\d+\.\d+\.\d+}', 'UtilsController@safeMap');
    $router->get('/safe-map-all/{location:\d+\.\d+\.\d+}', 'UtilsController@safeMapAll');
    $router->get('/user-balance/{identifier}', 'UtilsController@userBalance');
    $router->get('/user-chambers/{identifier}', 'UtilsController@userChambers');
    $router->get('/user-transactions/{identifier}', 'UtilsController@userTransactions');
});