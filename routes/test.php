<?php
$router->get('/shell', function () {
    return "";
});
$router->get('/db/reset', function () {
    header('Content-Type: text/plain');
    print_r(`php ../artisan migrate:refresh`);
    print_r(`php ../artisan db:seed`);
});

$router->group(['prefix'=>'test'], function() use($router) {
	$router->get('/node-balance', 'TestsController@NodeBalance');
	$router->get('/rate-limit', 'TestsController@RateLimit');
});