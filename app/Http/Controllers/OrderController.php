<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateOrderRequest;
use App\Jobs\CancelOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSku;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    use ApiResponse;
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * 创建订单
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function store(CreateOrderRequest $request, ProductSku $productSku, Order $order, Product $product)
    {
        // 验证是否有充足的库存，创建订单，商品扣库存，加销量
        // 记住一个原则：一锁二判三更新！！！
        $user = auth('api')->user();

        $productSkus = $request->input('product_skus'); // 商品sku id数组
        // [['product_sku_id' => 1, 'num' => 2], ['product_sku_id' => 2, 'num' => 3]]
        $remark = $request->input('remark'); // 订单备注
        $orderType = $request->input('order_type', Order::DELIVERY_TO_TABLE); // 订单类型，默认为送餐到桌
        $takeTime = $request->input('take_time'); // 自取时间
        $deskNum = $request->input('desk_num'); // 桌台号
        $shopId = $user->shop->id; // 订单所属店铺id，即用户所属店铺id
        $totalPrice = 0; // 订单总金额

        DB::beginTransaction();
        try {
            $skuIds = collect($productSkus)->pluck('product_sku_id');

            // 循环给商品加锁，锁住库存，只有有一个商品上不了锁，证明有人占有锁了，则抛出异常
            // TODO 这里应该finally上释放锁，不然会导致第二个商品抛异常了，第一个商品还是不给他释放锁
            $lockResult = [];
            foreach ($skuIds as $value) {
                $lock = Cache::lock('product_sku_id_'.$value.'_lock', 10);
                if (!$lock->get()) {
                    return $this->error([], '请勿重复提交');
                }
                $lockResult[] = $lock;
            }

            $orderItems = [];
            foreach ($productSkus as $value) {
                // 对提交上来的商品进行一系列验证
                $productSkuResult = $productSku::query()->with('product:id,product_name,on_sale')->where('id', $value['product_sku_id'])->first(['id', 'title', 'product_id', 'price', 'stock']);
                $productName = optional($productSkuResult->product)->product_name;
                $productTitle = $productSkuResult->title;

                if (!$productSkuResult->product) {
                    return $this->error([], "商品[{$productName}]不存在，请重新选购");
                }

                if (!$productSkuResult) {
                    return $this->error([], "商品[{$productName}，{$productTitle}]不存在，请重新选购");
                }

                if (optional($productSkuResult->product)->on_sale != 1) {
                    return $this->error([], "商品[{$productName}]已下架，请重新选购");
                }

                if ($productSkuResult->stock === 0) {
                    return $this->error([], "商品[{$productName}，{$productTitle}]已售空，请重新选购");
                }

                if ($value['num'] > $productSkuResult->stock) {
                    return $this->error([], "商品[{$productName}，{$productTitle}]库存不足");
                }

                // 计算提交上来的商品的总金额
                $totalPrice = bcadd($totalPrice, bcmul($productSkuResult['price'], $value['num'], 2), 2);

                $orderItems[] = [
                    'product_id' => $productSkuResult['product_id'],
                    'product_sku_id' => $productSkuResult['id'],
                    'num' => $value['num'],
                    'price' => $productSkuResult['price']
                ];
            }

            // 生成订单号
            $orderNo = $order::findAvailableNo();
            if (!$orderNo) {
                throw new \Exception('订单号生成失败');
            }

            // 获取提交上来的商品信息
            $orderResult = $product::query()->with(['productSkus' => function ($query) use ($skuIds) {
                return $query->whereIn('id', $skuIds);
            }])->get();
            $orderInfo = $orderResult->toJson();

            // 创建订单
            $order = $order::create([
                'user_id' => $user['id'],
                'shop_id' => $shopId,
                'order_no' => $orderNo,
                'total_price' => $totalPrice,
                'remark' => $remark,
                'status' => Order::UNPAID,
                'order_type' => $orderType,
                'take_time' => $takeTime,
                'desk_num' => $deskNum,
                'order_info' => $orderInfo
            ]);
            // 创建子订单
            $order->orderItems()->createMany($orderItems);

            // 减库存，加销量
            foreach ($productSkus as $value) {
                $productSku::query()->where('id', $value['product_sku_id'])->where('stock', '>', 0)->decrement('stock', $value['num']);
                $productSku::query()->where('id', $value['product_sku_id'])->increment('sales', $value['num']);
                $productId =  $productSku::query()->where('id', $value['product_sku_id'])->value('product_id');
                $product::query()->where('id', $productId)->where('stock', '>', 0)->decrement('stock', $value['num']);
                $product::query()->where('id', $productId)->increment('sales', $value['num']);
            }

            // CancelOrder::dispatch($order)->afterCommit()->onQueue('cancelOrder')->delay(Carbon::now()->addMinutes(15));
            DB::commit();

            // 将订单放入到队列里面，超过15分钟未支付则自动关闭订单，注意，这里没有加【afterCommit方法】，是因为【afterCommit方法】是用在【在事务"中"分发任务】时使用（看我上面注释的）。现在这里是在commit之后，所以不需要用此方法
            CancelOrder::dispatch($order)->onQueue('cancelOrder')->delay(Carbon::now()->addSeconds(15));

            return $this->success([], '订单创建成功');
        } catch (\Exception $exception) {
            DB::rollBack();
            Log::error($exception);
            return $this->error([], $exception->getMessage());

        } finally {
            if (!empty($lockResult)) {
                // 释放锁
                foreach ($lockResult as $lock) {
                    $lock->release();
                }
            }
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     */
    public function show(Order $order, int $id)
    {
        // 获取当前用户
        $order = $order::query()->find($id);
        if (!$order) {
            return $this->error([],'此订单不存在，请刷新页面');
        }

        $result = $order::query()
            ->with('shop:id,shop_name,address')
            ->where('id', $id)
            ->first(['id', 'order_no', 'total_price', 'status', 'shop_id', 'created_at', 'desk_num', 'take_time', 'order_type', 'order_info']);

        return $this->success($result);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    // 取消订单
    public function cancel(Order $order)
    {
        // TODO 这里本来想加锁的，因为有可能在判断订单状态和改订单状态之间如果有另外一个请求刚好把订单状态改了为已支付，然后下面又改为已取消，就会有问题，
        // TODO 但是这种情况只会出现在很高并发下才会出现，取消订单并不是高并发操作，所以我感觉可以不对这种情况做处理

        if ($order->status != Order::UNPAID) {
            return $this->error([], '无法取消，订单状态有误');
        }


        DB::beginTransaction();
        try {
            $order->status = Order::CANCELLED;
            $order->save();

            foreach ($order->orderItems as $item) {
                // 订单所购买的库存都加回去对应的商品
                $item->productSku()->increment('stock', $item->num);
                $item->product()->increment('stock', $item->num);
                // 销量减回去
                $item->productSku()->decrement('sales', $item->num);
                $item->product()->decrement('sales', $item->num);
            }

            DB::commit();
            return $this->success([], '取消成功');

        } catch (\Exception $exception) {
            DB::rollBack();
            return $this->error([], '取消失败');
        }


    }
}
