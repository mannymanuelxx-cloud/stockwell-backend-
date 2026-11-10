<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

// Open the separate application frontend by default.
$router->get('/', function () {
	header('Location: ../frontend/');
	exit;
});

// JSON API used by the separate frontend.
$router->options('/api/{resource}/{first?}/{second?}/{third?}', function () {
    lava_instance()->call->library('api');
});

$router->get('/api/products', 'ApiController::index');
$router->post('/api/products', 'ApiController::store');
$router->get('/api/stock-adjustments', 'ApiController::stockAdjustments');
$router->post('/api/products/{id}/stock-adjustments', 'ApiController::adjustStock');
$router->post('/api/products/{id}/image', 'ApiController::uploadImage');
$router->get('/api/products/{id}', 'ApiController::show');
$router->put('/api/products/{id}', 'ApiController::update');
$router->delete('/api/products/{id}', 'ApiController::destroy');
$router->get('/api/customers', 'BusinessController::customers');
$router->post('/api/customers', 'BusinessController::createCustomer');
$router->put('/api/customers/{id}', 'BusinessController::updateCustomer');
$router->delete('/api/customers/{id}', 'BusinessController::deleteCustomer');
$router->get('/api/suppliers', 'BusinessController::suppliers');
$router->post('/api/suppliers', 'BusinessController::createSupplier');
$router->put('/api/suppliers/{id}', 'BusinessController::updateSupplier');
$router->delete('/api/suppliers/{id}', 'BusinessController::deleteSupplier');
$router->get('/api/sales', 'BusinessController::sales');
$router->post('/api/sales', 'BusinessController::createSale');
$router->get('/api/sales/{id}', 'BusinessController::sale');
$router->put('/api/sales/{id}', 'BusinessController::updateSale');
$router->delete('/api/sales/{id}', 'BusinessController::deleteSale');
$router->get('/api/orders', 'BusinessController::orders');
$router->post('/api/orders', 'BusinessController::createOrder');
$router->patch('/api/orders/{id}/payment', 'BusinessController::updateOrderPayment');
$router->patch('/api/orders/{id}/tracking', 'BusinessController::updateOrderTracking');
$router->get('/api/orders/{id}', 'BusinessController::order');
$router->put('/api/orders/{id}', 'BusinessController::updateOrder');
$router->patch('/api/orders/{id}', 'BusinessController::completeOrder');
$router->delete('/api/orders/{id}', 'BusinessController::deleteOrder');
$router->get('/api/purchases', 'BusinessController::purchases');
$router->post('/api/purchases', 'BusinessController::createPurchase');
$router->get('/api/purchases/{id}', 'BusinessController::purchase');
$router->put('/api/purchases/{id}', 'BusinessController::updatePurchase');
$router->patch('/api/purchases/{id}', 'BusinessController::receivePurchase');
$router->delete('/api/purchases/{id}', 'BusinessController::deletePurchase');
$router->get('/api/reports', 'BusinessController::reports');
$router->get('/api/admin-notifications', 'BusinessController::adminNotifications');
$router->get('/api/activity', 'BusinessController::activity');
$router->get('/api/returns', 'BusinessController::returns');
$router->patch('/api/returns/{id}', 'BusinessController::updateReturn');
$router->get('/api/delivery-zones', 'BusinessController::deliveryZones');
$router->post('/api/delivery-zones', 'BusinessController::createDeliveryZone');
$router->put('/api/delivery-zones/{id}', 'BusinessController::updateDeliveryZone');
$router->delete('/api/delivery-zones/{id}', 'BusinessController::deleteDeliveryZone');
$router->get('/api/backup', 'BusinessController::backup');
$router->get('/api/users', 'UsersController::index');
$router->post('/api/users', 'UsersController::store');
$router->patch('/api/users/{id}', 'UsersController::updateRole');
$router->put('/api/users/{id}/permissions', 'UsersController::updatePermissions');
$router->delete('/api/users/{id}', 'UsersController::destroy');
$router->get('/api/shop/products', 'ShopController::products');
$router->get('/api/shop/products/{id}/reviews', 'ShopController::productReviews');
$router->post('/api/shop/products/{id}/reviews', 'ShopController::createProductReview');
$router->get('/api/shop/delivery-zones', 'ShopController::deliveryZones');
$router->get('/api/shop/orders', 'ShopController::orders');
$router->get('/api/shop/notifications', 'ShopController::notifications');
$router->patch('/api/shop/notifications/read', 'ShopController::markNotificationsRead');
$router->get('/api/shop/orders/{id}', 'ShopController::order');
$router->post('/api/shop/orders/{id}/cancel', 'ShopController::cancelOrder');
$router->post('/api/shop/orders', 'ShopController::checkout');
$router->get('/api/shop/profile', 'ShopController::profile');
$router->put('/api/shop/profile', 'ShopController::updateProfile');
$router->post('/api/shop/addresses', 'ShopController::addAddress');
$router->patch('/api/shop/addresses/{id}/default', 'ShopController::setDefaultAddress');
$router->delete('/api/shop/addresses/{id}', 'ShopController::deleteAddress');
$router->get('/api/shop/returns', 'ShopController::returns');
$router->post('/api/shop/returns', 'ShopController::createReturn');
$router->get('/api/auth/session', 'AuthController::sessionStatus');
$router->get('/api/auth/public-config', 'AuthController::publicConfig');
$router->post('/api/auth/login', 'AuthController::apiLogin');
$router->get('/api/auth/google/start', 'AuthController::googleStart');
$router->get('/api/auth/google/callback', 'AuthController::googleCallback');
$router->post('/api/auth/register', 'AuthController::apiRegister');
$router->post('/api/auth/forgot-password', 'AuthController::forgotPassword');
$router->post('/api/auth/reset-password', 'AuthController::resetPassword');
$router->post('/api/auth/logout', 'AuthController::apiLogout');

// Authentication routes (no middleware)
$router->get('/login', 'AuthController::login');
$router->post('/login', 'AuthController::login');
$router->post('/logout', 'AuthController::logout');
