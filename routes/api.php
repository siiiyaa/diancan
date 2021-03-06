<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductSkuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ShopController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// 登录
Route::post('user/login', [UserController::class, 'login']);

Route::middleware('jwt.auth')->group(function () {
    // 用户基本信息
    Route::get('user', [UserController::class, 'show']);
    // 显示所有商品
    Route::get('products', [ProductController::class, 'index']);
    // 展示特定商品
    Route::get('products/{product}', [ProductController::class, 'show']);
    // 特定商品规格信息
    Route::post('products/{product}/sku', [ProductSkuController::class, 'show']);
    // 创建订单
    Route::post('orders', [OrderController::class, 'store']);
    // 取消订单
    Route::put('orders/{order}/cancel', [OrderController::class, 'cancel']);
    // 订单详情
    Route::get('orders/{id}', [OrderController::class, 'show']);
    // 商店详情
    Route::get('shops/{id}', [ShopController::class, 'show'])->name('shops.show');

});


