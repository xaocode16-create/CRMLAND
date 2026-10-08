<?php

test('redirects the public entry point to login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});
